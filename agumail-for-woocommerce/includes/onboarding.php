<?php
// Onboarding: from activating the plugin to a connected store.
//
// Activating the plugin from the Plugins screen takes the merchant who did it
// to WooCommerce > AguMail, once. Until the store is connected, its row on the
// Plugins screen links to Connect, and a notice on the Dashboard, the Plugins
// screen and WooCommerce's own screens offers the one-click connect; the
// merchant can dismiss it for good. Once connected, the row links to Settings
// and the notice is gone. None of it shows while WooCommerce is not active.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_ACTIVATION_REDIRECT', 'agumail_activation_redirect');

add_action('init', 'agumail_onboarding_register_admin');
add_action('admin_init', 'agumail_onboarding_redirect', 5);
add_filter('plugin_action_links_' . plugin_basename(AGUMAIL_FILE), 'agumail_onboarding_action_links');
add_action('admin_notices', 'agumail_onboarding_notice');

function agumail_onboarding_register_admin() {
    agumail_admin_register_action('dismiss_connect_notice', 'agumail_onboarding_dismiss_notice');
}

/**
 * The settings page hangs off WooCommerce's menu, so onboarding points there
 * only while WooCommerce is active and loaded. WordPress before 6.5 runs this
 * plugin without it, and WordPress refuses to open the page then.
 */
function agumail_onboarding_woocommerce_ready() {
    return class_exists('WooCommerce') && did_action('woocommerce_loaded') > 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// The redirect after activation.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Called by agumail_activate(): remembers who activated the plugin, so their
 * next wp-admin page takes them to the settings page. Not when activating
 * across a network, from WP-CLI, the REST API or admin-ajax (the Add Plugins
 * screen, which keeps the merchant where they are), or several plugins at once
 * (the merchant is busy with the others), nor for a store already connected.
 */
function agumail_onboarding_arm_redirect($network_wide = false) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- plugins.php checked its own nonce; only which of its actions is running is read.
    $request = $_REQUEST;
    $bulk = isset($request['activate-multi'])
        || (isset($request['action']) && $request['action'] === 'activate-selected')
        || (isset($request['action2']) && $request['action2'] === 'activate-selected');
    if ($network_wide || $bulk || agumail_is_cli() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax()
        || agumail_has_api_key() || get_current_user_id() <= 0) {
        return;
    }
    set_transient(AGUMAIL_ACTIVATION_REDIRECT, get_current_user_id(), 5 * MINUTE_IN_SECONDS);
}

/**
 * The first wp-admin page the activating merchant opens: the settings page
 * instead, once. The mark is removed before anything else, so it can never
 * send anyone round in circles. Another administrator's page leaves it for
 * the one who activated.
 */
function agumail_onboarding_redirect() {
    if (wp_doing_ajax() || wp_doing_cron()) {
        return;
    }
    $user_id = (int) get_transient(AGUMAIL_ACTIVATION_REDIRECT);
    if ($user_id <= 0 || $user_id !== get_current_user_id()) {
        return;
    }
    delete_transient(AGUMAIL_ACTIVATION_REDIRECT);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only where the merchant is, to leave a bulk activation and our own page alone.
    $query = $_GET;
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY) || !agumail_onboarding_woocommerce_ready() || agumail_has_api_key() || isset($query['activate-multi'])
        || (isset($query['page']) && $query['page'] === AGUMAIL_ADMIN_PAGE)) {
        return;
    }
    wp_safe_redirect(agumail_admin_page_url());
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// The Plugins screen row.
// ─────────────────────────────────────────────────────────────────────────────

/** Connect (Settings, once connected) first among the row's links. */
function agumail_onboarding_action_links($links) {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY) || !agumail_onboarding_woocommerce_ready()) {
        return $links;
    }
    $label = agumail_has_api_key() ? __('Settings', 'agumail') : __('Connect', 'agumail');
    array_unshift($links, '<a href="' . esc_url(agumail_admin_page_url()) . '">' . esc_html($label) . '</a>');
    return $links;
}

// ─────────────────────────────────────────────────────────────────────────────
// The connect notice. Its button starts the one-click connect straight away;
// Dismiss is an admin action, so it passes the same nonce and capability
// checks as every other.
// ─────────────────────────────────────────────────────────────────────────────

/** Whether the notice belongs on the screen being drawn. */
function agumail_onboarding_notice_due() {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY) || !agumail_onboarding_woocommerce_ready() || agumail_has_api_key()
        || get_option('agumail_connect_notice_dismissed') === 'yes') {
        return false;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || empty($screen->id)) {
        return false;
    }
    // The settings page says it all already.
    if (substr($screen->id, -strlen('_page_' . AGUMAIL_ADMIN_PAGE)) === '_page_' . AGUMAIL_ADMIN_PAGE) {
        return false;
    }
    $screens = array('dashboard', 'plugins');
    if (function_exists('wc_get_screen_ids')) {
        $screens = array_merge($screens, (array) wc_get_screen_ids());
    }
    return in_array($screen->id, $screens, true);
}

function agumail_onboarding_notice() {
    if (!agumail_onboarding_notice_due()) {
        return;
    }
    echo '<div class="notice notice-info agumail-connect-notice"><p>'
        . esc_html__('Connect AguMail to start syncing your customers, orders and carts.', 'agumail') . '</p><p>'
        . '<a class="button button-primary" href="' . esc_url(agumail_admin_action_url('connect_start')) . '">' . esc_html__('Connect to AguMail', 'agumail') . '</a> '
        . '<a class="agumail-connect-notice-dismiss" href="' . esc_url(agumail_admin_action_url('dismiss_connect_notice')) . '">' . esc_html__('Dismiss', 'agumail') . '</a>'
        . '</p></div>';
}

/** Admin action: the notice stays away for good, and the merchant goes back to the page they were on. */
function agumail_onboarding_dismiss_notice() {
    update_option('agumail_connect_notice_dismissed', 'yes', false);
    $back = wp_get_referer();
    return array('redirect' => $back ? $back : admin_url());
}
