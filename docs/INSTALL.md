# Installation

## Production install (WP.org plugin directory)

_Available once the plugin clears WP.org review (typical 2-4 weeks)._

1. WP Admin → **Plugins → Add New**
2. Search "NinjaPay for WooCommerce"
3. Click **Install Now** → **Activate**

## Production install (GitHub release .zip)

1. Download the latest `ninjapay-woocommerce.zip` from
   [GitHub Releases](https://github.com/Blessedbiello/ninjapay-woocommerce/releases)
2. WP Admin → **Plugins → Add New → Upload Plugin**
3. Choose the zip → **Install Now** → **Activate**

## Development install (clone + composer)

```bash
cd wp-content/plugins/
git clone https://github.com/Blessedbiello/ninjapay-woocommerce.git
cd ninjapay-woocommerce
composer install --no-dev --optimize-autoloader
```

Then WP Admin → **Plugins** → activate "NinjaPay for WooCommerce".

## Configure

1. WP Admin → **WooCommerce → Settings → Payments**
2. Toggle **NinjaPay** to enabled
3. Click **Manage** to open the gateway settings
4. Choose environment (Test → staging API, Live → production)
5. Paste your **API key** (from `app.ninjapay.finance/dashboard/developers`)
6. Copy the displayed **webhook URL** (read-only)
7. Open `app.ninjapay.finance/dashboard/developers/webhooks` →
   **Add endpoint** → paste the webhook URL → save
8. Copy the new **webhook signing secret** back to the WC settings page
9. Choose privacy mode (PRIVATE default for consumer commerce; PUBLIC
   for B2B / audit-friendly)
10. **Save changes**

## Verify

1. Place a test order at the checkout page
2. NinjaPay → choose currency → complete payment on hosted checkout
3. You should land back on the WC order-received page
4. Within ~10 seconds (webhook latency): WC order flips to "Processing"
5. Order page shows the NinjaPay intent ID + a Solscan link to the
   on-chain attestation

## Troubleshooting

### Webhook not arriving

- Confirm webhook URL is reachable: `curl -X POST <your-webhook-url>` should return 400 (missing signature)
- Confirm webhook secret matches between WC + NinjaPay dashboard
- Confirm your hosting allows inbound HTTPS POST without IP allowlisting
- Check WC → **Status → Logs** → "ninjapay" source for diagnostic output

### "Invalid signature" in logs

- Webhook secret mismatch — re-paste from NinjaPay dashboard
- Server clock drift > 5 min — sync via `ntpdate` / `chrony`

### "Could not initiate NinjaPay payment"

- API key invalid or expired — regenerate in NinjaPay dashboard
- API unreachable — check `WP_HTTP_BLOCK_EXTERNAL` config in `wp-config.php`
