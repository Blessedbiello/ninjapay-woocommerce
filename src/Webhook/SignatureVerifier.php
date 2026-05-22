<?php
/**
 * Webhook signature verification.
 *
 * Stripe-portable HMAC-SHA256 scheme matching `@ninjapay/sdk/webhooks`
 * and `packages/webhook-delivery/src/signature.ts` in the NinjaPay
 * monorepo. Header format:
 *
 *     X-NinjaPay-Signature: t=<unix-secs>,v1=<hex-hmac>
 *
 * Signed payload: `<t>.<raw-body>`. Verifier:
 *   1. Parse header
 *   2. Assert timestamp within ±5min tolerance
 *   3. Recompute HMAC-SHA256(`<t>.<body>`, secret)
 *   4. Constant-time compare with `hash_equals`
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Webhook;

/**
 * Verify webhook signatures sent by the NinjaPay API.
 */
final class SignatureVerifier {

	private const TOLERANCE_SECONDS = 300;

	/**
	 * Result discriminator: success.
	 */
	public const RESULT_OK = 'ok';

	/**
	 * Result discriminator: header malformed.
	 */
	public const RESULT_MALFORMED = 'malformed_header';

	/**
	 * Result discriminator: timestamp outside tolerance.
	 */
	public const RESULT_EXPIRED = 'expired';

	/**
	 * Result discriminator: HMAC mismatch.
	 */
	public const RESULT_INVALID = 'invalid_signature';

	/**
	 * Verify a webhook signature.
	 *
	 * @param string $body          Raw request body.
	 * @param string $header_value  Value of `X-NinjaPay-Signature` header.
	 * @param string $secret        Webhook signing secret.
	 * @return array{ok: bool, reason?: string}
	 */
	public static function verify( string $body, string $header_value, string $secret ): array {
		$parsed = self::parse_header( $header_value );
		if ( null === $parsed ) {
			return [
				'ok'     => false,
				'reason' => self::RESULT_MALFORMED,
			];
		}

		[ $timestamp, $signature ] = $parsed;

		if ( abs( time() - $timestamp ) > self::TOLERANCE_SECONDS ) {
			return [
				'ok'     => false,
				'reason' => self::RESULT_EXPIRED,
			];
		}

		$signed_payload = $timestamp . '.' . $body;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return [
				'ok'     => false,
				'reason' => self::RESULT_INVALID,
			];
		}

		return [ 'ok' => true ];
	}

	/**
	 * Parse a `t=…,v1=…` header into [timestamp, signature].
	 *
	 * @param string $header_value Raw header value.
	 * @return array{0:int,1:string}|null
	 */
	private static function parse_header( string $header_value ): ?array {
		$timestamp = null;
		$signature = null;

		foreach ( explode( ',', $header_value ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$kv = explode( '=', $part, 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			[ $k, $v ] = $kv;
			if ( 't' === $k && ctype_digit( $v ) ) {
				$timestamp = (int) $v;
			} elseif ( 'v1' === $k && ctype_xdigit( $v ) ) {
				$signature = $v;
			}
		}

		if ( null === $timestamp || null === $signature ) {
			return null;
		}

		return [ $timestamp, $signature ];
	}
}
