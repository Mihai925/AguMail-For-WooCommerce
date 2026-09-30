<?php
// Orders and products as WooCommerce 10.7 saves them, for the orders and
// products scenarios (and for any lane that needs a product which reports its
// changes):
//   - products whose get_changes() reports what a save is about to write (the
//     core fakes' is always empty), and saves that fire what the data store
//     fires, wp_update_post() and its wp_after_insert_post included;
//   - an order class whose line prices honour "including tax" and which
//     records whether a meta save would trigger High-Performance Order
//     Storage's full order save;
//   - posts for WordPress's own hooks;
//   - the hooks of a status change (with WooCommerce's stock reduction inside
//     woocommerce_order_status_processing), a payment, a refund, the trash,
//     a restore and a deletion, in WooCommerce's order.
//
// This file sorts before wc.php, whose classes it extends, so it loads it first.

require_once __DIR__ . '/wc.php';

/** What get_changes() reports while a product is being saved. */
trait Agm_Product_Changes {
    public $agm_changes = array();
    public function get_changes() { return $this->agm_changes; }
}
class Agm_Simple_Product extends WC_Product_Simple { use Agm_Product_Changes; }
class Agm_Variable_Product extends WC_Product_Variable { use Agm_Product_Changes; }
class Agm_Variation_Product extends WC_Product_Variation { use Agm_Product_Changes; }

/** A product in the simulated catalogue, of a class that reports its changes. */
function agm_catalogue_product($id, array $props = array(), $class = 'Agm_Simple_Product') {
    return agm_wc_persist(new $class($id, $props));
}

/**
 * An order as WooCommerce keeps it with High-Performance Order Storage: a line's
 * unit price can include its tax, and save_meta_data() notes whether it would
 * have gone on to a full save() (woocommerce_orders_table_datastore_should_save_after_meta_change).
 */
class Agm_Order extends WC_Order {
    public function get_item_total($item, $inc_tax = false, $round = true) {
        $qty = max(1, (int) $item->get_quantity());
        $tax = $inc_tax ? (float) $item->get_total_tax() : 0.0;
        return ((float) $item->get_total() + $tax) / $qty;
    }
    public function save_meta_data() {
        $GLOBALS['agm']['order_full_saves'][] = (bool) apply_filters('woocommerce_orders_table_datastore_should_save_after_meta_change', true);
        parent::save_meta_data();
    }
}

/** agm_order(), as an Agm_Order. Lines are [product, qty] or [product, qty, tax]. */
function agm_shop_order($id, array $props = array(), array $lines = array()) {
    $order = new Agm_Order($id, array_merge(array('billing_email' => 'ada@example.com', 'billing_first_name' => 'Ada', 'billing_last_name' => 'Lovelace'), $props));
    $total = 0.0;
    foreach ($lines as $line) {
        $product = $line[0];
        $qty = $line[1];
        $tax = isset($line[2]) ? (float) $line[2] : 0.0;
        $line_total = (float) $product->get_price() * $qty;
        $total += $line_total + $tax;
        $order->items[] = new WC_Order_Item_Product(0, array('product_id' => $product->get_parent_id() ?: $product->get_id(),
            'variation_id' => $product->get_parent_id() ? $product->get_id() : 0, 'name' => $product->get_name(),
            'quantity' => $qty, 'total' => (string) $line_total, 'subtotal' => (string) $line_total, 'total_tax' => (string) $tax));
    }
    if (!isset($props['total'])) {
        $order->props['total'] = (string) $total;
    }
    return $id ? agm_wc_persist($order) : $order;
}

/** A post, as WordPress hands one to its hooks. */
class Agm_Post {
    public $ID = 0;
    public $post_type = 'post';
    public $post_status = 'publish';
    public $post_title = '';
    public $post_name = '';
    public $post_parent = 0;
    public function __construct(array $fields = array()) {
        foreach ($fields as $field => $value) {
            $this->$field = $value;
        }
    }
}

function agm_product_post(WC_Product $product) {
    return new Agm_Post(array(
        'ID'          => $product->get_id(),
        'post_type'   => $product->is_type('variation') ? 'product_variation' : 'product',
        'post_status' => $product->get_status(),
        'post_title'  => $product->get_name(),
        'post_name'   => $product->props['slug'],
        'post_parent' => (int) $product->get_parent_id(),
    ));
}

function agm_order_post(WC_Order $order) {
    return new Agm_Post(array('ID' => $order->get_id(), 'post_type' => 'shop_order', 'post_status' => 'wc-' . $order->get_status()));
}

/** get_objects_in_term() over the terms the scenarios file under $GLOBALS['agm']['terms'][object][taxonomy]. */
if (!function_exists('get_objects_in_term')) {
    function get_objects_in_term($term_ids, $taxonomies, $args = array()) {
        $ids = array();
        foreach (isset($GLOBALS['agm']['terms']) ? $GLOBALS['agm']['terms'] : array() as $object_id => $by_taxonomy) {
            foreach ((array) $taxonomies as $taxonomy) {
                foreach (isset($by_taxonomy[$taxonomy]) ? $by_taxonomy[$taxonomy] : array() as $term) {
                    if (isset($term->term_id) && in_array((int) $term->term_id, array_map('intval', (array) $term_ids), true)) {
                        $ids[] = (string) $object_id;
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }
}

// ── Products ──

/** The product fields kept on the post itself: changing one makes the data store call wp_update_post(). */
function agm_product_post_fields() {
    return array('name', 'description', 'short_description', 'parent_id', 'reviews_allowed', 'status', 'menu_order',
        'date_created', 'date_modified', 'slug', 'post_password');
}

/**
 * WC_Product::save() with these props changed: woocommerce_before_product_object_save
 * while get_changes() still lists them; the data store's write, which for a new
 * product (wp_insert_post) or a change to a post field (wp_update_post) fires
 * wp_after_insert_post before the meta is written; woocommerce_new_product or
 * woocommerce_update_product (the _variation ones for a variation); then
 * woocommerce_after_product_object_save.
 *
 * $rewrites_post forces the post write: a variation's data store regenerates
 * its title inside update() and rewrites the post, whatever the save changed.
 */
function agm_save_product(WC_Product $product, array $changes, $rewrites_post = null) {
    if (!property_exists($product, 'agm_changes')) {
        throw new Exception('agm_save_product() needs a product from agm_catalogue_product()');
    }
    foreach ($changes as $prop => $value) {
        $product->props[$prop] = $value;
    }
    $product->agm_changes = $changes;
    $new = !$product->get_id();
    do_action('woocommerce_before_product_object_save', $product, null);
    if ($rewrites_post === null) {
        $rewrites_post = $new || (bool) array_intersect(array_keys($changes), agm_product_post_fields());
    }
    agm_wc_persist($product);
    if ($rewrites_post) {
        do_action('wp_after_insert_post', $product->get_id(), agm_product_post($product), !$new, null);
    }
    $variation = $product->is_type('variation');
    if ($new) {
        do_action($variation ? 'woocommerce_new_product_variation' : 'woocommerce_new_product', $product->get_id(), $product);
    } else {
        do_action($variation ? 'woocommerce_update_product_variation' : 'woocommerce_update_product', $product->get_id(), $product);
    }
    $product->agm_changes = array();
    do_action('woocommerce_after_product_object_save', $product, null);
}

/**
 * The classic product editor's Update button. WordPress writes the post first
 * (title, status, slug, categories, image), then save_post runs WooCommerce's
 * product data box, which loads the product and saves the prices and SKU; only
 * those show in get_changes(), and inside save_post the data store writes the
 * post directly rather than through wp_update_post(). wp_after_insert_post
 * comes last.
 */
function agm_admin_edit_product(WC_Product $product, array $post_fields, array $changes, array $categories = null) {
    $id = $product->get_id();
    $before = agm_product_post($product);
    foreach ($post_fields as $prop => $value) {
        $product->props[$prop] = $value;
    }
    if ($categories !== null) {
        $GLOBALS['agm']['terms'][$id]['product_cat'] = array_map(function ($name) { return (object) array('name' => $name); }, $categories);
    }
    $after = agm_product_post($product);
    do_action('post_updated', $id, $after, $before);
    do_action('save_post_product', $id, $after, true);
    agm_save_product($product, $changes, false);
    do_action('save_post', $id, $after, true);
    do_action('wp_insert_post', $id, $after, true);
    do_action('wp_after_insert_post', $id, $after, true, $before);
}

/** wp_trash_post() on a product: the status goes through wp_update_post(). */
function agm_trash_product(WC_Product $product) {
    $id = $product->get_id();
    $before = agm_product_post($product);
    $previous = $product->get_status();
    do_action('wp_trash_post', $id, $previous);
    $product->props['status'] = 'trash';
    do_action('wp_after_insert_post', $id, agm_product_post($product), true, $before);
    do_action('trashed_post', $id, $previous);
}

function agm_untrash_product(WC_Product $product, $restore_to = 'publish') {
    $id = $product->get_id();
    $before = agm_product_post($product);
    do_action('untrash_post', $id, $restore_to);
    $product->props['status'] = $restore_to;
    do_action('wp_after_insert_post', $id, agm_product_post($product), true, $before);
    do_action('untrashed_post', $id, $restore_to);
}

/** wp_delete_post(): before_delete_post while the product is still there; WooCommerce then deletes its variations. */
function agm_delete_product(WC_Product $product) {
    $id = $product->get_id();
    $post = agm_product_post($product);
    do_action('before_delete_post', $id, $post);
    do_action('delete_post', $id, $post);
    foreach ($GLOBALS['agm']['products'] as $child) {
        if ((int) $child->get_parent_id() === $id) {
            agm_delete_product($child);
        }
    }
    unset($GLOBALS['agm']['products'][$id]);
    do_action('deleted_post', $id, $post);
}

// ── Orders ──

/**
 * Orders stored as posts ('posts') write their post through wp_insert_post()
 * and wp_update_post(), so wp_after_insert_post fires for them on every save;
 * with High-Performance Order Storage ('hpos', the default) it does not.
 */
function agm_order_storage($storage = null) {
    if ($storage !== null) {
        $GLOBALS['agm']['order_storage'] = $storage;
    }
    return isset($GLOBALS['agm']['order_storage']) ? $GLOBALS['agm']['order_storage'] : 'hpos';
}

function agm_order_post_written(WC_Order $order, $update) {
    if (agm_order_storage() === 'posts') {
        do_action('wp_after_insert_post', $order->get_id(), agm_order_post($order), $update, null);
    }
}

/** A new order saved for the first time: woocommerce_new_order, unless it is a draft. */
function agm_order_create(WC_Order $order) {
    agm_wc_persist($order);
    agm_order_post_written($order, false);
    if (!in_array($order->get_status(), array('auto-draft', 'draft', 'checkout-draft'), true)) {
        do_action('woocommerce_new_order', $order->get_id(), $order);
    }
}

/**
 * wc_maybe_reduce_stock_levels(), which WooCommerce hooks before any plugin to
 * woocommerce_payment_complete and woocommerce_order_status_processing,
 * _completed and _on-hold: each line's product is saved with only its stock
 * changed, once per order.
 */
function agm_wc_reduce_stock(WC_Order $order) {
    if ($order->get_meta('_order_stock_reduced') === 'yes') {
        return;
    }
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) {
            continue;
        }
        do_action($product->is_type('variation') ? 'woocommerce_variation_before_set_stock' : 'woocommerce_product_before_set_stock', $product);
        $stock = array('stock_quantity' => (int) $product->get_stock_quantity() - (int) $item->get_quantity());
        if (property_exists($product, 'agm_changes')) {
            agm_save_product($product, $stock, !empty($GLOBALS['agm']['stock_rewrites_variation_posts']) && $product->is_type('variation'));
        } else {
            $product->set_stock_quantity($stock['stock_quantity']);
            do_action('woocommerce_before_product_object_save', $product, null);
            agm_wc_persist($product);
            do_action('woocommerce_update_product', $product->get_id(), $product);
            do_action('woocommerce_after_product_object_save', $product, null);
        }
        do_action($product->is_type('variation') ? 'woocommerce_variation_set_stock' : 'woocommerce_product_set_stock', $product);
    }
    $order->meta['_order_stock_reduced'] = 'yes';
    do_action('woocommerce_reduce_order_stock', $order);
}

/**
 * WC_Order::maybe_set_date_paid(): the paid date is set on reaching the status a
 * completed payment moves the order to (processing), or completed after it.
 * Cash on delivery moves a completed payment to completed instead, so a cash
 * on delivery order in processing has no paid date until it is delivered.
 */
function agm_order_maybe_set_date_paid(WC_Order $order) {
    if ($order->get_date_paid()) {
        return;
    }
    $complete = $order->get_payment_method() === 'cod' ? 'completed' : 'processing';
    $status = $order->get_status();
    if ($status === $complete || ($complete === 'processing' && $status === 'completed')) {
        $order->props['date_paid'] = agm_date(time());
    }
}

function agm_order_post_written_and_persisted(WC_Order $order) {
    agm_wc_persist($order);
    agm_order_post_written($order, true);
}

/**
 * WC_Order::set_status() then save(), as High-Performance Order Storage runs it:
 *   - reaching the payment-complete status sets the paid date if none is set yet (maybe_set_date_paid);
 *   - an auto-draft counts as pending for the status hooks;
 *   - leaving a draft fires woocommerce_new_order and no woocommerce_update_order;
 *   - moving into or out of the trash fires neither;
 *   - WooCommerce's stock reduction runs first inside woocommerce_order_status_processing
 *     (_completed, _on-hold), before the plugins' callbacks and woocommerce_order_status_changed.
 */
function agm_order_status(WC_Order $order, $to) {
    $drafts = array('new', 'auto-draft', 'draft', 'checkout-draft');
    $previous = $order->get_status();
    $from = $previous === 'auto-draft' ? 'pending' : $previous;
    $order->set_status($to);
    agm_order_maybe_set_date_paid($order);
    agm_order_post_written_and_persisted($order);
    $id = $order->get_id();
    if (in_array($previous, $drafts, true) && !in_array($to, $drafts, true)) {
        do_action('woocommerce_new_order', $id, $order);
    } elseif (!in_array('trash', array($previous, $to), true)) {
        do_action('woocommerce_update_order', $id, $order);
    }
    if ($from === '' || $from === $to) {
        return;
    }
    if (in_array($to, array('processing', 'completed', 'on-hold'), true)) {
        agm_wc_reduce_stock($order);
    }
    do_action('woocommerce_order_status_' . $to, $id, $order, array('from' => $from, 'to' => $to));
    do_action('woocommerce_order_status_' . $from . '_to_' . $to, $id, $order);
    do_action('woocommerce_order_status_changed', $id, $from, $to, $order);
    if (in_array($from, array('pending', 'failed'), true) && in_array($to, wc_get_is_paid_statuses(), true)) {
        do_action('woocommerce_order_payment_status_changed', $id, $order);
    }
}

/** WC_Order::payment_complete(). */
function agm_order_pay(WC_Order $order) {
    $id = $order->get_id();
    do_action('woocommerce_pre_payment_complete', $id, 'txn_' . $id);
    if (!$order->get_date_paid()) {
        $order->props['date_paid'] = agm_date(time());
    }
    $order->props['transaction_id'] = 'txn_' . $id;
    agm_order_status($order, 'processing');
    agm_wc_reduce_stock($order);
    do_action('woocommerce_payment_complete', $id, 'txn_' . $id);
}

/** A plain save of the order, as the order screen's Update button or a plugin makes. */
function agm_order_save(WC_Order $order) {
    agm_wc_persist($order);
    agm_order_post_written($order, true);
    do_action('woocommerce_update_order', $order->get_id(), $order);
}

/** wc_create_refund(): a full refund also moves the order to refunded. */
function agm_order_refund(WC_Order $order, $amount) {
    $refund = new WC_Order_Refund($GLOBALS['agm']['next_id']++, array('amount' => (string) $amount, 'total' => (string) -$amount, 'parent_id' => $order->get_id()));
    $remaining = (float) $order->get_total() - (float) $order->get_total_refunded();
    $order->props['total_refunded'] = (float) $order->get_total_refunded() + (float) $amount;
    if ($remaining - (float) $amount > 0) {
        do_action('woocommerce_order_partially_refunded', $order->get_id(), $refund->get_id());
    } else {
        do_action('woocommerce_order_fully_refunded', $order->get_id(), $refund->get_id());
        agm_order_status($order, 'refunded');
    }
    do_action('woocommerce_refund_created', $refund->get_id(), array('amount' => $amount));
    do_action('woocommerce_order_refunded', $order->get_id(), $refund->get_id());
    return $refund;
}

/**
 * $order->delete(false). 'hpos': the data store sets the status and, with
 * compatibility sync on ($synced_posts), trashes the order's copy in the posts
 * table through wp_trash_post(), then fires woocommerce_trash_order. 'posts':
 * the legacy orders screen trashes through WordPress alone.
 */
function agm_order_trash(WC_Order $order, $via = 'hpos', $synced_posts = false) {
    $id = $order->get_id();
    $previous = $order->get_status();
    $order->meta['_wp_trash_meta_status'] = 'wc-' . $previous;
    if ($via === 'hpos') {
        do_action('woocommerce_before_trash_order', $id, $order);
        $order->set_status('trash');
        if ($synced_posts) {
            do_action('wp_trash_post', $id, 'wc-' . $previous);
            do_action('wp_after_insert_post', $id, agm_order_post($order), true, null);
            do_action('trashed_post', $id, 'wc-' . $previous);
        }
        do_action('woocommerce_trash_order', $id);
        return;
    }
    do_action('wp_trash_post', $id, 'wc-' . $previous);
    $order->set_status('trash');
    do_action('wp_after_insert_post', $id, agm_order_post($order), true, null);
    do_action('trashed_post', $id, 'wc-' . $previous);
}

/** Restoring from the trash: woocommerce_untrash_order, then set_status() and save(), which fires the status hooks but no woocommerce_update_order. */
function agm_order_untrash(WC_Order $order, $via = 'hpos') {
    $id = $order->get_id();
    $previous = substr((string) $order->meta['_wp_trash_meta_status'], 3);
    unset($order->meta['_wp_trash_meta_status']);
    if ($via === 'hpos') {
        do_action('woocommerce_untrash_order', $id, 'wc-' . $previous);
        agm_order_status($order, $previous);
        return;
    }
    do_action('untrash_post', $id, 'wc-' . $previous);
    $order->set_status($previous);
    do_action('wp_after_insert_post', $id, agm_order_post($order), true, null);
    do_action('untrashed_post', $id, 'wc-' . $previous);
}

/** $order->delete(true) with High-Performance Order Storage, or WordPress deleting an order stored as a post. */
function agm_order_delete(WC_Order $order, $via = 'hpos') {
    $id = $order->get_id();
    $post = agm_order_post($order);
    if ($via === 'hpos') {
        do_action('woocommerce_before_delete_order', $id, $order);
        unset($GLOBALS['agm']['orders'][$id]);
        // With sync on, the order's copy in the posts table goes too; the order itself is already gone.
        do_action('before_delete_post', $id, $post);
        do_action('woocommerce_delete_order', $id);
        return;
    }
    do_action('before_delete_post', $id, $post);
    do_action('delete_post', $id, $post);
    unset($GLOBALS['agm']['orders'][$id]);
    do_action('deleted_post', $id, $post);
}

// ── Reading back ──

/** Every record sent to an endpoint, across requests, in order, with money as floats. */
function agm_sent_records($path, $collection) {
    $records = array();
    foreach (agm_http_requests($path) as $request) {
        foreach (isset($request['body'][$collection]) ? $request['body'][$collection] : array() as $record) {
            $records[] = agm_money_floats($record);
        }
    }
    return $records;
}

/** JSON sends 30.0 as 30; every total and price read back as a float, so a test can say 30.0. */
function agm_money_floats($value) {
    if (!is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        if (($key === 'total' || $key === 'price') && is_numeric($item)) {
            $value[$key] = (float) $item;
        } elseif (is_array($item)) {
            $value[$key] = agm_money_floats($item);
        }
    }
    return $value;
}

/** One run of the drain, as Action Scheduler starts it from WP-Cron. Returns why it stopped. */
function agm_drain() {
    $outcome = null;
    agm_request('drain', 'cron', function () use (&$outcome) {
        $outcome = agumail_run_in_background('agumail_drain');
    });
    return $outcome;
}

/**
 * The checkout rule for one simulated shopper request: no call to AguMail (not
 * even one the guard refuses), and at most one write, to the plugin's tables or
 * the account's marketing answer. No order or product saved by the plugin.
 */
function agm_expect_shopper_quiet(array $result) {
    $name = $result['name'];
    $paths = array_map(function ($request) { return $request['method'] . ' ' . $request['path']; }, $result['http']);
    expect_that(!$paths, "\"$name\" called AguMail from the shopper's request: " . implode(', ', $paths));
    $refused = array_filter($result['logs'], function ($entry) { return stripos($entry['message'], 'refused') !== false; });
    expect_that(!$refused, "\"$name\" tried to call AguMail");
    $allowed = function ($write) {
        return $write['kind'] === 'outbox' || $write['kind'] === 'carts'
            || ($write['kind'] === 'user_meta' && $write['name'] === '_agumail_optin');
    };
    $describe = function (array $writes) {
        return implode(', ', array_map(function ($write) {
            return $write['kind'] . ':' . $write['name'] . ($write['detail'] ? ' (' . $write['detail'] . ')' : '');
        }, $writes));
    };
    $ours = array_values(array_filter($result['writes'], $allowed));
    expect_that(count($ours) <= 1, "\"$name\" made " . count($ours) . ' writes (' . $describe($ours) . '); at most one is allowed');
    $other = array_values(array_filter($result['writes'], function ($write) use ($allowed) { return !$allowed($write); }));
    expect_that(!$other, "\"$name\" wrote " . $describe($other) . " from the shopper's request");
}
