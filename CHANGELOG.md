# Changelog

All notable changes to this project will be documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [semver](https://semver.org/).

## [Unreleased]

### Added
- WooCommerce payment gateway: hosted-checkout redirect flow paying in
  USDC / USDT / SOL on Solana, privacy-by-default (PRIVATE) with an
  opt-in PUBLIC mode. Creates a NinjaPay payment link
  (`POST /v1/payment_links`) and redirects the payer.
- Automatic order-status sync from signed webhooks:
  `payment_intent.succeeded` marks the order paid (and records the
  settled intent id + on-chain attestation signature);
  `payment_intent.failed` fails the order.
- Full + partial refunds from the WC admin (`process_refund` →
  `POST /v1/refunds`), confirmed on-chain via `refund.*` webhooks.
- HMAC-SHA256 webhook verification (`X-NinjaPay-Signature`, ±5-min
  replay window) with idempotent, dedupe-after-success processing.
- Order admin panel showing the NinjaPay intent id, payment link id,
  and a Solscan link for the settlement signature.
- Idempotency keys on payment-link creation and refunds.
- Extensibility hooks: `ninjapay_intent_create_args`,
  `ninjapay_webhook_received`, `ninjapay_order_paid`,
  `ninjapay_refund_succeeded`.
- HPOS (High-Performance Order Storage) compatibility declaration.
- PHPUnit unit tests + WPCS lint; CI across PHP 7.4 / 8.0 / 8.1 / 8.2 /
  8.3.
- Documentation: README, PLAN, INSTALL, HOOKS, SECURITY.

[Unreleased]: https://github.com/Blessedbiello/ninjapay-woocommerce/compare/...HEAD
