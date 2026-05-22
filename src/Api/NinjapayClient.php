<?php
/**
 * Thin wrapper around `wp_remote_post` / `wp_remote_get`. Injects API
 * key + `Idempotency-Key` headers, retries 5xx with exponential backoff,
 * surfaces structured errors.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Api;

/**
 * Minimal NinjaPay API client built on WP's HTTP API.
 *
 * Avoids Guzzle to dodge PHP version conflicts on shared-hosting
 * environments. `wp_remote_post` handles TLS verification + timeouts
 * natively.
 */
final class NinjapayClient {

	private const TIMEOUT_SECONDS = 15;
	private const MAX_RETRIES     = 3;

	/** @var string */
	private $base_url;

	/** @var string */
	private $api_key;

	public function __construct( string $base_url, string $api_key ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->api_key  = $api_key;
	}

	/**
	 * POST to a NinjaPay endpoint with idempotency key.
	 *
	 * @param string                $path             Endpoint path (e.g. `/v1/payment_intents`).
	 * @param array<string,mixed>   $body             JSON-encodable body.
	 * @param string|null           $idempotency_key  Idempotency key (optional but recommended).
	 * @return array<string,mixed>                    Decoded response body.
	 * @throws NinjapayApiException                   On 4xx or exhausted retries.
	 */
	public function post( string $path, array $body, ?string $idempotency_key = null ): array {
		return $this->request( 'POST', $path, $body, $idempotency_key );
	}

	/**
	 * GET from a NinjaPay endpoint.
	 *
	 * @param string                $path  Endpoint path.
	 * @return array<string,mixed>         Decoded response body.
	 * @throws NinjapayApiException        On 4xx or exhausted retries.
	 */
	public function get( string $path ): array {
		return $this->request( 'GET', $path, null, null );
	}

	/**
	 * Inner request with retry loop.
	 *
	 * @param string                      $method           HTTP method.
	 * @param string                      $path             Endpoint path.
	 * @param array<string,mixed>|null    $body             Body (POST/PATCH).
	 * @param string|null                 $idempotency_key  Idempotency key.
	 * @return array<string,mixed>
	 * @throws NinjapayApiException
	 */
	private function request( string $method, string $path, ?array $body, ?string $idempotency_key ): array {
		$url     = $this->base_url . $path;
		$headers = [
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
			'User-Agent'    => 'ninjapay-woocommerce/' . NINJAPAY_WC_VERSION . ' wp/' . get_bloginfo( 'version' ),
		];

		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => self::TIMEOUT_SECONDS,
		];

		if ( null !== $body ) {
			$args['body']                 = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$attempt    = 0;
		$last_error = null;

		while ( $attempt < self::MAX_RETRIES ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				$attempt++;
				if ( $attempt < self::MAX_RETRIES ) {
					usleep( ( 2 ** $attempt ) * 250_000 );
					continue;
				}
				throw new NinjapayApiException( 'network_error: ' . $last_error );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );

			// Retry only on 5xx — never on 4xx (caller error).
			if ( $status >= 500 ) {
				$last_error = $raw;
				$attempt++;
				if ( $attempt < self::MAX_RETRIES ) {
					usleep( ( 2 ** $attempt ) * 250_000 );
					continue;
				}
				throw new NinjapayApiException( "server_error_{$status}: " . $raw );
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				throw new NinjapayApiException( 'malformed_response: ' . $raw );
			}

			if ( $status >= 400 ) {
				$error_code    = is_string( $decoded['error'] ?? null ) ? $decoded['error'] : "client_error_{$status}";
				$error_message = is_string( $decoded['message'] ?? null ) ? $decoded['message'] : $raw;
				throw new NinjapayApiException( "{$error_code}: {$error_message}", $status );
			}

			return $decoded;
		}

		throw new NinjapayApiException( 'exhausted_retries: ' . ( $last_error ?? 'unknown' ) );
	}
}
