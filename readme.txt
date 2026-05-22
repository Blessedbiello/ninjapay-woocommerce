=== NinjaPay for WooCommerce ===
Contributors: ninjapay
Tags: woocommerce, payments, solana, stablecoin, crypto, usdc, privacy
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Solana-native, privacy-by-default payments via NinjaPay. Stripe-portable commerce, USDC / USDT / SOL.

== Description ==

NinjaPay for WooCommerce lets your store accept payments in USDC, USDT, or SOL on Solana — with privacy by default. Customers pay via NinjaPay's hosted checkout (no on-chain merchant address or amount on the public ledger), and your store receives webhook notifications + automatic order status sync.

**Features:**

* USDC, USDT, SOL accepted at checkout
* Privacy-by-default settlement via the Umbra Privacy SDK
* Optional PUBLIC mode for B2B / audit-friendly flows
* HMAC-SHA256 signed webhooks with replay protection
* Refunds from WooCommerce admin (full + partial)
* WC HPOS (High-Performance Order Storage) compatible
* PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3 compatible
* WC 7.x / 8.x / 9.x compatible

**Requirements:**

* WooCommerce 7.0+
* PHP 7.4+
* HTTPS on your site (for webhook receipt)
* A NinjaPay account ([sign up](https://app.ninjapay.finance))

== Installation ==

1. Upload the plugin to /wp-content/plugins/ninjapay-woocommerce/ or install via WP Admin → Plugins → Add New.
2. Activate via the WP Admin → Plugins screen.
3. Go to WooCommerce → Settings → Payments → NinjaPay.
4. Enter your API key (from app.ninjapay.finance/dashboard/developers).
5. Copy the shown webhook URL into your NinjaPay dashboard.
6. Copy the webhook signing secret back to the WC settings.

== Frequently Asked Questions ==

= Do my customers need a Solana wallet? =

Yes — they need a Solana wallet (Phantom, Solflare, Backpack, etc.) with USDC, USDT, or SOL.

= Does the plugin store any customer wallet addresses? =

In PRIVATE mode (default), no. The Umbra-shielded deposit means the wallet address is not visible to the merchant. In PUBLIC mode, the payer's wallet address is recorded in WC order meta.

= Can I issue refunds? =

Yes — full or partial refunds via the WC admin order page. Refunds settle on-chain within a few seconds.

= How does the plugin handle privacy claims? =

The privacy comes from the Umbra Privacy SDK on Solana, not from this plugin. The plugin is a thin client to the NinjaPay API.

== Screenshots ==

1. NinjaPay payment method at WooCommerce checkout
2. NinjaPay hosted checkout page (payer's view)
3. Settings page in WC → Payments → NinjaPay
4. Order admin page with NinjaPay intent ID + Solscan link
5. Refund button in WC admin order actions
6. Webhook configuration in NinjaPay dashboard

== Changelog ==

= 0.1.0 (Unreleased) =
* Initial release in development.

== Upgrade Notice ==

= 0.1.0 =
First release. No prior version.
