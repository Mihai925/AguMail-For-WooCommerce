<?php
/**
 * Seeds shoppers and paid orders for the bulk-sync specs. Idempotent. Run with:
 *   wp eval-file /e2e/wp/seed.php [count=1200]
 *
 * Deterministic: shopper i (1-based) always gets the same email, kind, opt-in and date.
 *   - count orders from count distinct shoppers, one paid (completed) order each
 *   - 5 in 6 shoppers are registered customers, the rest are guests
 *   - every 4th shopper opted in: user meta _agumail_optin=yes (registered) or order meta
 *     _agumail_optin=yes (guest); half the guests and a fifth of the registered shoppers
 *   - an opted-in guest's order is created_via 'checkout', as the plugin trusts the box only on an
 *     order a checkout created; every other seeded order is created_via 'agumail-e2e-seed'
 *   - dates spread evenly from 730 days ago to 1 day ago (the newest few are inside 72 h)
 *
 * Writes /e2e/state/seed.json with the exact counts the bulk-sync specs assert.
 * The AguMail plugins must be inactive while seeding (wp-setup.sh seed takes care of that).
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

$count = isset($args[0]) ? max(1, (int) $args[0]) : 1200;

foreach (array('agumail/agumail.php', 'agumail-v1/agumail.php') as $plugin) {
    if (in_array($plugin, (array) get_option('active_plugins', array()), true)) {
        WP_CLI::error("Deactivate $plugin before seeding: live order hooks would fire for every seeded order.");
    }
}

wc_set_time_limit(0);
wp_defer_term_counting(true);
// Cheap password hashes for the seeded users (WordPress 6.8+ bcrypt); plain phpass otherwise.
add_filter('wp_hash_password_options', function ($options) {
    $options['cost'] = 4;
    return $options;
});
// No mail while seeding (the e2e mu-plugin also short-circuits wp_mail).
add_filter('pre_wp_mail', '__return_true');

$products = array();
foreach (array('AGM-TOTE', 'AGM-MUG', 'AGM-CANDLE', 'AGM-NOTE', 'AGM-TEE-M', 'AGM-BEANIE-NAVY') as $sku) {
    $id = wc_get_product_id_by_sku($sku);
    if ($id) {
        $products[] = wc_get_product($id);
    }
}
if (!$products) {
    WP_CLI::error('No catalogue found. Run the store setup first.');
}

$first_names = array('Ana', 'Ben', 'Chloe', 'Dan', 'Elena', 'Farid', 'Grace', 'Hugo', 'Ines', 'Jon', 'Kira', 'Liam');
$last_names  = array('Popescu', 'Smith', 'Garcia', 'Nguyen', 'Muller', 'Rossi', 'Kowalski', 'Silva', 'Dubois', 'Jensen');

function agumail_e2e_seed_registered($i) {
    return ($i % 6) !== 0;
}

function agumail_e2e_seed_opted_in($i) {
    return ($i % 4) === 0;
}

/** Where the order came from: the box counts only on an order a checkout created. */
function agumail_e2e_seed_created_via($registered, $opted_in) {
    return (!$registered && $opted_in) ? 'checkout' : 'agumail-e2e-seed';
}

function agumail_e2e_seeded_index_exists($i) {
    $found = wc_get_orders(array(
        'limit'      => 1,
        'return'     => 'ids',
        'meta_key'   => '_agumail_e2e_seed',
        'meta_value' => (string) $i,
        'status'     => 'any',
    ));
    return !empty($found);
}

$start = (int) get_option('agumail_e2e_seeded', 0);
// Resume safely after an interrupted run: the last index may or may not have been written.
while ($start > 0 && !agumail_e2e_seeded_index_exists($start)) {
    $start--;
}
$now      = time();
$progress = \WP_CLI\Utils\make_progress_bar('Seeding shoppers and paid orders', max(0, $count - $start));

for ($i = $start + 1; $i <= $count; $i++) {
    if (agumail_e2e_seeded_index_exists($i)) {
        $progress->tick();
        continue;
    }
    $registered = agumail_e2e_seed_registered($i);
    $opted_in   = agumail_e2e_seed_opted_in($i);
    $email      = sprintf('shopper%04d@e2e.agumail.test', $i);
    $first      = $first_names[$i % count($first_names)];
    $last       = $last_names[$i % count($last_names)];
    $days_ago   = $count > 1 ? 730 - (int) floor(($i - 1) * 729 / ($count - 1)) : 1;
    $order_ts   = $now - $days_ago * DAY_IN_SECONDS - (($i * 7919) % 36000);
    $address    = array(
        'first_name' => $first,
        'last_name'  => $last,
        'email'      => $email,
        'phone'      => sprintf('555%07d', $i),
        'address_1'  => $i . ' Market Street',
        'city'       => 'San Francisco',
        'state'      => 'CA',
        'postcode'   => '94110',
        'country'    => 'US',
    );

    $user_id = 0;
    if ($registered) {
        $user_id = (int) email_exists($email);
        if (!$user_id) {
            $user_id = wp_insert_user(array(
                'user_login'      => sprintf('shopper%04d', $i),
                'user_email'      => $email,
                'user_pass'       => 'shopper-' . $i,
                'first_name'      => $first,
                'last_name'       => $last,
                'role'            => 'customer',
                'user_registered' => gmdate('Y-m-d H:i:s', $order_ts - (($i % 30) + 1) * DAY_IN_SECONDS),
            ));
            if (is_wp_error($user_id)) {
                WP_CLI::error('User ' . $email . ': ' . $user_id->get_error_message());
            }
        }
        foreach ($address as $key => $value) {
            update_user_meta($user_id, 'billing_' . $key, $value);
        }
        update_user_meta($user_id, '_agumail_optin', $opted_in ? 'yes' : 'no');
    }

    $order = wc_create_order(array('customer_id' => $user_id, 'created_via' => agumail_e2e_seed_created_via($registered, $opted_in)));
    $product = $products[$i % count($products)];
    $order->add_product($product, 1 + ($i % 3));
    if ($i % 4 === 0) {
        $order->add_product($products[($i + 1) % count($products)], 1);
    }
    $order->set_address($address, 'billing');
    $shipping = $address;
    unset($shipping['email'], $shipping['phone']);
    $order->set_address($shipping, 'shipping');
    $order->set_payment_method('cod');
    $order->set_payment_method_title('Cash on delivery');
    $order->set_currency('USD');
    $order->set_date_created($order_ts);
    $order->calculate_totals(false);
    $order->set_date_paid($order_ts + 3600);
    $order->set_date_completed($order_ts + 2 * DAY_IN_SECONDS);
    $order->set_status('completed');
    $order->update_meta_data('_agumail_optin', (!$registered && $opted_in) ? 'yes' : 'no');
    $order->update_meta_data('_agumail_e2e_seed', (string) $i);
    $order->save();

    if ($i % 50 === 0) {
        update_option('agumail_e2e_seeded', $i, false);
        wp_cache_flush();
    }
    $progress->tick();
}
$progress->finish();
update_option('agumail_e2e_seeded', max($count, (int) get_option('agumail_e2e_seeded', 0)), false);
wp_defer_term_counting(false);

// Exact counts, from the database, for the specs.
global $wpdb;
$seeded_ids = wc_get_orders(array(
    'limit'    => -1,
    'return'   => 'ids',
    'meta_key' => '_agumail_e2e_seed',
    'status'   => 'any',
    'orderby'  => 'ID',
    'order'    => 'ASC',
));
$emails = array();
$summary = array(
    'orders'              => count($seeded_ids),
    'paidOrders'          => 0,
    'customers'           => 0,
    'registered'          => 0,
    'guests'              => 0,
    'optedInRegistered'   => 0,
    'optedInGuests'       => 0,
    'ordersWithin72h'     => 0,
    'oldestOrder'         => null,
    'newestOrder'         => null,
    'emailPattern'        => 'shopper%04d@e2e.agumail.test',
);
$oldest = PHP_INT_MAX;
$newest = 0;
foreach ($seeded_ids as $order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        continue;
    }
    if ($order->is_paid()) {
        $summary['paidOrders']++;
    }
    // Converge the opt-in meta, so a store seeded under an older rule matches this one.
    $index    = (int) $order->get_meta('_agumail_e2e_seed');
    $expected = agumail_e2e_seed_opted_in($index) ? 'yes' : 'no';
    if ($order->get_customer_id()) {
        if (get_user_meta($order->get_customer_id(), '_agumail_optin', true) !== $expected) {
            update_user_meta($order->get_customer_id(), '_agumail_optin', $expected);
        }
    } else {
        if ($order->get_meta('_agumail_optin') !== $expected) {
            $order->update_meta_data('_agumail_optin', $expected);
            $order->save_meta_data();
        }
        $via = agumail_e2e_seed_created_via(false, $expected === 'yes');
        if ($order->get_created_via() !== $via) {
            $order->set_created_via($via);
            $order->save();
        }
    }
    $email = strtolower($order->get_billing_email());
    $ts    = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
    $oldest = min($oldest, $ts);
    $newest = max($newest, $ts);
    if ($ts > $now - 72 * HOUR_IN_SECONDS) {
        $summary['ordersWithin72h']++;
    }
    if (isset($emails[$email])) {
        continue;
    }
    $emails[$email] = true;
    if ($order->get_customer_id()) {
        $summary['registered']++;
        if (get_user_meta($order->get_customer_id(), '_agumail_optin', true) === 'yes') {
            $summary['optedInRegistered']++;
        }
    } else {
        $summary['guests']++;
        if ($order->get_meta('_agumail_optin') === 'yes') {
            $summary['optedInGuests']++;
        }
    }
}
$summary['customers']   = count($emails);
$summary['oldestOrder'] = $oldest < PHP_INT_MAX ? gmdate('c', $oldest) : null;
$summary['newestOrder'] = $newest ? gmdate('c', $newest) : null;
file_put_contents('/e2e/state/seed.json', wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
@chmod('/e2e/state/seed.json', 0666);

WP_CLI::success(sprintf(
    'Seeded %d paid orders from %d shoppers (%d registered, %d guests; opted in: %d registered, %d guests).',
    $summary['paidOrders'],
    $summary['customers'],
    $summary['registered'],
    $summary['guests'],
    $summary['optedInRegistered'],
    $summary['optedInGuests']
));
