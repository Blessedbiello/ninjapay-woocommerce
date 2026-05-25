<?php
/**
 * Integration-test bootstrap. Loads the WordPress PHPUnit test library
 * (provisioned by `wp-env`), with WooCommerce and this plugin active.
 *
 * NOT used by the unit suite (phpunit.xml → tests/bootstrap.php), which
 * runs without a WordPress runtime.
 *
 * @package NinjaPay\WooCommerce\Tests
 */

declare( strict_types = 1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_functions = $_tests_dir . '/includes/functions.php';
if ( ! file_exists( $_functions ) ) {
	fwrite(
		STDERR,
		"WordPress test library not found at {$_tests_dir}.\n" .
		"Run the integration suite via wp-env — see docs/TESTING.md.\n"
	);
	exit( 1 );
}

require_once $_functions;

/**
 * Load WooCommerce (so WC_Order, wc_create_order, etc. exist) and this
 * plugin before WordPress finishes booting the test environment.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$wc = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( file_exists( $wc ) ) {
			require_once $wc;
		}
		require dirname( __DIR__, 2 ) . '/ninjapay-woocommerce.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
