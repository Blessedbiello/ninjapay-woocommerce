<?php
/**
 * Logger wrapper around WC_Logger. Adds a `ninjapay` source tag.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Support;

use WC_Logger;

/**
 * Lightweight wrapper that lazy-loads the WC logger.
 */
final class Logger {

	/** @var WC_Logger|null */
	private static $logger = null;

	/**
	 * Log a message.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (debug, info, notice, warning, error, critical, alert, emergency).
	 */
	public static function log( string $message, string $level = 'info' ): void {
		if ( null === self::$logger ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				self::$logger = wc_get_logger();
			} else {
				return;
			}
		}

		self::$logger->log( $level, $message, [ 'source' => 'ninjapay' ] );
	}
}
