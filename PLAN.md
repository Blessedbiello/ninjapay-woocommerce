# NinjaPay for WooCommerce — Build Plan

Week-by-week implementation guide for v1. Internal use; README has the
public-facing description.

## Scope

**v1 (this plan):** WC payment gateway via hosted-checkout redirect,
webhook receiver with HMAC-SHA256 verification, refund button in WC
admin, settings screen with API key + webhook secret, privacy mode
toggle (PRIVATE default, PUBLIC opt-in), idempotency on intent create.

**v2 (post-v1):** embedded checkout (no redirect), block-based
checkout extension (WC Blocks), multi-currency display per cart,
subscription / recurring billing.

## Tech decisions

| Decision | Choice | Why |
|---|---|---|
| License | GPL-2.0-or-later | WP.org requirement |
| HTTP client | `wp_remote_post` (built-in) | No Guzzle — avoids PHP version conflicts on host |
| Webhook auth | HMAC-SHA256 + `hash_equals` | `sodium_crypto_*` available but standard webhook receivers do HMAC; lower surface area |
| Idempotency | `wc_order_{id}_{key}` | Deterministic, dedupes WC retries on network failure |
| Order metadata | WC order meta (`_ninjapay_*`) | Native WC pattern; works with HPOS |
| Testing | PHPUnit + wp-env | Standard WP plugin development |
| Lint | PHPCS with WPCS ruleset | WP.org submission requires WPCS-clean code |
| Distribution | WP.org + GitHub releases | WP.org for discovery; GitHub for early adopters |

## Repo structure

```
ninjapay-woocommerce/
├── README.md                # public-facing
├── PLAN.md                  # this file
├── LICENSE                  # GPL-2.0-or-later
├── ninjapay-woocommerce.php # main plugin file with WP headers
├── readme.txt               # WP.org-flavored README (different from README.md)
├── composer.json
├── composer.lock
├── phpunit.xml
├── phpcs.xml
├── .gitignore
├── .github/workflows/
│   ├── ci.yml               # PHPCS + PHPUnit + WP integration tests
│   └── release.yml          # build .zip on tag → GitHub release
├── src/
│   ├── Plugin.php           # bootstrap, autoload, hook registration
│   ├── Gateway/
│   │   ├── NinjapayGateway.php       # extends WC_Payment_Gateway
│   │   └── HostedCheckoutHandler.php # redirect builder + return handler
│   ├── Webhook/
│   │   ├── Receiver.php             # /wc-api/ninjapay_webhook route
│   │   └── SignatureVerifier.php    # HMAC-SHA256 + hash_equals
│   ├── Admin/
│   │   ├── SettingsPage.php         # WC settings tab
│   │   └── RefundButton.php         # WC admin order action
│   ├── Api/
│   │   └── NinjapayClient.php       # wp_remote_post wrapper
│   └── Support/
│       ├── IdempotencyKey.php       # wc_order_{id}_{key} derivation
│       └── Logger.php               # WC_Logger wrapper
├── tests/
│   ├── unit/                # PHPUnit standalone
│   │   ├── SignatureVerifierTest.php
│   │   ├── IdempotencyKeyTest.php
│   │   └── NinjapayClientTest.php
│   ├── integration/         # wp-env-based
│   │   ├── CheckoutFlowTest.php
│   │   ├── WebhookFlowTest.php
│   │   └── RefundFlowTest.php
│   └── bootstrap.php
├── assets/
│   ├── icon.svg
│   ├── icon-128x128.png     # WP.org listing icon
│   ├── icon-256x256.png
│   ├── banner-772x250.png   # WP.org listing banner
│   ├── banner-1544x500.png
│   └── screenshot-1.png ... screenshot-6.png
├── languages/
│   └── ninjapay-woocommerce.pot
└── docs/
    ├── INSTALL.md
    ├── HOOKS.md
    └── SECURITY.md
```

## Week-by-week

### Week 1 — Plugin skeleton + settings

**Deliverables:**
- `ninjapay-woocommerce.php` with WP plugin headers
- `composer.json` with PSR-4 autoload (`NinjaPay\WooCommerce\` → `src/`)
- `src/Plugin.php` bootstrap (constructor, hook registration, version constant)
- `src/Admin/SettingsPage.php` — WC settings tab with: API key, webhook secret, privacy mode toggle, environment (test/live), webhook URL display (read-only)
- Plugin activation hook: requires WooCommerce active; checks PHP/WC versions
- Plugin deactivation hook: cleanup transients
- `readme.txt` (WP.org format) — top of header, description, FAQ, changelog

**Files to create:**
- `ninjapay-woocommerce.php` (main file, ~80 LOC)
- `src/Plugin.php`
- `src/Admin/SettingsPage.php`
- `composer.json`, `composer.lock` (commit lock file)
- `readme.txt`

**Tests:**
- `tests/unit/PluginBootstrapTest.php`: activation succeeds with WC active, fails with WC inactive
- Manual: activate plugin → see "NinjaPay" tab under WC → Settings → Payments

---

### Week 2 — Gateway class + hosted-checkout redirect

**Deliverables:**
- `src/Gateway/NinjapayGateway.php` extending `WC_Payment_Gateway`:
  - `process_payment(int $order_id)` → calls NinjaPay API `POST /v1/payment_intents` with idempotency key
  - Returns `[result => 'success', redirect => $hostedCheckoutUrl]`
- `src/Gateway/HostedCheckoutHandler.php`:
  - Handles return URL after payer completes/cancels checkout
  - Marks order pending until webhook arrives (does NOT mark paid on return — webhook is the source of truth)
- `src/Api/NinjapayClient.php`:
  - Single class wrapping `wp_remote_post` / `wp_remote_get`
  - Auto-injects API key + `Idempotency-Key` headers
  - Returns typed array results; throws `NinjapayApiException` on 4xx/5xx
- `src/Support/IdempotencyKey.php`:
  - `from_order(WC_Order $order): string` → returns `"wc_order_{$id}_{$key}"`

**Files to create:**
- `src/Gateway/NinjapayGateway.php` (~250 LOC)
- `src/Gateway/HostedCheckoutHandler.php`
- `src/Api/NinjapayClient.php`
- `src/Api/NinjapayApiException.php`
- `src/Support/IdempotencyKey.php`

**Tests:**
- Unit: `IdempotencyKey::from_order` is deterministic per order
- Unit: `NinjapayClient` retries on 5xx, doesn't retry on 4xx
- Integration: full checkout flow places an order in pending state with redirect URL

---

### Week 3 — Webhook receiver + signature verification

**Deliverables:**
- `src/Webhook/Receiver.php`:
  - Registers `/wc-api/ninjapay_webhook` route
  - Verifies signature via `SignatureVerifier`
  - Dedupes events via WP transients keyed on `event_id` (5min TTL)
  - Dispatches to handler per `event.type` (payment_intent.succeeded, .failed, refund.succeeded, refund.failed)
- `src/Webhook/SignatureVerifier.php`:
  - Parses `X-NinjaPay-Signature` header (`t=<unix-secs>,v1=<hex-hmac>`)
  - Asserts timestamp within ±5min tolerance
  - Recomputes HMAC-SHA256 over `<t>.<body>`
  - `hash_equals()` compare
- Order status transitions:
  - `payment_intent.succeeded` → `$order->payment_complete($attestation_sig)`
  - `payment_intent.failed` → `$order->update_status('failed', $reason)`
- Order meta updates: `_ninjapay_intent_id`, `_ninjapay_status`, `_ninjapay_attestation_sig`, `_ninjapay_settlement_at`

**Files to create:**
- `src/Webhook/Receiver.php` (~200 LOC)
- `src/Webhook/SignatureVerifier.php` (~80 LOC)
- `src/Webhook/EventHandlers.php` (per-type dispatch)

**Tests:**
- Unit: `SignatureVerifier` rejects: wrong secret, expired timestamp, malformed header, tampered body
- Unit: `SignatureVerifier` accepts: valid signature within window
- Integration: POST webhook with valid sig → order flips to processing
- Integration: POST same webhook twice → second is idempotent (dedupe via transient)

**Security check:** the verifier uses `hash_equals` (constant-time) and never falls back to `===`. Confirm no debug log emits the secret.

---

### Week 4 — Refund button + admin

**Deliverables:**
- `src/Admin/RefundButton.php`:
  - Hooks into `woocommerce_create_refund` WC action
  - On WC admin "Refund via NinjaPay" → calls `POST /v1/refunds` with idempotency key derived from `(refund.id, refund.amount)`
  - On success: WC refund recorded normally; meta `_ninjapay_refund_id` set
  - On API error: WC refund creation aborts with admin notice
- Order admin page: display NinjaPay intent ID + attestation sig (Solscan link)
- Settings page validation: API key smoke test via `GET /v1/health`

**Files to create:**
- `src/Admin/RefundButton.php`
- `src/Admin/OrderMetaDisplay.php`

**Tests:**
- Integration: refund flow end-to-end (place order → mark paid via webhook → refund from admin → assert API call + refund row in WC)

---

### Week 5 — Test coverage + WP integration tests

**Deliverables:**
- PHPUnit coverage ≥ 80% lines on:
  - `Gateway/NinjapayGateway` happy + error paths
  - `Webhook/SignatureVerifier` all branches
  - `Api/NinjapayClient` retry / error mapping
- wp-env-based integration tests for: checkout → webhook → order paid, refund admin flow
- PHPCS clean under WPCS ruleset
- PHP compat: 7.4 / 8.0 / 8.1 / 8.2 / 8.3 in CI matrix
- WC compat: 7.x / 8.x / 9.x in CI matrix

**Files to create:**
- Round out `tests/unit/*` (5+ new test classes)
- `tests/integration/*` (3 integration tests via wp-env)
- `phpcs.xml` (WPCS ruleset config)
- `.github/workflows/ci.yml` matrix expansion

**Verification:**
- `composer test` green
- `composer phpcs` clean
- `npx @wordpress/env start && composer test:integration` green

---

### Week 6 — WP.org submission prep

**Deliverables:**
- WP.org `readme.txt` finalized — see [https://wordpress.org/plugins/developers/](https://wordpress.org/plugins/developers/)
- Plugin assets (icon, banner, screenshots × 6) — sized per [WP.org plugin assets spec](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
- Internal plugin-check via [`wp plugin-check`](https://wordpress.org/plugins/plugin-check/) CLI
- Sideload .zip build via `composer build:zip` → tested on fresh WP install
- Submit to WP.org via [https://wordpress.org/plugins/developers/add/](https://wordpress.org/plugins/developers/add/)

**Files to create:**
- `readme.txt` (WP.org format, with `Tags: woocommerce, payments, solana, stablecoin, crypto`)
- `assets/banner-{772x250,1544x500}.png`
- `assets/icon-{128x128,256x256}.png`
- `assets/screenshot-{1..6}.png`
- `bin/build-zip.sh` — composer install --no-dev → zip up

**Verification:**
- `bin/build-zip.sh` produces a < 1MB zip
- Sideload zip onto fresh WP/WC → activate → run through checkout end-to-end against staging API

---

### Weeks 7-8 — WP.org review + GitHub release

**Deliverables:**
- Address any WP.org plugin review feedback (common: capability checks, sanitize/escape, nonce verification, freemium policy)
- Once approved: announce
- Parallel: maintain GitHub releases (`.zip` + changelog) for early adopters who want pre-WP-approved updates

**Files to create:**
- `.github/workflows/release.yml` — build .zip + attach to GitHub release on tag push
- `CHANGELOG.md` (Keep-a-Changelog format)

---

## Definition of done per week

- All listed files created
- All listed tests added and green
- PHPCS clean
- Manual smoke flow exercised end-to-end against staging API
- Screenshots captured per week's deliverables for WP.org assets

## Out of scope (deliberate)

- Block-based WC Blocks checkout extension (v2; WC Blocks v11+ is the gating dep)
- Subscriptions integration (WC Subscriptions plugin compat — v2)
- Multi-currency mode (USDC + USDT + SOL display per cart — v2)
- Multi-store / multisite — v2
- Saved payment methods — N/A (this is a redirect gateway, not a tokenized card surface)

## Risks + mitigations

- **WP.org review can reject for petty reasons** (string formatting, license headers, capability checks not on every admin handler). Mitigation: run `wp plugin-check` before submission; address every warning.
- **WC HPOS migration changes order meta access patterns.** Mitigation: use WC's order-data-store APIs (`$order->get_meta(...)` + `$order->update_meta_data(...)`) — they handle both legacy + HPOS.
- **PHP version dispersion.** Mitigation: CI matrix on 7.4 / 8.0 / 8.1 / 8.2 / 8.3; declare `Requires PHP: 7.4` in plugin header.

## Reference

- WP.org plugin handbook: https://developer.wordpress.org/plugins/
- WPCS coding standards: https://github.com/WordPress/WordPress-Coding-Standards
- WC payment gateway docs: https://woocommerce.com/document/payment-gateway-api/
- WC HPOS docs: https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage
- NinjaPay API: https://docs.ninjapay.finance/api
- @ninjapay/sdk (TypeScript reference for the API surface): https://www.npmjs.com/package/@ninjapay/sdk
