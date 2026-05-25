<?php
/**
 * Integration tests for EventHandlers::dispatch() — the WooCommerce
 * shell of webhook handling. Runs against a real WP + WooCommerce
 * (provisioned by wp-env); see docs/TESTING.md.
 *
 * @package NinjaPay\WooCommerce\Tests
 */

declare( strict_types = 1 );

namespace NinjaPay\WooCommerce\Tests\Integration;

use NinjaPay\WooCommerce\Webhook\EventHandlers;
use WC_Helper_Order;
use WP_UnitTestCase;

final class WebhookDispatchTest extends WP_UnitTestCase {

	/**
	 * Build a NinjaPay order in `pending` and return its id.
	 */
	private function make_pending_order(): int {
		$order = WC_Helper_Order::create_order();
		$order->set_payment_method( 'ninjapay' );
		$order->update_status( 'pending' );
		$order->save();
		return (int) $order->get_id();
	}

	/**
	 * @param array<string,mixed> $object data.object payload.
	 * @return array<string,mixed>
	 */
	private function event( string $type, array $object ): array {
		return [ 'id' => 'evt_' . wp_generate_uuid4(), 'type' => $type, 'data' => [ 'object' => $object ] ];
	}

	public function test_payment_succeeded_marks_order_paid_and_records_meta(): void {
		$order_id = $this->make_pending_order();

		EventHandlers::dispatch(
			$this->event(
				'payment_intent.succeeded',
				[
					'id'             => 'pi_int_1',
					'attestationSig' => 'sigXYZ',
					'metadata'       => [ 'wc_order_id' => (string) $order_id ],
				]
			)
		);

		$order = wc_get_order( $order_id );
		$this->assertTrue( $order->is_paid(), 'order should be paid after payment_intent.succeeded' );
		$this->assertSame( 'pi_int_1', $order->get_meta( '_ninjapay_intent_id' ) );
		$this->assertSame( 'sigXYZ', $order->get_meta( '_ninjapay_attestation_sig' ) );
	}

	public function test_payment_failed_marks_order_failed(): void {
		$order_id = $this->make_pending_order();

		EventHandlers::dispatch(
			$this->event(
				'payment_intent.failed',
				[ 'id' => 'pi_int_2', 'failureReason' => 'insufficient_funds', 'metadata' => [ 'wc_order_id' => (string) $order_id ] ]
			)
		);

		$this->assertSame( 'failed', wc_get_order( $order_id )->get_status() );
	}

	public function test_duplicate_success_does_not_double_complete(): void {
		$order_id = $this->make_pending_order();
		$event    = $this->event(
			'payment_intent.succeeded',
			[ 'id' => 'pi_int_3', 'metadata' => [ 'wc_order_id' => (string) $order_id ] ]
		);

		EventHandlers::dispatch( $event );
		$first = wc_get_order( $order_id )->get_date_paid();
		EventHandlers::dispatch( $event );
		$second = wc_get_order( $order_id )->get_date_paid();

		$this->assertEquals(
			$first ? $first->getTimestamp() : null,
			$second ? $second->getTimestamp() : null,
			'replayed success must not change the paid timestamp'
		);
	}

	public function test_unknown_order_is_ignored_without_error(): void {
		// No order with this id — dispatch must no-op, not throw.
		EventHandlers::dispatch(
			$this->event( 'payment_intent.succeeded', [ 'id' => 'pi_int_4', 'metadata' => [ 'wc_order_id' => '99999999' ] ] )
		);
		$this->assertTrue( true );
	}
}
