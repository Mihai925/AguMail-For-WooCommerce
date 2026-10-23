<?php
// A WordPress site without WooCommerce. The plugin runs there for its forms,
// popups, form plugin signups, registrations and tracking: its settings page is
// an administrator's, it connects with a key that ping checks, and nothing about
// a store is offered, queued or sent.
//
// Each test runs its body in a fresh process with AGM_NO_WOOCOMMERCE=1, where
// no WooCommerce class, function or logger exists, so a call into WooCommerce
// would stop the process.

// ── Helpers ──

function wordpress_only_run($function, array $env = array()) {
    agm_expect_child_passes('wordpress_only.php', $function, array_merge(array('AGM_NO_WOOCOMMERCE' => '1'), $env));
}

function wordpress_only_check_process() {
    expect_that(!class_exists('WooCommerce') && !function_exists('WC') && !function_exists('wc_get_logger'), 'WooCommerce is loaded in this process');
    expect_that(!agumail_woocommerce_active(), 'the plugin thinks WooCommerce is active');
}

/** The config ping hands a site whose account has site tracking. */
function wordpress_only_config() {
    return array(
        'siteKey'        => 'site_abc123',
        'siteScriptUrl'  => 'https://agupage.com/_/v1/site.js?v=3',
        'siteEndpoint'   => 'https://api.agumail.com/api/public/site/site_abc123/events',
        'formsLoaderUrl' => 'https://api.agumail.com/embed/agumail-forms.js',
        'identifySecret' => '0MuzRMHpG2I2mkdDM-TLd9cwZ3MJcsUZ1e6qXE5oqbg',
    );
}

/** Ping's answer for a key: the account, no store (integration null), the config. */
function wordpress_only_ping_body() {
    return array(
        'ok'          => true,
        'shop'        => array('userId' => 'usr_1', 'shopName' => 'Hollow Pine'),
        'integration' => null,
        'config'      => wordpress_only_config(),
    );
}

/** An administrator, signed in, on a site that has not connected yet. */
function wordpress_only_admin($connected = false) {
    agm_sign_in(1, 'owner@site.test');
    agm_grant('manage_options');
    if (!$connected) {
        unset($GLOBALS['agm']['options']['agumail_api_key']);
    }
    do_action('init');
}

function wordpress_only_action($action, array $fields = array()) {
    return agm_request('admin: ' . $action, 'admin', function () use ($action, $fields) {
        agm_input(array(), array_merge(array(
            'action'         => 'agumail',
            'agumail_action' => $action,
            '_wpnonce'       => agumail_admin_nonce($action),
        ), $fields));
        do_action('admin_post_agumail');
    });
}

/** Connected with a key, as the key form leaves the site. */
function wordpress_only_connected() {
    wordpress_only_admin();
    agm_http_respond(200, wordpress_only_ping_body());
    agm_http_respond(200, array('ok' => true, 'loaderUrl' => 'https://api.agumail.com/embed/agumail-forms.js', 'forms' => array(
        array('slug' => 'fspring', 'name' => 'Spring', 'doubleOptIn' => false, 'publishedAt' => '2026-09-01T10:00:00Z'),
    )));
    wordpress_only_action('connect_save_advanced', array('agumail_api_key' => 'agm_' . str_repeat('c', 40)));
    $GLOBALS['agm']['http'] = array();
    $GLOBALS['agm']['writes'] = array();
}

/** One run of the drain, as Action Scheduler starts it from WP-Cron. */
function wordpress_only_drain() {
    $outcome = null;
    agm_request('drain', 'cron', function () use (&$outcome) {
        $outcome = agumail_run_in_background('agumail_drain');
    });
    return $outcome;
}

function wordpress_only_paths() {
    return array_map(function ($request) { return $request['method'] . ' ' . $request['path'] . ($request['query'] ? '?' . $request['query'] : ''); }, $GLOBALS['agm']['http']);
}

// ── The settings page ──

function test_wordpress_only_the_settings_page_is_an_administrators_with_no_store_sections() {
    wordpress_only_run('wordpress_only_settings_page');
}

function wordpress_only_settings_page() {
    wordpress_only_check_process();
    wordpress_only_admin(true);
    do_action('admin_menu');
    expect_same(array('AguMail', 'AguMail', 'manage_agumail', 'agumail'), array_slice($GLOBALS['agm']['menus'][0], 0, 4), 'wrong menu entry');
    expect_that(current_user_can(AGUMAIL_ADMIN_CAPABILITY), 'an administrator cannot open the settings page');
    agm_grant('manage_options', false);
    agm_grant('manage_woocommerce');
    expect_that(!current_user_can(AGUMAIL_ADMIN_CAPABILITY), 'a shop manager left over from WooCommerce can open the settings page');

    $sections = array_keys(agumail_admin_sections());
    foreach (array('connection', 'accounts', 'tracking', 'forms', 'status') as $id) {
        expect_that(in_array($id, $sections, true), "no $id section");
    }
    foreach (array('checkout', 'sync') as $id) {
        expect_that(!in_array($id, $sections, true), "a store's $id section on a site without WooCommerce");
    }
    expect_same('Registration', agumail_admin_sections()['accounts']['title'], 'the accounts section is not about registration');
}

function test_wordpress_only_activation_schedules_the_jobs() {
    wordpress_only_run('wordpress_only_activation');
}

function wordpress_only_activation() {
    wordpress_only_check_process();
    agm_sign_in(1, 'owner@site.test');
    agm_grant('manage_options');
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    agm_request('activate', 'admin', function () {
        agm_input(array('action' => 'activate', 'plugin' => 'agumail/agumail.php'));
        agumail_activate(false);
    });
    expect_that((bool) agm_scheduled('agumail_drain'), 'the drain was not scheduled');
    expect_same(1, (int) get_transient('agumail_activation_redirect'), 'activating did not arm the way to the settings page');
}

function test_wordpress_only_activation_before_action_scheduler_loads_leaves_the_jobs_to_the_next_page() {
    // In the request that activates the plugin, plugins_loaded has run already, so the bundled
    // Action Scheduler is not there: the next wp-admin page must schedule the jobs, not an hour later.
    wordpress_only_run('wordpress_only_activation_without_scheduler', array('AGM_NO_SCHEDULER' => '1'));
}

function wordpress_only_activation_without_scheduler() {
    wordpress_only_check_process();
    expect_that(!function_exists('as_schedule_recurring_action'), 'Action Scheduler is loaded in this process');
    set_transient('agumail_maintained', 1, HOUR_IN_SECONDS);
    agumail_activate(false);
    expect_same(false, get_transient('agumail_maintained'), 'maintenance would wait out its hour before scheduling the jobs');
}

function test_wordpress_only_the_welcome_offers_a_free_account_and_the_key_steps() {
    wordpress_only_run('wordpress_only_welcome');
}

function wordpress_only_welcome() {
    wordpress_only_check_process();
    wordpress_only_admin();
    $html = agm_capture('agumail_connect_render_section');
    expect_that(strpos($html, 'Create your free AguMail account') !== false, 'no free account offered');
    expect_that(strpos($html, 'https://agumail.com/signup?utm_source=wordpress&amp;utm_medium=plugin&amp;utm_campaign=connect-welcome') !== false,
        'the free account link does not lead to the signup page: ' . $html);
    expect_that(strpos($html, 'for=store') === false, 'a site without a store signs up as a store');
    expect_that(strpos($html, 'https://agumail.com/dashboard/settings?utm_source=wordpress&amp;utm_medium=plugin&amp;utm_campaign=connect-key#api-keys') !== false,
        'no way to the API keys');
    expect_that(strpos($html, 'id="agumail_connect_key"') !== false, 'no key field in the steps');
    expect_that(strpos($html, 'connect_start') === false, 'the one-click connect is offered without WooCommerce');
}

// ── Connecting with a key ──

function test_wordpress_only_a_key_connects_through_ping_alone() {
    wordpress_only_run('wordpress_only_key_connect');
}

function wordpress_only_key_connect() {
    wordpress_only_check_process();
    wordpress_only_connected();
    // wordpress_only_connected() forgot the requests; connect again to see them.
    agm_http_respond(200, wordpress_only_ping_body());
    $result = wordpress_only_action('connect_save_advanced', array('agumail_api_key' => 'agm_' . str_repeat('d', 40)));
    $paths = array_map(function ($request) { return $request['method'] . ' ' . $request['path']; }, $result['http']);
    expect_same('GET /api/v1/ping', $paths[0], 'the key was not checked with ping');
    expect_that(!in_array('POST /api/v1/connect', $paths, true), 'a site without WooCommerce asked AguMail to connect a store');
    expect_same('wordpress', agumail_connection_platform(), 'the site did not connect as a WordPress site');
    $connection = agumail_connection();
    expect_same('connected', $connection['status'], 'not connected');
    expect_same('Hollow Pine', $connection['shopName'], 'the account was not read from ping');
    expect_same('usr_1', $connection['userId'], 'the account id was not read from ping');
    expect_same('site_abc123', agumail_config('siteKey'), 'the config was not kept');
    expect_same('Connected to AguMail as Hollow Pine.', agumail_admin_take_notice()['message'], 'wrong message');
}

function test_wordpress_only_a_refused_key_is_not_kept() {
    wordpress_only_run('wordpress_only_refused_key');
}

function wordpress_only_refused_key() {
    wordpress_only_check_process();
    wordpress_only_admin();
    agm_http_respond(401, array('ok' => false, 'error' => 'Invalid or revoked API key.'));
    wordpress_only_action('connect_save_advanced', array('agumail_api_key' => 'agm_' . str_repeat('e', 40)));
    expect_that(!agumail_has_api_key(), 'a refused key was kept');
    $notice = agumail_admin_take_notice();
    expect_same('error', $notice['type'], 'the refusal was not shown as an error');
    expect_same('Invalid or revoked API key.', $notice['message'], 'AguMail\'s words were not shown');
}

function test_wordpress_only_ping_without_a_store_keeps_the_site_connected() {
    wordpress_only_run('wordpress_only_ping');
}

function wordpress_only_ping() {
    wordpress_only_check_process();
    wordpress_only_connected();
    agm_http_respond(200, wordpress_only_ping_body());
    agm_request('housekeeping', 'cron', function () {
        do_action('agumail_housekeeping');
    });
    expect_same('GET /api/v1/ping', wordpress_only_paths()[0], 'ping asked about a store the site does not have');
    expect_same('connected', agumail_connection()['status'], 'a site with no store in AguMail read as disconnected');
    expect_that(!agumail_drain_paused(), 'sending is paused');
    expect_that(!in_array('POST /api/v1/products', wordpress_only_paths(), true), 'a site without WooCommerce asked about store sync');
}

// ── What it sends ──

function test_wordpress_only_the_drain_sends_signups_and_leaves_store_rows_alone() {
    wordpress_only_run('wordpress_only_drain_scope');
}

function wordpress_only_drain_scope() {
    wordpress_only_check_process();
    wordpress_only_connected();
    expect_that(!agumail_enqueue('order', 41), 'an order was queued on a site without WooCommerce');
    expect_that(!agumail_enqueue('cart', 'c1'), 'a cart was queued on a site without WooCommerce');
    // An order row left from when WooCommerce was active.
    agumail_store()->enqueue('order', '40', null, agumail_mysql_time());
    expect_that(agumail_signup_enqueue('cf7', array('id' => '7', 'name' => 'Newsletter'), array('email' => 'ada@example.com')), 'the signup was not queued');
    agm_http_default(function () { return array('code' => 200, 'body' => array('ok' => true)); });
    wordpress_only_drain();
    $paths = wordpress_only_paths();
    expect_same(array('POST /api/v1/signups'), $paths, 'the drain sent something besides the signup');
    $waiting = array_filter(agm_outbox('order'), function ($row) { return $row['status'] === 'pending'; });
    expect_same(1, count($waiting), 'the order row from before did not wait');
}

function test_wordpress_only_registration_sends_only_a_ticked_box() {
    wordpress_only_run('wordpress_only_registration');
}

function wordpress_only_registration() {
    wordpress_only_check_process();
    wordpress_only_connected();
    $form = agm_capture(function () { do_action('register_form'); });
    expect_that(strpos($form, 'name="agumail_optin"') !== false, 'no checkbox on the registration form');
    $register = function ($id, $email, $ticked) {
        return agm_request('register ' . $email, 'shopper', function () use ($id, $email, $ticked) {
            agm_input(array('action' => 'register'), $ticked ? array('agumail_optin' => '1') : array());
            do_action('register_post', $email, $email, new WP_Error());
            agm_add_user($id, $email);
            do_action('user_register', $id);
        });
    };
    $register(21, 'reader@example.com', false);
    expect_same(array(), agm_outbox('user'), 'somebody who did not tick the box was queued');
    $result = $register(22, 'fan@example.com', true);
    $rows = agm_outbox('user');
    expect_same(1, count($rows), 'a ticked box was not queued');
    expect_same('yes', $rows[0]['payload']['answer'], 'the ticked box was not a yes');
    expect_same(array(), $result['http'], 'registering called AguMail');
}

function test_wordpress_only_forms_render_without_woocommerce() {
    wordpress_only_run('wordpress_only_forms');
}

function wordpress_only_forms() {
    wordpress_only_check_process();
    wordpress_only_connected();
    update_option('agumail_popup', array('slug' => 'fspring', 'trigger' => 'delay', 'delay' => 5));
    $popup = agm_capture(function () { agumail_forms_render_popup(); });
    expect_that(strpos($popup, 'data-agumail-form="fspring" data-mode="popup"') !== false, 'no popup: ' . $popup);
    $html = agm_shortcode('agumail_form', array('slug' => 'fspring'));
    expect_that(strpos($html, 'data-agumail-form="fspring"') !== false, 'the shortcode printed no form: ' . $html);
}

// ── The log ──

function test_wordpress_only_the_log_goes_to_the_debug_log_while_it_is_on() {
    wordpress_only_run('wordpress_only_log');
}

function wordpress_only_log() {
    wordpress_only_check_process();
    $file = tempnam(sys_get_temp_dir(), 'agm-log');
    ini_set('error_log', $file);
    agumail_log('warning', 'Could not reach AguMail for ada@example.com.');
    expect_same('', (string) file_get_contents($file), 'a line was written while WP_DEBUG_LOG is off');
    define('WP_DEBUG_LOG', true);
    agumail_log('debug', 'POST /api/v1/signups: 200 in 12 ms');
    agumail_log('warning', 'Could not reach AguMail for ada@example.com.');
    $written = (string) file_get_contents($file);
    unlink($file);
    expect_that(strpos($written, 'AguMail warning: Could not reach AguMail for a***@example.com.') !== false, 'the warning did not reach the debug log: ' . $written);
    expect_that(strpos($written, 'ada@example.com') === false, 'an address reached the debug log unmasked');
    expect_that(strpos($written, '/api/v1/signups') === false, 'a debug line reached the debug log');
}

// ── Action Scheduler ──

function test_wordpress_only_the_bundled_action_scheduler_loads_only_without_woocommerce() {
    // Here WooCommerce is loaded: its own Action Scheduler is the one.
    agumail_load_action_scheduler();
    expect_that(!function_exists('action_scheduler_register_4_dot_2_dot_0'), 'the bundled Action Scheduler loaded beside WooCommerce');
    wordpress_only_run('wordpress_only_action_scheduler', array('AGM_NO_SCHEDULER' => '1'));
}

function wordpress_only_action_scheduler($plugin_dir) {
    wordpress_only_check_process();
    expect_that(!function_exists('as_enqueue_async_action'), 'Action Scheduler is loaded in this process');
    agumail_load_action_scheduler();
    expect_that(function_exists('action_scheduler_register_4_dot_2_dot_0'), 'the bundled Action Scheduler did not load');
    expect_that(is_readable($plugin_dir . '/vendor/woocommerce/action-scheduler/license.txt'), 'Action Scheduler\'s licence is not bundled with it');
}
