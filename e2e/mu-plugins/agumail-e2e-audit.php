<?php
/**
 * Plugin Name: AguMail e2e audit
 * Description: Test-stack only. Logs every outbound HTTP request WordPress makes, with the context of the request that made it, plus the server time of every PHP request. The specs read these logs to prove the AguMail plugin never calls out from a shopper-facing request and never slows checkout.
 *
 * Logs (JSON lines, host path e2e/.state/audit/):
 *   http.jsonl      one "start" line when a request is about to go out (pre_http_request), one "end" line with
 *                   its duration and result (http_api_debug). A request that never returns still has its start line.
 *   requests.jsonl  one line per PHP request at shutdown: uri, method, status, server ms, tag.
 *
 * Tag: a spec sets the cookie "agumail_e2e_tag=<id>" on the store (or sends the header "X-AguMail-E2E: <id>")
 * and every line written during that request carries it, so samples are matched without guessing. The cookie
 * is preferred in browsers: a custom header would also go to cross-origin hosts and fail their CORS preflight.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('AGUMAIL_E2E_AUDIT_DIR')) {
    define('AGUMAIL_E2E_AUDIT_DIR', '/e2e/state/audit');
}

function agumail_e2e_audit_write($file, array $record) {
    $dir = AGUMAIL_E2E_AUDIT_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);
    }
    $path  = $dir . '/' . $file;
    $fresh = !file_exists($path);
    @file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if ($fresh) {
        // The host user resets these files between specs; www-data creates them.
        @chmod($path, 0666);
    }
}

/** The Action Scheduler action running right now, if any (hook name). */
$GLOBALS['agumail_e2e_as_action'] = null;
add_action('action_scheduler_before_execute', function ($action_id) {
    $hook = 'action#' . $action_id;
    if (class_exists('ActionScheduler')) {
        try {
            $action = ActionScheduler::store()->fetch_action($action_id);
            if ($action && method_exists($action, 'get_hook')) {
                $hook = $action->get_hook();
            }
        } catch (\Throwable $e) {
            // Keep the id form.
        }
    }
    $GLOBALS['agumail_e2e_as_action'] = $hook;
}, 0);
$agumail_e2e_as_done = function () {
    $GLOBALS['agumail_e2e_as_action'] = null;
};
add_action('action_scheduler_after_execute', $agumail_e2e_as_done, PHP_INT_MAX);
add_action('action_scheduler_failed_execution', $agumail_e2e_as_done, PHP_INT_MAX);
unset($agumail_e2e_as_done);

function agumail_e2e_request_tag() {
    if (isset($_SERVER['HTTP_X_AGUMAIL_E2E'])) {
        return substr((string) $_SERVER['HTTP_X_AGUMAIL_E2E'], 0, 100);
    }
    if (isset($_COOKIE['agumail_e2e_tag'])) {
        return substr((string) $_COOKIE['agumail_e2e_tag'], 0, 100);
    }
    return null;
}

function agumail_e2e_request_context() {
    $uri       = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null;
    $is_cli    = defined('WP_CLI') && WP_CLI;
    $is_cron   = function_exists('wp_doing_cron') ? wp_doing_cron() : (defined('DOING_CRON') && DOING_CRON);
    $is_ajax   = function_exists('wp_doing_ajax') ? wp_doing_ajax() : (defined('DOING_AJAX') && DOING_AJAX);
    $is_admin  = function_exists('is_admin') ? is_admin() : false;
    $is_rest   = (defined('REST_REQUEST') && REST_REQUEST)
        || ($uri !== null && strpos($uri, '/wp-json/') !== false)
        || isset($_GET['rest_route']);
    $wc_ajax   = isset($_GET['wc-ajax']) ? sanitize_key(wp_unslash($_GET['wc-ajax'])) : null;
    $user_id   = 0;
    $can_manage = false;
    if (did_action('init') && function_exists('get_current_user_id')) {
        $user_id    = get_current_user_id();
        $can_manage = $user_id ? current_user_can('manage_woocommerce') : false;
    }
    $as_action = isset($GLOBALS['agumail_e2e_as_action']) ? $GLOBALS['agumail_e2e_as_action'] : null;

    // What the plugin's HTTP guard is meant to allow: background work (cron, WP-CLI, an Action Scheduler
    // callback) or a wp-admin screen / admin-ajax call made by a store manager. Everything else is a shopper.
    $background = $is_cron || $is_cli || $as_action !== null
        || ($is_admin && !$is_ajax)
        || ($is_ajax && $can_manage);

    return array(
        'uri'        => $is_cli ? 'wp-cli' : $uri,
        'method'     => isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : null,
        'cron'       => (bool) $is_cron,
        'admin'      => (bool) $is_admin,
        'ajax'       => (bool) $is_ajax,
        'rest'       => (bool) $is_rest,
        'cli'        => (bool) $is_cli,
        'wcAjax'     => $wc_ajax,
        'asAction'   => $as_action,
        'userId'     => (int) $user_id,
        'canManage'  => (bool) $can_manage,
        'background' => (bool) $background,
        'tag'        => agumail_e2e_request_tag(),
        'pid'        => getmypid(),
    );
}

/** True when the URL is one the AguMail plugin talks to (its API base, v1 or v2 style, or any /api/v1 path). */
function agumail_e2e_is_agumail_url($url) {
    $bases = array();
    if (defined('AGUMAIL_API_URL')) {
        $bases[] = AGUMAIL_API_URL;
    }
    $option = get_option('agumail_api_url', '');
    if (is_string($option) && $option !== '') {
        $bases[] = $option;
    }
    $bases[] = 'http://fake-agumail:8090';
    $bases[] = 'http://10.255.255.1:81';
    foreach ($bases as $base) {
        if (strpos($url, rtrim($base, '/')) === 0) {
            return true;
        }
    }
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    return strpos($path, '/api/v1/') === 0 || strpos($path, '/api/v1') === 0;
}

$GLOBALS['agumail_e2e_http_starts'] = array();

add_filter('pre_http_request', function ($preempt, $args, $url) {
    $id = substr(md5(uniqid('', true) . $url), 0, 12);
    $GLOBALS['agumail_e2e_http_starts'][] = array($id, microtime(true), $url);
    $parts = wp_parse_url($url);
    agumail_e2e_audit_write('http.jsonl', array(
        'phase'   => 'start',
        'id'      => $id,
        'time'    => microtime(true),
        'url'     => $url,
        'host'    => isset($parts['host']) ? $parts['host'] : null,
        'port'    => isset($parts['port']) ? $parts['port'] : null,
        'path'    => isset($parts['path']) ? $parts['path'] : null,
        'verb'    => isset($args['method']) ? $args['method'] : 'GET',
        'timeout' => isset($args['timeout']) ? $args['timeout'] : null,
        'blocking' => isset($args['blocking']) ? (bool) $args['blocking'] : true,
        'agumail' => agumail_e2e_is_agumail_url($url),
        'preempted' => $preempt !== false,
        'ctx'     => agumail_e2e_request_context(),
    ));
    return $preempt;
}, PHP_INT_MAX, 3);

add_action('http_api_debug', function ($response, $context, $class, $args, $url) {
    $id    = null;
    $start = null;
    foreach ($GLOBALS['agumail_e2e_http_starts'] as $i => $row) {
        if ($row[2] === $url) {
            list($id, $start) = $row;
            unset($GLOBALS['agumail_e2e_http_starts'][$i]);
            break;
        }
    }
    $record = array(
        'phase'      => 'end',
        'id'         => $id,
        'time'       => microtime(true),
        'url'        => $url,
        'durationMs' => $start !== null ? round((microtime(true) - $start) * 1000, 1) : null,
        'agumail'    => agumail_e2e_is_agumail_url($url),
        'ctx'        => agumail_e2e_request_context(),
    );
    if (is_wp_error($response)) {
        $record['error'] = $response->get_error_message();
    } else {
        $record['status'] = (int) wp_remote_retrieve_response_code($response);
    }
    agumail_e2e_audit_write('http.jsonl', $record);
}, PHP_INT_MAX, 5);

// Server time of every PHP request, taken as late as WordPress allows.
add_action('shutdown', function () {
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    $started = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null;
    $ctx     = agumail_e2e_request_context();
    agumail_e2e_audit_write('requests.jsonl', array(
        'time'     => microtime(true),
        'uri'      => $ctx['uri'],
        'method'   => $ctx['method'],
        'status'   => http_response_code(),
        'ms'       => $started ? round((microtime(true) - $started) * 1000, 1) : null,
        'tag'      => $ctx['tag'],
        'wcAjax'   => $ctx['wcAjax'],
        'rest'     => $ctx['rest'],
        'ajax'     => $ctx['ajax'],
        'admin'    => $ctx['admin'],
        'cron'     => $ctx['cron'],
        'background' => $ctx['background'],
        'peakMemMb' => round(memory_get_peak_usage(true) / 1048576, 1),
    ));
}, PHP_INT_MAX);

// Where a slow request spent its time: checkpoints on the big WordPress and WooCommerce moments, written
// to slow.jsonl only for requests slower than 1 s. Cheap for every other request.
$GLOBALS['agumail_e2e_marks'] = array();
foreach (array(
    'muplugins_loaded', 'plugins_loaded', 'setup_theme', 'init', 'wp_loaded', 'parse_request', 'rest_api_init',
    'woocommerce_checkout_process', 'woocommerce_checkout_create_order', 'woocommerce_new_order',
    'woocommerce_checkout_order_created', 'woocommerce_store_api_checkout_update_order_from_request',
    'woocommerce_order_status_changed', 'woocommerce_payment_complete', 'woocommerce_checkout_order_processed',
    'woocommerce_store_api_checkout_order_processed', 'woocommerce_email', 'pre_wp_mail',
    'woocommerce_cart_emptied', 'woocommerce_set_cart_cookies', 'action_scheduler_init',
) as $agumail_e2e_hook) {
    add_action($agumail_e2e_hook, function ($arg = null) use ($agumail_e2e_hook) {
        global $wpdb;
        $GLOBALS['agumail_e2e_marks'][] = array(
            $agumail_e2e_hook,
            round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 1),
            isset($wpdb) && is_object($wpdb) ? (int) $wpdb->num_queries : null,
        );
        return $arg;
    }, -PHP_INT_MAX);
}
unset($agumail_e2e_hook);

add_action('shutdown', function () {
    $ms = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000 : 0;
    if ($ms < 1000 || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    global $wpdb;
    agumail_e2e_audit_write('slow.jsonl', array(
        'time'    => microtime(true),
        'uri'     => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
        'ms'      => round($ms, 1),
        'tag'     => agumail_e2e_request_tag(),
        'queries' => isset($wpdb) ? (int) $wpdb->num_queries : null,
        'marks'   => $GLOBALS['agumail_e2e_marks'],
        'slowest' => agumail_e2e_slowest_queries(),
    ));
}, PHP_INT_MAX - 1);

/** With SAVEQUERIES on (config.json saveQueries), the five slowest queries of this request. */
function agumail_e2e_slowest_queries() {
    global $wpdb;
    if (!defined('SAVEQUERIES') || !SAVEQUERIES || empty($wpdb->queries)) {
        return null;
    }
    $queries = $wpdb->queries;
    usort($queries, function ($a, $b) {
        return $b[1] <=> $a[1];
    });
    $out = array();
    foreach (array_slice($queries, 0, 5) as $q) {
        $out[] = array('ms' => round($q[1] * 1000, 1), 'sql' => substr($q[0], 0, 300), 'caller' => substr((string) $q[2], -300));
    }
    return $out;
}
