<?php
/**
 * Unit tests for EventHandlers::parse() — the pure (WP-free) half of
 * webhook dispatch. Locks the event→decision mapping the WooCommerce
 * shell relies on.
 *
 * @package NinjaPay\WooCommerce\Tests
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Tests\Unit;

use NinjaPay\WooCommerce\Webhook\EventHandlers;
use PHPUnit\Framework\TestCase;

final class EventHandlersParseTest extends TestCase {

	/**
	 * @param array<string,mixed> $object data.object payload.
	 * @return array<string,mixed>
	 */
	private function event( string $type, array $object ): array {
		return [
			'id'   => 'evt_test',
			'type' => $type,
			'data' => [ 'object' => $object ],
		];
	}

	public function test_payment_succeeded_extracts_order_intent_and_attestation(): void {
		$decision = EventHandlers::parse(
			$this->event(
				'payment_intent.succeeded',
				[
					'id'             => 'pi_123',
					'status'         => 'SUCCEEDED',
					'attestationSig' => 'sigABC',
					'metadata'       => [ 'wc_order_id' => '42', 'wc_order_key' => 'wc_order_xyz' ],
				]
			)
		);
		$this->assertSame( 'payment_succeeded', $decision['kind'] );
		$this->assertSame( 42, $decision['orderId'] );
		$this->assertSame( 'pi_123', $decision['intentId'] );
		$this->assertSame( 'sigABC', $decision['attestationSig'] );
	}

	public function test_payment_succeeded_without_metadata_yields_null_order(): void {
		$decision = EventHandlers::parse(
			$this->event( 'payment_intent.succeeded', [ 'id' => 'pi_1' ] )
		);
		$this->assertSame( 'payment_succeeded', $decision['kind'] );
		$this->assertNull( $decision['orderId'] );
	}

	public function test_payment_failed_maps_reason(): void {
		$decision = EventHandlers::parse(
			$this->event(
				'payment_intent.failed',
				[ 'id' => 'pi_2', 'failureReason' => 'insufficient_funds', 'metadata' => [ 'wc_order_id' => '7' ] ]
			)
		);
		$this->assertSame( 'payment_failed', $decision['kind'] );
		$this->assertSame( 7, $decision['orderId'] );
		$this->assertSame( 'insufficient_funds', $decision['reason'] );
	}

	public function test_refund_succeeded_extracts_signature_and_order(): void {
		$decision = EventHandlers::parse(
			$this->event(
				'refund.succeeded',
				[ 'id' => 'ref_1', 'refundTxSig' => 'rsig9', 'metadata' => [ 'wc_order_id' => '99' ] ]
			)
		);
		$this->assertSame( 'refund_succeeded', $decision['kind'] );
		$this->assertSame( 99, $decision['orderId'] );
		$this->assertSame( 'rsig9', $decision['refundSig'] );
	}

	public function test_refund_failed_maps_reason(): void {
		$decision = EventHandlers::parse(
			$this->event(
				'refund.failed',
				[ 'id' => 'ref_2', 'failureReason' => 'chain_revert', 'metadata' => [ 'wc_order_id' => '5' ] ]
			)
		);
		$this->assertSame( 'refund_failed', $decision['kind'] );
		$this->assertSame( 'chain_revert', $decision['reason'] );
	}

	public function test_unknown_event_type_is_ignored(): void {
		$decision = EventHandlers::parse(
			$this->event( 'customer.updated', [ 'id' => 'cus_1' ] )
		);
		$this->assertSame( 'ignored', $decision['kind'] );
	}

	public function test_integer_order_id_metadata_is_accepted(): void {
		$decision = EventHandlers::parse(
			$this->event( 'payment_intent.succeeded', [ 'id' => 'pi_3', 'metadata' => [ 'wc_order_id' => 13 ] ] )
		);
		$this->assertSame( 13, $decision['orderId'] );
	}

	public function test_non_numeric_order_id_metadata_is_rejected(): void {
		$decision = EventHandlers::parse(
			$this->event( 'payment_intent.succeeded', [ 'id' => 'pi_4', 'metadata' => [ 'wc_order_id' => 'not-a-number' ] ] )
		);
		$this->assertNull( $decision['orderId'] );
	}

	public function test_missing_data_object_is_safe(): void {
		$decision = EventHandlers::parse( [ 'id' => 'evt_x', 'type' => 'payment_intent.succeeded' ] );
		$this->assertSame( 'payment_succeeded', $decision['kind'] );
		$this->assertNull( $decision['orderId'] );
		$this->assertNull( $decision['intentId'] );
	}
}
