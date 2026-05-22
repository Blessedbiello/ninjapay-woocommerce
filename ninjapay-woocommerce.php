<?php
/**
 * Plugin Name:       NinjaPay for WooCommerce
 * Plugin URI:        https://github.com/Blessedbiello/ninjapay-woocommerce
 * Description:       Accept Solana-native, privacy-by-default payments on WooCommerce. Powered by NinjaPay.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            NinjaPay
 * Author URI:        https://ninjapay.finance
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ninjapay-woocommerce
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.4
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

define( 'NINJAPAY_WC_VERSION', '0.1.0' );
define( 'NINJAPAY_WC_PLUGIN_FILE', __FILE__ );
define( 'NINJAPAY_WC_PLUGIN_DIR', __DIR__ );
define( 'NINJAPAY_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer autoload.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Bootstrap. Defers everything until WC is loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'NinjaPay for WooCommerce requires WooCommerce to be active.', 'ninjapay-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'NinjaPay for WooCommerce requires PHP 7.4 or higher.', 'ninjapay-woocommerce' );
					echo '</p></div>';
				}
			);
			return;
		}

		( new \NinjaPay\WooCommerce\Plugin() )->boot();
	}
);

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * Required for WC 8.0+ stores running HPOS. Without this declaration
 * WC shows a "not compatible" warning in the admin.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				NINJAPAY_WC_PLUGIN_FILE,
				true
			);
		}
	}
);
