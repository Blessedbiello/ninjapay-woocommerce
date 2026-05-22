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

### `ninjapay_order_paid` (week 3+)

Fires after a WC order is flipped to `processing`/`completed` by a
NinjaPay webhook.

```php
add_action('ninjapay_order_paid', function($order, $attestation_sig) {
    // Trigger a fulfillment workflow
    my_fulfillment_queue->enqueue($order, $attestation_sig);
}, 10, 2);
```

**Args:**
- `$order` (WC_Order): the order that just settled
- `$attestation_sig` (string): the Solana transaction signature

### `ninjapay_refund_succeeded` (week 4+)

Fires after a refund settles on-chain (webhook confirmation).

```php
add_action('ninjapay_refund_succeeded', function($refund, $order) {
    // Send a custom refund-confirmation email
}, 10, 2);
```

**Args:**
- `$refund` (WC_Order_Refund): the WC refund row
- `$order` (WC_Order): the parent order

## Notes

- All hooks fire on the WP main loop (not in a background process).
- Heavy work in hooks should be queued via `Action Scheduler` to avoid
  blocking the webhook response.
- Hooks are versioned with the plugin — major bumps may rename/remove
  hooks with a deprecation period.
