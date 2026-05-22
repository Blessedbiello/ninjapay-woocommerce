<?php
/**
 * Derive a deterministic idempotency key from a WC order.
 *
 * Format: `wc_order_{$id}_{$order_key}`. The `order_key` is WC's
 * tamper-resistant per-order secret (`wc_get_order(...)->get_order_key()`).
 *
 * Same WC order → same idempotency key. WC retries on network failure
 * dedupe at the NinjaPay API surface.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Support;

use WC_Order;

/**
 * Idempotency key derivation.
 */
final class IdempotencyKey {

	/**
	 * Derive an idempotency key from a WC order.
	 *
	 * @param WC_Order $order WC order instance.
	 * @return string         Deterministic idempotency key.
	 */
	public static function from_order( WC_Order $order ): string {
		return sprintf( 'wc_order_%d_%s', $order->get_id(), $order->get_order_key() );
	}
}
