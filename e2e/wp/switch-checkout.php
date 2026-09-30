<?php
/**
 * Makes one of the checkouts WooCommerce's checkout. Run with:
 *   wp eval-file /e2e/wp/switch-checkout.php classic|block|checkoutwc
 *
 *   classic     [woocommerce_checkout] / [woocommerce_cart] pages, CheckoutWC off
 *   block       WooCommerce's block checkout and cart pages, CheckoutWC off
 *   checkoutwc  the classic pages with CheckoutWC active and its templates enabled
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$type  = isset($args[0]) ? $args[0] : '';
$pages = get_option('agumail_e2e_pages', array());
if (!in_array($type, array('classic', 'block', 'checkoutwc'), true)) {
    WP_CLI::error('Usage: switch-checkout.php classic|block|checkoutwc');
}
if (empty($pages['classicCheckout']) || empty($pages['blockCheckout'])) {
    WP_CLI::error('Run the store setup first (wp-setup.sh setup).');
}

$cfw = 'checkout-for-woocommerce/checkout-for-woocommerce.php';
if ($type === 'block') {
    update_option('woocommerce_checkout_page_id', (int) $pages['blockCheckout']);
    update_option('woocommerce_cart_page_id', (int) $pages['blockCart']);
} else {
    update_option('woocommerce_checkout_page_id', (int) $pages['classicCheckout']);
    update_option('woocommerce_cart_page_id', (int) $pages['classicCart']);
}

if ($type === 'checkoutwc') {
    if (!file_exists(WP_PLUGIN_DIR . '/' . $cfw)) {
        WP_CLI::error('CheckoutWC is not mounted (set CHECKOUTWC_DIR in e2e/.env).');
    }
    if (!is_plugin_active($cfw)) {
        $result = activate_plugin($cfw);
        if (is_wp_error($result)) {
            WP_CLI::error('CheckoutWC did not activate: ' . $result->get_error_message());
        }
    }
    update_option('_cfw_enable', 'yes');
} elseif (is_plugin_active($cfw)) {
    deactivate_plugins($cfw);
}

update_option('agumail_e2e_checkout', $type, false);
flush_rewrite_rules(false);
WP_CLI::success('Checkout is now: ' . $type . ' (' . get_permalink(wc_get_page_id('checkout')) . ')');
