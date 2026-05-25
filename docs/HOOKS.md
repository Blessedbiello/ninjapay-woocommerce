# Hooks reference

Filters and actions exposed by NinjaPay for WooCommerce. All hooks
follow the WC naming convention.

## Filters

### `ninjapay_intent_create_args`

Filter the payment intent payload before it's POSTed to the NinjaPay API.

```php
add_filter('ninjapay_intent_create_args', function($args, $order) {
    // Add a custom metadata field
    $args['metadata']['referrer'] = $order->get_meta('_referrer_source');
    return $args;
}, 10, 2);
```

**Args:**
- `$args` (array): the intent payload (amount, currency, metadata, …)
- `$order` (WC_Order): the WC order being charged

**Returns:** modified payload array.

## Actions

### `ninjapay_webhook_received`

Fires after a webhook signature is verified but before per-type handlers run.

```php
add_action('ninjapay_webhook_received', function($event) {
    // Log every event to a custom audit table
    my_audit_log($event['id'], $event['type']);
});
```

**Args:**
- `$event` (array): the verified webhook payload (`{id, type, data, created_at}`)

### `ninjapay_order_paid`

Fires after a WC order is marked paid (`payment_complete`) by a
`payment_intent.succeeded` webhook. The settled intent id + on-chain
attestation signature are also stored on the order as
`_ninjapay_intent_id` and `_ninjapay_attestation_sig`.

```php
add_action('ninjapay_order_paid', function($order, $event) {
    $sig = $order->get_meta('_ninjapay_attestation_sig');
    // Trigger a fulfillment workflow
    my_fulfillment_queue->enqueue($order, $sig);
}, 10, 2);
```

**Args:**
- `$order` (WC_Order): the order that just settled
- `$event` (array): the full verified webhook event

### `ninjapay_refund_succeeded`

Fires when a `refund.succeeded` webhook confirms an on-chain refund
settlement.

```php
add_action('ninjapay_refund_succeeded', function($order, $event) {
    // Send a custom refund-confirmation email
}, 10, 2);
```

**Args:**
- `$order` (WC_Order): the parent order
- `$event` (array): the full verified webhook event

## Notes

- All hooks fire on the WP main loop (not in a background process).
- Heavy work in hooks should be queued via `Action Scheduler` to avoid
  blocking the webhook response.
- Hooks are versioned with the plugin — major bumps may rename/remove
  hooks with a deprecation period.
