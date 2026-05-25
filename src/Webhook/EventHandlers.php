<?php
/**
 * Webhook event handlers — map verified NinjaPay events onto WooCommerce
 * order state.
 *
 * Split in two on purpose:
 *   - `parse()` is PURE (no WP/WC calls): a decoded event array in, a
 *     normalized decision array out. Unit-tested in isolation.
 *   - `dispatch()` is the thin WC shell: calls `parse()`, loads the
 *     order, applies the side effect. Covered by wp-env integration
 *     tests.
 *
 * Order correlation rides on the `metadata.wc_order_id` we stamp onto
 * the payment link at create time — NinjaPay copies link metadata onto
 * the intent, so it round-trips back on every payment_intent.* event,
 * and we stamp it onto refunds too so refund.* events correlate the
 * same way.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Webhook;

use NinjaPay\WooCommerce\Support\Logger;
use WC_Order;

/**
 * Per-type webhook dispatch.
 */
final class EventHandlers {

	/**
	 * Normalize a verified event into a decision the dispatcher can act
	 * on without re-reading the raw payload. Pure — no WP/WC calls, so
	 * it unit-tests without a WordPress runtime.
	 *
	 * @param array<string,mixed> $event Decoded, signature-verified event.
	 * @return array{kind:string,orderId:?int,intentId:?string,attestationSig:?string,refundSig:?string,reason:?string}
	 */
	public static function parse( array $event ): array {
		$decision = [
			'kind'           => 'ignored',
			'orderId'        => null,
			'intentId'       => null,
			'attestationSig' => null,
			'refundSig'      => null,
			'reason'         => null,
		];

		$type   = isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : '';
		$object = ( isset( $event['data'] ) && is_array( $event['data'] ) && isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) )
			? $event['data']['object']
			: [];

		$decision['orderId'] = self::order_id_from( $object );

		switch ( $type ) {
			case 'payment_intent.succeeded':
				$decision['kind']           = 'payment_succeeded';
				$decision['intentId']       = self::string_or_null( $object['id'] ?? null );
				$decision['attestationSig'] = self::string_or_null(
					$object['attestationSig'] ?? $object['attestation_sig'] ?? ( $object['settlement']['attestationSig'] ?? null )
				);
				break;

			case 'payment_intent.failed':
				$decision['kind']     = 'payment_failed';
				$decision['intentId'] = self::string_or_null( $object['id'] ?? null );
				$decision['reason']   = self::string_or_null(
					$object['failureReason'] ?? $object['failure_reason'] ?? $object['status'] ?? null
				);
				break;

			case 'refund.succeeded':
				$decision['kind']      = 'refund_succeeded';
				$decision['refundSig'] = self::string_or_null( $object['refundTxSig'] ?? $object['refund_tx_sig'] ?? null );
				break;

			case 'refund.failed':
				$decision['kind']   = 'refund_failed';
				$decision['reason'] = self::string_or_null(
					$object['failureReason'] ?? $object['failure_reason'] ?? null
				);
				break;
		}

		return $decision;
	}

	/**
	 * Apply a verified event to the corresponding WooCommerce order.
	 *
	 * @param array<string,mixed> $event Decoded, signature-verified event.
	 */
	public static function dispatch( array $event ): void {
		$decision = self::parse( $event );
		if ( 'ignored' === $decision['kind'] ) {
			return;
		}
		if ( null === $decision['orderId'] ) {
			Logger::log(
				sprintf( 'webhook %s carried no wc_order_id metadata; skipping', (string) ( $event['type'] ?? '' ) ),
				'warning'
			);
			return;
		}

		$order = wc_get_order( $decision['orderId'] );
		if ( ! $order instanceof WC_Order ) {
			Logger::log( sprintf( 'webhook references unknown order #%d', $decision['orderId'] ), 'warning' );
			return;
		}

		switch ( $decision['kind'] ) {
			case 'payment_succeeded':
				if ( null !== $decision['intentId'] ) {
					$order->update_meta_data( '_ninjapay_intent_id', $decision['intentId'] );
				}
				if ( null !== $decision['attestationSig'] ) {
					$order->update_meta_data( '_ninjapay_attestation_sig', $decision['attestationSig'] );
				}
				$order->update_meta_data( '_ninjapay_status', 'succeeded' );
				// payment_complete() is idempotent in WC — a replayed
				// event won't double-complete a paid order.
				$order->payment_complete( $decision['intentId'] ?? '' );
				$order->add_order_note( __( 'NinjaPay payment settled.', 'ninjapay-woocommerce' ) );
				$order->save();
				do_action( 'ninjapay_order_paid', $order, $event );
				break;

			case 'payment_failed':
				$order->update_meta_data( '_ninjapay_status', 'failed' );
				$order->save();
				$order->update_status(
					'failed',
					sprintf(
						/* translators: %s is the failure reason. */
						__( 'NinjaPay payment failed: %s', 'ninjapay-woocommerce' ),
						$decision['reason'] ?? __( 'unknown', 'ninjapay-woocommerce' )
					)
				);
				break;

			case 'refund_succeeded':
				$order->add_order_note(
					sprintf(
						/* translators: %s is the on-chain refund signature. */
						__( 'NinjaPay refund settled on-chain%s.', 'ninjapay-woocommerce' ),
						null !== $decision['refundSig'] ? ' (' . $decision['refundSig'] . ')' : ''
					)
				);
				do_action( 'ninjapay_refund_succeeded', $order, $event );
				break;

			case 'refund_failed':
				// The WC refund was already recorded optimistically when
				// process_refund() returned. A failed on-chain settlement
				// needs a human: surface it loudly rather than silently
				// reversing WC state.
				$order->add_order_note(
					sprintf(
						/* translators: %s is the failure reason. */
						__( 'NinjaPay refund FAILED on-chain: %s. Manual reconciliation required.', 'ninjapay-woocommerce' ),
						$decision['reason'] ?? __( 'unknown', 'ninjapay-woocommerce' )
					)
				);
				break;
		}
	}

	/**
	 * Extract a WC order id from an event object's metadata.
	 *
	 * @param array<string,mixed> $object Event `data.object`.
	 */
	private static function order_id_from( array $object ): ?int {
		$metadata = ( isset( $object['metadata'] ) && is_array( $object['metadata'] ) ) ? $object['metadata'] : [];
		$raw      = $metadata['wc_order_id'] ?? null;
		if ( is_int( $raw ) ) {
			return $raw > 0 ? $raw : null;
		}
		if ( is_string( $raw ) && ctype_digit( $raw ) ) {
			$id = (int) $raw;
			return $id > 0 ? $id : null;
		}
		return null;
	}

	/**
	 * Coerce a value to a non-empty string or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function string_or_null( $value ): ?string {
		return ( is_string( $value ) && '' !== $value ) ? $value : null;
	}
}
