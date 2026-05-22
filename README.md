# NinjaPay for WooCommerce

Accept Solana-native, privacy-by-default payments on any WordPress +
WooCommerce store. Powered by [NinjaPay](https://ninjapay.finance) —
Stripe-portable commerce API on Solana.

This plugin adds a **NinjaPay** payment gateway to your WooCommerce
checkout. Customers pay with USDC / USDT / SOL via NinjaPay's hosted
checkout (redirect flow); your store receives webhook notifications
on success/failure, and order status syncs automatically. Refunds
work from the WC admin like any other gateway.

## Status

**v1 in development.** See [PLAN.md](./PLAN.md) for the build plan.
This README describes the intended product.

## Requirements

- WordPress ≥ 6.3
- WooCommerce ≥ 7.0
- PHP ≥ 7.4 (8.x supported and recommended)
- HTTPS on your site (required for webhook delivery)

## Install

### From WP.org plugin directory (recommended once published)

1. WP Admin → **Plugins → Add New**
2. Search "NinjaPay for WooCommerce"
3. Install + Activate

### From a GitHub release

1. Download `ninjapay-woocommerce.zip` from [Releases](https://github.com/Blessedbiello/ninjapay-woocommerce/releases)
2. WP Admin → **Plugins → Add New → Upload Plugin**
3. Activate

### Configure

1. **WooCommerce → Settings → Payments**
2. Enable **NinjaPay**
3. Enter your API key (from `app.ninjapay.finance/dashboard/developers`)
4. Copy the webhook URL shown on the settings page
5. In your NinjaPay dashboard: **Developers → Webhooks → Add endpoint**, paste the URL
6. Copy the webhook signing secret back to the WC settings page
7. Save

The plugin verifies every webhook with HMAC-SHA256 + a constant-time
comparison; replay protection uses a ±5 minute timestamp window.

## Privacy

By default, NinjaPay settles all payments through the [Umbra Privacy
SDK](https://sdk.umbraprivacy.com/) — the merchant address and amount
aren't recorded on the public Solana ledger. For B2B / audit-friendly
flows, the merchant can opt into **PUBLIC** mode on a per-store basis
via the settings page.

## Refunds

Issue refunds from the WC admin like any other gateway. The "Refund
via NinjaPay" button calls the NinjaPay API; full + partial refunds
are supported. Same idempotency guarantees: a retried refund returns
the cached result.

## Hooks

For developers extending the plugin behavior, see
[docs/HOOKS.md](./docs/HOOKS.md):

- `ninjapay_intent_create_args` — filter the intent create payload
- `ninjapay_webhook_received` — action after webhook verified
- `ninjapay_order_paid` — action after WC order flipped to processing/completed
- `ninjapay_refund_succeeded` — action after refund settles

## Security

- HMAC-SHA256 webhook verification with `hash_equals` (constant-time)
- Nonce verification on every admin POST
- `current_user_can('manage_woocommerce')` capability check
- Prepared SQL via `$wpdb->prepare()` throughout
- Sanitize-in / escape-out applied uniformly
- WC's HPOS (High-Performance Order Storage) compatible

Report security issues to `security@ninjapay.finance`. Do not file
GitHub issues for security problems.

## Development

```bash
# clone
git clone https://github.com/Blessedbiello/ninjapay-woocommerce.git
cd ninjapay-woocommerce

# install deps
composer install

# run tests
composer test

# lint (WPCS)
composer phpcs

# start a local WP+WC env (requires Docker)
npx @wordpress/env start
npx @wordpress/env run cli wp plugin activate ninjapay-woocommerce
```

Open `http://localhost:8888/wp-admin/`. Default user `admin`, password `password`.

## Contributing

Issues tagged `good-first-issue` are the best starting point. Run
`composer test && composer phpcs` before any PR.

## License

[GPL-2.0-or-later](./LICENSE) — required for WordPress.org plugin
directory distribution. The NinjaPay API and SaaS remain proprietary;
this client plugin is GPL.
