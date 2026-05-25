<?php
/**
 * Render NinjaPay settlement metadata on the WooCommerce order admin
 * screen — the payment intent id, payment link id, on-chain attestation
 * signature (with a Solscan link), and our internal status.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Admin;

use WC_Order;

/**
 * Order-edit-screen metadata panel for NinjaPay orders.
 */
final class OrderMetaDisplay {

	/**
	 * Hook the renderer onto the order admin screen.
	 */
	public static function register(): void {
		add_action(
			'woocommerce_admin_order_data_after_billing_address',
			[ self::class, 'render' ]
		);
	}

	/**
	 * Render the NinjaPay panel for a NinjaPay-paid order.
	 *
	 * @param WC_Order $order The order being edited.
	 */
	public static function render( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'ninjapay' !== $order->get_payment_method() ) {
			return;
		}

		$status     = (string) $order->get_meta( '_ninjapay_status' );
		$intent_id  = (string) $order->get_meta( '_ninjapay_intent_id' );
		$link_id    = (string) $order->get_meta( '_ninjapay_payment_link_id' );
		$attest_sig = (string) $order->get_meta( '_ninjapay_attestation_sig' );

		if ( '' === $status && '' === $intent_id && '' === $link_id ) {
			return;
		}

		echo '<div class="ninjapay-order-meta">';
		echo '<h3>' . esc_html__( 'NinjaPay', 'ninjapay-woocommerce' ) . '</h3>';
		echo '<p class="form-field form-field-wide">';

		if ( '' !== $status ) {
			echo '<strong>' . esc_html__( 'Status', 'ninjapay-woocommerce' ) . ':</strong> '
				. esc_html( $status ) . '<br />';
		}
		if ( '' !== $intent_id ) {
			echo '<strong>' . esc_html__( 'Payment intent', 'ninjapay-woocommerce' ) . ':</strong> <code>'
				. esc_html( $intent_id ) . '</code><br />';
		}
		if ( '' !== $link_id ) {
			echo '<strong>' . esc_html__( 'Payment link', 'ninjapay-woocommerce' ) . ':</strong> <code>'
				. esc_html( $link_id ) . '</code><br />';
		}
		if ( '' !== $attest_sig ) {
			echo '<strong>' . esc_html__( 'On-chain settlement', 'ninjapay-woocommerce' ) . ':</strong> '
				. '<a href="' . esc_url( self::explorer_url( $attest_sig ) ) . '" target="_blank" rel="noopener noreferrer"><code>'
				. esc_html( self::shorten( $attest_sig ) ) . '</code></a>';
		}

		echo '</p></div>';
	}

	/**
	 * Build a Solscan URL for a transaction signature, honouring the
	 * gateway's live/test environment toggle (test → devnet).
	 */
	private static function explorer_url( string $signature ): string {
		$settings    = get_option( 'woocommerce_ninjapay_settings', [] );
		$environment = is_array( $settings ) ? (string) ( $settings['environment'] ?? 'test' ) : 'test';
		$base        = 'https://solscan.io/tx/' . rawurlencode( $signature );
		return 'live' === $environment ? $base : $base . '?cluster=devnet';
	}

	/**
	 * Abbreviate a long signature for display (head…tail).
	 */
	private static function shorten( string $value ): string {
		if ( strlen( $value ) <= 18 ) {
			return $value;
		}
		return substr( $value, 0, 10 ) . '…' . substr( $value, -6 );
	}
}
