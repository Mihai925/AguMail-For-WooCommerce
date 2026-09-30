<?php
// Orders: which reach AguMail and when, what the record says, and what the
// shopper's checkout costs. An order is sent once it is paid, and again when
// what it is worth changes; the record is built when the drain sends it.

function orders_catalogue() {
    return array(
        'mug'    => agm_catalogue_product(11, array('name' => 'Mug', 'price' => '30', 'regular_price' => '30', 'sku' => 'MUG-1', 'manage_stock' => true, 'stock_quantity' => 5)),
        'candle' => agm_catalogue_product(12, array('name' => 'Candle', 'price' => '12', 'regular_price' => '12', 'sku' => 'CAN-1')),
        'tee'    => agm_catalogue_product(20, array('name' => 'Tee', 'price' => '15', 'regular_price' => '15', 'sku' => 'TEE'), 'Agm_Variable_Product'),
        'blue'   => agm_catalogue_product(21, array('name' => 'Tee - Blue', 'price' => '15', 'regular_price' => '15', 'sku' => 'TEE-BLUE', 'parent_id' => 20, 'manage_stock' => true, 'stock_quantity' => 3), 'Agm_Variation_Product'),
    );
}

/** A paid order already in the store, as a setup step (no hooks fire). */
function orders_paid($id, array $props = array(), array $lines = null) {
    if (!wc_get_product(11)) {
        orders_catalogue();
    }
    return agm_shop_order($id, array_merge(array('status' => 'processing', 'date_paid' => agm_date(time())), $props), $lines === null ? array(array(wc_get_product(11), 1)) : $lines);
}

/** An order AguMail already holds. */
function orders_synced($id, array $props = array()) {
    $order = orders_paid($id, $props);
    $order->meta['_agumail_synced'] = 'yes';
    return $order;
}

/**
 * The classic checkout's submit, which WooCommerce answers with JSON: the order
 * is created pending, then paid by card ($pay true), put on hold for a bank
 * transfer ($pay false) or moved to processing for cash on delivery ($pay
 * 'cod'), which reduces the stock, and the cart is emptied. Returns [order, result].
 */
function orders_classic_checkout(array $lines, array $post, $pay = true) {
    static $count = 0;
    $order = null;
    $method = $pay === 'cod' ? 'cod' : ($pay ? 'card' : 'bacs');
    $result = agm_request('classic checkout ' . (++$count) . ': place order', 'shopper', function () use ($lines, $post, $method, &$order) {
        agm_input(array('wc-ajax' => 'checkout'), $post);
        do_action('woocommerce_checkout_process');
        $order = agm_shop_order(0, array('status' => 'pending', 'billing_email' => isset($post['billing_email']) ? $post['billing_email'] : 'ada@example.com',
            'customer_id' => get_current_user_id(), 'payment_method' => $method), $lines);
        do_action('woocommerce_checkout_create_order', $order, $post);
        agm_order_create($order);
        do_action('woocommerce_checkout_update_order_meta', $order->get_id(), $post);
        do_action('woocommerce_checkout_order_created', $order);
        do_action('woocommerce_checkout_order_processed', $order->get_id(), $post, $order);
        if ($method === 'card') {
            agm_order_pay($order);
        } elseif ($method === 'cod') {
            // WC_Gateway_COD::process_payment(): processing, with no payment taken.
            agm_order_status($order, 'processing');
        } else {
            agm_order_status($order, 'on-hold');
        }
        WC()->cart->empty_cart();
        do_action('woocommerce_cart_emptied', true);
        wp_send_json(array('result' => 'success'));
    });
    return array($order, $result);
}

/**
 * The block checkout's place-order call (POST /wc/store/v1/checkout): the draft
 * the checkout page saved becomes pending (woocommerce_new_order), then paid.
 * Returns [order, result].
 */
function orders_block_checkout(array $lines, array $additional_fields, $pay = true) {
    static $count = 0;
    do_action('woocommerce_init');
    $draft = agm_shop_order(0, array('status' => 'checkout-draft', 'billing_email' => 'dee@example.com', 'billing_first_name' => 'Dee', 'billing_last_name' => 'Hart', 'created_via' => 'store-api'), $lines);
    agm_order_create($draft);
    $result = agm_request('block checkout ' . (++$count) . ': place order', 'shopper_rest', function () use ($draft, $additional_fields, $pay) {
        $request = new WP_REST_Request('POST', '/wc/store/v1/checkout', array(
            'billing_address' => array('email' => 'dee@example.com', 'first_name' => 'Dee'),
            'payment_method' => 'card',
            'additional_fields' => $additional_fields,
        ));
        do_action('woocommerce_store_api_checkout_update_customer_from_request', WC()->customer, $request);
        do_action('woocommerce_store_api_checkout_update_order_from_request', $draft, $request);
        agm_wc_persist($draft);
        agm_order_status($draft, 'pending');
        do_action('woocommerce_store_api_checkout_order_processed', $draft);
        if ($pay) {
            agm_order_pay($draft);
        }
        WC()->cart->empty_cart();
        do_action('woocommerce_cart_emptied', true);
    });
    return array($draft, $result);
}

/** A shopper landing from one of the store's emails. */
function orders_land_from_campaign($cid) {
    return agm_request('landing from an email', 'shopper', function () use ($cid) {
        agm_input(array('agm_cid' => $cid, 'utm_source' => 'agumail'));
        do_action('init');
        do_action('wp_loaded');
    });
}

function orders_sent() {
    return agm_sent_records('/api/v1/orders', 'orders');
}

function orders_sent_contacts() {
    return agm_sent_records('/api/v1/contacts', 'contacts');
}

function orders_queued_ids() {
    return array_map(function ($row) { return $row['object_key']; }, agm_outbox('order'));
}

function orders_request_writes(array $result) {
    return array_values(array_filter($result['writes'], function ($write) { return $write['kind'] === 'outbox'; }));
}

// ── Which orders reach AguMail ──

function test_orders_an_unpaid_order_is_not_sent() {
    $products = orders_catalogue();
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'eve@example.com'), false);
    agm_request('the order fails', 'admin', function () use ($order) {
        agm_order_status($order, 'failed');
        agm_order_status($order, 'pending');
        agm_order_save($order);
    });
    expect_same(array(), agm_outbox('order'), 'an order nobody paid for was queued');
    agm_drain();
    expect_same(array(), orders_sent(), 'an order nobody paid for was sent');
}

function test_orders_cancelling_or_trashing_an_order_that_was_never_paid_sends_nothing() {
    $order = agm_shop_order(501, array('status' => 'pending'), array(array(orders_catalogue()['mug'], 1)));
    agm_request('unpaid order cancelled', 'cron', function () use ($order) {
        agm_order_status($order, 'cancelled');
    });
    agm_request('unpaid order trashed and deleted', 'admin', function () use ($order) {
        agm_order_trash($order);
        agm_order_delete($order);
    });
    expect_same(array(), agm_outbox('order'), 'an order AguMail never had was queued');
}

function test_orders_every_hook_queues_a_paid_order_and_ignores_an_unpaid_one() {
    $cases = array(
        'woocommerce_new_order'             => function ($order) { do_action('woocommerce_new_order', $order->get_id(), $order); },
        'woocommerce_update_order'          => function ($order) { do_action('woocommerce_update_order', $order->get_id(), $order); },
        'woocommerce_order_status_changed'  => function ($order) { do_action('woocommerce_order_status_changed', $order->get_id(), 'on-hold', $order->get_status(), $order); },
        'woocommerce_order_refunded'        => function ($order) { do_action('woocommerce_order_refunded', $order->get_id(), 999); },
        'woocommerce_trash_order'           => function ($order) { $order->set_status('trash'); do_action('woocommerce_trash_order', $order->get_id()); },
        'woocommerce_untrash_order'         => function ($order) { do_action('woocommerce_untrash_order', $order->get_id(), 'processing'); },
        'trashed_post'                      => function ($order) { $order->set_status('trash'); do_action('trashed_post', $order->get_id(), 'wc-processing'); },
        'untrashed_post'                    => function ($order) { do_action('untrashed_post', $order->get_id(), 'wc-processing'); },
    );
    $id = 600;
    foreach ($cases as $hook => $fire) {
        $paid = orders_paid(++$id);
        $result = agm_request("$hook on a paid order", 'admin', function () use ($fire, $paid) { $fire($paid); });
        expect_same(1, count(orders_request_writes($result)), "$hook did not queue the paid order once");
        expect_that(in_array((string) $id, orders_queued_ids(), true), "$hook queued the wrong row");

        $unpaid = agm_shop_order(++$id, array('status' => 'on-hold'), array(array(wc_get_product(11), 1)));
        $result = agm_request("$hook on an unpaid order", 'admin', function () use ($fire, $unpaid) { $fire($unpaid); });
        expect_same(array(), orders_request_writes($result), "$hook queued an unpaid order AguMail never had");
    }
    // A refund is not an order of its own.
    $refund = new WC_Order_Refund(700, array('date_paid' => agm_date(time())));
    $result = agm_request('a refund object saved', 'admin', function () use ($refund) {
        do_action('woocommerce_update_order', 700, $refund);
    });
    expect_same(array(), orders_request_writes($result), 'a refund object was queued as an order');
}

function test_orders_deleting_a_synced_order_stores_its_zeroed_record_at_once() {
    foreach (array('hpos' => 801, 'posts' => 802) as $via => $id) {
        $order = orders_synced($id, array('total' => '30'));
        $order->meta['_agumail_cid'] = 'cmp_9';
        $result = agm_request("delete ($via)", 'admin', function () use ($order, $via) {
            agm_order_delete($order, $via);
        });
        expect_same(1, count(orders_request_writes($result)), "deleting ($via) should be one write");
        $row = null;
        foreach (agm_outbox('order') as $candidate) {
            if ($candidate['object_key'] === (string) $id) {
                $row = $candidate;
            }
        }
        expect_that($row && isset($row['payload']['record']), "deleting ($via) did not store the record, which cannot be built once the order is gone");
        expect_same(0.0, agm_money_floats($row['payload']['record'])['total'], "the deleted order ($via) should be worth nothing");
        expect_same('cmp_9', $row['payload']['record']['campaignRef'], 'the campaign must be kept so its revenue is re-totalled');
        expect_that(!array_key_exists('items', $row['payload']['record']), "the deleted order ($via) carries lines, which would replace the ones AguMail holds");
    }
    agm_drain();
    $sent = orders_sent();
    expect_same(array('801', '802'), array_column($sent, 'externalId'), 'the deleted orders were not sent');
    expect_same(array(), orders_sent_contacts(), 'a deleted order AguMail already holds needs no contact');
}

function test_orders_deleting_an_order_agumail_never_had_sends_nothing() {
    $order = orders_paid(501);
    agm_request('gateway marks it paid', 'shopper', function () use ($order) {
        agm_order_status($order, 'completed');
    });
    expect_same(array('501'), orders_queued_ids(), 'the paid order was not queued');
    agm_request('merchant deletes it before it went', 'admin', function () use ($order) {
        agm_order_delete($order);
    });
    agm_drain();
    expect_same(array(), orders_sent(), 'an order AguMail never had was sent after it was deleted');
    expect_same(array(), agm_outbox('order'), 'the row for the deleted order stayed queued');
}

// ── What the record says ──

function test_orders_a_paid_block_checkout_order_carries_its_consent_and_campaign() {
    $products = orders_catalogue();
    orders_land_from_campaign('cmp_42');
    list($order, $result) = orders_block_checkout(array(array($products['mug'], 1)), array('agumail/optin' => true));
    agm_expect_shopper_quiet($result);
    unset($_COOKIE[AGUMAIL_CID_COOKIE]);

    agm_drain();
    $contacts = orders_sent_contacts();
    $orders = orders_sent();
    expect_same(1, count($orders), 'expected one order');
    expect_same(array('email' => 'dee@example.com', 'firstName' => 'Dee', 'lastName' => 'Hart', 'tags' => array('woocommerce'),
        'consent' => array('optedIn' => true, 'source' => 'checkout'), 'customerSince' => agumail_iso_date($order->get_date_created()),
        'consentedAt' => agumail_iso_date($order->get_date_paid())), $contacts[0], 'the ticked block checkbox did not reach AguMail with the contact');
    expect_same('cmp_42', isset($orders[0]['campaignRef']) ? $orders[0]['campaignRef'] : null, 'the campaign that brought the shopper was lost');
    expect_same(array((string) $order->get_id(), 'dee@example.com', 30.0, 'EUR', 'woocommerce'),
        array($orders[0]['externalId'], $orders[0]['email'], $orders[0]['total'], $orders[0]['currency'], $orders[0]['source']), 'wrong order record');
    expect_same(array(array('externalId' => '11', 'sku' => 'MUG-1', 'title' => 'Mug', 'qty' => 1, 'price' => 30.0)), $orders[0]['items'], 'wrong lines');
    expect_same('yes', $order->get_meta('_agumail_synced'), 'the sent order was not marked as synced');
}

function test_orders_the_classic_checkbox_reaches_agumail_ticked_unticked_or_not_shown() {
    $products = orders_catalogue();
    $answers = array(
        'ticked'    => array(array('billing_email' => 'tick@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'), array('optedIn' => true, 'source' => 'checkout')),
        'unticked'  => array(array('billing_email' => 'no@example.com', 'agumail_optin_shown' => '1'), array('optedIn' => false, 'source' => 'checkout')),
        'not shown' => array(array('billing_email' => 'hidden@example.com'), null),
    );
    foreach ($answers as $answer) {
        orders_classic_checkout(array(array($products['candle'], 1)), $answer[0]);
    }
    agm_drain();
    $contacts = array();
    foreach (orders_sent_contacts() as $contact) {
        $contacts[$contact['email']] = isset($contact['consent']) ? $contact['consent'] : null;
    }
    foreach ($answers as $name => $answer) {
        expect_same($answer[1], $contacts[$answer[0]['billing_email']], "the $name box reached AguMail wrongly");
    }
}

function test_orders_a_customer_who_opts_in_at_checkout_is_remembered_on_their_account() {
    $products = orders_catalogue();
    agm_sign_in(7, 'bea@example.com');
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bea@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    expect_same('', get_user_meta(7, '_agumail_optin', true), 'the account was written from the shopper\'s request');
    agm_drain();
    expect_same('yes', get_user_meta(7, '_agumail_optin', true), 'the opt-in was not stored on the customer');

    agm_sign_in(8, 'cal@example.com');
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'cal@example.com', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same('', get_user_meta(8, '_agumail_optin', true), 'an unticked box changed the account');
}

function test_orders_a_bank_transfer_paid_five_days_later_happened_when_it_was_paid() {
    $products = orders_catalogue();
    $placed_at = time() - 5 * DAY_IN_SECONDS;
    $order = agm_shop_order(501, array('status' => 'on-hold', 'payment_method' => 'bacs', 'date_created' => agm_date($placed_at)), array(array($products['mug'], 1)));
    agm_request('merchant marks the transfer received', 'admin', function () use ($order) {
        agm_order_status($order, 'processing');
    });
    agm_drain();
    $sent = orders_sent();
    expect_same(agumail_iso_date($order->get_date_paid()), $sent[0]['occurredAt'], 'occurredAt should be the paid date');
    expect_that($sent[0]['occurredAt'] !== agumail_iso_date($placed_at), 'occurredAt is the date the order was placed, which the 72 h history rule would treat as history');
}

function test_orders_an_order_without_a_paid_date_falls_back_to_when_it_was_placed() {
    $placed_at = time() - 3 * DAY_IN_SECONDS;
    $order = orders_synced(501, array('date_paid' => null, 'date_created' => agm_date($placed_at)));
    agm_request('synced order cancelled', 'admin', function () use ($order) {
        agm_order_status($order, 'cancelled');
    });
    agm_drain();
    expect_same(agumail_iso_date($placed_at), orders_sent()[0]['occurredAt'], 'occurredAt should fall back to the created date');
}

function test_orders_lines_name_the_parent_product_and_the_line_sku() {
    $products = orders_catalogue();
    $gone = new WC_Order_Item_Product(0, array('product_id' => 0, 'name' => 'Discontinued lamp', 'quantity' => 2, 'total' => '19.98'));
    $order = orders_paid(501, array(), array(array($products['blue'], 2), array($products['mug'], 1)));
    $order->items[] = $gone;
    $order->props['total'] = '79.98';
    agm_request('paid', 'admin', function () use ($order) { agm_order_save($order); });
    agm_drain();
    expect_same(array(
        array('externalId' => '20', 'sku' => 'TEE-BLUE', 'title' => 'Tee - Blue', 'qty' => 2, 'price' => 15.0),
        array('externalId' => '11', 'sku' => 'MUG-1', 'title' => 'Mug', 'qty' => 1, 'price' => 30.0),
        array('title' => 'Discontinued lamp', 'qty' => 2, 'price' => 9.99),
    ), orders_sent()[0]['items'], 'wrong lines');
}

function test_orders_the_record_is_clamped_to_what_agumail_accepts() {
    $products = orders_catalogue();
    $long = agm_catalogue_product(13, array('name' => str_repeat('Big mug ', 60), 'price' => '1', 'sku' => str_repeat('S', 150)));
    $lines = array();
    for ($i = 0; $i < 120; $i++) {
        $lines[] = array($i === 0 ? $long : $products['candle'], 1);
    }
    $order = orders_paid(501, array('currency' => 'eur'), $lines);
    $order->meta['_agumail_cid'] = str_repeat('c', 101);
    agm_request('paid', 'admin', function () use ($order) { agm_order_save($order); });
    agm_drain();
    $sent = orders_sent()[0];
    expect_same(100, count($sent['items']), 'more than 100 lines were sent');
    expect_that(strlen($sent['items'][0]['title']) <= 300, 'a line title longer than 300 characters was sent');
    expect_same(100, strlen($sent['items'][0]['sku']), 'the SKU was not clamped to 100 characters');
    expect_that(!isset($sent['campaignRef']), 'a campaign reference longer than AguMail takes was sent');
    expect_same('EUR', $sent['currency'], 'the currency was not uppercased');
}

function test_orders_an_order_without_a_valid_email_is_dropped() {
    orders_catalogue();
    $order = orders_paid(501, array('billing_email' => 'not an email'));
    agm_request('paid', 'admin', function () use ($order) { agm_order_save($order); });
    agm_drain();
    expect_same(array(), agm_http_requests(), 'an order without a valid email was sent');
    expect_same(array(), agm_outbox('order'), 'the unsendable row stayed queued');
}

// ── Refunds, cancellations, the trash ──

function test_orders_refunds_and_cancellations_re_total_the_order() {
    $products = orders_catalogue();
    orders_land_from_campaign('cmp_7');
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com', 'agumail_optin_shown' => '1'));
    unset($_COOKIE[AGUMAIL_CID_COOKIE]);
    agm_drain();

    agm_request('partial refund', 'admin', function () use ($order) { agm_order_refund($order, 10); });
    agm_drain();
    agm_request('cancelled', 'admin', function () use ($order) { agm_order_status($order, 'cancelled'); });
    agm_drain();

    $sent = orders_sent();
    expect_same(array(30.0, 20.0, 0.0), array_column($sent, 'total'), 'the sale, a partial refund and a cancellation should be sent as 30, 20 and 0');
    expect_same(array('cmp_7', 'cmp_7', 'cmp_7'), array_column($sent, 'campaignRef'), 'an update dropped the campaign, which would leave its revenue stale');
    expect_same(1, count(orders_sent_contacts()), 'updates to an order AguMail holds should not resend its contact');
}

function test_orders_a_full_refund_zeroes_the_order_in_one_write() {
    orders_catalogue();
    $order = orders_synced(501);
    $result = agm_request('full refund', 'admin', function () use ($order) { agm_order_refund($order, 30); });
    expect_same(1, count(orders_request_writes($result)), 'a full refund (status change, save, refund hook) should queue the order once');
    agm_drain();
    expect_same(0.0, orders_sent()[0]['total'], 'a fully refunded order should be worth nothing');
}

function test_orders_a_refund_on_a_paid_order_that_has_not_reached_agumail_still_gets_there() {
    $products = orders_catalogue();
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_http_default(function ($url) {
        return strpos($url, '/api/v1/orders') !== false ? array('code' => 503, 'body' => array('ok' => false, 'error' => 'down')) : array('code' => 200, 'body' => array('ok' => true));
    });
    agm_drain();
    expect_same('', $order->get_meta('_agumail_synced'), 'an order AguMail refused was marked as synced');

    agm_http_default(null);
    agm_request('refund before it went', 'admin', function () use ($order) { agm_order_refund($order, 5); });
    agm_drain();
    $sent = orders_sent();
    expect_same(25.0, end($sent)['total'], 'the refund on the unsynced order did not get there');
    $contacts = orders_sent_contacts();
    expect_same(array('optedIn' => true, 'source' => 'checkout'), end($contacts)['consent'], 'the contact of a first delivery must go first, consent included');
    expect_same('yes', $order->get_meta('_agumail_synced'), 'the order was not marked as synced once it arrived');
}

function test_orders_the_trash_zeroes_a_synced_order_and_a_restore_brings_it_back() {
    orders_catalogue();
    foreach (array('hpos' => 501, 'posts' => 502) as $via => $id) {
        $order = orders_synced($id);
        agm_request("trash ($via)", 'admin', function () use ($order, $via) { agm_order_trash($order, $via); });
        agm_drain();
        $sent = orders_sent();
        expect_same(array((string) $id, 0.0), array(end($sent)['externalId'], end($sent)['total']), "a trashed order ($via) should be worth nothing");
        agm_request("untrash ($via)", 'admin', function () use ($order, $via) { agm_order_untrash($order, $via); });
        agm_drain();
        $sent = orders_sent();
        expect_same(array((string) $id, 30.0), array(end($sent)['externalId'], end($sent)['total']), "a restored order ($via) should be worth its total again");
    }
}

function test_orders_an_update_to_a_synced_order_is_queued_once_per_request() {
    orders_catalogue();
    $order = orders_synced(501);
    $result = agm_request('merchant edits the order', 'admin', function () use ($order) {
        agm_order_save($order);
        agm_order_save($order);
        agm_order_status($order, 'completed');
        agm_order_save($order);
    });
    expect_same(1, count(orders_request_writes($result)), 'saves and a status change that leave the record as it was should be one write');
    $result = agm_request('merchant edits the total', 'admin', function () use ($order) {
        $order->props['total'] = '35';
        agm_order_save($order);
        agm_order_save($order);
    });
    expect_same(1, count(orders_request_writes($result)), 'a change to what the record carries was not queued once');

    $unsynced = agm_shop_order(502, array('status' => 'on-hold'));
    $result = agm_request('merchant edits an unpaid order', 'admin', function () use ($unsynced) { agm_order_save($unsynced); });
    expect_same(array(), orders_request_writes($result), 'an unpaid order AguMail never had was queued on update');
}

// ── Contacts first ──

function test_orders_the_contact_goes_first_and_a_refused_contact_holds_its_order() {
    $products = orders_catalogue();
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com'));
    agm_drain();
    expect_same(array('/api/v1/contacts', '/api/v1/orders'), array_column(agm_http_requests(), 'path'), 'the contact did not go before its order');

    $GLOBALS['agm']['http'] = array();
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bob@example.com'));
    agm_http_respond(400, array('ok' => false, 'error' => 'contacts.0.email: Invalid email'));
    agm_drain();
    expect_same(array('/api/v1/contacts'), array_column(agm_http_requests(), 'path'), 'an order was sent although AguMail refused its contact');
    $rows = agm_outbox('order');
    expect_same('failed', $rows[0]['status'], 'the order whose contact was refused should be set aside');
}

// ── The shopper's checkout ──

function test_orders_a_checkout_that_marks_the_order_paid_is_one_write_and_no_call() {
    $products = orders_catalogue();
    WC()->session->set('agumail_cart_id', 'wc-11111111-1111-4111-8111-111111111111');
    orders_land_from_campaign('cmp_1');
    // A variation's data store may rewrite its post (a regenerated title) on any save, the stock-only one included.
    $GLOBALS['agm']['stock_rewrites_variation_posts'] = true;

    list($classic, $result) = orders_classic_checkout(array(array($products['mug'], 1), array($products['blue'], 1)),
        array('billing_email' => 'ada@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_expect_shopper_quiet($result);
    expect_same(array('order:' . $classic->get_id()), array_column(orders_request_writes($result), 'name'), 'the classic checkout should queue its order');

    list($block, $result) = orders_block_checkout(array(array($products['candle'], 2)), array('agumail/optin' => false));
    agm_expect_shopper_quiet($result);
    expect_same(array('order:' . $block->get_id()), array_column(orders_request_writes($result), 'name'), 'the block checkout should queue its order');

    list($transfer, $result) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'eve@example.com'), false);
    agm_expect_shopper_quiet($result);
    expect_same(array(), $result['writes'], 'an unpaid checkout wrote something');
    $callback = agm_request('gateway callback marks the transfer paid', 'shopper', function () use ($transfer) {
        agm_input(array('wc-api' => 'gateway_callback'));
        do_action('init');
        agm_order_pay($transfer);
    });
    agm_expect_shopper_quiet($callback);
    expect_same(1, count($callback['writes']), 'the payment should queue the order');

    foreach (array($classic, $block, $transfer) as $order) {
        expect_same('', $order->get_meta('_agumail_synced'), 'an order was synced from the shopper\'s request');
    }
    expect_same(array(), agm_http_requests(), 'AguMail was called before the drain ran');
}

function test_orders_the_cart_and_campaign_are_written_on_the_order_without_a_save_of_their_own() {
    $products = orders_catalogue();
    orders_land_from_campaign('cmp_5');
    WC()->session->set('agumail_cart_id', 'wc-22222222-2222-4222-8222-222222222222');

    $classic = agm_shop_order(0, array('status' => 'pending'), array(array($products['mug'], 1)));
    $result = agm_request('classic checkout creates the order', 'shopper', function () use ($classic) {
        agm_input(array(), array('billing_email' => 'ada@example.com'));
        do_action('woocommerce_checkout_create_order', $classic, array());
    });
    expect_same(array(), $result['writes'], 'writing the order meta cost a write of its own (an order save?)');
    expect_same(array('cmp_5', 'wc-22222222-2222-4222-8222-222222222222'), array($classic->get_meta('_agumail_cid'), $classic->get_meta('_agumail_cart_id')), 'the classic order is missing its campaign or cart');

    $block = agm_shop_order(0, array('status' => 'checkout-draft'), array(array($products['mug'], 1)));
    $result = agm_request('block checkout saves the order', 'shopper_rest', function () use ($block) {
        do_action('woocommerce_store_api_checkout_update_order_from_request', $block, new WP_REST_Request('POST', '/wc/store/v1/checkout', array()));
    });
    expect_same(array(), $result['writes'], 'writing the block order meta cost a write of its own');
    expect_same(array('cmp_5', 'wc-22222222-2222-4222-8222-222222222222'), array($block->get_meta('_agumail_cid'), $block->get_meta('_agumail_cart_id')), 'the block order is missing its campaign or cart');

    // No session value, or one that is not a cart id, writes nothing.
    WC()->session->set('agumail_cart_id', array('not' => 'an id'));
    unset($_COOKIE[AGUMAIL_CID_COOKIE]);
    $plain = agm_shop_order(0, array('status' => 'pending'));
    do_action('woocommerce_checkout_create_order', $plain, array());
    expect_same(array('', ''), array($plain->get_meta('_agumail_cid'), $plain->get_meta('_agumail_cart_id')), 'meta written from nothing');
    $GLOBALS['agm']['wc']->session = null;
    do_action('woocommerce_checkout_create_order', $plain, array());
    expect_same('', $plain->get_meta('_agumail_cart_id'), 'a missing session broke the checkout');
}

function test_orders_the_campaign_link_is_remembered_in_a_cookie() {
    orders_land_from_campaign('cmp_abc');
    expect_same('cmp_abc', isset($_COOKIE[AGUMAIL_CID_COOKIE]) ? $_COOKIE[AGUMAIL_CID_COOKIE] : null, 'the campaign was not kept');
    expect_same('agm_cid', AGUMAIL_CID_COOKIE, 'agm_cid is the parameter AguMail\'s links carry');
    orders_land_from_campaign(str_repeat('x', 101));
    expect_same('cmp_abc', $_COOKIE[AGUMAIL_CID_COOKIE], 'a reference longer than AguMail takes replaced the cookie');
    orders_land_from_campaign('cmp_def');
    expect_same('cmp_def', $_COOKIE[AGUMAIL_CID_COOKIE], 'the latest campaign should win');
}

// ── After it arrives ──

function test_orders_a_sent_order_marks_the_cart_it_came_from_as_ordered() {
    $products = orders_catalogue();
    agumail_cart_save('wc-33333333-3333-4333-8333-333333333333', array('token' => str_repeat('b', 32), 'session_key' => 't_guest',
        'email' => 'ada@example.com', 'lines' => array(array('product_id' => 11, 'qty' => 1)), 'total' => 30, 'currency' => 'EUR'));
    agumail_cart_mark_queued('wc-33333333-3333-4333-8333-333333333333', 1);
    WC()->session->set('agumail_cart_id', 'wc-33333333-3333-4333-8333-333333333333');
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com'));
    expect_same('open', agumail_cart_get('wc-33333333-3333-4333-8333-333333333333')['status'], 'the cart changed from the shopper\'s request');

    agm_drain();
    $cart = agumail_cart_get('wc-33333333-3333-4333-8333-333333333333');
    expect_same(array('ordered', $order->get_id(), 1), array($cart['status'], $cart['order_id'], $cart['dirty']),
        'the cart should be ordered, name its order, and be sent again so AguMail closes it');
}

function test_orders_the_cart_id_comes_from_the_carts_feature_when_it_offers_one() {
    // The carts feature knows the cart even when the email capture has not yet
    // put its id in the session: the open row for this session.
    if (!function_exists('agumail_cart_id_for_current_session')) {
        function agumail_cart_id_for_current_session() {
            $session = WC()->session;
            $id = (string) $session->get('agumail_cart_id', '');
            if ($id !== '') {
                return $id;
            }
            $row = agumail_cart_find_by_session((string) $session->get_customer_id(), array('open'));
            return $row ? $row['id'] : '';
        }
    }
    $products = orders_catalogue();
    agumail_cart_save('wc-66666666-6666-4666-8666-666666666666', array('token' => str_repeat('c', 32), 'session_key' => WC()->session->get_customer_id(),
        'email' => 'ada@example.com', 'lines' => array(array('product_id' => 11, 'qty' => 1)), 'total' => 30, 'currency' => 'EUR'));
    WC()->session->set('agumail_cart_id', null);
    $order = agm_shop_order(0, array('status' => 'pending'), array(array($products['mug'], 1)));
    $result = agm_request('classic checkout before the capture landed', 'shopper', function () use ($order) {
        do_action('woocommerce_checkout_create_order', $order, array());
    });
    expect_same('wc-66666666-6666-4666-8666-666666666666', $order->get_meta('_agumail_cart_id'), 'the cart the carts feature knows was not written on the order');
    expect_same(array(), $result['writes'], 'finding the cart cost a write');
}

// ── Subscriptions ──

function test_orders_a_renewal_trusts_none_of_the_meta_it_copied() {
    $products = orders_catalogue();
    agm_add_user(7, 'bea@example.com');
    agumail_cart_save('wc-55555555-5555-4555-8555-555555555555', array('token' => str_repeat('d', 32), 'session_key' => 't_7', 'email' => 'bea@example.com',
        'lines' => array(array('product_id' => 11, 'qty' => 1)), 'total' => 30, 'currency' => 'EUR'));
    agumail_cart_mark_queued('wc-55555555-5555-4555-8555-555555555555', 1);
    // WooCommerce Subscriptions copies the first order's meta onto the renewal.
    $renewal = agm_shop_order(900, array('status' => 'pending', 'created_via' => 'subscription', 'customer_id' => 7, 'billing_email' => 'bea@example.com'), array(array($products['mug'], 1)));
    $renewal->meta = array('_agumail_optin' => 'yes', '_agumail_cid' => 'cmp_old', '_agumail_cart_id' => 'wc-55555555-5555-4555-8555-555555555555', '_subscription_renewal' => 42);
    agm_request('scheduled renewal payment', 'cron', function () use ($renewal) {
        agm_order_pay($renewal);
    });
    agm_drain();
    $contact = orders_sent_contacts()[0];
    $order = orders_sent()[0];
    expect_that(!isset($contact['consent']), 'a renewal claimed the consent of the checkout it was copied from');
    expect_that(!isset($order['campaignRef']), 'a renewal claimed the campaign of the checkout it was copied from');
    expect_same('open', agumail_cart_get('wc-55555555-5555-4555-8555-555555555555')['status'], 'a renewal closed a cart it was not bought from');
    expect_same('', get_user_meta(7, '_agumail_optin', true), 'a renewal wrote the account\'s answer');
    expect_same('yes', $renewal->get_meta('_agumail_synced'), 'the renewal itself should still reach AguMail');
}

function test_orders_subscriptions_do_not_copy_the_plugin_meta() {
    $copied = array('_agumail_optin' => 'yes', '_agumail_cid' => 'cmp_1', '_agumail_cart_id' => 'wc-1', '_agumail_synced' => 'yes', '_billing_email' => 'bea@example.com');
    foreach (array('wc_subscriptions_subscription_data', 'wc_subscriptions_renewal_order_data') as $filter) {
        expect_same(array('_billing_email' => 'bea@example.com'), apply_filters($filter, $copied, null, null), "$filter kept the plugin's meta");
    }
    // Older versions pass database rows.
    $rows = array(array('meta_key' => '_agumail_optin', 'meta_value' => 'yes'), array('meta_key' => '_order_total', 'meta_value' => '30'));
    expect_same(array(1 => array('meta_key' => '_order_total', 'meta_value' => '30')), apply_filters('wc_subscriptions_renewal_order_data', $rows, null, null), 'a row of plugin meta was kept');
    expect_same('not an array', apply_filters('wc_subscriptions_renewal_order_data', 'not an array', null, null), 'a value that is not a list was changed');
}

// ── When a first delivery is late ──

function test_orders_a_late_first_delivery_keeps_the_dates_of_the_sale() {
    $products = orders_catalogue();
    $placed = time() - 6 * DAY_IN_SECONDS;
    $paid = time() - 4 * DAY_IN_SECONDS;
    $ticked = orders_paid(501, array('date_created' => agm_date($placed), 'date_paid' => agm_date($paid), 'billing_email' => 'late@example.com'));
    $ticked->meta['_agumail_optin'] = 'yes';
    $unticked = orders_paid(502, array('date_created' => agm_date($placed), 'date_paid' => agm_date($paid), 'billing_email' => 'quiet@example.com'));
    $unticked->meta['_agumail_optin'] = 'no';
    // AguMail was unreachable for four days; the orders go now.
    agm_request('queued long ago', 'admin', function () use ($ticked, $unticked) {
        agm_order_save($ticked);
        agm_order_save($unticked);
    });
    agm_drain();
    $contacts = array();
    foreach (orders_sent_contacts() as $contact) {
        $contacts[$contact['email']] = $contact;
    }
    expect_same(array(agumail_iso_date($paid), agumail_iso_date($placed)),
        array($contacts['late@example.com']['consentedAt'], $contacts['late@example.com']['customerSince']), 'the opt-in should date from the paid checkout and the customer from the order');
    expect_that(!isset($contacts['quiet@example.com']['consentedAt']), 'a contact who did not opt in carried a consent date');
    expect_same(agumail_iso_date($placed), $contacts['quiet@example.com']['customerSince'], 'the customer should date from the order');
    expect_same(array(agumail_iso_date($paid), agumail_iso_date($paid)), array_column(orders_sent(), 'occurredAt'), 'the orders should date from their payment');
}

function test_orders_an_unsubscribed_account_is_not_opted_back_in_by_a_ticked_box() {
    $products = orders_catalogue();
    agm_sign_in(7, 'bea@example.com');
    update_user_meta(7, '_agumail_optin', 'unsubscribed');
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bea@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same('unsubscribed', get_user_meta(7, '_agumail_optin', true), 'a ticked box replaced the account\'s unsubscribe; AguMail asks them to confirm instead');
    expect_same(array('optedIn' => true, 'source' => 'checkout'), orders_sent_contacts()[0]['consent'], 'the tick itself should still reach AguMail');
}

function test_orders_marking_an_order_synced_saves_only_its_meta() {
    $products = orders_catalogue();
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com'));
    $GLOBALS['agm']['order_full_saves'] = array();
    agm_drain();
    expect_same(array(false), $GLOBALS['agm']['order_full_saves'], 'saving _agumail_synced would make High-Performance Order Storage save the whole order (and fire order.updated webhooks)');
    expect_same(true, apply_filters('woocommerce_orders_table_datastore_should_save_after_meta_change', true), 'the filter was left in place for everyone else');
}

// ── One write per request ──

function test_orders_paid_then_completed_in_one_request_is_one_write() {
    $products = orders_catalogue();
    $order = agm_shop_order(0, array('status' => 'pending', 'billing_email' => 'dl@example.com'), array(array($products['candle'], 1)));
    $result = agm_request('a download paid and completed at once', 'shopper', function () use ($order) {
        do_action('woocommerce_checkout_create_order', $order, array());
        agm_order_create($order);
        agm_order_pay($order);
        agm_order_status($order, 'completed');
    });
    agm_expect_shopper_quiet($result);
    expect_same(1, count(orders_request_writes($result)), 'paid then completed in one request should be one write');
}

function test_orders_a_checkout_on_a_store_with_orders_as_posts_is_one_write() {
    $products = orders_catalogue();
    agm_order_storage('posts');
    list($order, $result) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com'));
    agm_expect_shopper_quiet($result);
    expect_same(array('order:' . $order->get_id()), array_column(orders_request_writes($result), 'name'), 'the order post written during checkout queued something else');
}

function test_orders_trashing_with_posts_sync_on_fires_both_trash_hooks_and_is_one_write() {
    orders_catalogue();
    $order = orders_synced(501);
    $result = agm_request('trash with compatibility sync on', 'admin', function () use ($order) {
        agm_order_trash($order, 'hpos', true);
    });
    expect_same(1, count(orders_request_writes($result)), 'trashed_post and woocommerce_trash_order should queue the order once');
    agm_drain();
    expect_same(0.0, orders_sent()[0]['total'], 'the trashed order should be worth nothing');
}

function test_orders_a_manual_admin_order_from_auto_draft_to_completed_is_sent() {
    $products = orders_catalogue();
    $order = agm_shop_order(0, array('status' => 'auto-draft', 'created_via' => 'admin', 'billing_email' => 'phone@example.com'), array(array($products['mug'], 2)));
    agm_order_create($order);
    $result = agm_request('admin: Add order, then Create as completed', 'admin', function () use ($order) {
        agm_order_status($order, 'completed');
    });
    expect_same(1, count(orders_request_writes($result)), 'the new admin order was not queued once');
    agm_drain();
    $sent = orders_sent();
    expect_same(array((string) $order->get_id(), 60.0, agumail_iso_date($order->get_date_paid())), array($sent[0]['externalId'], $sent[0]['total'], $sent[0]['occurredAt']), 'wrong admin order');
    expect_that(!isset(orders_sent_contacts()[0]['consent']), 'an order no shopper submitted carried consent');
}

// ── Lines ──

function test_orders_line_prices_include_tax_when_the_store_shows_prices_with_tax() {
    $products = orders_catalogue();
    $order = orders_paid(501, array(), array(array($products['mug'], 2, 12)));
    agm_request('paid', 'admin', function () use ($order) { agm_order_save($order); });
    agm_drain();
    expect_same(30.0, orders_sent()[0]['items'][0]['price'], 'without tax shown, the line price should be without tax');

    update_option('woocommerce_tax_display_cart', 'incl');
    $taxed = orders_paid(502, array(), array(array($products['mug'], 2, 12)));
    agm_request('paid', 'admin', function () use ($taxed) { agm_order_save($taxed); });
    agm_drain();
    $sent = orders_sent();
    expect_same(36.0, end($sent)['items'][0]['price'], 'with tax shown, the line price should be per unit with its tax');
}

function test_orders_line_names_are_plain_text() {
    $products = orders_catalogue();
    $products['mug']->props['name'] = 'Tom &amp; Jerry&#8217;s mug';
    $order = orders_paid(501, array(), array(array($products['mug'], 1)));
    agm_request('paid', 'admin', function () use ($order) { agm_order_save($order); });
    agm_drain();
    expect_same("Tom & Jerry\u{2019}s mug", orders_sent()[0]['items'][0]['title'], 'a line name went with its HTML entities');
}

function test_orders_the_account_remembers_when_it_opted_in() {
    $products = orders_catalogue();
    agm_sign_in(7, 'bea@example.com');
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bea@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same(agumail_iso_date($order->get_date_paid()), get_user_meta(7, '_agumail_optin_at', true), 'the account should date its opt-in from the paid order');

    // A later opt-in keeps the first date.
    update_user_meta(7, '_agumail_optin_at', '2026-01-02T03:04:05Z');
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bea@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same('2026-01-02T03:04:05Z', get_user_meta(7, '_agumail_optin_at', true), 'a later order replaced the date the account first opted in');

    // An unsubscribed account gets neither.
    agm_sign_in(8, 'cal@example.com');
    update_user_meta(8, '_agumail_optin', 'unsubscribed');
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'cal@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same('', get_user_meta(8, '_agumail_optin_at', true), 'an unsubscribed account was given an opt-in date');
}

// ── Cash on delivery ──

function test_orders_a_cash_on_delivery_order_counts_once_it_is_processing() {
    $products = orders_catalogue();
    $queued_at = time() + HOUR_IN_SECONDS;
    agumail_set_clock(function () use ($queued_at) { return $queued_at; });
    list($order, $result) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'cod@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'), 'cod');
    expect_same(array('processing', null), array($order->get_status(), $order->get_date_paid()), 'setup: cash on delivery has no paid date until delivery');
    agm_expect_shopper_quiet($result);
    expect_same(array('order:' . $order->get_id()), array_column(orders_request_writes($result), 'name'), 'a cash on delivery order in processing should be queued at checkout, once');
    expect_same(true, agumail_order_counts_as_paid($order), 'a cash on delivery order in processing counts as paid');
    agm_drain();
    $sent = orders_sent();
    expect_same(array((string) $order->get_id(), 30.0, agumail_iso_date($queued_at)), array($sent[0]['externalId'], $sent[0]['total'], $sent[0]['occurredAt']),
        'the order should go with its total, dated when it first counted (queued), having no paid date');
    expect_same(agumail_iso_date($queued_at), orders_sent_contacts()[0]['consentedAt'], 'the opt-in should date from the moment the order counted, as the order does');
}

function test_orders_a_bank_transfer_on_hold_waits_until_it_is_processing() {
    $products = orders_catalogue();
    list($order, $result) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'bacs@example.com'), false);
    expect_same(array(), $result['writes'], 'an order on hold for a bank transfer was queued');
    expect_same(false, agumail_order_counts_as_paid($order), 'an order on hold does not count as paid');
    agm_drain();
    expect_same(array(), orders_sent(), 'an order on hold was sent');
    $result = agm_request('merchant marks the transfer received', 'admin', function () use ($order) {
        agm_order_status($order, 'processing');
    });
    expect_same(1, count(orders_request_writes($result)), 'the order was not queued once it was processing');
    agm_drain();
    expect_same(array((string) $order->get_id()), array_column(orders_sent(), 'externalId'), 'the order was not sent once it was processing');
}

function test_orders_a_cash_on_delivery_order_refused_at_the_door_re_totals_to_nothing() {
    $products = orders_catalogue();
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'cod@example.com'), 'cod');
    agm_drain();
    expect_same('yes', $order->get_meta('_agumail_synced'), 'setup: the order should have reached AguMail');
    agm_request('courier returns it, merchant cancels', 'admin', function () use ($order) {
        agm_order_status($order, 'cancelled');
    });
    agm_drain();
    $sent = orders_sent();
    expect_same(array(30.0, 0.0), array_column($sent, 'total'), 'the refused order should re-total to nothing');
}

function test_orders_a_cash_on_delivery_order_delivered_keeps_counting() {
    $products = orders_catalogue();
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'cod@example.com'), 'cod');
    agm_drain();
    agm_request('delivered and paid', 'admin', function () use ($order) {
        agm_order_status($order, 'completed');
    });
    expect_that((bool) $order->get_date_paid(), 'setup: completing a cash on delivery order sets its paid date');
    agm_drain();
    $sent = orders_sent();
    expect_same(array(30.0, 30.0), array_column($sent, 'total'), 'the delivered order should still count');
    expect_same(agumail_iso_date($order->get_date_paid()), end($sent)['occurredAt'], 'once paid, the order dates from its payment');
}

// ── What a later send keeps ──

function test_orders_an_order_keeps_the_moment_it_first_counted() {
    $products = orders_catalogue();
    $now = 1790000000;
    agumail_set_clock(function () use ($now) { return $now; });
    // Cash on delivery for a download waits on hold; four days later the merchant moves it to processing.
    $order = agm_shop_order(501, array('status' => 'on-hold', 'payment_method' => 'cod', 'date_created' => agm_date($now - 4 * DAY_IN_SECONDS)), array(array($products['mug'], 1)));
    $order->meta['_agumail_optin'] = 'yes';
    agm_request('merchant moves it to processing', 'admin', function () use ($order) { agm_order_status($order, 'processing'); });
    expect_same(null, $order->get_date_paid(), 'setup: no paid date yet');
    agm_drain();
    $first = agumail_iso_date($now);
    expect_same($first, orders_sent()[0]['occurredAt'], 'a first send with no paid date should date from when the order was first queued, so it is not history');
    expect_same($first, $order->get_meta('_agumail_occurred_at'), 'the moment sent first was not kept on the order');
    $contact = orders_sent_contacts()[0];
    expect_same(array($first, agumail_iso_date($now - 4 * DAY_IN_SECONDS)), array($contact['consentedAt'], $contact['customerSince']),
        'the opt-in should be as live as its order, and the customer date from when the order was placed');

    agm_request('delivered, paid, completed', 'admin', function () use ($order) { agm_order_status($order, 'completed'); });
    expect_that((bool) $order->get_date_paid(), 'setup: delivery sets the paid date');
    agm_drain();
    $sent = orders_sent();
    expect_same(array($first, $first), array_column($sent, 'occurredAt'), 'a later send moved the order\'s moment, which can change the flow it is credited to');
}

function test_orders_a_tick_on_an_order_billed_to_another_address_leaves_the_account_alone() {
    $products = orders_catalogue();
    agm_sign_in(7, 'bea@example.com');
    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'gift@example.com', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same(array('', ''), array(get_user_meta(7, '_agumail_optin', true), get_user_meta(7, '_agumail_optin_at', true)), 'a tick for another address subscribed the account');
    expect_same(array('gift@example.com', true), array(orders_sent_contacts()[0]['email'], orders_sent_contacts()[0]['consent']['optedIn']), 'the tick belongs to the billing address');

    orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'Bea@Example.COM', 'agumail_optin' => '1', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same('yes', get_user_meta(7, '_agumail_optin', true), 'the account\'s own address, in other letter case, should count');
}

function test_orders_the_sync_flag_names_the_agumail_account() {
    $products = orders_catalogue();
    agumail_update_connection(array('status' => 'connected', 'userId' => 'usr_1'));
    list($order) = orders_classic_checkout(array(array($products['mug'], 1)), array('billing_email' => 'ada@example.com', 'agumail_optin_shown' => '1'));
    agm_drain();
    expect_same('usr_1', $order->get_meta('_agumail_synced'), 'the flag should name the account that holds the order');

    // Synced to an account the store has since left: to this one it is new, contact and all.
    $moved = orders_paid(601, array('billing_email' => 'old@example.com'));
    $moved->meta['_agumail_synced'] = 'usr_old';
    // Synced before the flag named an account.
    $legacy = orders_paid(602, array('billing_email' => 'legacy@example.com'));
    $legacy->meta['_agumail_synced'] = 'yes';
    agm_request('refunds', 'admin', function () use ($moved, $legacy) {
        agm_order_refund($moved, 5);
        agm_order_refund($legacy, 5);
    });
    $contacts_before = count(orders_sent_contacts());
    agm_drain();
    $emails = array_slice(array_column(orders_sent_contacts(), 'email'), $contacts_before);
    expect_same(array('old@example.com'), $emails, 'only the order synced to another account should go with its contact');
    expect_same(array('usr_1', 'yes'), array($moved->get_meta('_agumail_synced'), $legacy->get_meta('_agumail_synced')), 'the moved order should now name this account; a legacy flag stays');
}

function test_orders_wordpress_post_hooks_are_safe_without_woocommerce($plugin_dir) {
    $fakes = dirname(__DIR__) . '/fakes';
    $script = tempnam(sys_get_temp_dir(), 'agm');
    file_put_contents($script, '<?php
require ' . var_export($fakes . '/core.php', true) . ';
foreach (array("http", "logger", "scheduler", "store", "wpdb") as $f) { require_once ' . var_export($fakes, true) . ' . "/" . $f . ".php"; }
function get_post_type($id = 0) { $types = array(5 => "product", 6 => "shop_order", 7 => "product_variation"); return isset($types[(int) $id]) ? $types[(int) $id] : false; }
require ' . var_export($plugin_dir . '/agumail.php', true) . ';
$GLOBALS["agm"]["options"]["agumail_api_key"] = "agm_" . str_repeat("a", 40);
$GLOBALS["agm"]["options"]["agumail_db_version"] = "1";
agumail_set_store(new Agm_Fake_Store());
foreach (array(5, 6, 7) as $id) {
    do_action("wp_after_insert_post", $id, null, true, null);
    do_action("trashed_post", $id, "publish");
    do_action("untrashed_post", $id, "publish");
    do_action("before_delete_post", $id, null);
}
do_action("edited_product_cat", 3, 3, array());
echo "survived";
');
    $output = array();
    exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($script) . ' 2>&1', $output, $code);
    unlink($script);
    expect_same(array(0, 'survived'), array($code, (string) end($output)), "WordPress's post hooks broke a site without WooCommerce:\n        " . implode("\n        ", $output));
}
