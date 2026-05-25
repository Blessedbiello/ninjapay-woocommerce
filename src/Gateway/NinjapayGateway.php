<?php
/**
 * NinjaPay payment gateway. Hosted-checkout redirect flow.
 *
 * @package NinjaPay\WooCommerce
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Gateway;

use NinjaPay\WooCommerce\Api\NinjapayClient;
use NinjaPay\WooCommerce\Support\IdempotencyKey;
use NinjaPay\WooCommerce\Support\Logger;
use WC_Order;
use WC_Payment_Gateway;

/**
 * WC payment gateway: redirects payer to NinjaPay hosted checkout,
 * then waits for webhook confirmation before marking the order paid.
 *
 * The return URL only sets `pending` on the order — never `paid`. The
 * webhook is the source of truth.
 */
final class NinjapayGateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'ninjapay';
		$this->method_title       = __( 'NinjaPay', 'ninjapay-woocommerce' );
		$this->method_description = __( 'Accept Solana-native, privacy-by-default payments via NinjaPay.', 'ninjapay-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = [ 'products', 'refunds' ];

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', __( 'NinjaPay', 'ninjapay-woocommerce' ) );
		$this->description = (string) $this->get_option( 'description', '' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * @inheritDoc
	 */
	public function init_form_fields(): void {
		$webhook_url = WC()->api_request_url( 'ninjapay_webhook' );

		$this->form_fields = [
			'enabled'        => [
				'title'   => __( 'Enable / Disable', 'ninjapay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable NinjaPay', 'ninjapay-woocommerce' ),
				'default' => 'no',
			],
			'title'          => [
				'title'   => __( 'Title', 'ninjapay-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'NinjaPay (Solana, USDC / USDT / SOL)', 'ninjapay-woocommerce' ),
				'desc_tip' => true,
				'description' => __( 'Payment method label shown to customers at checkout.', 'ninjapay-woocommerce' ),
			],
			'description'    => [
				'title'   => __( 'Description', 'ninjapay-woocommerce' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with USDC, USDT, or SOL via NinjaPay. Private by default — your payment details aren\'t recorded on the public Solana ledger.', 'ninjapay-woocommerce' ),
			],
			'environment'    => [
				'title'   => __( 'Environment', 'ninjapay-woocommerce' ),
				'type'    => 'select',
				'options' => [
					'live' => __( 'Live (api.ninjapay.finance)', 'ninjapay-woocommerce' ),
					'test' => __( 'Test (api-staging.ninjapay.finance)', 'ninjapay-woocommerce' ),
				],
				'default' => 'test',
			],
			'api_key'        => [
				'title'   => __( 'API key', 'ninjapay-woocommerce' ),
				'type'    => 'password',
				'description' => sprintf(
					/* translators: %s is the NinjaPay dashboard URL */
					__( 'Get your API key from %s.', 'ninjapay-woocommerce' ),
					'<a href="https://app.ninjapay.finance/dashboard/developers" target="_blank">app.ninjapay.finance/dashboard/developers</a>'
				),
				'default' => '',
			],
			'webhook_secret' => [
				'title'   => __( 'Webhook signing secret', 'ninjapay-woocommerce' ),
				'type'    => 'password',
				'default' => '',
			],
			'webhook_url'    => [
				'title'   => __( 'Webhook URL (read-only)', 'ninjapay-woocommerce' ),
				'type'    => 'text',
				'default' => $webhook_url,
				'custom_attributes' => [ 'readonly' => 'readonly' ],
				'description' => __( 'Add this URL as a webhook endpoint in your NinjaPay dashboard.', 'ninjapay-woocommerce' ),
			],
			'privacy_mode'   => [
				'title'   => __( 'Privacy mode', 'ninjapay-woocommerce' ),
				'type'    => 'select',
				'options' => [
					'private' => __( 'PRIVATE — recommended for consumer commerce', 'ninjapay-woocommerce' ),
					'public'  => __( 'PUBLIC — B2B / audit-friendly', 'ninjapay-woocommerce' ),
				],
				'default' => 'private',
			],
		];
	}

	/**
	 * Build a payment intent at NinjaPay, then redirect the payer to
	 * the hosted checkout URL.
	 *
	 * @inheritDoc
	 *
	 * @param int $order_id WC order ID.
	 * @return array{result:string, redirect?:string, message?:string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return [ 'result' => 'failure', 'message' => __( 'Order not found.', 'ninjapay-woocommerce' ) ];
		}

		$client          = $this->client();
		$idempotency_key = IdempotencyKey::from_order( $order );

		$payload = apply_filters(
			'ninjapay_intent_create_args',
			[
				'amount'      => (string) $order->get_total(),
				'currency'    => $order->get_currency(),
				'description' => sprintf( '%s — Order #%d', get_bloginfo( 'name' ), $order->get_id() ),
				'metadata'    => [
					'wc_order_id'  => (string) $order->get_id(),
					'wc_order_key' => $order->get_order_key(),
				],
				// camelCase + uppercase enum to match the /v1/payment_links
				// request schema. The settlement mint is resolved
				// server-side from (currency, cluster), so we send none.
				'privacyMode' => 'public' === $this->get_option( 'privacy_mode', 'private' ) ? 'PUBLIC' : 'PRIVATE',
				'successUrl'  => $this->get_return_url( $order ),
				'cancelUrl'   => wc_get_checkout_url(),
			],
			$order
		);

		try {
			$response = $client->post( '/v1/payment_links', $payload, $idempotency_key );
		} catch ( \Throwable $e ) {
			Logger::log( 'payment link create failed: ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'Could not initiate NinjaPay payment. Please try again.', 'ninjapay-woocommerce' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		// The create response nests the link under `payment_link`.
		$link       = isset( $response['payment_link'] ) && is_array( $response['payment_link'] )
			? $response['payment_link']
			: $response;
		$hosted_url = isset( $link['hosted_url'] ) && is_string( $link['hosted_url'] ) ? $link['hosted_url'] : '';
		if ( '' === $hosted_url ) {
			Logger::log( 'payment link create returned no hosted_url', 'error' );
			wc_add_notice( __( 'Could not initiate NinjaPay payment. Please try again.', 'ninjapay-woocommerce' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		// Store the link id. The settled PAYMENT INTENT id (what refunds
		// reference) arrives later on the payment_intent.succeeded webhook.
		if ( isset( $link['id'] ) && is_string( $link['id'] ) ) {
			$order->update_meta_data( '_ninjapay_payment_link_id', $link['id'] );
		}
		$order->update_meta_data( '_ninjapay_status', 'pending' );
		$order->update_status(
			'pending',
			__( 'NinjaPay payment link created. Redirecting payer to hosted checkout.', 'ninjapay-woocommerce' )
		);
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => $hosted_url,
		];
	}

	/**
	 * Refund a settled NinjaPay payment. Calls `POST /v1/refunds` against
	 * the payment intent recorded on the order by the success webhook.
	 *
	 * Returns true on a successful refund REQUEST (HTTP 201) so WC records
	 * the refund; the on-chain settlement is confirmed asynchronously by
	 * the `refund.succeeded` / `refund.failed` webhook, which annotates
	 * the order.
	 *
	 * @inheritDoc
	 *
	 * @param int        $order_id WC order id.
	 * @param float|null $amount   Amount to refund; null = full order total.
	 * @param string     $reason   Merchant-supplied reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new \WP_Error( 'ninjapay_refund_no_order', __( 'Order not found.', 'ninjapay-woocommerce' ) );
		}

		$intent_id = (string) $order->get_meta( '_ninjapay_intent_id' );
		if ( '' === $intent_id ) {
			return new \WP_Error(
				'ninjapay_refund_not_settled',
				__( 'This order has no settled NinjaPay payment yet — refunds are possible only once the payment confirms.', 'ninjapay-woocommerce' )
			);
		}

		$refund_amount = ( null === $amount ) ? (float) $order->get_total() : (float) $amount;
		if ( $refund_amount <= 0 ) {
			return new \WP_Error( 'ninjapay_refund_amount', __( 'Refund amount must be greater than zero.', 'ninjapay-woocommerce' ) );
		}
		$amount_str = wc_format_decimal( $refund_amount, wc_get_price_decimals() );

		$payload = [
			'paymentIntentId' => $intent_id,
			'amount'          => $amount_str,
			'reason'          => 'REQUESTED_BY_CUSTOMER',
			'metadata'        => [ 'wc_order_id' => (string) $order->get_id() ],
		];
		if ( '' !== $reason ) {
			$payload['description'] = $reason;
		}

		// Stable-ish key per (order, amount) guards against double-clicks;
		// a deliberately different amount refunds as a distinct request.
		$idempotency_key = sprintf( 'wc_refund_%d_%s_%s', $order->get_id(), $order->get_order_key(), $amount_str );

		try {
			$response = $this->client()->post( '/v1/refunds', $payload, $idempotency_key );
		} catch ( \Throwable $e ) {
			Logger::log( 'refund request failed: ' . $e->getMessage(), 'error' );
			return new \WP_Error(
				'ninjapay_refund_failed',
				__( 'NinjaPay refund request failed: ', 'ninjapay-woocommerce' ) . $e->getMessage()
			);
		}

		$refund_obj = isset( $response['refund'] ) && is_array( $response['refund'] ) ? $response['refund'] : $response;
		$refund_id  = isset( $refund_obj['id'] ) && is_string( $refund_obj['id'] ) ? $refund_obj['id'] : '';
		$order->add_order_note(
			sprintf(
				/* translators: 1: refunded amount, 2: NinjaPay refund id. */
				__( 'NinjaPay refund requested: %1$s%2$s. On-chain settlement confirmed via webhook.', 'ninjapay-woocommerce' ),
				$amount_str,
				'' !== $refund_id ? ' (' . $refund_id . ')' : ''
			)
		);

		return true;
	}

	/**
	 * Build an authenticated API client from current settings.
	 */
	private function client(): NinjapayClient {
		$base_url = 'live' === $this->get_option( 'environment' )
			? 'https://api.ninjapay.finance'
			: 'https://api-staging.ninjapay.finance';

		return new NinjapayClient(
			$base_url,
			(string) $this->get_option( 'api_key', '' )
		);
	}
}
