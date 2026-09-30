<?php
// The drain: what it sends, in what order and size, and what each answer from
// AguMail does to the queued rows.
//
// The tests register their own builders under agm_* types, so they stay
// independent of the real ones the feature lanes add.

function drain_setup($now = 1790000000) {
    $GLOBALS['agm']['clock'] = (float) $now;
    agumail_set_clock(function () { return $GLOBALS['agm']['clock']; });
    $GLOBALS['agm']['succeeded'] = array();
    $record = function ($key, $row) { return isset($row['payload']['record']) ? $row['payload']['record'] : null; };
    $contacts = function ($key, $row) { return isset($row['payload']['contacts']) ? $row['payload']['contacts'] : array(); };
    $success = function ($key, $row, $response) { $GLOBALS['agm']['succeeded'][] = $row['type'] . ':' . $key; };
    agumail_register_builder('agm_cart', array('endpoint' => '/api/v1/carts', 'collection' => 'carts', 'phase' => 40, 'build' => $record, 'contacts' => $contacts, 'on_success' => $success));
    agumail_register_builder('agm_order_history', array('endpoint' => '/api/v1/orders', 'collection' => 'orders', 'phase' => 30, 'backfill' => true, 'build' => $record, 'contacts' => $contacts, 'on_success' => $success));
    agumail_register_builder('agm_order', array('endpoint' => '/api/v1/orders', 'collection' => 'orders', 'phase' => 30, 'build' => $record, 'contacts' => $contacts, 'on_success' => $success));
    agumail_register_builder('agm_product', array('endpoint' => '/api/v1/products', 'collection' => 'products', 'phase' => 20, 'build' => $record, 'on_success' => $success));
}

function drain_advance($seconds) {
    $GLOBALS['agm']['clock'] += $seconds;
}

/** Queues an order whose builder names its contact. */
function drain_order($id, $email = 'ada@example.com', $contact = array(), $type = 'agm_order') {
    $contact = array_merge(array('email' => $email, 'tags' => array('woocommerce')), $contact);
    agumail_enqueue($type, $id, array(
        'record'   => array('externalId' => (string) $id, 'email' => $email, 'total' => 10),
        'contacts' => array($contact),
    ));
}

function drain_record($type, $key, array $record) {
    agumail_enqueue($type, $key, array('record' => $record));
}

/** One Action Scheduler run of the drain, in cron. Returns why it stopped. */
function drain_run() {
    $outcome = null;
    agm_request('drain', 'cron', function () use (&$outcome) {
        $outcome = agumail_run_in_background('agumail_drain');
    });
    return $outcome;
}

function drain_paths() {
    return array_map(function ($request) { return $request['path']; }, agm_http_requests());
}

function drain_row($type, $key) {
    foreach (agm_outbox($type) as $row) {
        if ($row['object_key'] === (string) $key) {
            return $row;
        }
    }
    return null;
}

// ── Sending ──

function test_drain_sends_due_rows_and_deletes_them() {
    drain_setup();
    drain_record('agm_product', 11, array('externalId' => '11', 'title' => 'Mug'));
    drain_record('agm_product', 12, array('externalId' => '12', 'title' => 'Candle'));
    expect_same('done', drain_run(), 'the run did not finish');
    $sent = agm_http_requests('/api/v1/products');
    expect_same(1, count($sent), 'expected one request for two products');
    expect_same(array('products' => array(array('externalId' => '11', 'title' => 'Mug'), array('externalId' => '12', 'title' => 'Candle'))), $sent[0]['body'], 'wrong body');
    expect_same(array(), agm_outbox(), 'sent rows were not deleted');
    expect_same(array('agm_product:11', 'agm_product:12'), $GLOBALS['agm']['succeeded'], 'on_success was not called for each row');
}

function test_drain_a_row_that_changed_while_sending_is_sent_again_next_run() {
    drain_setup();
    drain_record('agm_product', 11, array('externalId' => '11', 'title' => 'Mug'));
    agm_http_respond(function () {
        // The merchant saves the product while the request is in flight.
        agumail_enqueue_suppressed(false);
        drain_record('agm_product', 11, array('externalId' => '11', 'title' => 'Big mug'));
        agumail_enqueue_suppressed(true);
        return array('code' => 200, 'body' => array('ok' => true));
    });
    drain_run();
    expect_same(2, drain_row('agm_product', 11)['version'], 'the changed row was deleted');
    drain_run();
    $sent = agm_http_requests('/api/v1/products');
    expect_same('Big mug', $sent[1]['body']['products'][0]['title'], 'the change was not sent');
    expect_same(array(), agm_outbox(), 'the row is still queued');
}

function test_drain_a_builder_returning_null_drops_the_row_unsent() {
    drain_setup();
    agumail_enqueue('agm_product', 99);
    drain_run();
    expect_same(array(), agm_http_requests(), 'a dropped row was sent');
    expect_same(array(), agm_outbox(), 'a dropped row stayed queued');
}

function test_drain_a_contact_queued_with_its_record_is_sent() {
    drain_setup();
    agumail_enqueue('contact', 'Ada@Example.com', agumail_contact_payload('Ada@Example.com', array('opted_in' => true, 'source' => 'my_account')));
    drain_run();
    $sent = agm_http_requests('/api/v1/contacts');
    expect_same(array('contacts' => array(array('email' => 'ada@example.com', 'tags' => array('woocommerce'), 'consent' => array('optedIn' => true, 'source' => 'my_account')))), $sent[0]['body'], 'wrong contact sent');
}

function test_drain_sends_in_phase_order_and_keeps_history_apart() {
    drain_setup();
    drain_record('agm_cart', 'wc-1', array('externalId' => 'wc-1'));
    drain_order(3, 'old@example.com', array(), 'agm_order_history');
    drain_order(2);
    drain_record('agm_product', 11, array('externalId' => '11'));
    agumail_enqueue('contact', 'sub@example.com', array('email' => 'sub@example.com'));
    drain_run();
    expect_same(array('/api/v1/contacts', '/api/v1/products', '/api/v1/contacts', '/api/v1/orders', '/api/v1/contacts', '/api/v1/orders', '/api/v1/carts'), drain_paths(), 'wrong order');
    $requests = agm_http_requests();
    expect_that(!isset($requests[3]['body']['backfill']) && $requests[3]['body']['orders'][0]['externalId'] === '2', 'the live order should go first, without backfill');
    expect_same(true, isset($requests[4]['body']['backfill']) ? $requests[4]['body']['backfill'] : null, 'the history order\'s contact did not say backfill');
    expect_same(true, isset($requests[5]['body']['backfill']) ? $requests[5]['body']['backfill'] : null, 'the history order did not say backfill');
    expect_same('3', $requests[5]['body']['orders'][0]['externalId'], 'the history order was mixed with the live one');
}

// ── Contacts first ──

function test_drain_sends_each_batch_contacts_first_merged_by_email() {
    drain_setup();
    drain_order(1, 'ada@example.com', array('consent' => array('optedIn' => false, 'source' => 'checkout'), 'firstName' => 'Ada'));
    drain_order(2, 'ADA@example.com', array('consent' => array('optedIn' => true, 'source' => 'checkout')));
    drain_order(3, 'ada@example.com', array('lastName' => 'Lovelace'));
    drain_order(4, 'bea@example.com', array('consent' => array('optedIn' => true, 'source' => 'checkout')));
    drain_order(5, 'bea@example.com', array('unsubscribed' => true));
    drain_run();
    expect_same(array('/api/v1/contacts', '/api/v1/orders'), drain_paths(), 'contacts did not go first, in one request');
    $contacts = agm_http_requests('/api/v1/contacts')[0]['body']['contacts'];
    expect_same(2, count($contacts), 'contacts were not merged by email');
    expect_same(array('email' => 'ada@example.com', 'tags' => array('woocommerce'), 'consent' => array('optedIn' => true, 'source' => 'checkout'), 'firstName' => 'Ada', 'lastName' => 'Lovelace'), $contacts[0], 'the opt-in should win over a no and over no answer, keeping the names');
    expect_that(!empty($contacts[1]['unsubscribed']) && !isset($contacts[1]['consent']), 'an unsubscribe should win over an opt-in');
}

function test_drain_merge_rules() {
    $capture = array('email' => 'a@example.com');
    $no = array('email' => 'a@example.com', 'consent' => array('optedIn' => false));
    $yes = array('email' => 'a@example.com', 'consent' => array('optedIn' => true));
    $stop = array('email' => 'a@example.com', 'unsubscribed' => true);
    $cases = array(
        array(array($yes, $capture), $yes),
        array(array($capture, $yes), $yes),
        array(array($no, $capture), $no),
        array(array($capture, $no), $no),
        array(array($no, $yes), $yes),
        array(array($yes, $stop), $stop),
        array(array($stop, $yes, $capture), $stop),
    );
    foreach ($cases as $i => $case) {
        $merged = agumail_merge_contacts($case[0]);
        $winner = $merged[0];
        unset($winner['tags']);
        expect_same($case[1], $winner, "merge case $i");
    }
    $earliest = agumail_merge_contacts(array(
        array('email' => 'a@example.com', 'customerSince' => '2025-03-01T00:00:00Z'),
        array('email' => 'a@example.com', 'customerSince' => '2024-01-01T00:00:00Z'),
    ));
    expect_same('2024-01-01T00:00:00Z', $earliest[0]['customerSince'], 'customerSince should keep the earliest date');
}

function test_drain_an_older_tick_never_overtakes_a_newer_unsubscribe_in_the_same_run() {
    drain_setup();
    agumail_enqueue('contact', 'ada@example.com', agumail_contact_payload('ada@example.com', array('unsubscribed' => true)));
    drain_order(1, 'ada@example.com', array('consent' => array('optedIn' => true, 'source' => 'checkout'), 'consentedAt' => '2026-09-14T10:00:00Z', 'firstName' => 'Ada'));
    drain_run();
    $contacts = agm_http_requests('/api/v1/contacts');
    expect_same(2, count($contacts), 'expected the queued contact, then the order\'s contact');
    $inline = $contacts[1]['body']['contacts'][0];
    expect_that(!isset($inline['consent']) && !empty($inline['unsubscribed']), 'a week-old tick on an order was sent after a newer unsubscribe: ' . json_encode($inline));
    expect_same('Ada', $inline['firstName'], 'the order\'s contact lost its name');
}

function test_drain_a_newer_tick_still_counts_after_an_older_unsubscribe_in_the_same_run() {
    drain_setup(1790000000 - 3 * DAY_IN_SECONDS);
    agumail_enqueue('contact', 'ada@example.com', agumail_contact_payload('ada@example.com', array('unsubscribed' => true)));
    drain_setup(1790000000);
    drain_order(1, 'ada@example.com', array('consent' => array('optedIn' => true, 'source' => 'checkout'), 'consentedAt' => gmdate('Y-m-d\TH:i:s\Z', 1790000000)));
    drain_run();
    $inline = agm_http_requests('/api/v1/contacts')[1]['body']['contacts'][0];
    expect_same(array('optedIn' => true, 'source' => 'checkout'), isset($inline['consent']) ? $inline['consent'] : null, 'a tick given after the unsubscribe was dropped');
}

function test_drain_an_order_whose_contact_was_refused_is_never_sent() {
    drain_setup();
    drain_order(1, 'ada@example.com');
    drain_order(2, 'not-an-email');
    drain_order(3, 'cy@example.com');
    agm_http_respond(400, array('ok' => false, 'error' => 'contacts.1.email: Invalid email'));
    drain_run();
    expect_same(array('/api/v1/contacts', '/api/v1/contacts', '/api/v1/orders'), drain_paths(), 'wrong requests');
    expect_same(array('ada@example.com', 'cy@example.com'), array_column(agm_http_requests('/api/v1/contacts')[1]['body']['contacts'], 'email'), 'the good contacts were not sent again without the bad one');
    expect_same(array('1', '3'), array_column(agm_http_requests('/api/v1/orders')[0]['body']['orders'], 'externalId'), 'an order whose contact was refused was sent');
    $parked = drain_row('agm_order', 2);
    expect_same('failed', $parked['status'], 'the order with the refused contact was not parked');
    expect_same('AguMail refused its contact: contacts.1.email: Invalid email', $parked['last_error'], 'wrong reason');
}

function test_drain_when_contacts_cannot_be_delivered_no_order_is_sent() {
    drain_setup();
    drain_order(1);
    drain_order(2, 'bea@example.com');
    agm_http_respond(503, array('ok' => false, 'error' => 'Down for maintenance'));
    expect_same('stopped', drain_run(), 'the run should stop');
    expect_same(array('/api/v1/contacts'), drain_paths(), 'orders went out although their contacts did not');
    foreach (array(1, 2) as $id) {
        $row = drain_row('agm_order', $id);
        expect_same(array('pending', 1), array($row['status'], $row['attempts']), "order $id should wait with one attempt counted");
    }
}

// ── 400: the record is refused ──

function test_drain_a_400_naming_a_record_parks_just_that_record() {
    drain_setup();
    foreach (array(1, 2, 3) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    agm_http_respond(400, array('ok' => false, 'error' => 'products.1.title: Required'));
    drain_run();
    $sent = agm_http_requests('/api/v1/products');
    expect_same(2, count($sent), 'the rest of the batch was not sent again');
    expect_same(array('1', '3'), array_column($sent[1]['body']['products'], 'externalId'), 'the resend should leave out the refused record');
    expect_same(array(array('2', 'failed', 'products.1.title: Required', 0)), array_map(function ($row) {
        return array($row['object_key'], $row['status'], $row['last_error'], $row['attempts']);
    }, agm_outbox()), 'only the refused record should stay, parked');
}

function test_drain_a_400_about_the_request_itself_defers_the_batch() {
    foreach (array('backfill: Expected boolean, received string', 'Invalid payload.') as $error) {
        $GLOBALS['agm']['http'] = array();
        drain_setup();
        agm_store()->outbox = array();
        foreach (range(1, 4) as $id) {
            drain_record('agm_product', $id, array('externalId' => (string) $id));
        }
        agm_http_respond(400, array('ok' => false, 'error' => $error));
        drain_run();
        expect_same(1, count(agm_http_requests()), "\"$error\" should not be retried record by record");
        foreach (agm_outbox() as $row) {
            expect_same(array('pending', 1, $error), array($row['status'], $row['attempts'], $row['last_error']), "\"$error\" is about the request, so its records should wait and count an attempt, not be parked");
        }
    }
}

function test_drain_a_400_about_the_batch_as_a_whole_splits_it_and_a_single_record_is_deferred() {
    drain_setup();
    foreach (range(1, 5) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    agm_http_default(function ($url, $args) {
        $body = json_decode($args['body'], true);
        return count($body['products']) > 2
            ? array('code' => 400, 'body' => array('ok' => false, 'error' => 'products: Array must contain at most 2 element(s)'))
            : array('code' => 200, 'body' => array('ok' => true));
    });
    drain_run();
    expect_same(array(), agm_outbox(), 'halving the batch did not get every record through');

    drain_record('agm_product', 9, array('externalId' => '9'));
    agm_http_default(function () {
        return array('code' => 400, 'body' => array('ok' => false, 'error' => 'products: Expected array, received object'));
    });
    drain_run();
    $row = drain_row('agm_product', 9);
    expect_same(array('pending', 1), array($row['status'], $row['attempts']), 'a lone record refused for the batch as a whole should wait, not be parked');
}

function test_drain_a_413_splits_the_batch_and_parks_a_record_too_large_alone() {
    drain_setup();
    foreach (range(1, 4) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id, 'description' => str_repeat('x', $id === 3 ? 3000 : 100)));
    }
    agm_http_default(function ($url, $args) {
        return strlen($args['body']) > 2500
            ? array('code' => 413, 'body' => 'Request Entity Too Large')
            : array('code' => 200, 'body' => array('ok' => true));
    });
    drain_run();
    $left = agm_outbox();
    expect_same(1, count($left), 'the records that fit were not sent');
    expect_same(array('3', 'failed'), array($left[0]['object_key'], $left[0]['status']), 'a record too large on its own should be parked');
}

function test_drain_a_429_with_a_date_waits_until_then() {
    drain_setup();
    drain_record('agm_product', 1, array('externalId' => '1'));
    agm_http_respond(429, array('ok' => false, 'error' => 'Rate limit'), array('Retry-After' => gmdate('D, d M Y H:i:s', 1790000090) . ' GMT'));
    drain_run();
    expect_same(1790000090, agumail_backoff_until(), 'a Retry-After date was not honoured');
}

// ── 401, 402, 403: the key or plan is refused ──

function test_drain_a_refused_key_or_plan_pauses_everything_without_counting_attempts() {
    foreach (array(401 => 'Invalid or revoked API key.', 402 => 'Store sync is not included in your plan.', 403 => 'Forbidden') as $code => $error) {
        $GLOBALS['agm']['http'] = array();
        drain_setup();
        agm_store()->outbox = array();
        delete_option('agumail_connection');
        agumail_clear_backoff();
        drain_record('agm_product', 1, array('externalId' => '1'));
        drain_order(2);
        agm_http_respond($code, array('ok' => false, 'error' => $error));
        expect_same('stopped', drain_run(), "a $code should stop the run");
        expect_same(1, count(agm_http_requests()), "a $code should stop at once");
        $connection = agumail_connection();
        expect_same(array('error', $error, $code), array($connection['status'], $connection['error'], $connection['errorCode']), "the $code was not recorded for the settings page");
        expect_same(1790000000 + 15 * MINUTE_IN_SECONDS, agumail_backoff_until(), "a $code should pause sending for 15 minutes");
        foreach (agm_outbox() as $row) {
            expect_same(array('pending', 0), array($row['status'], $row['attempts']), "a $code must not count attempts");
        }
        expect_same('backoff', drain_run(), "the next run should wait out the pause");
        expect_same(1, count(agm_http_requests()), 'a run during the pause sent something');
        drain_advance(15 * MINUTE_IN_SECONDS + 1);
        drain_run();
        expect_same('connected', agumail_connection()['status'], 'a successful send should clear the refusal');
        expect_same(array(), agm_outbox(), 'the rows were not sent once the pause ended');
    }
}

// ── 429: slow down ──

function test_drain_a_429_waits_for_retry_after() {
    drain_setup();
    drain_record('agm_product', 1, array('externalId' => '1'));
    agm_http_respond(429, array('ok' => false, 'error' => 'Rate limit', 'retryAfter' => 30), array('Retry-After' => '7'));
    expect_same('stopped', drain_run(), 'a 429 should stop the run');
    expect_same(1790000007, agumail_backoff_until(), 'the pause should follow Retry-After');
    expect_same(0, drain_row('agm_product', 1)['attempts'], 'a 429 must not count an attempt');
    drain_advance(5);
    expect_same('backoff', drain_run(), 'a run before Retry-After should wait');
    expect_same(1, count(agm_http_requests()), 'a run before Retry-After sent something');
    drain_advance(3);
    drain_run();
    expect_same(array(), agm_outbox(), 'the row was not sent after Retry-After');

    drain_record('agm_product', 2, array('externalId' => '2'));
    agm_http_respond(429, array('ok' => false, 'error' => 'Rate limit', 'retryAfter' => 12));
    drain_run();
    expect_same(agumail_time() + 12, agumail_backoff_until(), 'without the header, the body\'s retryAfter should count');
}

// ── 5xx and network failures ──

function test_drain_failures_back_off_exponentially_and_park_after_twenty_attempts() {
    drain_setup();
    drain_record('agm_product', 1, array('externalId' => '1'));
    agm_http_respond(500, array('ok' => false, 'error' => 'Internal error'));
    expect_same('stopped', drain_run(), 'a 500 should stop the run');
    $row = drain_row('agm_product', 1);
    $wait = strtotime($row['available_at'] . ' UTC') - agumail_time();
    expect_same(array('pending', 1, 'Internal error'), array($row['status'], $row['attempts'], $row['last_error']), 'wrong row after a 500');
    expect_that($wait >= 24 && $wait <= 30, "the first retry should come after about 30 seconds, not $wait");

    expect_same('idle', drain_run(), 'a run before the retry time should find nothing due');
    expect_same(1, count(agm_http_requests()), 'the row was retried too early');

    drain_advance(31);
    agm_http_respond(new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'));
    drain_run();
    $row = drain_row('agm_product', 1);
    $wait = strtotime($row['available_at'] . ' UTC') - agumail_time();
    expect_same(2, $row['attempts'], 'a network failure should count an attempt');
    expect_that($wait >= 48 && $wait <= 60, "the second retry should come after about a minute, not $wait");
    expect_same('cURL error 28: Operation timed out', $row['last_error'], 'wrong reason');

    agm_store()->outbox[$row['id']]['attempts'] = 19;
    drain_advance(61);
    agm_http_respond(502, 'Bad gateway');
    drain_run();
    $row = drain_row('agm_product', 1);
    expect_same(array('failed', 20), array($row['status'], $row['attempts']), 'the row should park after 20 attempts');
}

function test_drain_retry_jitter_comes_from_wp_rand() {
    $GLOBALS['agm']['wp_rand_calls'] = 0;
    $GLOBALS['agm']['wp_rand'] = array(0, 1000, 500);
    expect_same(array(120, 96, 108), array(agumail_retry_delay(3), agumail_retry_delay(3), agumail_retry_delay(3)),
        'the third retry should wait 120 s less up to a fifth, as wp_rand() decides');
    expect_same(3, $GLOBALS['agm']['wp_rand_calls'], 'the jitter did not come from wp_rand()');
}

function test_drain_retry_delay_doubles_and_stops_at_six_hours() {
    foreach (array(1 => 30, 2 => 60, 3 => 120, 10 => 15360, 11 => 21600, 19 => 21600) as $attempts => $full) {
        for ($i = 0; $i < 20; $i++) {
            $delay = agumail_retry_delay($attempts);
            expect_that($delay <= $full && $delay >= (int) floor($full * 0.8), "attempt $attempts waited $delay, expected up to $full");
        }
    }
}

// ── Lock ──

function test_drain_the_lock_keeps_two_runs_apart() {
    drain_setup();
    drain_record('agm_product', 1, array('externalId' => '1'));
    update_option('agumail_drain_lock', 'other-run|' . (agumail_time() + 60));
    expect_same('locked', drain_run(), 'a run went ahead while another held the lock');
    expect_same(array(), agm_http_requests(), 'a locked-out run sent something');

    update_option('agumail_drain_lock', 'crashed-run|' . (agumail_time() - 1));
    expect_same('done', drain_run(), 'an expired lock was not taken over');
    expect_same(false, get_option('agumail_drain_lock'), 'the lock was not released after the run');

    drain_record('agm_product', 2, array('externalId' => '2'));
    agm_http_respond(function () { throw new RuntimeException('fatal in a filter'); });
    try {
        drain_run();
    } catch (RuntimeException $e) {
        // Action Scheduler records the failed action.
    }
    expect_same(false, get_option('agumail_drain_lock'), 'a run that threw kept the lock');
    expect_that(!agumail_enqueue_suppressed(), 'a run that threw left queueing switched off');
}

// ── Batch size and time ──

function test_drain_requests_wait_up_to_a_minute_and_never_follow_redirects() {
    drain_setup();
    drain_record('agm_product', 1, array('externalId' => '1'));
    drain_run();
    $sent = agm_http_requests()[0];
    expect_same(60, $sent['timeout'], 'the drain should give AguMail a minute to ingest a batch');
    expect_same(0, $sent['args']['redirection'], 'the drain followed redirects');
}

function test_drain_batch_sizes_follow_each_builder() {
    drain_setup();
    agumail_register_builder('agm_big', array('endpoint' => '/api/v1/products', 'collection' => 'products', 'phase' => 20, 'batch' => 1000,
        'build' => function ($key) { return array('externalId' => $key); }));
    agumail_register_builder('agm_small', array('endpoint' => '/api/v1/products', 'collection' => 'products', 'phase' => 20, 'batch' => 7,
        'build' => function ($key) { return array('externalId' => $key); }));
    foreach (range(1, 600) as $id) {
        agumail_enqueue('contact', "c$id@example.com", array('email' => "c$id@example.com"));
        drain_record('agm_product', $id, array('externalId' => (string) $id));
        agumail_enqueue('agm_big', $id);
    }
    foreach (range(1, 250) as $id) {
        drain_order($id, "o$id@example.com");
        drain_record('agm_cart', "wc-$id", array('externalId' => "wc-$id"));
    }
    foreach (range(1, 10) as $id) {
        agumail_enqueue('agm_small', $id);
    }
    agm_http_default(function () { return array('code' => 200, 'body' => array('ok' => true)); });
    drain_run();
    $sizes = array();
    foreach (agm_http_requests() as $request) {
        $collection = array_keys(array_diff_key($request['body'], array('backfill' => 1)))[0];
        $sizes[$collection][] = count($request['body'][$collection]);
    }
    expect_that(max($sizes['contacts']) === 250, 'contacts should go 250 at a time, not ' . max($sizes['contacts']));
    expect_that(max($sizes['orders']) === 100 && count($sizes['orders']) === 3, 'orders should go 100 at a time: ' . implode(',', $sizes['orders']));
    expect_that(max($sizes['carts']) === 100, 'carts should go 100 at a time, not ' . max($sizes['carts']));
    expect_that(in_array(250, $sizes['products'], true), 'products should go 250 at a time');
    expect_that(max($sizes['products']) === 500, 'a builder may not ask for more than 500 records a request');
    expect_that(in_array(7, $sizes['products'], true) && in_array(3, $sizes['products'], true), 'a builder\'s own batch size was ignored');
    expect_same(array(), agm_outbox(), 'rows are left');
}

function test_drain_a_timeout_halves_that_types_batch() {
    drain_setup();
    foreach (range(1, 600) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    agm_http_respond(new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received'));
    drain_run();
    expect_same(250, count(agm_http_requests()[0]['body']['products']), 'the first batch should be the full 250');
    drain_advance(40);
    drain_run();
    $second = agm_http_requests()[1]['body']['products'];
    expect_same(125, count($second), 'after a timeout the next batch of that type should be half the size');
    drain_advance(DAY_IN_SECONDS + 60);
    foreach (range(601, 900) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    $GLOBALS['agm']['http'] = array();
    drain_run();
    expect_same(250, count(agm_http_requests()[0]['body']['products']), 'the smaller batch should not last for ever');
}

function test_drain_checks_its_time_budget_while_building() {
    drain_setup();
    agumail_register_builder('agm_product', array('endpoint' => '/api/v1/products', 'collection' => 'products', 'phase' => 20,
        'build' => function ($key) {
            drain_advance(5);
            return array('externalId' => $key);
        },
    ));
    foreach (range(1, 10) as $id) {
        agumail_enqueue('agm_product', $id);
    }
    expect_same('budget', drain_run(), 'the run should stop for time while building');
    expect_that($GLOBALS['agm']['clock'] - 1790000000 <= 25, 'the run kept building long after its budget');
    expect_same(1, count(agm_scheduled('agumail_drain_now')), 'another run was not scheduled');
}

function test_drain_stops_when_memory_runs_low_and_asks_for_another_run() {
    drain_setup();
    foreach (range(1, 10) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    add_filter('agumail_drain_memory_limit', function () { return (int) (memory_get_usage(true) / 0.8) - 1; });
    expect_same('budget', drain_run(), 'the run should stop when memory runs low');
    expect_same(array(), agm_http_requests(), 'a run short of memory kept sending');
    expect_same(1, count(agm_scheduled('agumail_drain_now')), 'another run was not scheduled');
}

function test_drain_flushes_the_runtime_cache_after_each_batch() {
    drain_setup();
    foreach (range(1, 600) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    $GLOBALS['agm']['runtime_flushes'] = 0;
    drain_run();
    expect_that($GLOBALS['agm']['runtime_flushes'] >= 3, 'the runtime cache was flushed ' . $GLOBALS['agm']['runtime_flushes'] . ' times for three batches');
}

function test_drain_leaves_the_runtime_cache_alone_when_the_object_cache_cannot_flush_it() {
    drain_setup();
    foreach (range(1, 600) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    $GLOBALS['agm']['cache_unsupported'] = array('flush_runtime');
    $GLOBALS['agm']['runtime_flushes'] = 0;
    drain_run();
    expect_same(0, $GLOBALS['agm']['runtime_flushes'], 'the drain flushed a runtime cache the object cache cannot flush');
    expect_same(array(), agm_outbox(), 'rows are left');
}

function test_drain_takes_no_lock_when_nothing_is_due() {
    drain_setup();
    expect_same('idle', drain_run(), 'an empty queue should end the run at once');
    expect_same(array(), agm_writes(), 'an empty queue still took the lock');
    agumail_cart_save('wc-1', array('token' => str_repeat('a', 32)));
    expect_that(drain_run() !== 'idle', 'a changed cart is work to do');
}

function test_drain_a_follow_up_run_that_runs_out_of_time_schedules_the_next() {
    drain_setup();
    foreach (range(1, 3000) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    agm_http_default(function () {
        drain_advance(7);
        return array('code' => 200, 'body' => array('ok' => true));
    });
    drain_run();
    expect_same(1, count(agm_scheduled('agumail_drain_now')), 'the first follow-up was not scheduled');
    agm_request('async queue runner', 'cron', function () {
        agm_run_actions('agumail_drain_now');
    });
    expect_same(1, count(agm_scheduled('agumail_drain_now')), 'a follow-up that ran out of time did not schedule the next, because it counted itself');
}

function test_drain_a_builder_may_ask_for_up_to_five_hundred_records_a_request() {
    drain_setup();
    agumail_register_builder('agm_product', array('endpoint' => '/api/v1/products', 'collection' => 'products', 'phase' => 20, 'batch' => 500,
        'build' => function ($key, $row) { return $row['payload']['record']; }));
    foreach (range(1, 1200) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    drain_run();
    expect_same(array(500, 500, 200), array_map(function ($request) { return count($request['body']['products']); }, agm_http_requests()), 'wrong batch sizes');
    expect_same(array(), agm_outbox(), 'rows are left');
}

function test_drain_batches_stay_under_four_megabytes() {
    drain_setup();
    foreach (range(1, 10) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id, 'description' => str_repeat('x', 900 * 1024)));
    }
    drain_record('agm_product', 11, array('externalId' => '11', 'description' => str_repeat('x', 5 * 1024 * 1024)));
    drain_run();
    $requests = agm_http_requests();
    foreach ($requests as $request) {
        expect_that(strlen($request['raw']) <= 4 * 1024 * 1024, 'a request carried ' . strlen($request['raw']) . ' bytes');
    }
    expect_same(10, array_sum(array_map(function ($request) { return count($request['body']['products']); }, $requests)), 'not every record was sent');
    expect_that(count($requests) >= 3, 'ten records of 900 KB need at least three requests');
    $left = agm_outbox();
    expect_same(array('11', 'failed'), array($left[0]['object_key'], $left[0]['status']), 'a record too large for any request should be parked');
}

function test_drain_stops_after_its_time_budget_and_asks_for_another_run() {
    drain_setup();
    foreach (range(1, 1000) as $id) {
        drain_record('agm_product', $id, array('externalId' => (string) $id));
    }
    for ($i = 0; $i < 10; $i++) {
        agm_http_respond(function () {
            drain_advance(7);
            return array('code' => 200, 'body' => array('ok' => true));
        });
    }
    expect_same('budget', drain_run(), 'the run should stop for time');
    expect_same(3, count(agm_http_requests()), 'the run should stop once 20 seconds have gone');
    expect_same(1, count(agm_scheduled('agumail_drain_now')), 'another run was not scheduled');
    drain_run();
    expect_same(1, count(agm_scheduled('agumail_drain_now')), 'another run was scheduled twice');

    agm_request('queue runner', 'cron', function () {
        agm_run_actions('agumail_drain_now');
    });
    expect_same(array(), agm_outbox(), 'the follow-up run did not finish the queue');
}

// ── The drain's own writes, and carts ──

function test_drain_changes_made_while_draining_queue_nothing() {
    drain_setup();
    agumail_register_builder('agm_order', array('endpoint' => '/api/v1/orders', 'collection' => 'orders', 'phase' => 30,
        'build' => function ($key, $row) { return array('externalId' => $key); },
        'contacts' => function () { return array(array('email' => 'ada@example.com')); },
        'on_success' => function ($key) {
            // Marking the order synced saves it, which fires the hooks that queue orders.
            $GLOBALS['agm']['queued_during_drain'] = agumail_enqueue('agm_order', $key);
        },
    ));
    agumail_enqueue('agm_order', 7);
    drain_run();
    expect_same(false, $GLOBALS['agm']['queued_during_drain'], 'the drain queued its own change');
    expect_same(array(), agm_outbox(), 'the order came back into the outbox');
    expect_that(agumail_enqueue('agm_order', 8), 'queueing stayed off after the drain');
}

function test_drain_moves_changed_carts_into_the_outbox_first() {
    drain_setup();
    add_action('agumail_drain_move_carts', 'agumail_carts_move_dirty_to_outbox');
    agumail_register_builder('cart', array('endpoint' => '/api/v1/carts', 'collection' => 'carts', 'phase' => 40,
        'build' => function ($key) {
            $cart = agumail_cart_get($key);
            return array('externalId' => $key, 'email' => $cart['email'], 'total' => $cart['total']);
        },
        'contacts' => function ($key) { return array(array('email' => agumail_cart_get($key)['email'])); },
    ));
    agumail_cart_save('wc-1', array('token' => str_repeat('a', 32), 'email' => 'ada@example.com', 'total' => 30));
    drain_run();
    $sent = agm_http_requests('/api/v1/carts')[0]['body']['carts'];
    expect_same(array('wc-1', 'ada@example.com'), array($sent[0]['externalId'], $sent[0]['email']), 'the changed cart was not sent');
    expect_that((float) $sent[0]['total'] === 30.0, 'the cart total was lost');
    expect_same(1, agumail_cart_get('wc-1')['queued_version'], 'the queued version was not recorded');
    drain_run();
    expect_same(1, count(agm_http_requests('/api/v1/carts')), 'an unchanged cart was sent again');
}

function test_drain_a_builder_that_throws_is_retried_later_and_the_rest_go() {
    drain_setup();
    agumail_register_builder('agm_product', array('endpoint' => '/api/v1/products', 'collection' => 'products', 'phase' => 20,
        'build' => function ($key) {
            if ($key === '2') {
                throw new RuntimeException('Database went away');
            }
            return array('externalId' => $key);
        },
    ));
    foreach (array(1, 2, 3) as $id) {
        agumail_enqueue('agm_product', $id);
    }
    drain_run();
    expect_same(array('1', '3'), array_column(agm_http_requests('/api/v1/products')[0]['body']['products'], 'externalId'), 'the other rows were not sent');
    $row = drain_row('agm_product', 2);
    expect_same(array('pending', 1, 'Database went away'), array($row['status'], $row['attempts'], $row['last_error']), 'a row that could not be built should wait and count an attempt');
    expect_same(1, count(agm_logs('Could not build agm_product 2, will try again: Database went away')), 'the log line does not say what happens next');

    agm_store()->outbox[$row['id']]['attempts'] = 19;
    agm_store()->outbox[$row['id']]['available_at'] = agumail_mysql_time();
    drain_run();
    expect_same('failed', drain_row('agm_product', 2)['status'], 'a row that never builds should park after 20 attempts');
    expect_same(1, count(agm_logs('Could not build agm_product 2, set aside after 20 attempts')), 'parking was not logged');
}

function test_drain_a_builder_for_a_record_that_needs_a_contact_must_name_it() {
    expect_that(!agumail_register_builder('agm_event', array('endpoint' => '/api/v1/events', 'collection' => 'events', 'phase' => 30,
        'build' => function ($key) { return array('email' => 'ada@example.com'); })), 'an events builder without contacts was accepted');
    expect_same(null, agumail_builder('agm_event'), 'the refused builder was registered');
    expect_that(agumail_register_builder('agm_catalogue', array('endpoint' => '/api/v1/products', 'collection' => 'products',
        'build' => function ($key) { return array('externalId' => $key); })), 'a products builder needs no contacts');
    // AguMail makes a signup's contact itself, as a signup form does; the contacts sync would tag a subscriber again.
    expect_that(agumail_register_builder('agm_signup', array('endpoint' => '/api/v1/signups', 'collection' => 'signups',
        'build' => function ($key) { return array('email' => 'ada@example.com'); })), 'a signups builder needs no contacts');
}

function test_drain_a_400_naming_a_signup_parks_just_that_signup() {
    drain_setup();
    agumail_register_builder('agm_signup', array('endpoint' => '/api/v1/signups', 'collection' => 'signups', 'phase' => 15,
        'build' => function ($key, $row) { return $row['payload']['record']; }));
    foreach (array('a', 'b', 'c', 'd') as $who) {
        drain_record('agm_signup', $who, array('email' => $who . '@example.com'));
    }
    agm_http_respond(400, array('ok' => false, 'error' => 'signups.2.email: Invalid email'));
    drain_run();
    $sent = agm_http_requests('/api/v1/signups');
    expect_same(2, count($sent), 'the rest of the batch was not sent again');
    expect_same(array('a@example.com', 'b@example.com', 'd@example.com'), array_column($sent[1]['body']['signups'], 'email'), 'the resend should leave out the refused signup');
    expect_same(array(), agm_http_requests('/api/v1/contacts'), 'a signup sent contacts first');
    expect_same(array(array('c', 'failed', 'signups.2.email: Invalid email')), array_map(function ($row) {
        return array($row['object_key'], $row['status'], $row['last_error']);
    }, agm_outbox()), 'only the refused signup should stay, parked');
}

function test_drain_clamps_count_utf16_units_like_the_server() {
    $emoji = "\u{1F600}";
    expect_same(str_repeat($emoji, 50), agumail_clamp(str_repeat($emoji, 51), 100), 'an emoji is two units to the server');
    expect_same(str_repeat('a', 99), agumail_clamp(str_repeat('a', 99) . $emoji, 100), 'a clamp split an emoji or went over the limit');
    expect_same(str_repeat("\u{00E9}", 100), agumail_clamp(str_repeat("\u{00E9}", 100), 100), 'an accented letter is one unit');
    expect_same(100, strlen(agumail_clamp(str_repeat('b', 150), 100)), 'plain text was not cut at the limit');
}

// ── When the drain does nothing ──

function test_drain_waits_until_the_store_is_ready_connected_and_not_paused() {
    drain_setup();
    drain_record('agm_product', 1, array('externalId' => '1'));

    delete_option('agumail_db_version');
    expect_same('not_ready', drain_run(), 'ran before the tables exist');
    update_option('agumail_db_version', AGUMAIL_DB_VERSION);

    update_option('agumail_connection', array('status' => 'disconnected'));
    expect_same('paused', drain_run(), 'ran for a store AguMail disconnected');
    delete_option('agumail_connection');

    add_filter('agumail_drain_paused', '__return_true');
    expect_same('paused', drain_run(), 'the pause filter was ignored');
    remove_filter('agumail_drain_paused', '__return_true');

    delete_option('agumail_api_key');
    expect_same('paused', drain_run(), 'ran without a key');
    expect_same(array(), agm_http_requests(), 'something was sent');
}

function test_drain_fired_from_a_shopper_request_sends_nothing() {
    drain_setup();
    drain_record('agm_product', 1, array('externalId' => '1'));
    $result = agm_request('storefront page', 'shopper', function () {
        do_action('agumail_drain');
    });
    expect_same(array(), $result['http'], 'the drain called AguMail from a storefront request');
    expect_same(array(), $result['writes'], 'the drain took its lock or wrote from a storefront request');
    expect_same(array('pending', 0), array(drain_row('agm_product', 1)['status'], drain_row('agm_product', 1)['attempts']), 'the refused run touched the row');
}

function test_drain_runs_as_the_scheduled_action() {
    drain_setup();
    agumail_schedule();
    drain_record('agm_product', 1, array('externalId' => '1'));
    agm_request('queue runner', 'cron', function () {
        agm_run_actions('agumail_drain');
    });
    expect_same(1, count(agm_http_requests('/api/v1/products')), 'the scheduled drain did not send');
    expect_same(1, count(agm_scheduled('agumail_drain')), 'the recurring drain was lost');
}
