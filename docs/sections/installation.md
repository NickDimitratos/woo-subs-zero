# Installation

## Option 1: Install from source (development)

1. Place this plugin folder in your WordPress plugins directory, for example: `wp-content/plugins/woo-subs-zero`.
2. Ensure the plugin bootstrap file exists at `wsz-woo-subscriptions.php`.
3. Install dependencies in the plugin folder: `composer install`.
4. Activate WooCommerce.
5. Activate Woo Subs-Zero from WordPress Admin > Plugins.

For production packaging:

- `composer install --no-dev`

## Option 2: Install as ZIP

1. Zip the plugin folder contents.
2. In WordPress Admin go to Plugins > Add New > Upload Plugin.
3. Upload the ZIP and activate.

## Optional: Recurring Charge Provider Integration

If you want SDK-backed recurring charge calls inside this plugin:

- Hook `wsz_subs_recurring_charge_callback` with your provider implementation.
