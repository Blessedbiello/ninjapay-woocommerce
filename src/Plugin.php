<?php
/**
 * Plugin bootstrap.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce;

use NinjaPay\WooCommerce\Admin\SettingsPage;
use NinjaPay\WooCommerce\Gateway\NinjapayGateway;
use NinjaPay\WooCommerce\Webhook\Receiver;

/**
 * Plugin bootstrap. Registers the gateway, settings page, and webhook
 * receiver. Constructor is intentionally light — heavy work runs in
 * `boot()` after `plugins_loaded` fires + WC is confirmed active.
 */
final class Plugin {

	/**
	 * Register hooks. Called from the main plugin file after WC
	 * activation is confirmed.
	 */
	public function boot(): void {
		// Register the gateway with WC.
		add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );

		// Register the webhook receiver on the `wc-api/ninjapay_webhook` URL.
		add_action( 'woocommerce_api_ninjapay_webhook', [ Receiver::class, 'handle' ] );

		// Load text domain for translations.
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain(
					'ninjapay-woocommerce',
					false,
					dirname( plugin_basename( NINJAPAY_WC_PLUGIN_FILE ) ) . '/languages'
				);
			}
		);
	}

	/**
	 * Register the NinjaPay gateway in WC.
	 *
	 * @param array<string> $gateways Existing gateway class names.
	 * @return array<string>
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = NinjapayGateway::class;
		return $gateways;
	}
}
