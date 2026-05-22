<?php
/**
 * Webhook receiver — handles inbound `POST /wc-api/ninjapay_webhook`.
 *
 * Verifies signature, dedupes by `event_id` via WP transients, dispatches
 * to per-type handlers.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Webhook;

use NinjaPay\WooCommerce\Support\Logger;

/**
 * Inbound webhook endpoint. Registered at /wc-api/ninjapay_webhook by
 * the `Plugin::boot()` bootstrap.
 */
final class Receiver {

	private const DEDUPE_TTL_SECONDS = 600;

	/**
	 * Handle an inbound webhook. Called by WC's `woocommerce_api_*` hook
	 * before any output. Must write the response + exit cleanly.
	 */
	public static function handle(): void {
		$body = (string) file_get_contents( 'php://input' );

		// Stop here if body is empty — likely a misfire.
		if ( '' === $body ) {
			self::respond( 400, [ 'error' => 'empty_body' ] );
			return;
		}

		$header_value = isset( $_SERVER['HTTP_X_NINJAPAY_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_NINJAPAY_SIGNATURE'] ) )
			: '';

		if ( '' === $header_value ) {
			self::respond( 400, [ 'error' => 'missing_signature_header' ] );
			return;
		}

		$gateway_settings = get_option( 'woocommerce_ninjapay_settings', [] );
		$webhook_secret   = $gateway_settings['webhook_secret'] ?? '';

		if ( '' === $webhook_secret ) {
			Logger::log( 'webhook received but no secret configured', 'error' );
			self::respond( 500, [ 'error' => 'webhook_not_configured' ] );
			return;
		}

		$verification = SignatureVerifier::verify( $body, $header_value, $webhook_secret );
		if ( ! $verification['ok'] ) {
			Logger::log( 'webhook signature rejected: ' . ( $verification['reason'] ?? '' ), 'warning' );
			self::respond( 400, [ 'error' => $verification['reason'] ?? 'invalid_signature' ] );
			return;
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || ! isset( $event['id'], $event['type'] ) ) {
			self::respond( 400, [ 'error' => 'malformed_payload' ] );
			return;
		}

		$event_id = (string) $event['id'];

		// Dedupe via WP transient.
		$dedupe_key = 'ninjapay_webhook_' . md5( $event_id );
		if ( false !== get_transient( $dedupe_key ) ) {
			// Already processed — silent success.
			self::respond( 200, [ 'received' => true, 'duplicate' => true ] );
			return;
		}
		set_transient( $dedupe_key, '1', self::DEDUPE_TTL_SECONDS );

		// Allow extensions to hook in before our dispatch.
		do_action( 'ninjapay_webhook_received', $event );

		// Per-type dispatch — handlers land in week 3 (EventHandlers.php).
		// For now: respond 200 to acknowledge receipt.
		self::respond( 200, [ 'received' => true ] );
	}

	/**
	 * Send a JSON response with the given status code and exit.
	 *
	 * @param int                  $status Status code.
	 * @param array<string,mixed>  $body   Response body.
	 */
	private static function respond( int $status, array $body ): void {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}
}
