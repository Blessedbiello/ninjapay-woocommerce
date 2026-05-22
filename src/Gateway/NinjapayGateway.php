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

		$client = $this->client();
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
				'privacy_mode' => $this->get_option( 'privacy_mode', 'private' ),
				'success_url'  => $this->get_return_url( $order ),
				'cancel_url'   => wc_get_checkout_url(),
			],
			$order
		);

		try {
			$intent = $client->post( '/v1/payment_intents', $payload, $idempotency_key );
		} catch ( \Throwable $e ) {
			Logger::log( 'intent create failed: ' . $e->getMessage(), 'error' );
			wc_add_notice( __( 'Could not initiate NinjaPay payment. Please try again.', 'ninjapay-woocommerce' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$order->update_meta_data( '_ninjapay_intent_id', $intent['id'] ?? '' );
		$order->update_meta_data( '_ninjapay_status', 'pending' );
		$order->update_status( 'pending', __( 'NinjaPay intent created. Awaiting payer settlement.', 'ninjapay-woocommerce' ) );
		$order->save();

		return [
			'result'   => 'success',
			'redirect' => (string) ( $intent['hosted_url'] ?? $this->get_return_url( $order ) ),
		];
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
