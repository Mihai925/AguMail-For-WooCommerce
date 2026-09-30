<?php
/**
 * Plugin Name: AguMail for WooCommerce
 * Plugin URI:  https://agumail.com
 * Description: Syncs your WooCommerce customers, orders, carts and products to AguMail — consent-aware email marketing with abandoned-cart recovery and revenue attribution.
 * Version:     1.2.0
 * Author:      AguMail
 * License:     GPLv2 or later
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_VERSION', '1.2.0');
define('AGUMAIL_OPTION_URL', 'agumail_api_url');
define('AGUMAIL_OPTION_KEY', 'agumail_api_key');
define('AGUMAIL_OPTION_CONSENT_LABEL', 'agumail_consent_label');
define('AGUMAIL_OPTION_SITE_KEY', 'agumail_site_key');
define('AGUMAIL_OPTION_SITE_SRC', 'agumail_site_src');
define('AGUMAIL_CID_COOKIE', 'agm_cid');
define('AGUMAIL_CART_TRANSIENT', 'agumail_pending_carts');
define('AGUMAIL_CART_SESSION_KEY', 'agumail_cart_id');
define('AGUMAIL_CHECKOUT_FIELD', 'agumail/optin');

// ─────────────────────────────────────────────────────────────────────────────
// WooCommerce features this plugin runs on. Orders are read and written through
// the order object, never as posts, so High-Performance Order Storage is fine;
// consent, attribution and carts are captured on the block checkout as well as
// the classic one.
// ─────────────────────────────────────────────────────────────────────────────

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// HTTP client — every call is fire-and-forget from the storefront's
// perspective; failures are logged, never surfaced to shoppers.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_api_request($method, $path, $body = null) {
    $base = rtrim(get_option(AGUMAIL_OPTION_URL, ''), '/');
    $key  = get_option(AGUMAIL_OPTION_KEY, '');
    if (!$base || !$key) {
        return new WP_Error('agumail_not_configured', 'AguMail URL or API key is not configured.');
    }
    $args = array(
        'method'  => $method,
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Bearer ' . $key,
            'Content-Type'  => 'application/json',
        ),
    );
    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }
    $response = wp_remote_request($base . $path, $args);
    if (is_wp_error($response)) {
        error_log('[AguMail] ' . $path . ' failed: ' . $response->get_error_message());
        return $response;
    }
    $code = wp_remote_retrieve_response_code($response);
    $json = json_decode(wp_remote_retrieve_body($response), true);
    if ($code >= 400) {
        $msg = isset($json['error']) ? $json['error'] : ('HTTP ' . $code);
        error_log('[AguMail] ' . $path . ' failed: ' . $msg);
        return new WP_Error('agumail_http_' . $code, $msg);
    }
    return $json;
}

// ─────────────────────────────────────────────────────────────────────────────
// Attribution cookie: AguMail campaign links land with ?agm_cid=<campaignId>.
// We keep it for 30 days and send it back on orders for revenue attribution.
// ─────────────────────────────────────────────────────────────────────────────

add_action('init', function () {
    if (isset($_GET[AGUMAIL_CID_COOKIE]) && !headers_sent()) {
        $cid = sanitize_text_field(wp_unslash($_GET[AGUMAIL_CID_COOKIE]));
        if ($cid !== '' && strlen($cid) <= 100) {
            setcookie(AGUMAIL_CID_COOKIE, $cid, time() + 30 * DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN);
            $_COOKIE[AGUMAIL_CID_COOKIE] = $cid;
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// On-site tracking: the AguMail script, so a flow can follow up with somebody
// who looked at a product and left. It only ever records people AguMail already
// knows, recognised when they arrive from one of the shop's own emails.
//
// The script identifies a logged-in customer outright, which is the one thing a
// plugin can do that a pasted tag cannot.
// ─────────────────────────────────────────────────────────────────────────────

add_action('wp_enqueue_scripts', function () {
    $src  = get_option(AGUMAIL_OPTION_SITE_SRC, '');
    $key  = get_option(AGUMAIL_OPTION_SITE_KEY, '');
    $base = rtrim(get_option(AGUMAIL_OPTION_URL, ''), '/');
    if ($src === '' || $key === '' || $base === '') {
        return;
    }
    wp_enqueue_script('agumail-site', $src, array(), AGUMAIL_VERSION, false);
    wp_script_add_data('agumail-site', 'async', true);
    // wp_enqueue_script has no way to add arbitrary attributes, and the script
    // reads both of these off its own tag.
    add_filter('script_loader_tag', function ($tag, $handle) use ($key, $base) {
        if ($handle !== 'agumail-site') {
            return $tag;
        }
        $endpoint = $base . '/api/public/site/' . rawurlencode($key) . '/events';
        return str_replace(
            '<script ',
            '<script data-site="' . esc_attr($key) . '" data-endpoint="' . esc_url($endpoint) . '" ',
            $tag
        );
    }, 10, 2);

    // A customer who is signed in is known without waiting for an email click.
    $user = wp_get_current_user();
    if ($user && !empty($user->user_email) && agumail_user_marketing_optin($user->ID)) {
        wp_add_inline_script(
            'agumail-site',
            'window.addEventListener("load",function(){if(window.agumail)window.agumail.identify(' .
                wp_json_encode($user->user_email) . ');});'
        );
    }
});

function agumail_current_campaign_ref() {
    if (!isset($_COOKIE[AGUMAIL_CID_COOKIE])) {
        return null;
    }
    $cid = sanitize_text_field(wp_unslash($_COOKIE[AGUMAIL_CID_COOKIE]));
    return $cid !== '' ? $cid : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Checkout consent checkbox — unchecked means the contact lands in AguMail as
// "unconfirmed" (not mailable). AguMail never assumes permission.
//
// A store runs one of two checkouts. The classic one renders the box from a
// template hook and posts it with the form; the block checkout only shows
// fields registered with WooCommerce, and keeps the answer on the order itself.
// agumail_order_opted_in() reads whichever of the two the order went through.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_consent_label() {
    return get_option(AGUMAIL_OPTION_CONSENT_LABEL, __('Email me about new products and offers', 'agumail'));
}

add_action('woocommerce_review_order_before_submit', function () {
    woocommerce_form_field('agumail_optin', array(
        'type'  => 'checkbox',
        'class' => array('form-row', 'agumail-optin'),
        'label' => esc_html(agumail_consent_label()),
    ), WC()->checkout->get_value('agumail_optin'));
});

add_action('woocommerce_init', function () {
    // WooCommerce 8.9 and later. On older stores only the classic checkout asks.
    if (!function_exists('woocommerce_register_additional_checkout_field')) {
        return;
    }
    woocommerce_register_additional_checkout_field(array(
        'id'       => AGUMAIL_CHECKOUT_FIELD,
        'label'    => agumail_consent_label(),
        'location' => 'order',
        'type'     => 'checkbox',
    ));
});

// The answer and the campaign that brought the shopper are written onto the
// order while it is created, because that is the shopper's own request, cookie
// included. The order reaches AguMail once it is paid, often from a payment
// gateway's callback that carries neither.
add_action('woocommerce_checkout_create_order', function ($order) {
    $order->update_meta_data('_agumail_optin', empty($_POST['agumail_optin']) ? 'no' : 'yes');
    agumail_remember_campaign_ref($order);
});

add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order) {
    agumail_remember_campaign_ref($order);
    $order->save_meta_data();
});

function agumail_remember_campaign_ref($order) {
    $cid = agumail_current_campaign_ref();
    if ($cid) {
        $order->update_meta_data('_agumail_cid', $cid);
    }
}

function agumail_order_opted_in($order) {
    return $order->get_meta('_agumail_optin') === 'yes' || (bool) agumail_block_checkout_answer($order);
}

function agumail_block_checkout_answer($order) {
    if (class_exists('\Automattic\WooCommerce\Blocks\Package')
        && class_exists('\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields')) {
        $fields = \Automattic\WooCommerce\Blocks\Package::container()->get(
            \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class
        );
        return $fields->get_field_from_object(AGUMAIL_CHECKOUT_FIELD, $order, 'other');
    }
    return $order->get_meta('_wc_other/' . AGUMAIL_CHECKOUT_FIELD);
}

// ─────────────────────────────────────────────────────────────────────────────
// Contacts: new WP users and checkout customers.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_contact_payload($email, $first, $last, $opted_in, $source) {
    $contact = array('email' => $email);
    if ($first) {
        $contact['firstName'] = $first;
    }
    if ($last) {
        $contact['lastName'] = $last;
    }
    $contact['tags']    = array('woocommerce');
    $contact['consent'] = array('optedIn' => (bool) $opted_in, 'source' => $source);
    return $contact;
}

add_action('user_register', function ($user_id) {
    $user = get_userdata($user_id);
    if (!$user || !$user->user_email) {
        return;
    }
    // Account creation alone is NOT marketing consent.
    agumail_api_request('POST', '/api/v1/contacts', array(
        'contacts' => array(agumail_contact_payload($user->user_email, $user->first_name, $user->last_name, false, 'wp_register')),
    ));
});

// ─────────────────────────────────────────────────────────────────────────────
// Orders: sent once they are paid, and again whenever what they are worth
// changes.
//
// "Paid" is WooCommerce's own list (processing and completed unless a store
// says otherwise). An order AguMail holds starts post-purchase flows and counts
// as revenue, so a declined card or a bank transfer nobody made must never get
// there. The server keys orders by id: a second delivery updates the record.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_is_paid($order) {
    return $order->has_status(wc_get_is_paid_statuses());
}

/** What the order is still worth: its total less refunds. A cancelled or fully refunded order is worth nothing. */
function agumail_order_value($order) {
    if ($order->has_status(array('cancelled', 'refunded', 'failed'))) {
        return 0.0;
    }
    return max(0.0, (float) $order->get_total() - (float) $order->get_total_refunded());
}

function agumail_order_payload($order) {
    $items = array();
    foreach ($order->get_items() as $item) {
        $line = array(
            'externalId' => (string) $item->get_product_id(),
            'title'      => $item->get_name(),
            'qty'        => (int) $item->get_quantity(),
            'price'      => (float) $order->get_item_total($item),
        );
        // The stock code, so a segment can ask "bought SKU X". A variation
        // carries its own; a deleted product carries none.
        $line_product = $item->get_product();
        if ($line_product && $line_product->get_sku()) {
            $line['sku'] = (string) $line_product->get_sku();
        }
        $items[] = $line;
    }
    $payload = array(
        'externalId' => (string) $order->get_id(),
        'email'      => $order->get_billing_email(),
        'total'      => agumail_order_value($order),
        'currency'   => $order->get_currency(),
        'items'      => $items,
        'occurredAt' => gmdate('c', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time()),
        'source'     => 'woocommerce',
    );
    // Sent on every delivery, not just the first: AguMail re-totals the
    // campaign an order names, and an update without it would leave that
    // campaign quoting money the store has since given back.
    $cid = $order->get_meta('_agumail_cid');
    if ($cid) {
        $payload['campaignRef'] = $cid;
    }
    return $payload;
}

function agumail_push_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !$order->get_billing_email() || !agumail_is_paid($order)) {
        return;
    }
    $opted_in = agumail_order_opted_in($order);

    // Contact first so the order can attach to it.
    agumail_api_request('POST', '/api/v1/contacts', array(
        'contacts' => array(agumail_contact_payload(
            $order->get_billing_email(),
            $order->get_billing_first_name(),
            $order->get_billing_last_name(),
            $opted_in,
            'checkout'
        )),
    ));

    // A signed-in customer who ticked the box has said yes on their account
    // too, which is what the on-site script and the bulk sync read.
    if ($opted_in && $order->get_customer_id()) {
        update_user_meta($order->get_customer_id(), '_agumail_optin', 'yes');
    }

    $result = agumail_api_request('POST', '/api/v1/orders', array('orders' => array(agumail_order_payload($order))));
    if (!is_wp_error($result)) {
        $order->update_meta_data('_agumail_synced', 'yes');
        $order->save_meta_data();
    }

    // The purchase closes this shopper's cart — drop any pending snapshot.
    agumail_forget_cart($order->get_billing_email());
}

add_action('woocommerce_order_status_processing', 'agumail_push_order', 20);
add_action('woocommerce_order_status_completed', 'agumail_push_order', 20);

/**
 * A refund or a cancellation changes what an order AguMail already holds is
 * worth. Orders it never received stay that way.
 */
function agumail_update_order_value($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_agumail_synced') !== 'yes') {
        return;
    }
    agumail_api_request('POST', '/api/v1/orders', array('orders' => array(agumail_order_payload($order))));
}

add_action('woocommerce_order_refunded', 'agumail_update_order_value', 20);
add_action('woocommerce_order_status_cancelled', 'agumail_update_order_value', 20);

// ─────────────────────────────────────────────────────────────────────────────
// Carts: snapshot on every cart change (when we know an email), buffered in a
// transient and flushed by WP-Cron every 15 minutes. AguMail's server decides
// abandonment (idle > threshold) and fires the recovery automation.
//
// Each cart gets its own id, kept in the shopper's session. AguMail moves a
// cart forward only (open, abandoned, paid), so a shopper whose every cart
// shared one id would have exactly one cart to recover, ever. An emptied cart
// is sent empty, which the server never treats as abandoned, and whatever the
// shopper adds next starts a new id.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_cart_email() {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if ($user->user_email) {
            return $user->user_email;
        }
    }
    if (function_exists('WC') && WC()->customer) {
        $billing = WC()->customer->get_billing_email();
        if ($billing) {
            return $billing;
        }
    }
    return null;
}

function agumail_cart_snapshot($id, $email, $items, $total) {
    return array(
        'externalId'     => $id,
        'email'          => $email,
        'total'          => (float) $total,
        'currency'       => get_woocommerce_currency(),
        'items'          => $items,
        'checkoutUrl'    => wc_get_checkout_url(),
        'status'         => 'open',
        'lastActivityAt' => gmdate('c'),
        'source'         => 'woocommerce',
    );
}

function agumail_queue_cart($snapshot) {
    $pending                          = get_transient(AGUMAIL_CART_TRANSIENT) ?: array();
    $pending[$snapshot['externalId']] = $snapshot;
    set_transient(AGUMAIL_CART_TRANSIENT, $pending, DAY_IN_SECONDS);
}

function agumail_remember_cart($email = null) {
    if (!function_exists('WC') || !WC()->cart || !WC()->session) {
        return;
    }
    if (WC()->cart->is_empty()) {
        agumail_close_cart();
        return;
    }
    $email = $email ?: agumail_cart_email();
    if (!$email) {
        return; // anonymous guest — nothing to recover yet
    }
    $items = array();
    foreach (WC()->cart->get_cart() as $cart_item) {
        $product = $cart_item['data'];
        if (!$product) {
            continue;
        }
        $image_id = $product->get_image_id();
        $item     = array(
            'title'             => $product->get_name(),
            'qty'               => (int) $cart_item['quantity'],
            'price'             => (float) $product->get_price(),
            'url'               => get_permalink($product->get_id()),
            // The recovery email shows each line with its picture; AguMail falls
            // back to the synced product's image when this is missing.
            'productExternalId' => (string) $product->get_id(),
        );
        if ($product->get_sku()) {
            $item['sku'] = (string) $product->get_sku();
        }
        if ($image_id) {
            $item['imageUrl'] = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');
        }
        $items[] = $item;
    }
    $id = WC()->session->get(AGUMAIL_CART_SESSION_KEY);
    if (!$id) {
        $id = 'wc-' . wp_generate_uuid4();
        WC()->session->set(AGUMAIL_CART_SESSION_KEY, $id);
    }
    agumail_queue_cart(agumail_cart_snapshot($id, $email, $items, WC()->cart->get_total('edit')));
}

/** The shopper's cart is empty: AguMail's copy empties too, and the id is retired. */
function agumail_close_cart() {
    if (!function_exists('WC') || !WC()->session) {
        return;
    }
    $id = WC()->session->get(AGUMAIL_CART_SESSION_KEY);
    if (!$id) {
        return; // never reported, so there is nothing to close
    }
    $email = agumail_cart_email();
    if ($email) {
        agumail_queue_cart(agumail_cart_snapshot($id, $email, array(), 0));
    }
    WC()->session->set(AGUMAIL_CART_SESSION_KEY, null);
}

function agumail_forget_cart($email) {
    $pending = get_transient(AGUMAIL_CART_TRANSIENT) ?: array();
    $kept    = array_filter($pending, function ($snapshot) use ($email) {
        return $snapshot['email'] !== $email;
    });
    if (count($kept) !== count($pending)) {
        set_transient(AGUMAIL_CART_TRANSIENT, $kept, DAY_IN_SECONDS);
    }
}

add_action('woocommerce_add_to_cart', 'agumail_remember_cart', 20, 0);
add_action('woocommerce_cart_item_removed', 'agumail_remember_cart', 20, 0);
add_action('woocommerce_cart_item_set_quantity', 'agumail_remember_cart', 20, 0);
add_action('woocommerce_cart_emptied', 'agumail_close_cart', 20, 0);

// A guest becomes somebody to recover the moment they type an email at
// checkout, which is also when the cart matters most. The classic checkout
// reports its fields as it refreshes the order review; the block checkout
// updates the customer through the Store API.
add_action('woocommerce_checkout_update_order_review', function ($posted) {
    parse_str((string) $posted, $fields);
    $email = isset($fields['billing_email']) ? sanitize_email($fields['billing_email']) : '';
    if (is_email($email)) {
        agumail_remember_cart($email);
    }
});

add_action('woocommerce_store_api_cart_update_customer_from_request', function ($customer) {
    $email = $customer->get_billing_email();
    if ($email) {
        agumail_remember_cart($email);
    }
});

// WP-Cron: flush buffered cart snapshots every 15 minutes.
add_filter('cron_schedules', function ($schedules) {
    $schedules['agumail_15min'] = array(
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __('Every 15 minutes (AguMail)', 'agumail'),
    );
    return $schedules;
});

add_action('agumail_flush_carts', function () {
    $pending = get_transient(AGUMAIL_CART_TRANSIENT) ?: array();
    if (empty($pending)) {
        return;
    }
    $result = agumail_api_request('POST', '/api/v1/carts', array('carts' => array_values($pending)));
    if (!is_wp_error($result)) {
        delete_transient(AGUMAIL_CART_TRANSIENT);
    }
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('agumail_flush_carts')) {
        wp_schedule_event(time() + 60, 'agumail_15min', 'agumail_flush_carts');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('agumail_flush_carts');
});

// ─────────────────────────────────────────────────────────────────────────────
// Products: keep the AguMail product picker fresh.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_product_payload($product) {
    $image_id = $product->get_image_id();
    // The categories this product is filed under, by name. AguMail copies them
    // onto every order that contains the product, which is what makes
    // "ordered from Gifts in the last 90 days" a segment a shop owner can write.
    $categories = array();
    $terms      = get_the_terms($product->get_id(), 'product_cat');
    if (is_array($terms)) {
        foreach (array_slice($terms, 0, 20) as $term) {
            $categories[] = $term->name;
        }
    }
    $payload = array(
        'externalId' => (string) $product->get_id(),
        'title'      => $product->get_name(),
        'price'      => (float) $product->get_price(),
        'currency'   => get_woocommerce_currency(),
        'imageUrl'   => $image_id ? wp_get_attachment_url($image_id) : null,
        'productUrl' => get_permalink($product->get_id()),
        'categories' => $categories,
        'status'     => $product->get_status() === 'publish' ? 'active' : 'archived',
        'source'     => 'woocommerce',
    );
    if ($product->get_sku()) {
        $payload['sku'] = (string) $product->get_sku();
    }
    return $payload;
}

add_action('save_post_product', function ($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') {
        return;
    }
    $product = wc_get_product($post_id);
    if ($product) {
        agumail_api_request('POST', '/api/v1/products', array('products' => array(agumail_product_payload($product))));
    }
}, 20, 3);

// ─────────────────────────────────────────────────────────────────────────────
// Bulk sync (admin button): customers → products → recent orders.
// ─────────────────────────────────────────────────────────────────────────────

// How many records we batch into a single API call, and how many users/orders
// we pull per DB page. Kept modest so memory stays bounded on large stores.
define('AGUMAIL_SYNC_PAGE_SIZE', 500);

// Returns the number of records the server confirmed it accepted for a contacts
// push. The API may echo an explicit count (e.g. {"accepted": 12}); if it does
// not, we fall back to the count we sent, since a non-error response means the
// server took the batch. WP_Error responses count as zero accepted.
function agumail_accepted_count($result, $sent) {
    if (is_wp_error($result)) {
        return 0;
    }
    if (is_array($result)) {
        if (isset($result['accepted']) && is_numeric($result['accepted'])) {
            return (int) $result['accepted'];
        }
        // Some endpoints return the stored records under a key; count them.
        if (isset($result['contacts']) && is_array($result['contacts'])) {
            return count($result['contacts']);
        }
    }
    return (int) $sent;
}

// Reads WooCommerce's real marketing-consent signal for a WP user.
//
// WooCommerce core does not store a single canonical "marketing opt-in" user
// meta key, so we look, in order, at:
//   1. This plugin's own per-user opt-in meta (`_agumail_optin`), written when
//      a signed-in customer opts in at checkout (see agumail_push_order).
//   2. The de-facto community key used by several marketing integrations
//      (`marketing_opt_in` user meta, value 'yes').
// Anything else is treated as unknown → optedIn=false so the server gates the
// contact as unconfirmed. We never assume permission.
function agumail_user_marketing_optin($user_id) {
    $plugin_optin = get_user_meta($user_id, '_agumail_optin', true);
    if ($plugin_optin === 'yes' || $plugin_optin === '1' || $plugin_optin === true) {
        return true;
    }
    $wc_optin = get_user_meta($user_id, 'marketing_opt_in', true);
    if ($wc_optin === 'yes' || $wc_optin === '1' || $wc_optin === true) {
        return true;
    }
    return false; // unknown → unconfirmed; server gates non-opted-in contacts
}

function agumail_bulk_sync() {
    $summary = array('contacts' => 0, 'products' => 0, 'orders' => 0);

    // Large stores can exceed the default PHP time limit; lift the cap so a
    // legitimate full sync is not silently truncated half-way through.
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }

    // Customers — paginated so we never silently truncate stores larger than a
    // single page. We page through users, batching contacts into API-sized
    // chunks and counting only what the server confirms it accepted.
    $batch = array();
    $paged = 1;
    do {
        $customers = get_users(array(
            'role__in' => array('customer', 'subscriber'),
            'number'   => AGUMAIL_SYNC_PAGE_SIZE,
            'paged'    => $paged,
            'orderby'  => 'ID',
            'order'    => 'ASC',
        ));
        foreach ($customers as $user) {
            if (!$user->user_email) {
                continue;
            }
            // Push the customer's true marketing-consent signal, not a blanket
            // false. Unknown consent still resolves to optedIn=false below.
            $opted_in = agumail_user_marketing_optin($user->ID);
            $batch[]  = agumail_contact_payload(
                $user->user_email,
                $user->first_name,
                $user->last_name,
                $opted_in,
                'bulk_sync'
            );
            if (count($batch) === AGUMAIL_SYNC_PAGE_SIZE) {
                $result = agumail_api_request('POST', '/api/v1/contacts', array('contacts' => $batch));
                $summary['contacts'] += agumail_accepted_count($result, count($batch));
                $batch = array();
            }
        }
        $paged++;
    } while (count($customers) === AGUMAIL_SYNC_PAGE_SIZE);
    if ($batch) {
        $result = agumail_api_request('POST', '/api/v1/contacts', array('contacts' => $batch));
        $summary['contacts'] += agumail_accepted_count($result, count($batch));
    }

    // Products — paginated likewise so catalogs larger than one page sync fully.
    $page = 1;
    do {
        $products = wc_get_products(array(
            'limit'   => AGUMAIL_SYNC_PAGE_SIZE,
            'page'    => $page,
            'status'  => 'publish',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ));
        if ($products) {
            agumail_api_request('POST', '/api/v1/products', array(
                'products' => array_map('agumail_product_payload', $products),
            ));
            $summary['products'] += count($products);
        }
        $page++;
    } while (count($products) === AGUMAIL_SYNC_PAGE_SIZE);

    // Paid orders from the last 90 days, paginated so high-volume stores sync
    // fully, and paid only for the reason agumail_push_order gives.
    $since = '>' . gmdate('Y-m-d', time() - 90 * DAY_IN_SECONDS);
    $page  = 1;
    do {
        $orders = wc_get_orders(array(
            'limit'        => AGUMAIL_SYNC_PAGE_SIZE,
            'page'         => $page,
            'status'       => wc_get_is_paid_statuses(),
            'date_created' => $since,
            'orderby'      => 'ID',
            'order'        => 'ASC',
        ));
        foreach ($orders as $order) {
            agumail_push_order($order->get_id());
            $summary['orders']++;
        }
        $page++;
    } while (count($orders) === AGUMAIL_SYNC_PAGE_SIZE);

    return $summary;
}

// ─────────────────────────────────────────────────────────────────────────────
// Settings page: WooCommerce → AguMail.
// ─────────────────────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        'AguMail',
        'AguMail',
        'manage_woocommerce',
        'agumail',
        'agumail_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('agumail', AGUMAIL_OPTION_URL, array('sanitize_callback' => 'esc_url_raw'));
    register_setting('agumail', AGUMAIL_OPTION_KEY, array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('agumail', AGUMAIL_OPTION_CONSENT_LABEL, array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('agumail', AGUMAIL_OPTION_SITE_KEY, array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('agumail', AGUMAIL_OPTION_SITE_SRC, array('sanitize_callback' => 'esc_url_raw'));
});

function agumail_render_settings_page() {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $notice = null;

    if (isset($_POST['agumail_action']) && check_admin_referer('agumail_tools')) {
        if ($_POST['agumail_action'] === 'test') {
            $result = agumail_api_request('GET', '/api/v1/ping');
            if (is_wp_error($result)) {
                $notice = array('error', 'Connection failed: ' . esc_html($result->get_error_message()));
            } else {
                // The ping proves the key; the connect is what AguMail can refuse
                // (a plan that already has its store connected, say), and a
                // refusal has to reach the person who pressed the button rather
                // than a log file.
                $connected = agumail_api_request('POST', '/api/v1/connect', array(
                    'platform' => 'woocommerce',
                    'storeUrl' => home_url(),
                    'currency' => get_woocommerce_currency(),
                ));
                $notice = is_wp_error($connected)
                    ? array('error', 'The key works, but AguMail did not connect this store: ' . esc_html($connected->get_error_message()))
                    : array('success', 'Connected to AguMail as “' . esc_html($result['shop']['shopName'] ?: 'your business') . '”.');
            }
        } elseif ($_POST['agumail_action'] === 'sync') {
            $summary = agumail_bulk_sync();
            $notice  = array('success', sprintf(
                'Synced %d contacts, %d products and %d orders to AguMail.',
                $summary['contacts'],
                $summary['products'],
                $summary['orders']
            ));
        }
    }
    ?>
    <div class="wrap">
        <h1>AguMail</h1>
        <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo wp_kses_post($notice[1]); ?></p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('agumail'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="agumail_api_url">AguMail URL</label></th>
                    <td>
                        <input name="<?php echo esc_attr(AGUMAIL_OPTION_URL); ?>" id="agumail_api_url" type="url" class="regular-text"
                               value="<?php echo esc_attr(get_option(AGUMAIL_OPTION_URL, '')); ?>" placeholder="https://api.agumail.com" />
                        <p class="description">The server URL from your AguMail account.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="agumail_api_key">API key</label></th>
                    <td>
                        <input name="<?php echo esc_attr(AGUMAIL_OPTION_KEY); ?>" id="agumail_api_key" type="password" class="regular-text"
                               value="<?php echo esc_attr(get_option(AGUMAIL_OPTION_KEY, '')); ?>" placeholder="agm_…" autocomplete="off" />
                        <p class="description">Create one in AguMail → Settings → API keys (choose “WooCommerce”).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="agumail_consent_label">Checkout consent label</label></th>
                    <td>
                        <input name="<?php echo esc_attr(AGUMAIL_OPTION_CONSENT_LABEL); ?>" id="agumail_consent_label" type="text" class="large-text"
                               value="<?php echo esc_attr(get_option(AGUMAIL_OPTION_CONSENT_LABEL, 'Email me about new products and offers')); ?>" />
                        <p class="description">Shown as a checkbox at checkout. Customers who leave it unchecked are stored as non-mailable.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="agumail_site_key">Website tracking key</label></th>
                    <td>
                        <input name="<?php echo esc_attr(AGUMAIL_OPTION_SITE_KEY); ?>" id="agumail_site_key" type="text" class="regular-text"
                               value="<?php echo esc_attr(get_option(AGUMAIL_OPTION_SITE_KEY, '')); ?>" autocomplete="off" />
                        <p class="description">From AguMail &rarr; Settings &rarr; Website tracking. Lets a flow follow up with somebody who looked at a product and left.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="agumail_site_src">Website tracking script</label></th>
                    <td>
                        <input name="<?php echo esc_attr(AGUMAIL_OPTION_SITE_SRC); ?>" id="agumail_site_src" type="url" class="large-text"
                               value="<?php echo esc_attr(get_option(AGUMAIL_OPTION_SITE_SRC, '')); ?>" placeholder="https://…/p/_/v1/site.js?v=…" />
                        <p class="description">The <code>src</code> from the same snippet. Leave both blank to keep tracking off.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save settings'); ?>
        </form>

        <hr />
        <h2>Tools</h2>
        <form method="post">
            <?php wp_nonce_field('agumail_tools'); ?>
            <p>
                <button class="button" name="agumail_action" value="test">Test connection</button>
                <button class="button button-primary" name="agumail_action" value="sync"
                        onclick="return confirm('Sync customers, products and recent orders to AguMail now?');">
                    Sync store data now
                </button>
            </p>
            <p class="description">
                Bulk sync pushes existing customers (as non-mailable until they opt in), published products,
                and orders from the last 90 days. New activity syncs automatically.
            </p>
        </form>
    </div>
    <?php
}
