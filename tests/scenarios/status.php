<?php
// The Status section: whether the store is connected and to which account,
// when AguMail last took something, what is waiting and for how long, whether
// sending is paused, the items AguMail refused (with Retry and Discard, one or
// all, through the outbox's own functions), the bulk sync, and the way to the
// log. Every action passes the settings page's capability and nonce checks.

// ── Helpers ──

function status_connected() {
    update_option('agumail_connection', array('status' => 'connected', 'shopName' => 'Hollow Pine', 'storeUrl' => 'shop.test', 'checkedAt' => time()));
}

/** A row AguMail refused: queued, then parked with $error. Returns its id. */
function status_failed_row($type, $key, $error) {
    agumail_enqueue($type, $key);
    $row = agm_store()->find($type, (string) $key);
    agm_store()->park($row['id'], $row['version'], $error, agumail_mysql_time());
    return $row['id'];
}

function status_text($html) {
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}

// ── What it shows ──

function test_status_shows_the_connection_the_queue_and_a_pause() {
    status_connected();
    agm_p4_merchant();
    agumail_enqueue('order', 501);
    agumail_enqueue('contact', 'a@example.com', agumail_contact_payload('a@example.com'));
    agumail_enqueue('contact', 'b@example.com', agumail_contact_payload('b@example.com'));
    status_failed_row('product', 7, 'products.0.price: Expected number');
    agumail_set_backoff_until(time() + 600);

    $text = status_text(agm_p4_section('status'));
    expect_that(strpos($text, 'Hollow Pine') !== false, 'the account is not shown: ' . $text);
    expect_that(strpos($text, 'Connected') !== false, 'the connection is not shown');
    expect_that(preg_match('/Contacts 2 0/', $text) === 1, 'the contacts waiting are not shown: ' . $text);
    expect_that(preg_match('/Orders 1 0/', $text) === 1, 'the orders waiting are not shown: ' . $text);
    expect_that(preg_match('/Products 0 1/', $text) === 1, 'the failed product is not counted: ' . $text);
    expect_that(strpos($text, 'Oldest waiting item') !== false, 'the age of the oldest item is not shown');
    expect_that(strpos($text, 'Sending is paused until') !== false, 'the pause is not shown: ' . $text);
}

function test_status_says_when_nothing_is_waiting_and_the_store_is_not_connected() {
    unset($GLOBALS['agm']['options']['agumail_api_key']);
    agm_p4_merchant();
    $text = status_text(agm_p4_section('status'));
    expect_that(strpos($text, 'Not connected') !== false, 'an unconnected store is not said to be so: ' . $text);
    expect_that(strpos($text, 'Nothing is waiting') !== false, 'an empty queue is not said to be so: ' . $text);
    expect_that(strpos($text, 'Nothing has been sent yet') !== false, 'a store that never sent claims a last sync');
    expect_that(strpos($text, 'Sending is paused') === false, 'a pause is shown with none set');
}

function test_status_lists_failed_items_escaped_with_their_actions() {
    status_connected();
    agm_p4_merchant();
    $id = status_failed_row('order', 777, '<script>alert(1)</script> orders.0.email: Invalid');
    $html = agm_p4_section('status');
    expect_that(strpos($html, '<script>alert(1)') === false, 'an error from AguMail was printed unescaped');
    expect_that(strpos($html, '&lt;script&gt;') !== false, 'the error is not shown');
    expect_that(strpos($html, 'Order #777') !== false, 'the failed order is not named');
    $retry = htmlspecialchars(agumail_admin_action_url('status_retry', array('id' => $id)), ENT_QUOTES);
    expect_that(strpos($html, $retry) !== false, 'the item has no Retry link carrying its id and a nonce');
    $discard = htmlspecialchars(agumail_admin_action_url('status_discard', array('id' => $id, 'version' => 1)), ENT_QUOTES);
    expect_that(strpos($html, $discard) !== false, 'the item has no Discard link carrying its id, version and a nonce');
    foreach (array('status_retry_all', 'status_discard_all') as $action) {
        expect_that(strpos($html, 'value="' . $action . '"') !== false, "the list has no $action button");
    }
}

function test_status_links_to_the_agumail_log() {
    agm_p4_merchant();
    $html = agm_p4_section('status');
    expect_that(strpos($html, htmlspecialchars('https://shop.test/wp-admin/admin.php?page=wc-status&tab=logs&source=agumail', ENT_QUOTES)) !== false,
        'the section does not link to WooCommerce > Status > Logs for source agumail');
}

function test_status_shows_the_last_successful_send() {
    status_connected();
    agumail_enqueue('contact', 'c@example.com', agumail_contact_payload('c@example.com'));
    agm_drain();
    $last = get_option('agumail_last_sent');
    expect_that(is_array($last) && $last['count'] === 1 && $last['endpoint'] === '/api/v1/contacts', 'the send was not recorded: ' . json_encode($last));
    agm_p4_merchant();
    $text = status_text(agm_p4_section('status'));
    expect_that(strpos($text, 'Last successful sync') !== false && strpos($text, 'Nothing has been sent yet') === false, 'the last send is not shown: ' . $text);
}

function test_status_shows_the_bulk_sync() {
    status_connected();
    agm_p4_order(100, 'ann@example.com', '2025-01-05 10:00:00');
    agm_p4_merchant();
    agm_p4_admin_action('bulk_sync_start');
    $text = status_text(agm_p4_section('status'));
    expect_that(strpos($text, 'Store data sync') !== false && strpos($text, 'Running') !== false, 'a running bulk sync is not shown: ' . $text);
    agm_p4_run_bulk();
    $text = status_text(agm_p4_section('status'));
    expect_that(strpos($text, 'Finished') !== false, 'the finished bulk sync is not shown: ' . $text);
}

// ── What it does ──

function test_status_retry_and_discard_one_or_all_through_the_outbox() {
    status_connected();
    agm_p4_merchant();
    $first = status_failed_row('order', 1, 'Boom');
    $second = status_failed_row('order', 2, 'Boom');
    $third = status_failed_row('product', 3, 'Boom');
    agumail_enqueue('contact', 'd@example.com', agumail_contact_payload('d@example.com'));

    agm_p4_admin_action('status_retry', array('id' => $first));
    $row = agm_store()->get($first);
    expect_same('pending', $row['status'], 'Retry did not put the item back in line');
    expect_same(0, $row['attempts'], 'Retry kept the old attempts');

    agm_p4_admin_action('status_discard', array('id' => $second, 'version' => agm_store()->get($second)['version']));
    expect_same(null, agm_store()->get($second), 'Discard kept the item');

    status_failed_row('order', 4, 'Boom');
    agm_p4_admin_action('status_retry_all');
    expect_same(array(), agumail_outbox_failed(), 'Retry all left failed items');

    status_failed_row('order', 5, 'Boom');
    status_failed_row('order', 6, 'Boom');
    agm_p4_admin_action('status_discard_all');
    expect_same(array(), agumail_outbox_failed(), 'Discard all left failed items');
    expect_that(agm_store()->find('contact', 'd@example.com') !== null, 'Discard all removed an item that had not failed');
    expect_that(agm_store()->get($third) !== null, 'Discard all removed a retried item');
}

function test_status_actions_need_the_capability_and_a_nonce() {
    status_connected();
    agm_sign_in(5, 'clerk@shop.test');
    do_action('init');
    $id = status_failed_row('order', 9, 'Boom');
    $forbidden = agm_p4_admin_action('status_discard', array('id' => $id));
    expect_that(strpos((string) $forbidden['halt'], 'wp_die') === 0, 'a user without manage_woocommerce discarded an item');
    expect_that(agm_store()->get($id) !== null, 'the item was discarded anyway');

    agm_grant('manage_woocommerce');
    $GLOBALS['agm']['nonces_ok'] = false;
    $stale = agm_p4_admin_action('status_discard_all');
    expect_that(strpos((string) $stale['halt'], 'wp_die') === 0, 'a discard without a valid nonce ran');
    expect_that(agm_store()->get($id) !== null, 'the item was discarded anyway');
}

// ── Form signups ──

function test_status_names_form_signups_and_who_signed_up_on_which_form() {
    status_connected();
    agm_p4_merchant();
    agumail_signup_enqueue('elementor', array('id' => '12:abc123', 'name' => 'Welcome popup'), array('email' => 'ada@example.com'));
    agumail_signup_enqueue('cf7', array('id' => '7', 'name' => 'Contact & "offers"'), array('email' => 'bea@example.com'));
    $row = agm_store()->find('signup', 'cf7:7:bea@example.com');
    agm_store()->park($row['id'], $row['version'], 'signups.0.email: Invalid email', agumail_mysql_time());

    $html = agm_p4_section('status');
    $text = status_text($html);
    expect_that(preg_match('/Form signups 1 1/', $text) === 1, 'form signups are not counted under their own label: ' . $text);
    expect_that(strpos($text, 'bea@example.com from Contact & "offers"') !== false, 'the failed signup does not say who and which form: ' . $text);
    expect_that(strpos($html, 'Contact &amp; &quot;offers&quot;') !== false, 'the form name was printed unescaped');
}

function test_status_shows_a_send_of_form_signups() {
    status_connected();
    agumail_signup_enqueue('wpforms', array('id' => '42', 'name' => 'Newsletter'), array('email' => 'cy@example.com'));
    agm_drain();
    agm_p4_merchant();
    $text = status_text(agm_p4_section('status'));
    expect_that(strpos($text, '1 form signups') !== false, 'the last send does not say it was form signups: ' . $text);
}

// ── Copy ──

function test_status_the_lane_copy_has_no_em_dashes($plugin_dir) {
    foreach (array('includes/customers.php', 'includes/bulk-sync.php', 'includes/status.php', 'assets/js/admin.js') as $file) {
        $code = (string) @file_get_contents($plugin_dir . '/' . $file);
        expect_that($code !== '', "$file is missing");
        expect_that(strpos($code, "\u{2014}") === false, "$file has an em-dash");
    }
}

function test_status_discard_never_drops_a_row_that_changed_since_the_page_was_drawn() {
    status_connected();
    agm_p4_merchant();
    $id = status_failed_row('user', 7, 'Boom');
    $shown = agm_store()->get($id)['version'];
    // The customer answers again: the row goes back in line with a newer version.
    agumail_enqueue('user', 7, array('answer' => 'yes', 'source' => 'my_account'));
    agm_p4_admin_action('status_discard', array('id' => $id, 'version' => $shown));
    expect_that(agm_store()->get($id) !== null, 'Discard dropped an answer queued after the page was drawn');

    $pending = agm_store()->get($id);
    agm_p4_admin_action('status_discard', array('id' => $id, 'version' => $pending['version']));
    expect_that(agm_store()->get($id) !== null, 'Discard dropped a row that had not failed');
}
