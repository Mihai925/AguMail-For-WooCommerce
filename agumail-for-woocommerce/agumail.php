<?php
/**
 * Plugin Name:          AguMail for WooCommerce
 * Plugin URI:           https://agumail.com
 * Description:          Syncs your WooCommerce customers, orders, carts and products to AguMail: email marketing with abandoned-cart recovery and revenue attribution.
 * Version:              2.1.1
 * Author:               AguMail
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          agumail
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_VERSION', '2.1.1');
define('AGUMAIL_FILE', __FILE__);
define('AGUMAIL_DIR', __DIR__ . '/');

// ─────────────────────────────────────────────────────────────────────────────
// The plugin is a set of files, loaded in order: the foundation first (settings,
// log, store, installer, HTTP client, payload helpers, the drain, checkout
// consent, the admin shell), then the features built on it.
//
// One rule holds across all of them: a shopper's request never talks to
// AguMail. Storefront hooks only record what changed in the outbox; the drain,
// run by Action Scheduler, is what sends it.
// ─────────────────────────────────────────────────────────────────────────────

foreach (array(
    'settings', 'log', 'store', 'install', 'http', 'payloads', 'drain', 'consent', 'admin',
    'orders', 'products', 'carts', 'connect', 'tracking', 'forms', 'customers', 'signups',
    'integrations/elementor', 'integrations/cf7', 'integrations/wpforms', 'bulk-sync', 'status', 'onboarding',
) as $agumail_include) {
    require_once AGUMAIL_DIR . 'includes/' . $agumail_include . '.php';
}
unset($agumail_include);

// ─────────────────────────────────────────────────────────────────────────────
// WooCommerce features this plugin runs on. Orders are read and written through
// the order object, never as posts, so High-Performance Order Storage is fine;
// consent, attribution and carts are captured on the block checkout as well as
// the classic one.
// ─────────────────────────────────────────────────────────────────────────────

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

register_activation_hook(__FILE__, 'agumail_activate');
register_deactivation_hook(__FILE__, 'agumail_deactivate');
