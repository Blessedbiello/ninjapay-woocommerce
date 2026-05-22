<?php
/**
 * Exception thrown by NinjapayClient on API errors.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Api;

use Exception;

/**
 * Signal API-side failure: 4xx (caller error) or exhausted retries on 5xx.
 */
final class NinjapayApiException extends Exception {}
