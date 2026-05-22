<?php
/**
 * Unit tests for SignatureVerifier.
 *
 * @package NinjaPay\WooCommerce\Tests
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Tests\Unit;

use NinjaPay\WooCommerce\Webhook\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase {

	private const SECRET = 'whsec_test_supersecret_long_enough_to_be_realistic_xxxxxxxxxxx';

	public function test_accepts_valid_signature(): void {
		$body      = '{"id":"evt_1","type":"payment_intent.succeeded","data":{}}';
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );
		$header    = "t={$timestamp},v1={$signature}";

		$result = SignatureVerifier::verify( $body, $header, self::SECRET );

		self::assertTrue( $result['ok'] );
	}

	public function test_rejects_expired_timestamp(): void {
		$body      = '{"id":"evt_1","type":"x","data":{}}';
		$timestamp = time() - 600; // > 5 min tolerance
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );
		$header    = "t={$timestamp},v1={$signature}";

		$result = SignatureVerifier::verify( $body, $header, self::SECRET );

		self::assertFalse( $result['ok'] );
		self::assertSame( SignatureVerifier::RESULT_EXPIRED, $result['reason'] );
	}

	public function test_rejects_wrong_secret(): void {
		$body      = '{"id":"evt_1","type":"x","data":{}}';
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, 'wrong_secret' );
		$header    = "t={$timestamp},v1={$signature}";

		$result = SignatureVerifier::verify( $body, $header, self::SECRET );

		self::assertFalse( $result['ok'] );
		self::assertSame( SignatureVerifier::RESULT_INVALID, $result['reason'] );
	}

	public function test_rejects_tampered_body(): void {
		$body      = '{"id":"evt_1","type":"x","data":{}}';
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );
		$tampered  = str_replace( 'evt_1', 'evt_2', $body );
		$header    = "t={$timestamp},v1={$signature}";

		$result = SignatureVerifier::verify( $tampered, $header, self::SECRET );

		self::assertFalse( $result['ok'] );
		self::assertSame( SignatureVerifier::RESULT_INVALID, $result['reason'] );
	}

	public function test_rejects_malformed_header(): void {
		$result = SignatureVerifier::verify( '{}', 'not-a-real-header', self::SECRET );

		self::assertFalse( $result['ok'] );
		self::assertSame( SignatureVerifier::RESULT_MALFORMED, $result['reason'] );
	}

	public function test_rejects_missing_v1(): void {
		$result = SignatureVerifier::verify( '{}', 't=1234567890', self::SECRET );

		self::assertFalse( $result['ok'] );
		self::assertSame( SignatureVerifier::RESULT_MALFORMED, $result['reason'] );
	}
}
