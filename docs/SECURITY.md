# Security

## Threat model

The plugin handles money in flight: it creates payment intents at
NinjaPay, redirects payers to hosted checkout, and processes webhook
callbacks that flip WC order status. A compromised WordPress site or
a tampered webhook can fraudulently mark orders as paid, leak the
webhook secret, or initiate refunds.

## Mitigations applied uniformly

- **HMAC-SHA256 webhook verification** with constant-time `hash_equals`
  (`src/Webhook/SignatureVerifier.php`). Tolerance window ±5 min.
- **Nonce verification** on every admin POST handler
  (`wp_verify_nonce()` / `check_admin_referer()`)
- **Capability check** on every admin action: `current_user_can('manage_woocommerce')`
- **Prepared SQL** via `$wpdb->prepare()` — no string concatenation
- **Sanitize on input** via `sanitize_text_field()` / `wp_kses_post()`
- **Escape on output** via `esc_html()` / `esc_attr()` / `wp_json_encode()`
- **TLS-verified outbound HTTP** via `wp_remote_post()` (WP enables
  verification by default)
- **Webhook dedupe** via WP transient keyed on `event_id` (5min TTL) —
  defense in depth against replay attacks

## What we trust

- WordPress core + WooCommerce — they handle session, CSRF, password
  hashing, capability framework
- The NinjaPay API — its TLS cert + its HMAC signing
- The merchant's webhook secret as the long-term shared key

## What we don't trust

- The inbound webhook payload — verify signature + timestamp + body integrity before processing
- The redirect-return URL — never marks the order paid; only the
  signed webhook can do that
- Admin GET parameters — sanitize before use in DB queries or output
- Order metadata read paths — escape on render

## Key rotation

Webhook secret rotation steps:
1. NinjaPay dashboard → **Developers → Webhooks → Rotate secret**
2. New secret valid immediately; old secret valid for 24h grace
3. Update WC settings → **Save**
4. After 24h: dashboard auto-revokes old secret

The plugin does NOT support dual-secret verification today (single
secret only). Rotation requires brief downtime — schedule outside
peak hours.

## Reporting

Security issues: `security@ninjapay.finance`. PGP key on
`https://ninjapay.finance/.well-known/security.txt`.

We acknowledge within 24h. Confirmed criticals patched within 7 days
with a coordinated disclosure timeline if external dependencies
are involved.

## Out of scope

- HTTPS termination at the WP site (operator responsibility)
- Webhook secret storage at rest (WP options table — operator should
  not run on shared hosting with WP_DEBUG_LOG enabled in production)
- File-level permissions on `wp-config.php` (operator responsibility)
