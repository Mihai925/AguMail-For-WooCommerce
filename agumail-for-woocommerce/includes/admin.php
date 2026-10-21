<?php
// The settings page, WooCommerce > AguMail, built from sections and actions
// that each feature registers.
//
// A section is a titled block of the page. An action is something the merchant
// does from it (save, connect, retry, sync); it is reached through
// admin-post.php for forms and links, or admin-ajax.php for scripts, and every
// action passes the same checks here: the manage_woocommerce capability and a
// nonce of its own. Actions run as background work, so they may call AguMail.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_ADMIN_PAGE', 'agumail');

// ─────────────────────────────────────────────────────────────────────────────
// Registries.
// ─────────────────────────────────────────────────────────────────────────────

function &agumail_admin_registry() {
    static $registry = array('sections' => array(), 'actions' => array());
    return $registry;
}

/** A block of the settings page; lower priorities come first. Registering an id again replaces it. */
function agumail_admin_register_section($id, $title, $render_callback, $priority = 50) {
    $registry = &agumail_admin_registry();
    $registry['sections'][$id] = array(
        'id'       => (string) $id,
        'title'    => (string) $title,
        'render'   => $render_callback,
        'priority' => (int) $priority,
        'order'    => isset($registry['sections'][$id]) ? $registry['sections'][$id]['order'] : count($registry['sections']),
    );
}

/**
 * Something the merchant can do. $callback receives the request fields
 * (unslashed) and returns one of:
 *   a message string, or ['message' => ..., 'type' => success|error|warning|info]
 *   a WP_Error, shown as an error
 *   ['redirect' => url] to send the merchant somewhere else: this site, or AguMail's app
 *   null, to say nothing
 * Called from a script, the value comes back as JSON instead.
 */
function agumail_admin_register_action($action, $callback) {
    $registry = &agumail_admin_registry();
    $registry['actions'][(string) $action] = $callback;
}

/** Sections in page order. Until a feature supplies its own, a plain Connection section stands in. */
function agumail_admin_sections() {
    $registry = &agumail_admin_registry();
    $sections = $registry['sections'];
    if (!isset($sections['connection'])) {
        $sections['connection'] = array('id' => 'connection', 'title' => __('Connection', 'agumail'),
            'render' => 'agumail_admin_render_connection_placeholder', 'priority' => 10, 'order' => -1);
    }
    uasort($sections, function ($a, $b) {
        if ($a['priority'] !== $b['priority']) {
            return $a['priority'] < $b['priority'] ? -1 : 1;
        }
        return $a['order'] < $b['order'] ? -1 : 1;
    });
    return $sections;
}

// ─────────────────────────────────────────────────────────────────────────────
// Reaching an action.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_admin_nonce($action) {
    return wp_create_nonce('agumail_action_' . $action);
}

/** The hidden fields a form needs to post to an action. */
function agumail_admin_action_fields($action) {
    echo '<input type="hidden" name="action" value="agumail" />';
    echo '<input type="hidden" name="agumail_action" value="' . esc_attr($action) . '" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(agumail_admin_nonce($action)) . '" />';
}

/** A link that runs an action (for a Retry button, say). */
function agumail_admin_action_url($action, array $args = array()) {
    return add_query_arg(array_merge($args, array(
        'action'         => 'agumail',
        'agumail_action' => $action,
        '_wpnonce'       => agumail_admin_nonce($action),
    )), admin_url('admin-post.php'));
}

function agumail_admin_page_url(array $args = array()) {
    return add_query_arg(array_merge(array('page' => AGUMAIL_ADMIN_PAGE), $args), admin_url('admin.php'));
}

add_action('admin_post_agumail', 'agumail_admin_handle_post');
add_action('wp_ajax_agumail', 'agumail_admin_handle_ajax');

/** A form or link: runs the action, keeps its message for the next page view, and goes back to the settings page. */
function agumail_admin_handle_post() {
    $request = wp_unslash($_REQUEST); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in agumail_admin_dispatch().
    $action = isset($request['agumail_action']) ? sanitize_key($request['agumail_action']) : '';
    $result = agumail_admin_dispatch($action, is_array($request) ? $request : array());
    if (is_wp_error($result) && in_array($result->get_error_code(), array('agumail_forbidden', 'agumail_bad_nonce'), true)) {
        wp_die(esc_html($result->get_error_message()), '', array('response' => 403));
    }
    $redirect = agumail_admin_page_url();
    if (is_array($result) && !empty($result['redirect'])) {
        $redirect = $result['redirect'];
    }
    $notice = agumail_admin_notice_from($result);
    if ($notice) {
        agumail_admin_notice($notice['type'], $notice['message']);
    }
    // An action may send the merchant to AguMail itself (to approve a
    // connection, say), and only there: its host is allowed for this redirect alone.
    $allow_agumail = 'agumail_allow_app_redirect';
    add_filter('allowed_redirect_hosts', $allow_agumail);
    try {
        wp_safe_redirect($redirect);
    } finally {
        remove_filter('allowed_redirect_hosts', $allow_agumail);
    }
    exit;
}

function agumail_allow_app_redirect($hosts) {
    $host = wp_parse_url(agumail_app_url(), PHP_URL_HOST);
    if ($host) {
        $hosts[] = $host;
    }
    return $hosts;
}

/** A script: runs the action and answers with JSON. */
function agumail_admin_handle_ajax() {
    $request = wp_unslash($_REQUEST); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked in agumail_admin_dispatch().
    $action = isset($request['agumail_action']) ? sanitize_key($request['agumail_action']) : '';
    $result = agumail_admin_dispatch($action, is_array($request) ? $request : array());
    if (is_wp_error($result)) {
        $status = in_array($result->get_error_code(), array('agumail_forbidden', 'agumail_bad_nonce'), true) ? 403 : 400;
        wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $result->get_error_code()), $status);
    }
    wp_send_json_success($result);
}

/** The central checks, then the action itself, as background work. */
function agumail_admin_dispatch($action, array $request) {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return new WP_Error('agumail_forbidden', __('You are not allowed to manage AguMail.', 'agumail'));
    }
    $nonce = isset($request['_wpnonce']) ? (string) $request['_wpnonce'] : (isset($request['_ajax_nonce']) ? (string) $request['_ajax_nonce'] : '');
    if ($action === '' || !wp_verify_nonce($nonce, 'agumail_action_' . $action)) {
        return new WP_Error('agumail_bad_nonce', __('This link has expired. Go back to the AguMail settings and try again.', 'agumail'));
    }
    $registry = &agumail_admin_registry();
    if (!isset($registry['actions'][$action]) || !is_callable($registry['actions'][$action])) {
        return new WP_Error('agumail_unknown_action', __('Unknown AguMail action.', 'agumail'));
    }
    return agumail_run_in_background($registry['actions'][$action], array($request));
}

// ─────────────────────────────────────────────────────────────────────────────
// Notices, kept per merchant until the page shows them.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_admin_notice($type, $message) {
    set_transient('agumail_notice_' . get_current_user_id(), array('type' => (string) $type, 'message' => (string) $message), 5 * MINUTE_IN_SECONDS);
}

function agumail_admin_take_notice() {
    $key = 'agumail_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if ($notice) {
        delete_transient($key);
    }
    return is_array($notice) ? $notice : null;
}

function agumail_admin_notice_from($result) {
    if (is_wp_error($result)) {
        return array('type' => 'error', 'message' => $result->get_error_message());
    }
    if (is_string($result) && $result !== '') {
        return array('type' => 'success', 'message' => $result);
    }
    if (is_array($result) && !empty($result['message'])) {
        return array('type' => isset($result['type']) ? $result['type'] : 'success', 'message' => $result['message']);
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// The page.
// ─────────────────────────────────────────────────────────────────────────────

add_action('admin_menu', 'agumail_admin_menu');

function agumail_admin_menu() {
    add_submenu_page('woocommerce', __('AguMail', 'agumail'), __('AguMail', 'agumail'), AGUMAIL_ADMIN_CAPABILITY, AGUMAIL_ADMIN_PAGE, 'agumail_admin_render_page');
}

function agumail_admin_render_page() {
    if (!current_user_can(AGUMAIL_ADMIN_CAPABILITY)) {
        return;
    }
    $notice = agumail_admin_take_notice();
    echo '<div class="wrap agumail-settings"><h1>' . esc_html__('AguMail', 'agumail') . '</h1>';
    if ($notice) {
        $type = in_array($notice['type'], array('success', 'error', 'warning', 'info'), true) ? $notice['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }
    foreach (agumail_admin_sections() as $section) {
        echo '<div class="agumail-section" id="agumail-section-' . esc_attr($section['id']) . '">';
        echo '<h2>' . esc_html($section['title']) . '</h2>';
        if (is_callable($section['render'])) {
            call_user_func($section['render']);
        }
        echo '</div>';
    }
    echo '</div>';
}

/** Where AguMail is and whether a key is saved, until the connect feature supplies its own section. */
function agumail_admin_render_connection_placeholder() {
    $connection = agumail_connection();
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row">' . esc_html__('AguMail server', 'agumail') . '</th><td><code>' . esc_html(agumail_api_url()) . '</code>';
    if (agumail_api_url_is_pinned()) {
        echo '<p class="description">' . esc_html__('Set in wp-config.php.', 'agumail') . '</p>';
    }
    echo '</td></tr>';
    echo '<tr><th scope="row">' . esc_html__('Status', 'agumail') . '</th><td>';
    if (!agumail_has_api_key()) {
        esc_html_e('Not connected.', 'agumail');
    } elseif ($connection['status'] === 'error' && $connection['error'] !== '') {
        /* translators: %s: the reason AguMail gave for refusing the store. */
        echo esc_html(sprintf(__('AguMail refused this store: %s', 'agumail'), $connection['error']));
    } else {
        esc_html_e('Connected.', 'agumail');
    }
    echo '</td></tr></tbody></table>';
}

// ─────────────────────────────────────────────────────────────────────────────
// Checkout: the words next to the marketing checkbox, and whether it starts
// ticked.
// ─────────────────────────────────────────────────────────────────────────────

// Registered on init, like every section with a translated title: WordPress
// loads translations no earlier.
add_action('init', 'agumail_admin_register_checkout');

function agumail_admin_register_checkout() {
    agumail_admin_register_section('checkout', __('Checkout', 'agumail'), 'agumail_admin_render_checkout', 40);
    agumail_admin_register_action('save_checkout', 'agumail_admin_save_checkout');
}

function agumail_admin_render_checkout() {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    agumail_admin_action_fields('save_checkout');
    echo '<table class="form-table" role="presentation"><tbody><tr>';
    echo '<th scope="row"><label for="agumail_consent_label">' . esc_html__('Checkbox label', 'agumail') . '</label></th>';
    echo '<td><input name="agumail_consent_label" id="agumail_consent_label" type="text" class="large-text" value="' . esc_attr(agumail_consent_label()) . '" />';
    echo '<p class="description">' . esc_html__('Shown next to the marketing checkbox on the classic and block checkouts, once the store is connected.', 'agumail') . '</p>';
    echo '<p class="description">' . esc_html__('Links show on the classic checkout and registration forms. The block checkout shows the words only.', 'agumail') . '</p></td>';
    echo '</tr><tr>';
    echo '<th scope="row">' . esc_html__('Checkbox', 'agumail') . '</th>';
    echo '<td><label for="agumail_checkout_optin_default"><input type="checkbox" name="agumail_checkout_optin_default" id="agumail_checkout_optin_default" value="yes"'
        . checked(agumail_checkout_optin_ticked_by_default(), true, false) . ' /> ' . esc_html__('Tick the box by default', 'agumail') . '</label></td>';
    echo '</tr></tbody></table>';
    submit_button(__('Save', 'agumail'));
    echo '</form>';
}

function agumail_admin_save_checkout(array $request) {
    $label = isset($request['agumail_consent_label']) ? agumail_admin_clean_label($request['agumail_consent_label']) : '';
    if ($label === '' || $label === agumail_default_consent_label()) {
        delete_option('agumail_consent_label');
    } else {
        update_option('agumail_consent_label', $label);
    }
    update_option('agumail_checkout_optin_default', !empty($request['agumail_checkout_optin_default']) ? 'yes' : 'no');
    return array('message' => __('Checkout settings saved.', 'agumail'));
}

/** One line of words, with links and no other markup. */
function agumail_admin_clean_label($label) {
    $label = wp_kses(is_string($label) ? $label : '', agumail_consent_label_tags());
    return trim(preg_replace('/[\r\n\t ]+/', ' ', $label));
}
