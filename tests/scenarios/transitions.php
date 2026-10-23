<?php
// A site that changes under the plugin: a store connected for forms and signups
// (a WordPress site that gained WooCommerce, or a store on a plan without store
// sync), the plan dropping store sync after the trial and gaining it back, and
// WooCommerce switched off and on. The connection, queue and settings stay;
// what is offered and sent follows.

require_once __DIR__ . '/orders.php';

// ── Helpers ──

/** Connected by key: AguMail keeps no store for this site (platform wordpress). */
function transitions_forms_only() {
    update_option('agumail_connection', array('status' => 'connected', 'shopName' => 'Hollow Pine', 'userId' => 'usr_1',
        'siteHost' => 'shop.test', 'storeUrl' => 'shop.test', 'checkedAt' => time(), 'platform' => 'wordpress'));
    $GLOBALS['agm']['writes'] = array();
}

/** AguMail's answer for a plan without store sync. */
function transitions_no_store_sync() {
    return array('code' => 402, 'body' => array('ok' => false, 'error' => 'Connecting a store, and sending its orders, carts and products, is on the Commerce plans. Contacts, signups and events work on every plan.',
        'code' => 'PLAN_FEATURE', 'feature' => 'storeSync'), 'headers' => array());
}

/**
 * Answers as AguMail would for a plan with or without store sync: orders,
 * carts and products refused without it (an empty products list is a 400 with
 * it), everything else taken.
 */
function transitions_plan($store_sync) {
    agm_http_default(function ($url, $args) {
        $path = parse_url($url, PHP_URL_PATH);
        if (in_array($path, array('/api/v1/orders', '/api/v1/carts', '/api/v1/products', '/api/v1/connect'), true) && !$GLOBALS['agm']['store_sync']) {
            return transitions_no_store_sync();
        }
        if ($path === '/api/v1/products' && isset($args['body']) && strpos((string) $args['body'], '"products":[]') !== false) {
            return array('code' => 400, 'body' => array('ok' => false, 'error' => 'products: Array must contain at least 1 element(s)'), 'headers' => array());
        }
        if ($path === '/api/v1/connect') {
            return array('code' => 200, 'body' => array('ok' => true, 'integrationId' => 'int_1', 'shopName' => 'Hollow Pine', 'userId' => 'usr_1',
                'config' => array('formsLoaderUrl' => 'https://api.agumail.com/embed/agumail-forms.js')), 'headers' => array());
        }
        return array('code' => 200, 'body' => array('ok' => true), 'headers' => array());
    });
    $GLOBALS['agm']['store_sync'] = $store_sync;
}

/** The paths the plugin called, in order. */
function transitions_paths(array $requests = null) {
    return array_map(function ($request) { return $request['method'] . ' ' . $request['path']; }, $requests === null ? $GLOBALS['agm']['http'] : $requests);
}

function transitions_pending($type) {
    return array_values(array_filter(agm_outbox($type), function ($row) { return $row['status'] === 'pending'; }));
}

function transitions_admin_action($action) {
    agm_sign_in(1, 'owner@shop.test');
    agm_grant('manage_woocommerce');
    do_action('init');
    return agm_request('admin: ' . $action, 'admin', function () use ($action) {
        agm_input(array(), array('action' => 'agumail', 'agumail_action' => $action, '_wpnonce' => agumail_admin_nonce($action)));
        do_action('admin_post_agumail');
    });
}

function transitions_housekeeping() {
    return agm_request('housekeeping', 'cron', function () {
        do_action('agumail_housekeeping');
    });
}

// ── A. A WordPress site that gained WooCommerce, connected by key ──

function test_transitions_a_store_connected_by_key_sends_checkout_subscribers_and_no_store_data() {
    $products = orders_catalogue();
    transitions_forms_only();
    transitions_plan(false);
    expect_that(agumail_checkout_optin_visible(), 'the checkout checkbox is hidden on a store connected for forms and signups');
    expect_that(!agumail_store_sync_on(), 'store sync is on for a store connected by key');

    agm_sign_in(7, 'bea@example.com');
    list(, $ticked) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bea@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_expect_shopper_quiet($ticked);
    list(, $unticked) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'cal@example.com', 'agumail_optin_shown' => '1'));
    agm_expect_shopper_quiet($unticked);
    expect_same(array(), agm_outbox('order'), 'an order was queued for a store connected by key');
    expect_same(array(), agm_outbox('cart'), 'a cart was queued for a store connected by key');
    expect_same(1, count(agm_outbox('order_contact')), 'the ticked checkout was not queued as a subscriber, or the unticked one was');

    $GLOBALS['agm']['http'] = array();
    agm_drain();
    expect_same(array('POST /api/v1/contacts'), transitions_paths(), 'the drain sent store data, or no subscriber');
    $contacts = agm_sent_records('/api/v1/contacts', 'contacts');
    expect_same('bea@example.com', $contacts[0]['email'], 'the wrong contact was sent');
    expect_same(array('optedIn' => true, 'source' => 'checkout'), $contacts[0]['consent'], 'the subscriber went without their checkout answer');
    expect_that(!empty($contacts[0]['consentedAt']), 'the subscriber went without the moment of the order, which could re-subscribe someone who left since');
    expect_same('yes', get_user_meta(7, '_agumail_optin', true), 'the account did not learn its own yes');
    expect_same(array(), agm_outbox('order_contact'), 'the subscriber row stayed after it was sent');
}

function test_transitions_a_store_connected_by_key_is_offered_store_sync_once_its_plan_has_it() {
    transitions_forms_only();
    transitions_plan(false);
    transitions_housekeeping();
    expect_same(false, agumail_store_sync_availability()['available'], 'a plan without store sync was taken as having it');
    $html = agm_capture('agumail_connect_render_store_state');
    expect_that(strpos($html, 'Connected for forms and signups.') !== false && strpos($html, 'See plans') !== false, 'the plan was not explained: ' . $html);
    // WooCommerce just arrived on a site connected by key: said once, whatever the plan.
    expect_same('offer', get_option('agumail_store_notice'), 'the merchant is not told the store can sync too');
    delete_option('agumail_store_notice');
    transitions_housekeeping();
    expect_same(false, get_option('agumail_store_notice'), 'the offer was made again the next day');

    $GLOBALS['agm']['store_sync'] = true;
    transitions_housekeeping();
    expect_same(true, agumail_store_sync_availability()['available'], 'the plan\'s store sync was not seen');
    expect_same('available', get_option('agumail_store_notice'), 'the merchant is not told once');
    $html = agm_capture('agumail_connect_render_store_state');
    expect_that(strpos($html, 'Your AguMail plan now includes store sync.') !== false && strpos($html, 'agumail_action" value="connect_store"') !== false,
        'Sync your store is not offered: ' . $html);
    $checks = array_filter($GLOBALS['agm']['http'], function ($request) { return $request['path'] === '/api/v1/products'; });
    foreach ($checks as $check) {
        expect_same(array('products' => array()), $check['body'], 'the plan check carried products');
    }
}

// ── B. Sync your store ──

function test_transitions_sync_your_store_connects_the_store_with_the_saved_key() {
    transitions_forms_only();
    transitions_plan(false);
    transitions_admin_action('connect_store');
    expect_same('wordpress', agumail_connection_platform(), 'a refused store connect changed the connection');
    expect_same('warning', agumail_admin_take_notice()['type'], 'the plan was not explained');

    $GLOBALS['agm']['store_sync'] = true;
    $result = transitions_admin_action('connect_store');
    expect_same(array('POST /api/v1/connect', 'GET /api/v1/forms'), transitions_paths($result['http']), 'the store was not connected with the saved key');
    expect_same('woocommerce', $result['http'][0]['body']['platform'], 'the store connected as something else');
    expect_same('woocommerce', agumail_connection_platform(), 'the connection is still a WordPress site\'s');
    expect_that(agumail_store_sync_on(), 'store sync is not on');
    expect_same('Your store is connected. Orders, carts and products sync from now on.', agumail_admin_take_notice()['message'], 'wrong message');
    $html = agm_capture(function () { agumail_connect_render_connected(agumail_connection()); });
    expect_that(strpos($html, 'Sync store data now') !== false, 'the store\'s history is not offered');
}

// ── C. The trial ends without a Commerce plan ──

function test_transitions_a_plan_without_store_sync_holds_only_store_data() {
    $products = orders_catalogue();
    transitions_plan(true);
    // A cart queued earlier, not due yet, and an order and a form signup waiting.
    agumail_store()->enqueue('cart', 'cart_1', null, agumail_mysql_time(time() + 600));
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agumail_signup_enqueue('cf7', array('id' => '7', 'name' => 'Newsletter'), array('email' => 'sam@example.com'));
    // A product change goes before the order and is refused first, so the order's own contact never goes this run.
    agumail_store()->enqueue('product', '11', null, agumail_mysql_time());

    $GLOBALS['agm']['store_sync'] = false;
    $GLOBALS['agm']['http'] = array();
    agm_drain();
    expect_that(in_array('POST /api/v1/signups', transitions_paths(), true), 'the form signup did not go');
    expect_that(agumail_store_sync_held(), 'store sync was not held');
    expect_that(agumail_connection()['status'] !== 'error', 'a plan without store sync read as a refused store');
    expect_same(0, agumail_backoff_until(), 'everything was paused, signups included');
    $orders = transitions_pending('order');
    expect_same(1, count($orders), 'the order did not wait');
    expect_same(0, $orders[0]['attempts'], 'the plan refusal counted as a failed attempt');
    expect_same(array(), agm_outbox('cart'), 'queued carts were kept while store sync is held');
    expect_that(!agumail_carts_active(), 'carts are still kept');
    expect_same('held', get_option('agumail_store_notice'), 'the merchant is not told');

    // Later runs leave store data alone, and send checkout subscribers.
    agm_sign_in(7, 'bea@example.com');
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bea@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    expect_that(agumail_checkout_optin_visible(), 'the checkbox was hidden while store sync is held');
    expect_same(2, count(transitions_pending('order')), 'the new order did not wait with the first');
    $GLOBALS['agm']['http'] = array();
    agm_drain();
    expect_same(array('POST /api/v1/contacts'), transitions_paths(), 'a held store sent store data, or not its checkout subscribers');
    $subscribers = array_map(function ($contact) { return $contact['email']; }, agm_sent_records('/api/v1/contacts', 'contacts'));
    sort($subscribers);
    expect_same(array('ada@example.com', 'bea@example.com'), $subscribers, 'the subscriber of an order queued before the hold did not join, or the new one did not');

    // The plan has store sync again: within the hour, the waiting orders go.
    $GLOBALS['agm']['store_sync'] = true;
    $GLOBALS['agm']['http'] = array();
    agm_drain();
    expect_same(array(), transitions_paths(), 'the plan was asked again before the hour was up');
    agumail_set_clock(function () { return microtime(true) + HOUR_IN_SECONDS + 60; });
    agm_drain();
    $paths = transitions_paths();
    expect_same('POST /api/v1/products', $paths[0], 'the plan was not asked again after an hour');
    expect_that(!agumail_store_sync_held(), 'store sync stayed held');
    expect_same(2, count(agm_sent_records('/api/v1/orders', 'orders')), 'the waiting orders did not go');
    expect_same('resumed', get_option('agumail_store_notice'), 'the merchant is not told it is back');
}

function test_transitions_store_data_held_for_ninety_days_is_dropped() {
    transitions_plan(false);
    agumail_store()->enqueue('order', '501', null, agumail_mysql_time(time() - 100 * DAY_IN_SECONDS));
    agumail_store()->enqueue('order', '502', null, agumail_mysql_time(time() - DAY_IN_SECONDS));
    agumail_store()->enqueue('signup', 'cf7:7:sam@example.com', '{"email":"sam@example.com"}', agumail_mysql_time(time() - 100 * DAY_IN_SECONDS));
    update_option(AGUMAIL_STORE_HOLD_OPTION, time() - 95 * DAY_IN_SECONDS);
    transitions_housekeeping();
    expect_same(array('502'), array_map(function ($row) { return $row['object_key']; }, transitions_pending('order')), 'the wrong orders were dropped');
    expect_same(1, count(agm_outbox('signup')), 'a signup was dropped with the store data');
}

function test_transitions_the_store_data_sync_stops_while_store_sync_is_held() {
    transitions_plan(false);
    update_option(AGUMAIL_STORE_HOLD_OPTION, time());
    do_action('init');
    $sections = array_keys(agumail_admin_sections());
    expect_that(!in_array('sync', $sections, true), 'the store data sync is offered while store sync is held');
}

// ── D. WooCommerce switched off, then on ──

function test_transitions_with_woocommerce_off_store_rows_wait_and_go_once_it_is_back() {
    transitions_plan(true);
    orders_paid(601);
    agumail_store()->enqueue('order', '601', null, agumail_mysql_time());
    agumail_signup_enqueue('cf7', array('id' => '7', 'name' => 'Newsletter'), array('email' => 'sam@example.com'));

    unset($GLOBALS['agm']['did']['woocommerce_loaded']);
    expect_that(!agumail_store_connected(), 'a store with WooCommerce off still counts as connected');
    expect_same('woocommerce', agumail_connection_platform(), 'the connection was downgraded');
    $GLOBALS['agm']['http'] = array();
    agm_drain();
    expect_same(array('POST /api/v1/signups'), transitions_paths(), 'with WooCommerce off, store data was sent or the signup was not');
    expect_same(1, count(transitions_pending('order')), 'the order did not wait');

    do_action('woocommerce_loaded');
    $GLOBALS['agm']['http'] = array();
    agm_drain();
    expect_same(1, count(agm_sent_records('/api/v1/orders', 'orders')), 'the order did not go once WooCommerce was back');
}
