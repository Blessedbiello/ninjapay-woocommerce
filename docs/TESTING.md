# Testing

Two suites, two runtimes.

## Unit (`tests/unit`)

Pure PHP — no WordPress runtime. Covers the signature verifier and the
webhook event router (`EventHandlers::parse`).

```bash
composer install
composer test          # phpunit --testsuite unit (phpunit.xml)
```

Requires a PHP build with the `dom`, `mbstring`, `xml`, and `xmlwriter`
extensions (PHPUnit's baseline). CI (`shivammathur/setup-php`) has them.

## Integration (`tests/integration`)

Runs against a real WordPress + WooCommerce, provisioned by
[`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
(Docker). Covers the WooCommerce shell of webhook handling
(`EventHandlers::dispatch` → order state).

```bash
npm i -g @wordpress/env        # or: npx @wordpress/env
wp-env start                   # boots WP + WooCommerce (see .wp-env.json)

# run the integration suite inside the test container
wp-env run tests-cli --env-cwd=wp-content/plugins/ninjapay-woocommerce \
  vendor/bin/phpunit -c phpunit-integration.xml
```

`.wp-env.json` installs WooCommerce (latest stable) alongside this
plugin. The integration bootstrap (`tests/integration/bootstrap.php`)
loads the WP PHPUnit test library, WooCommerce, and the plugin.

> The integration suite is authored to the standard wp-env pattern but
> validate it against your local Docker/WP stack before wiring it into a
> gating CI job — WC table install + load order can need environment
> tweaks the first time.

## Lint

```bash
composer phpcs         # WordPress Coding Standards
```
