<?php
// Settings: where AguMail lives, the key that speaks for this store, and the
// store-wide choices the merchant makes. Every option is read through one of
// these functions, so each default lives in one place.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_DEFAULT_API_URL', 'https://api.agumail.com');
define('AGUMAIL_DEFAULT_APP_URL', 'https://agumail.com');

// Who manages AguMail from wp-admin: the settings page, its tools, admin-ajax.
define('AGUMAIL_ADMIN_CAPABILITY', 'manage_woocommerce');

// ─────────────────────────────────────────────────────────────────────────────
// Where AguMail lives. wp-config.php can pin either address with AGUMAIL_API_URL
// or AGUMAIL_APP_URL, which is how a development or end-to-end site points the
// plugin at a local server; a pinned address wins over anything saved.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_api_url() {
    return agumail_resolve_url('AGUMAIL_API_URL', 'agumail_api_url', AGUMAIL_DEFAULT_API_URL);
}

function agumail_app_url() {
    return agumail_resolve_url('AGUMAIL_APP_URL', 'agumail_app_url', AGUMAIL_DEFAULT_APP_URL);
}

/** True when wp-config.php pins the API address, so the settings page shows it read-only. */
function agumail_api_url_is_pinned() {
    return defined('AGUMAIL_API_URL') && (string) AGUMAIL_API_URL !== '';
}

function agumail_resolve_url($constant, $option, $default) {
    if (defined($constant) && (string) constant($constant) !== '') {
        return untrailingslashit((string) constant($constant));
    }
    $saved = trim((string) get_option($option, ''));
    return untrailingslashit($saved !== '' ? $saved : $default);
}

function agumail_api_key() {
    return trim((string) get_option('agumail_api_key', ''));
}

function agumail_has_api_key() {
    return agumail_api_key() !== '';
}

/** Saves the key, autoloaded: every storefront request reads it, so it must not cost a query of its own. */
function agumail_save_api_key($key) {
    return update_option('agumail_api_key', trim((string) $key), true);
}

/**
 * Whether a user (the signed-in one by default) is store staff rather than a
 * shopper: anyone who manages WooCommerce, unless the agumail_is_staff filter
 * says otherwise. Staff are never tracked or treated as customers.
 */
function agumail_is_staff($user_id = null) {
    $user_id = $user_id === null ? get_current_user_id() : (int) $user_id;
    $staff = $user_id > 0 && user_can($user_id, AGUMAIL_ADMIN_CAPABILITY);
    return (bool) apply_filters('agumail_is_staff', $staff, $user_id);
}

// ─────────────────────────────────────────────────────────────────────────────
// Store-wide choices.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_default_consent_label() {
    return __('Email me about new products and offers', 'agumail');
}

/**
 * The words next to the marketing checkbox, on both checkouts and the
 * customer account forms. They may carry links (<a href target rel>, nothing
 * else): print them with wp_kses() and agumail_consent_label_tags(), or as
 * agumail_consent_label_text() where only words fit.
 */
function agumail_consent_label() {
    $label = trim((string) get_option('agumail_consent_label', ''));
    return $label !== '' ? $label : agumail_default_consent_label();
}

/** The only markup the label keeps: links. */
function agumail_consent_label_tags() {
    return array('a' => array('href' => true, 'target' => true, 'rel' => true));
}

/** The label as plain words, for the block checkout, which shows it as text. */
function agumail_consent_label_text() {
    return trim(html_entity_decode(wp_strip_all_tags(agumail_consent_label()), ENT_QUOTES, 'UTF-8'));
}

/** Whether the checkout's marketing checkbox starts ticked. Off unless the merchant turns it on. */
function agumail_checkout_optin_ticked_by_default() {
    return get_option('agumail_checkout_optin_default', 'no') === 'yes';
}

/** A yes/no switch: agumail_tracking_enabled, agumail_registration_optin, agumail_account_toggle. All default to yes. */
function agumail_switch_on($option) {
    return get_option($option, 'yes') !== 'no';
}

/** The config AguMail last handed over through ping or connect, or one key of it. */
function agumail_config($key = null) {
    $config = get_option('agumail_config', array());
    if (!is_array($config)) {
        $config = array();
    }
    if ($key === null) {
        return $config;
    }
    return isset($config[$key]) ? $config[$key] : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Connection state, as last seen by ping, connect or the drain:
// {status, shopName, userId, integrationId, checkedAt, error, errorCode}.
// status is '' until the store has connected, then connected, disconnected or
// error. errorCode is the HTTP status behind an error, so the settings page can
// tell a revoked key (401) from a lapsed plan (402).
// ─────────────────────────────────────────────────────────────────────────────

function agumail_connection() {
    $saved = get_option('agumail_connection', array());
    return array_merge(array(
        'status'        => '',
        'shopName'      => '',
        'userId'        => '',
        'integrationId' => '',
        'checkedAt'     => 0,
        'error'         => '',
        'errorCode'     => 0,
    ), is_array($saved) ? $saved : array());
}

function agumail_update_connection(array $changes) {
    return update_option('agumail_connection', array_merge(agumail_connection(), $changes), false);
}

/** AguMail refused the key or the plan (401, 402 or 403): the drain stops and the settings page says why. */
function agumail_record_connection_error($code, $message) {
    agumail_update_connection(array(
        'status'    => 'error',
        'error'     => (string) $message,
        'errorCode' => (int) $code,
        'checkedAt' => time(),
    ));
}

/** A call went through again, so an earlier refusal no longer stands. */
function agumail_clear_connection_error() {
    $connection = agumail_connection();
    if ($connection['status'] !== 'error') {
        return;
    }
    agumail_update_connection(array('status' => 'connected', 'error' => '', 'errorCode' => 0, 'checkedAt' => time()));
}

// ─────────────────────────────────────────────────────────────────────────────
// Backoff: a moment before which the drain sends nothing at all, set by a 429's
// Retry-After or by a refused key.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_backoff_until() {
    return (int) get_option('agumail_backoff_until', 0);
}

function agumail_set_backoff_until($timestamp) {
    update_option('agumail_backoff_until', (int) $timestamp, false);
}

function agumail_clear_backoff() {
    delete_option('agumail_backoff_until');
}
