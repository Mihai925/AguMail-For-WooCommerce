<?php
// Customer accounts: the registration checkbox, the My Account switch, account
// changes, the checkout checkbox for known subscribers, and the daily reverse
// sync of where each account stands in AguMail. Every answer counts only once
// its form is submitted, costs the shopper one write and no call, and goes to
// AguMail as live news (no consentedAt, no backfill).

// ── Helpers ──

/** A registration on the WooCommerce (My Account) or WordPress (wp-login.php) form, as a shopper request. */
function customers_register($id, $email, array $post, $form = 'woocommerce') {
    return agm_request('register ' . $email . ' on the ' . $form . ' form', 'shopper', function () use ($id, $email, $post, $form) {
        $GLOBALS['agm']['page'] = 'account';
        agm_input(array(), $post);
        do_action($form === 'woocommerce' ? 'woocommerce_register_post' : 'register_post', $email, $email, new WP_Error());
        agm_p4_user($id, $email, array(
            'first_name'      => 'Gus',
            'last_name'       => 'Ray',
            'user_registered' => '2026-09-28 10:00:00',
            'roles'           => array($form === 'woocommerce' ? 'customer' : 'subscriber'),
        ));
        do_action('user_register', $id, array('user_email' => $email));
        if ($form === 'woocommerce') {
            do_action('woocommerce_created_customer', $id, array('user_email' => $email), false);
        } else {
            do_action('register_new_user', $id);
        }
    });
}

/**
 * wp_update_user() as WordPress runs it: the users table first, then the
 * insert_user_meta filter with the new meta while the old is still stored,
 * the meta itself, and profile_update with the user as they were.
 */
function customers_update_user($id, array $changes) {
    $user = get_userdata($id);
    $old = clone $user;
    if (isset($changes['user_email'])) {
        $user->user_email = $changes['user_email'];
    }
    $meta = array(
        'first_name' => isset($changes['first_name']) ? $changes['first_name'] : $user->first_name,
        'last_name'  => isset($changes['last_name']) ? $changes['last_name'] : $user->last_name,
    );
    apply_filters('insert_user_meta', $meta, $user, true, $changes);
    foreach ($meta as $key => $value) {
        $user->$key = $value;
    }
    do_action('profile_update', $id, $old, $changes);
}

/** My Account > Account details, submitted: WooCommerce updates the user, saves the customer (a second update), then fires its hook. */
function customers_save_account($id, array $post, array $changes = array()) {
    return agm_request('save account details of user ' . $id, 'shopper', function () use ($id, $post, $changes) {
        agm_sign_in($id);
        $GLOBALS['agm']['page'] = 'account';
        agm_input(array(), array_merge(array('action' => 'save_account_details', 'save-account-details-nonce' => 'n'), $post));
        customers_update_user($id, $changes);
        customers_update_user($id, array());
        do_action('woocommerce_save_account_details', $id);
    });
}

function customers_account_form($id) {
    agm_sign_in($id);
    return agm_capture(function () {
        do_action('woocommerce_edit_account_form');
    });
}

function customers_checked($html) {
    return (bool) preg_match('/<input[^>]*name="agumail_optin"[^>]*checked/', $html);
}

/** The one user row, decoded. */
/** A clock the scenario moves; the outbox stamps rows with it. */
function customers_clock($start) {
    $GLOBALS['agm_customers_now'] = (float) $start;
    agumail_set_clock(function () { return $GLOBALS['agm_customers_now']; });
}

function customers_user_row($id) {
    $row = agm_store()->find('user', (string) $id);
    expect_that($row !== null, "no account change was queued for user $id");
    return $row;
}

function customers_sent_contact($email) {
    foreach (agm_sent_records('/api/v1/contacts', 'contacts') as $record) {
        if ($record['email'] === $email) {
            return $record;
        }
    }
    throw new Exception("$email was never sent to AguMail");
}

function customers_expect_live_requests() {
    foreach (agm_http_requests('/api/v1/contacts') as $request) {
        expect_that(!array_key_exists('backfill', $request['body']), 'a live account change was sent as history (backfill)');
    }
}

// ── Registration ──

function test_customers_registration_with_the_tick_is_an_opt_in_in_one_write_and_no_call() {
    customers_clock(strtotime('2026-09-28 10:00:00 UTC'));
    $result = customers_register(21, 'gus@example.com', array('email' => 'gus@example.com', 'agumail_optin' => '1', 'woocommerce-register-nonce' => 'n'));
    agm_expect_shopper_quiet($result);
    expect_same(1, count(agm_writes('outbox', $result['name'])), 'registering should be exactly one write');
    $row = customers_user_row(21);
    expect_same(array('answer' => 'yes', 'source' => 'wp_register'), $row['payload'], 'the ticked box was not queued as an opt-in');

    $GLOBALS['agm_customers_now'] += 3600;
    agm_drain();
    $contact = customers_sent_contact('gus@example.com');
    expect_same(array('optedIn' => true, 'source' => 'wp_register'), $contact['consent'], 'the registration opt-in was not sent');
    expect_same('Gus', $contact['firstName'], 'the name was not sent');
    expect_same('2026-09-28T10:00:00Z', $contact['customerSince'], 'the registration date was not sent as customerSince');
    expect_that(!isset($contact['consentedAt']), 'a live opt-in carried consentedAt, which would make it history');
    customers_expect_live_requests();
    expect_same('yes', get_user_meta(21, '_agumail_optin', true), 'the account was not marked subscribed once AguMail had the opt-in');
    expect_same('2026-09-28T10:00:00Z', get_user_meta(21, '_agumail_optin_at', true), 'the opt-in moment is not when the form was submitted');
    expect_same(array(), agm_outbox(), 'the sent row stayed queued');
}

function test_customers_registration_without_the_tick_sends_no_consent() {
    $result = customers_register(22, 'hal@example.com', array('email' => 'hal@example.com', 'woocommerce-register-nonce' => 'n'));
    agm_expect_shopper_quiet($result);
    expect_same(null, customers_user_row(22)['payload'], 'an unticked box was queued as an answer');

    agm_drain();
    $contact = customers_sent_contact('hal@example.com');
    expect_that(!isset($contact['consent']) && !isset($contact['unsubscribed']) && !isset($contact['consentedAt']),
        'an unticked registration said something about consent: ' . json_encode($contact));
    expect_same('', get_user_meta(22, '_agumail_optin', true), 'an unticked registration recorded an answer on the account');
    expect_same('', get_user_meta(22, '_agumail_optin_at', true), 'an unticked registration recorded an opt-in moment');
}

function test_customers_the_wordpress_registration_form_counts_the_same_way() {
    agm_expect_shopper_quiet(customers_register(23, 'ida@example.com', array('user_email' => 'ida@example.com', 'agumail_optin' => '1'), 'wordpress'));
    expect_same(array('answer' => 'yes', 'source' => 'wp_register'), customers_user_row(23)['payload'], 'the WordPress form opt-in was not queued');
}

function test_customers_both_registration_forms_show_the_checkout_words_unticked() {
    update_option('agumail_consent_label', 'Send me the good stuff');
    foreach (array('woocommerce_register_form', 'register_form') as $hook) {
        $html = agm_capture(function () use ($hook) { do_action($hook); });
        expect_that(strpos($html, 'name="agumail_optin"') !== false, "$hook shows no marketing checkbox");
        expect_that(strpos($html, 'Send me the good stuff') !== false, "$hook does not use the checkout checkbox's words");
        expect_that(!customers_checked($html), "$hook ticks the box for the shopper");
    }
}

function test_customers_the_registration_checkbox_can_be_switched_off() {
    update_option('agumail_registration_optin', 'no');
    $html = agm_capture(function () { do_action('woocommerce_register_form'); do_action('register_form'); });
    expect_same('', $html, 'the checkbox shows while switched off');
    customers_register(24, 'jo@example.com', array('email' => 'jo@example.com', 'agumail_optin' => '1'));
    expect_same(null, customers_user_row(24)['payload'], 'a tick posted while the checkbox is off counted');
}

function test_customers_an_account_created_at_checkout_is_left_to_the_order() {
    $classic = agm_request('classic checkout creates an account', 'shopper', function () {
        agm_input(array('wc-ajax' => 'checkout'), array('billing_email' => 'kim@example.com', 'createaccount' => '1', 'agumail_optin' => '1'));
        do_action('woocommerce_before_checkout_process');
        do_action('woocommerce_checkout_process');
        do_action('woocommerce_register_post', 'kim', 'kim@example.com', new WP_Error());
        agm_p4_user(31, 'kim@example.com');
        do_action('user_register', 31, array('user_email' => 'kim@example.com'));
        do_action('woocommerce_created_customer', 31, array('user_email' => 'kim@example.com'), false);
    });
    agm_expect_shopper_quiet($classic);
    expect_same(array(), agm_outbox('user'), 'an account made at the classic checkout was queued; the order carries its contact');
}

function test_customers_an_account_created_at_the_block_checkout_is_left_to_the_order() {
    $order = agm_order(0, array('status' => 'checkout-draft', 'billing_email' => 'lou@example.com'));
    $block = agm_request('block checkout creates an account', 'shopper_rest', function () use ($order) {
        $request = new WP_REST_Request('POST', '/wc/store/v1/checkout', array('create_account' => true, 'additional_fields' => array('agumail/optin' => true)));
        do_action('woocommerce_store_api_checkout_update_order_from_request', $order, $request);
        do_action('woocommerce_register_post', 'lou', 'lou@example.com', new WP_Error());
        agm_p4_user(32, 'lou@example.com');
        do_action('user_register', 32, array('user_email' => 'lou@example.com'));
        do_action('woocommerce_created_customer', 32, array('user_email' => 'lou@example.com'), false);
    });
    agm_expect_shopper_quiet($block);
    expect_same(array(), agm_outbox('user'), 'an account made at the block checkout was queued; the order carries its contact');
}

function test_customers_an_account_a_merchant_adds_for_staff_is_not_a_contact() {
    agm_p4_merchant();
    agm_request('add a shop manager', 'admin', function () {
        agm_p4_user(33, 'manager@shop.test', array('roles' => array('shop_manager')));
        do_action('user_register', 33, array('user_email' => 'manager@shop.test'));
        agm_p4_user(34, 'buyer@example.com');
        do_action('user_register', 34, array('user_email' => 'buyer@example.com'));
    });
    expect_same(array('34'), array_column(agm_outbox('user'), 'object_key'), 'only the customer account should be queued');
}

// ── Account changes ──

function test_customers_a_changed_email_or_name_queues_the_account() {
    agm_p4_user(40, 'mia@example.com', array('first_name' => 'Mia'));
    agm_p4_user(41, 'ned@example.com', array('first_name' => 'Ned'));
    agm_p4_user(42, 'ops@shop.test', array('first_name' => 'Ops', 'roles' => array('administrator')));
    agm_request('edit users', 'admin', function () {
        customers_update_user(40, array('first_name' => 'Mira'));
        customers_update_user(41, array('user_email' => 'ned@new.example'));
        customers_update_user(42, array('first_name' => 'Opsie'));
    });
    expect_same(array('40', '41'), array_column(agm_outbox('user'), 'object_key'), 'the changed customers (and only they) should be queued');

    agm_drain();
    expect_same('Mira', customers_sent_contact('mia@example.com')['firstName'], 'the new name was not sent');
    expect_that(!isset(customers_sent_contact('mia@example.com')['consent']), 'a name change said something about consent');
    customers_sent_contact('ned@new.example');
}

function test_customers_an_update_that_changes_nothing_queues_nothing() {
    agm_p4_user(43, 'oda@example.com', array('first_name' => 'Oda'));
    $result = agm_request('password change', 'admin', function () {
        customers_update_user(43, array('user_pass' => 'secret'));
        customers_update_user(43, array('first_name' => 'Oda'));
    });
    expect_same(array(), agm_writes('outbox', $result['name']), 'an update that changed nothing AguMail holds was queued');
}

function test_customers_a_name_saved_during_checkout_is_left_to_the_order() {
    agm_p4_user(44, 'pia@example.com');
    $result = agm_request('checkout fills in the account name', 'shopper', function () {
        do_action('woocommerce_checkout_process');
        do_action('woocommerce_before_checkout_process');
        customers_update_user(44, array('first_name' => 'Pia'));
    });
    agm_expect_shopper_quiet($result);
    expect_same(array(), agm_outbox('user'), 'a checkout queued the account as well as its order');
}

function test_customers_a_queued_answer_survives_a_later_update() {
    customers_register(45, 'quinn@example.com', array('email' => 'quinn@example.com', 'agumail_optin' => '1'));
    agm_request('rename before the drain', 'admin', function () {
        customers_update_user(45, array('last_name' => 'Stone'));
    });
    expect_same(array('answer' => 'yes', 'source' => 'wp_register'), customers_user_row(45)['payload'], 'a later change dropped the queued opt-in');
}

// ── My Account ──

function test_customers_the_account_switch_shows_the_saved_answer() {
    agm_p4_user(50, 'ray@example.com');
    $html = customers_account_form(50);
    expect_that(strpos($html, 'name="agumail_optin"') !== false, 'the account details form has no marketing switch');
    expect_that(strpos($html, 'name="agumail_account_optin"') !== false, 'the switch carries no marker, so an unticked box could not be told from a missing one');
    expect_that(strpos($html, 'Email me about new products and offers') !== false, 'the switch does not use the checkout words');
    expect_that(!customers_checked($html), 'an account with no answer shows as subscribed');

    update_user_meta(50, '_agumail_optin', 'yes');
    expect_that(customers_checked(customers_account_form(50)), 'a subscribed account shows as not subscribed');
    update_user_meta(50, '_agumail_optin', 'unsubscribed');
    expect_that(!customers_checked(customers_account_form(50)), 'an unsubscribed account shows as subscribed');
}

function test_customers_the_account_switch_and_registration_box_keep_the_labels_link_and_nothing_else() {
    update_option('agumail_consent_label', 'Email me. <a href="https://shop.test/privacy" rel="noopener">Privacy</a><img src="x" onerror="steal()">');
    agm_p4_user(53, 'uma@example.com');
    $account = customers_account_form(53);
    agm_sign_out();
    $registration = agm_capture(function () { do_action('woocommerce_register_form'); });
    foreach (array('My Account' => $account, 'registration' => $registration) as $where => $html) {
        expect_that(strpos($html, '<span>Email me. <a href="https://shop.test/privacy" rel="noopener">Privacy</a></span>') !== false, "the $where form lost the label's link: $html");
        expect_that(strpos($html, '<img') === false && strpos($html, 'onerror') === false, "the $where form printed markup other than the link: $html");
    }
}

function test_customers_a_queued_answer_shows_before_the_saved_one() {
    agm_p4_user(51, 'sam@example.com', array(), array('_agumail_optin' => 'yes'));
    agumail_enqueue('user', 51, array('answer' => 'unsubscribed', 'source' => 'my_account'));
    expect_that(!customers_checked(customers_account_form(51)), 'the switch ignored the answer waiting to be sent');

    agm_p4_user(52, 'tia@example.com', array(), array('_agumail_optin' => 'no'));
    agumail_enqueue('user', 52, array('answer' => 'yes', 'source' => 'my_account'));
    expect_that(customers_checked(customers_account_form(52)), 'the switch ignored the opt-in waiting to be sent');
}

function test_customers_switching_on_in_my_account_is_an_opt_in() {
    customers_clock(strtotime('2026-09-20 08:30:00 UTC'));
    agm_p4_user(53, 'uma@example.com', array(), array('_agumail_optin_at' => '2024-01-01T00:00:00Z'));
    $result = customers_save_account(53, array('agumail_account_optin' => '1', 'agumail_optin' => '1'));
    agm_expect_shopper_quiet($result);
    expect_same(array('answer' => 'yes', 'source' => 'my_account'), customers_user_row(53)['payload'], 'switching on was not queued as an opt-in');

    agm_drain();
    $contact = customers_sent_contact('uma@example.com');
    expect_same(array('optedIn' => true, 'source' => 'my_account'), $contact['consent'], 'the account opt-in was not sent');
    expect_that(!isset($contact['consentedAt']), 'a live opt-in carried consentedAt');
    customers_expect_live_requests();
    expect_same('yes', get_user_meta(53, '_agumail_optin', true), 'the account was not marked subscribed once AguMail had it');
    expect_same('2026-09-20T08:30:00Z', get_user_meta(53, '_agumail_optin_at', true), 'the switch did not record when the customer opted in again');
}

function test_customers_switching_off_in_my_account_unsubscribes() {
    agm_p4_user(54, 'val@example.com', array(), array('_agumail_optin' => 'yes', '_agumail_optin_at' => '2025-01-01T00:00:00Z'));
    $result = customers_save_account(54, array('agumail_account_optin' => '1'));
    agm_expect_shopper_quiet($result);
    expect_same(array('answer' => 'unsubscribed', 'source' => 'my_account'), customers_user_row(54)['payload'], 'switching off was not queued as an unsubscribe');

    agm_drain();
    $contact = customers_sent_contact('val@example.com');
    expect_same(true, isset($contact['unsubscribed']) ? $contact['unsubscribed'] : null, 'the unsubscribe was not sent');
    expect_that(!isset($contact['consent']), 'an unsubscribe also carried consent');
    expect_same('unsubscribed', get_user_meta(54, '_agumail_optin', true), 'the account was not marked unsubscribed');
    expect_same('2025-01-01T00:00:00Z', get_user_meta(54, '_agumail_optin_at', true), 'an unsubscribe rewrote the opt-in moment');
}

function test_customers_saving_the_account_without_touching_the_switch_says_nothing() {
    agm_p4_user(55, 'wes@example.com');
    agm_p4_user(56, 'xan@example.com', array(), array('_agumail_optin' => 'yes'));
    $off = customers_save_account(55, array('agumail_account_optin' => '1'));
    $on = customers_save_account(56, array('agumail_account_optin' => '1', 'agumail_optin' => '1'));
    expect_same(array(), agm_writes('outbox', $off['name']), 'saving with the switch left off unsubscribed a customer who never asked');
    expect_same(array(), agm_outbox('user'), 'saving with the switch left on sent a fresh opt-in');
    unset($on);
}

function test_customers_a_form_without_the_switch_is_no_answer() {
    agm_p4_user(57, 'yan@example.com', array(), array('_agumail_optin' => 'yes'));
    customers_save_account(57, array());
    expect_same(array(), agm_outbox('user'), 'an account form without the switch was read as switching off');
}

function test_customers_a_name_change_and_the_switch_in_one_save_is_one_write() {
    agm_p4_user(58, 'zoe@example.com', array('first_name' => 'Zoe'));
    $result = customers_save_account(58, array('agumail_account_optin' => '1', 'agumail_optin' => '1'), array('first_name' => 'Zoey'));
    agm_expect_shopper_quiet($result);
    expect_same(1, count(agm_writes('outbox', $result['name'])), 'the save should be one write');
    expect_same(array('answer' => 'yes', 'source' => 'my_account'), customers_user_row(58)['payload'], 'the switch was lost to the name change');

    agm_p4_user(59, 'abe@example.com', array('first_name' => 'Abe'));
    $rename = customers_save_account(59, array('agumail_account_optin' => '1'), array('first_name' => 'Abel'));
    expect_same(1, count(agm_writes('outbox', $rename['name'])), 'a rename from My Account should be one write');
    expect_same(null, customers_user_row(59)['payload'], 'a rename said something about consent');
}

function test_customers_the_account_switch_can_be_switched_off() {
    update_option('agumail_account_toggle', 'no');
    agm_p4_user(60, 'bo@example.com');
    expect_same('', customers_account_form(60), 'the switch shows while switched off');
    customers_save_account(60, array('agumail_account_optin' => '1', 'agumail_optin' => '1'));
    expect_same(array(), agm_outbox('user'), 'a switch posted while switched off counted');
}

// ── The checkout checkbox ──

function test_customers_the_checkout_checkbox_is_hidden_for_a_subscribed_customer() {
    agm_p4_user(61, 'cy@example.com', array(), array('_agumail_optin' => 'yes'));
    agm_sign_in(61);
    $html = '';
    $result = agm_request('checkout page, subscribed', 'shopper', function () use (&$html) {
        $GLOBALS['agm']['page'] = 'checkout';
        do_action('woocommerce_init');
        $html = agm_capture(function () { do_action('woocommerce_review_order_before_submit'); });
    });
    agm_expect_shopper_quiet($result);
    expect_same(array(), $GLOBALS['agm']['fields'], 'the block checkout still asks a subscribed customer');
    expect_that(strpos($html, 'agumail_optin') === false, 'the classic checkout still asks a subscribed customer');
}

function test_customers_a_customer_who_said_no_starts_with_the_checkout_box_unticked_even_when_it_starts_ticked() {
    update_option('agumail_checkout_optin_default', 'yes');
    $draft = agm_order(0, array('status' => 'checkout-draft'));
    // How the box starts on each checkout: ticked on the classic one, and the block one's default for the draft.
    $starts = function () use ($draft) {
        $html = agm_capture(function () { do_action('woocommerce_review_order_before_submit'); });
        expect_that(strpos($html, 'name="agumail_optin"') !== false, 'setup: the classic checkout shows no box');
        return array(customers_checked($html), apply_filters('woocommerce_get_default_value_for_agumail/optin', null, 'other', $draft));
    };
    agm_p4_user(63, 'ed@example.com', array(), array('_agumail_optin' => 'no'));
    agm_p4_user(64, 'flo@example.com', array(), array('_agumail_optin' => 'unsubscribed'));
    agm_p4_user(65, 'gil@example.com');
    agm_p4_user(66, 'hal@example.com');
    agumail_enqueue('user', 66, array('answer' => 'unsubscribed', 'source' => 'my_account'));
    foreach (array(63 => 'said no', 64 => 'unsubscribed', 66 => 'just switched off in My Account') as $id => $who) {
        agm_sign_in($id);
        expect_same(array(false, null), $starts(), "the box starts ticked for a customer who $who");
    }
    agm_sign_in(65);
    expect_same(array(true, true), $starts(), 'the box does not start ticked for a customer who never answered');
    agm_sign_out();
    expect_same(array(true, true), $starts(), 'the box does not start ticked for a guest');
}

function test_customers_the_checkout_checkbox_shows_for_everyone_else() {
    agm_p4_user(62, 'di@example.com', array(), array('_agumail_optin' => 'unsubscribed'));
    agm_sign_in(62);
    expect_that(agumail_checkout_optin_visible(), 'an unsubscribed customer cannot opt in again at checkout');
    agm_sign_out();
    expect_that(agumail_checkout_optin_visible(), 'a guest is not asked');
    do_action('woocommerce_init');
    expect_same(1, count($GLOBALS['agm']['fields']), 'the block checkout does not ask a guest');
}

// ── Reverse sync ──

/** AguMail's answer to /contacts/status, from a table of email => [status, subscribed]. */
function customers_status_server(array $table) {
    agm_http_default(function ($url, $args) use ($table) {
        $body = json_decode($args['body'], true);
        $contacts = array();
        foreach ($body['emails'] as $email) {
            $known = isset($table[$email]) ? $table[$email] : array(null, false);
            $contacts[] = array('email' => $email, 'status' => $known[0], 'subscribed' => $known[1]);
        }
        return array('code' => 200, 'body' => array('ok' => true, 'contacts' => $contacts));
    });
}

/** The daily job as Action Scheduler starts it, or the follow-up it scheduled when one is waiting. */
function customers_reverse_sync() {
    return agm_request('reverse sync', 'cron', function () {
        if (agm_scheduled('agumail_reverse_sync')) {
            agm_run_actions('agumail_reverse_sync');
        } else {
            do_action('agumail_reverse_sync');
        }
    });
}

function test_customers_reverse_sync_keeps_each_account_in_step_with_agumail() {
    $cases = array(
        // id => [email, meta before, status, subscribed, meta after]
        70 => array('a@example.com', '', 'active', true, 'yes'),
        71 => array('b@example.com', 'yes', 'active', false, 'unsubscribed'),
        72 => array('c@example.com', 'yes', 'unsubscribed', false, 'unsubscribed'),
        73 => array('d@example.com', '', 'complained', false, 'unsubscribed'),
        74 => array('e@example.com', 'yes', 'nonsubscribed', false, 'no'),
        75 => array('f@example.com', '', 'unconfirmed', false, 'no'),
        76 => array('g@example.com', 'yes', 'bounced', false, 'no'),
        77 => array('h@example.com', 'yes', null, false, 'yes'),
        78 => array('i@example.com', '', null, false, ''),
        79 => array('j@example.com', 'yes', 'active', true, 'yes'),
    );
    $table = array();
    foreach ($cases as $id => $case) {
        agm_p4_user($id, $case[0], array(), $case[1] === '' ? array() : array('_agumail_optin' => $case[1]));
        $table[$case[0]] = array($case[2], $case[3]);
    }
    customers_status_server($table);
    $result = customers_reverse_sync();

    $requests = agm_http_requests('/api/v1/contacts/status');
    expect_same(1, count($requests), 'ten accounts should take one request');
    foreach ($cases as $id => $case) {
        expect_same($case[4], get_user_meta($id, '_agumail_optin', true), "user $id ({$case[2]}) was not kept in step");
    }
    expect_same(7, count(agm_writes('user_meta', $result['name'])), 'an account that already matched was written again');
    expect_same(array(), array_values(array_filter(agm_writes('user_meta', $result['name']), function ($write) { return $write['name'] === '_agumail_optin_at'; })),
        'the reverse sync recorded an opt-in moment it never saw');
}

function test_customers_reverse_sync_asks_500_at_a_time_from_where_it_stopped() {
    for ($id = 100; $id < 1301; $id++) {
        agm_p4_user($id, "c$id@example.com");
    }
    customers_status_server(array());
    agumail_set_clock(function () { return $GLOBALS['agm_customers_now']; });
    $GLOBALS['agm_customers_now'] = 1000000.0;
    $default = $GLOBALS['agm']['http_default'];
    agm_http_default(function ($url, $args) use ($default) {
        // Each answer takes AguMail half a minute: the run's time is gone after the first.
        $GLOBALS['agm_customers_now'] += 30;
        return call_user_func($default, $url, $args);
    });

    customers_reverse_sync();
    expect_same(1, count(agm_http_requests('/api/v1/contacts/status')), 'the run went on past its time');
    expect_same(1, count(agm_scheduled('agumail_reverse_sync')), 'the rest was not scheduled to carry on');
    expect_same(599, (int) get_option('agumail_reverse_sync_cursor'), 'the next run does not carry on from the last account asked about');

    customers_reverse_sync();
    customers_reverse_sync();
    $sizes = array_map(function ($request) { return count($request['body']['emails']); }, agm_http_requests('/api/v1/contacts/status'));
    expect_same(array(500, 500, 201), $sizes, 'accounts should be asked about 500 at a time');
    $asked = array();
    foreach (agm_http_requests('/api/v1/contacts/status') as $request) {
        $asked = array_merge($asked, $request['body']['emails']);
    }
    expect_same(1201, count(array_unique($asked)), 'an account was skipped or asked about twice');
    expect_same('c100@example.com', $asked[0], 'accounts were not asked about in id order');
    expect_same(false, get_option('agumail_reverse_sync_cursor'), 'a finished run left its place behind');
    expect_same(array(), agm_scheduled('agumail_reverse_sync'), 'a finished run scheduled another');
}

function test_customers_reverse_sync_leaves_an_account_with_a_queued_answer() {
    agm_p4_user(80, 'k@example.com', array(), array('_agumail_optin' => 'no'));
    agumail_enqueue('user', 80, array('answer' => 'yes', 'source' => 'my_account'));
    customers_status_server(array('k@example.com' => array('unsubscribed', false)));
    customers_reverse_sync();
    expect_same('no', get_user_meta(80, '_agumail_optin', true), 'the reverse sync overwrote an answer still waiting to be sent');
}

function test_customers_reverse_sync_waits_when_agumail_asks() {
    agm_p4_user(81, 'l@example.com');
    agm_http_respond(429, array('ok' => false, 'error' => 'Rate limit', 'retryAfter' => 120), array('Retry-After' => '120'));
    $before = time();
    $result = customers_reverse_sync();
    expect_same(array(), agm_writes('user_meta', $result['name']), 'a refused request changed an account');
    $next = agm_scheduled('agumail_reverse_sync');
    expect_same(1, count($next), 'the run was not scheduled again');
    expect_that($next[0]['timestamp'] >= $before + 120, 'the next run does not wait for Retry-After');
}

function test_customers_reverse_sync_never_runs_from_a_shopper_request() {
    agm_p4_user(82, 'm@example.com');
    $result = agm_request('a shopper request fires the job', 'shopper', function () {
        do_action('agumail_reverse_sync');
    });
    agm_expect_shopper_quiet($result);
}

// ── A new email address ──

/** The contacts each drained request carried, in order, as [email => record] lists. */
function customers_contact_requests() {
    return array_map(function ($request) {
        $records = array();
        foreach ($request['body']['contacts'] as $record) {
            $records[$record['email']] = $record;
        }
        return $records;
    }, agm_http_requests('/api/v1/contacts'));
}

function test_customers_a_new_email_from_my_account_moves_the_subscription() {
    agm_p4_user(90, 'old@example.com', array('first_name' => 'Ola'), array('_agumail_optin' => 'yes', '_agumail_email' => 'old@example.com', '_agumail_optin_at' => '2025-01-01T00:00:00Z'));
    $result = customers_save_account(90, array('agumail_account_optin' => '1', 'agumail_optin' => '1'), array('user_email' => 'new@example.com'));
    agm_expect_shopper_quiet($result);
    expect_same(1, count(agm_writes('outbox', $result['name'])), 'the new address should be one write');

    agm_drain();
    $requests = customers_contact_requests();
    expect_same(1, count($requests), 'the old address was sent too; WooCommerce keeps it as the billing address, so it is left alone');
    expect_same(array('new@example.com'), array_keys($requests[0]), 'the old address was sent too');
    $new = $requests[0]['new@example.com'];
    expect_same(array('optedIn' => true, 'source' => 'my_account'), $new['consent'], 'the subscription did not move to the new address');
    expect_same('2025-01-01T00:00:00Z', isset($new['consentedAt']) ? $new['consentedAt'] : null,
        'the moved subscription is not dated at the original consent, so AguMail would welcome them again');
    expect_same('new@example.com', get_user_meta(90, '_agumail_email', true), 'the address AguMail holds was not recorded');
    expect_same('2025-01-01T00:00:00Z', get_user_meta(90, '_agumail_optin_at', true), 'moving an existing subscription rewrote when they opted in');
}

function test_customers_a_new_email_set_by_the_merchant_moves_it_too() {
    agm_p4_user(91, 'a1@example.com', array(), array('_agumail_optin' => 'yes'));
    agm_request('edit the customer', 'admin', function () {
        customers_update_user(91, array('user_email' => 'b1@example.com'));
        customers_update_user(91, array());
    });
    expect_same('a1@example.com', customers_user_row(91)['payload']['previous'], 'the old address was not kept with the change');
    agm_drain();
    $requests = customers_contact_requests();
    expect_same(array('b1@example.com'), array_keys($requests[0]), 'the old address was touched');
    expect_same(1, count($requests), 'the old address was touched');
    $new = $requests[0]['b1@example.com'];
    expect_same(true, $new['consent']['optedIn'], 'the new address was not subscribed');
    expect_that(!isset($new['consentedAt']), 'a consent date was made up for an account that has none recorded');
}

function test_customers_switching_off_unsubscribes_the_held_address_too() {
    agm_p4_user(92, 'cur@example.com', array(), array('_agumail_optin' => 'yes', '_agumail_email' => 'prev@example.com'));
    customers_save_account(92, array('agumail_account_optin' => '1'));
    agm_drain();
    $requests = customers_contact_requests();
    expect_same(true, $requests[0]['prev@example.com']['unsubscribed'], 'the address AguMail held was not unsubscribed');
    expect_same(true, $requests[1]['cur@example.com']['unsubscribed'], 'the current address was not unsubscribed');
}

function test_customers_a_new_email_without_a_subscription_says_nothing_of_consent_for_it() {
    agm_p4_user(93, 'o3@example.com', array(), array('_agumail_email' => 'o3@example.com'));
    agm_request('edit the customer', 'admin', function () {
        customers_update_user(93, array('user_email' => 'n3@example.com'));
    });
    agm_drain();
    $requests = customers_contact_requests();
    expect_same(array(array('n3@example.com')), array_map('array_keys', $requests), 'the old address was touched');
    $new = $requests[0]['n3@example.com'];
    expect_that(!isset($new['consent']) && !isset($new['unsubscribed']) && !isset($new['consentedAt']), 'the new address was sent with a consent claim');
}

// ── An answer AguMail refused is kept ──

function test_customers_a_parked_answer_is_never_dropped() {
    customers_register(94, 'pk@example.com', array('email' => 'pk@example.com', 'agumail_optin' => '1'));
    $row = customers_user_row(94);
    agm_store()->park($row['id'], $row['version'], 'contacts.0.email: Invalid', agumail_mysql_time());
    agm_request('rename after it failed', 'admin', function () {
        customers_update_user(94, array('last_name' => 'Kay'));
    });
    $row = customers_user_row(94);
    expect_same('yes', $row['payload']['answer'], 'a later change dropped the parked opt-in');
    expect_same('pending', $row['status'], 'the change did not put the row back in line');
}

function test_customers_a_parked_answer_still_shows_and_holds_off_the_reverse_sync() {
    agm_p4_user(95, 'sh@example.com', array(), array('_agumail_optin' => 'yes'));
    agumail_enqueue('user', 95, array('answer' => 'unsubscribed', 'source' => 'my_account'));
    $row = customers_user_row(95);
    agm_store()->park($row['id'], $row['version'], 'Boom', agumail_mysql_time());
    expect_that(!customers_checked(customers_account_form(95)), 'the switch ignored the parked answer');

    agm_p4_user(96, 'rs@example.com', array(), array('_agumail_optin' => 'no'));
    agumail_enqueue('user', 96, array('answer' => 'yes', 'source' => 'my_account'));
    $row = customers_user_row(96);
    agm_store()->park($row['id'], $row['version'], 'Boom', agumail_mysql_time());
    customers_status_server(array('rs@example.com' => array('unsubscribed', false), 'sh@example.com' => array('active', true)));
    customers_reverse_sync();
    expect_same('no', get_user_meta(96, '_agumail_optin', true), 'the reverse sync wrote over a parked answer');
    expect_same('yes', get_user_meta(95, '_agumail_optin', true), 'the reverse sync wrote over a parked answer');
}

// ── Staff, and a store not connected ──

function test_customers_reverse_sync_asks_about_customers_only() {
    agm_p4_user(97, 'boss@shop.test', array('roles' => array('administrator', 'customer')));
    agm_p4_user(98, 'mgr@shop.test', array('roles' => array('shop_manager')), array('paying_customer' => '1'));
    $GLOBALS['agm']['user_caps'][97]['manage_woocommerce'] = true;
    $GLOBALS['agm']['user_caps'][98]['manage_woocommerce'] = true;
    agm_p4_user(99, 'buyer@example.com', array('roles' => array('subscriber')), array('paying_customer' => '1'));
    agm_p4_user(100, 'cust@example.com');
    agm_p4_user(101, 'reader@example.com', array('roles' => array('subscriber')));
    customers_status_server(array());
    customers_reverse_sync();
    $asked = array();
    foreach (agm_http_requests('/api/v1/contacts/status') as $request) {
        $asked = array_merge($asked, $request['body']['emails']);
    }
    expect_same(array('buyer@example.com', 'cust@example.com'), $asked, 'the reverse sync asked about staff or non-customers');
}

function test_customers_a_store_not_connected_asks_nothing() {
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    agm_p4_user(102, 'nc@example.com');
    $html = agm_capture(function () { do_action('woocommerce_register_form'); do_action('register_form'); });
    expect_same('', $html, 'the registration checkbox shows on a store not connected');
    expect_same('', customers_account_form(102), 'the account switch shows on a store not connected');
}

// ── Accounts made after a checkout, and the checkout page ──

function test_customers_an_account_made_on_the_thank_you_page_is_left_to_the_order() {
    $order = agm_p4_order(510, 'tia@example.com', gmdate('Y-m-d H:i:s'), 'processing', array('created_via' => 'store-api'), array('_agumail_optin' => 'yes'));
    $result = agm_request('create an account on the order received page', 'shopper', function () use ($order) {
        $GLOBALS['agm']['page'] = 'order-received';
        agm_input(array(), array('create-account' => '1', 'email' => 'tia@example.com', '_wpnonce' => 'n', 'password' => 'secret-pass'));
        do_action('woocommerce_register_post', 'tia', 'tia@example.com', new WP_Error());
        agm_p4_user(511, 'tia@example.com');
        do_action('user_register', 511, array('user_email' => 'tia@example.com'));
        do_action('woocommerce_created_customer', 511, array('user_email' => 'tia@example.com'), false);
        $order->props['customer_id'] = 511;
        agm_wc_persist($order);
        do_action('woocommerce_update_order', $order->get_id(), $order);
    });
    agm_expect_shopper_quiet($result);
    expect_same(array(), agm_outbox('user'), 'an account made after the checkout was queued; its order carries the contact');
}

function test_customers_the_registration_checkbox_is_not_shown_twice_on_the_checkout() {
    $GLOBALS['agm']['page'] = 'checkout';
    $html = agm_capture(function () { do_action('woocommerce_register_form'); });
    expect_same('', $html, 'the registration checkbox showed on the checkout page, next to the checkout\'s own');
}
