<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads Composer autoload + Yoast polyfills. For integration tests,
 * additionally bootstraps wp-env (see tests/integration/*).
 *
 * @package NinjaPay\WooCommerce\Tests
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

if ( file_exists( __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' ) ) {
	require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
}

// Define WP-ish constants for unit tests that don't run inside WP.
if ( ! defined( 'NINJAPAY_WC_VERSION' ) ) {
	define( 'NINJAPAY_WC_VERSION', '0.1.0' );
}
