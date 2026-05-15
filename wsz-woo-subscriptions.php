<?php
/**
 * Plugin Name: Woo Subs-Zero
 * Plugin URI: https://ndimitratos.com
 * Description: Deterministic, gateway-agnostic subscription management for WooCommerce.
 * Version: 0.1.47
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: Nick Dimitratos
 * Author URI: https://ndimitratos.com
 * Text Domain: woo-subzero
 * WC requires at least: 8.8
 * WC tested up to: 9.0
 */

defined('ABSPATH') || exit;

if (defined('WSZ_WOO_SUBZERO_BOOTSTRAP_LOADED')) {
    return;
}

define('WSZ_WOO_SUBZERO_BOOTSTRAP_LOADED', true);

if (!defined('WSZ_WOO_SUBZERO_VERSION')) {
    define('WSZ_WOO_SUBZERO_VERSION', '0.1.47');
}

if (!defined('WSZ_WOO_SUBZERO_FILE')) {
    define('WSZ_WOO_SUBZERO_FILE', __FILE__);
}

if (!defined('WSZ_WOO_SUBZERO_PATH')) {
    define('WSZ_WOO_SUBZERO_PATH', plugin_dir_path(__FILE__));
}

if (!defined('WSZ_WOO_SUBZERO_URL')) {
    define('WSZ_WOO_SUBZERO_URL', plugin_dir_url(__FILE__));
}

$wsz_subs_autoload = WSZ_WOO_SUBZERO_PATH . 'vendor/autoload.php';

if (is_readable($wsz_subs_autoload)) {
    require_once $wsz_subs_autoload;
}

add_action(
    'before_woocommerce_init',
    static function () {
        if (!class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            return;
        }

        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            WSZ_WOO_SUBZERO_FILE,
            true
        );

        Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'orders_cache',
            WSZ_WOO_SUBZERO_FILE,
            true
        );
    }
);

require_once WSZ_WOO_SUBZERO_PATH . 'includes/class-woo-subzero.php';

register_activation_hook(WSZ_WOO_SUBZERO_FILE, array('WSZ_Woo_Subzero', 'activate'));
register_deactivation_hook(WSZ_WOO_SUBZERO_FILE, array('WSZ_Woo_Subzero', 'deactivate'));

WSZ_Woo_Subzero::instance();
