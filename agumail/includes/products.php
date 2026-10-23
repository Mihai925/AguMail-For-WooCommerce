<?php
// Products: the catalogue AguMail's emails pick from, kept current.
//
// A product is queued once its save is complete, and the drain builds the
// record from the product as it is then. save_post_product is not used: it
// fires after WordPress has written the post but before WooCommerce writes the
// prices and SKU, so whatever it read was the product a moment before.
//
// Two writers save a product, and both are watched:
//   - WooCommerce (the product data box, the REST API, imports, scheduled
//     sales, code): woocommerce_before_product_object_save shows what the save
//     changes, and the product is queued when the save is done, only if that
//     touches something AguMail shows. A save that changes only the stock,
//     as every order placed does, queues nothing.
//   - WordPress (the title, status, slug, categories and image of the editor,
//     quick and bulk edit, scheduled publishing, the trash): these never show
//     in WooCommerce's changes, so any save of the post queues the product,
//     once it is complete (wp_after_insert_post). A post WooCommerce writes
//     itself in the middle of its own save is left to that save: a variation
//     rewrites its post (a regenerated title) even when only its stock moved,
//     and that must queue nothing while an order is placed.
// Renaming a category queues the products filed under it.
// A variation is not a product of AguMail's catalogue: a change to one queues
// its parent, whose price is the lowest of its variations.

if (!defined('ABSPATH')) {
    exit;
}

/** The product fields a record is built from: a save that touches none of them queues nothing. */
function agumail_product_synced_props() {
    return array('name', 'regular_price', 'sale_price', 'price', 'date_on_sale_from', 'date_on_sale_to',
        'sku', 'image_id', 'status', 'category_ids', 'slug', 'catalog_visibility');
}

// ─────────────────────────────────────────────────────────────────────────────
// Saves through WooCommerce.
// ─────────────────────────────────────────────────────────────────────────────

add_action('woocommerce_before_product_object_save', 'agumail_product_before_save', 10, 1);
add_action('woocommerce_after_product_object_save', 'agumail_product_after_save', 10, 1);
add_action('woocommerce_new_product', 'agumail_product_created', 10, 1);
add_action('woocommerce_update_product', 'agumail_product_updated', 10, 1);
add_action('woocommerce_new_product_variation', 'agumail_product_variation_created', 10, 2);
add_action('woocommerce_update_product_variation', 'agumail_product_variation_updated', 10, 2);

/** Products (and variations) whose save in progress changes a synced field, by id. */
function &agumail_product_pending() {
    static $pending = array();
    return $pending;
}

/**
 * Saves WooCommerce has begun and not finished: existing products by id, new
 * ones (which have no id until the data store inserts their post) by object.
 */
function &agumail_product_saving() {
    static $saving = array('ids' => array(), 'new' => array());
    return $saving;
}

function agumail_product_before_save($product) {
    if (!is_a($product, 'WC_Product')) {
        return;
    }
    $saving = &agumail_product_saving();
    $id = (int) $product->get_id();
    if (!$id) {
        $saving['new'][spl_object_hash($product)] = true;
        return;
    }
    $saving['ids'][$id] = isset($saving['ids'][$id]) ? $saving['ids'][$id] + 1 : 1;
    if (array_intersect(array_keys((array) $product->get_changes()), agumail_product_synced_props())) {
        $pending = &agumail_product_pending();
        $pending[$id] = true;
    }
}

function agumail_product_after_save($product) {
    if (!is_a($product, 'WC_Product')) {
        return;
    }
    $saving = &agumail_product_saving();
    $hash = spl_object_hash($product);
    if (isset($saving['new'][$hash])) {
        unset($saving['new'][$hash]);
        return;
    }
    $id = (int) $product->get_id();
    if (isset($saving['ids'][$id]) && --$saving['ids'][$id] <= 0) {
        unset($saving['ids'][$id]);
    }
}

/**
 * Whether WooCommerce wrote this post inside a save of its own: the product's
 * save is under way, or a new product is being created (its post is inserted
 * before it has an id to be known by).
 */
function agumail_product_post_inside_save($post_id, $update) {
    $saving = &agumail_product_saving();
    return isset($saving['ids'][(int) $post_id]) || (!$update && !empty($saving['new']));
}

/** Whether the save of $id that just finished changed a synced field; the flag is used up. */
function agumail_product_take_pending($id) {
    $pending = &agumail_product_pending();
    $id = (int) $id;
    if (!isset($pending[$id])) {
        return false;
    }
    unset($pending[$id]);
    return true;
}

/** A new product: everything about it is new. */
function agumail_product_created($product_id) {
    agumail_product_take_pending($product_id);
    agumail_product_queue($product_id);
}

function agumail_product_updated($product_id) {
    if (agumail_product_take_pending($product_id)) {
        agumail_product_queue($product_id);
    }
}

function agumail_product_variation_created($variation_id, $variation = null) {
    agumail_product_take_pending($variation_id);
    agumail_product_queue(agumail_product_parent_of($variation_id, $variation));
}

function agumail_product_variation_updated($variation_id, $variation = null) {
    if (agumail_product_take_pending($variation_id)) {
        agumail_product_queue(agumail_product_parent_of($variation_id, $variation));
    }
}

function agumail_product_parent_of($variation_id, $variation = null) {
    if (!is_a($variation, 'WC_Product')) {
        $variation = wc_get_product($variation_id);
    }
    return $variation ? (int) $variation->get_parent_id() : 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Saves through WordPress, the trash, and deletion.
// ─────────────────────────────────────────────────────────────────────────────

add_action('wp_after_insert_post', 'agumail_product_post_saved', 10, 3);
add_action('trashed_post', 'agumail_product_post_changed', 10, 1);
add_action('untrashed_post', 'agumail_product_post_changed', 10, 1);
add_action('before_delete_post', 'agumail_product_post_deleted', 10, 2);
add_action('edited_product_cat', 'agumail_product_category_edited', 10, 1);

/**
 * The catalogue product a post stands for: a product's own id, a variation's
 * parent, or 0 for any other post.
 */
function agumail_product_for_post($post_id, $post = null) {
    $type = is_object($post) && isset($post->post_type) ? $post->post_type : get_post_type($post_id);
    if ($type !== 'product' && $type !== 'product_variation') {
        return 0;
    }
    if ($type === 'product_variation' && is_object($post) && !empty($post->post_parent)) {
        return (int) $post->post_parent;
    }
    $product = wc_get_product($post_id);
    if ($product && $product->is_type('variation')) {
        return (int) $product->get_parent_id();
    }
    return (int) $post_id;
}

// WordPress fires these for every post, WooCommerce loaded or not.
function agumail_product_post_saved($post_id, $post = null, $update = true) {
    if (!function_exists('wc_get_product')) {
        return;
    }
    if (is_object($post) && isset($post->post_status) && $post->post_status === 'auto-draft') {
        return;
    }
    $target = agumail_product_for_post($post_id, $post);
    if ($target && !agumail_product_post_inside_save($post_id, (bool) $update)) {
        agumail_product_queue($target);
    }
}

/** A category renamed (or moved, or its slug changed): every product filed under it carries its name. */
function agumail_product_category_edited($term_id) {
    if (!function_exists('wc_get_product')) {
        return;
    }
    $ids = get_objects_in_term((int) $term_id, 'product_cat');
    foreach (is_array($ids) ? $ids : array() as $id) {
        agumail_product_queue($id);
    }
}

function agumail_product_post_changed($post_id) {
    if (function_exists('wc_get_product')) {
        agumail_product_queue(agumail_product_for_post($post_id));
    }
}

/**
 * A product about to be deleted goes as archived. Once it is gone there is
 * nothing to build its record from, so the record goes into the queue now.
 * A variation's parent is queued instead, unless the parent is going too. An
 * auto-draft (the empty product every "Add New" leaves, which WordPress clears
 * out after a week) was never a product.
 */
function agumail_product_post_deleted($post_id, $post = null) {
    if (!function_exists('wc_get_product')) {
        return;
    }
    $target = agumail_product_for_post($post_id, $post);
    if ($target !== (int) $post_id) {
        agumail_product_queue($target);
        return;
    }
    $product = $target ? wc_get_product($target) : null;
    if (!$product || $product->get_status() === 'auto-draft') {
        return;
    }
    $record = agumail_product_record($product);
    $record['status'] = 'archived';
    agumail_enqueue('product', $target, array('record' => $record));
}

/**
 * Queues a catalogue product. A product queued as deleted stays so: what a
 * variation deleted after it, or any later hook, says can no longer be built.
 */
function agumail_product_queue($product_id) {
    $product_id = (int) $product_id;
    if ($product_id <= 0 || !agumail_ready() || !agumail_has_api_key()) {
        return false;
    }
    $queued = agumail_store()->find('product', (string) $product_id);
    if ($queued && agumail_product_stored_record($queued)) {
        return false;
    }
    return agumail_enqueue('product', $product_id);
}

// ─────────────────────────────────────────────────────────────────────────────
// The record, built when the drain sends it.
// ─────────────────────────────────────────────────────────────────────────────

agumail_register_builder('product', array(
    'endpoint'   => '/api/v1/products',
    'collection' => 'products',
    'phase'      => 20,
    'build'      => 'agumail_build_product',
    'store'      => true,
));

function agumail_product_stored_record($row) {
    return is_array($row['payload']) && isset($row['payload']['record']) && is_array($row['payload']['record'])
        ? $row['payload']['record']
        : null;
}

function agumail_build_product($key, $row) {
    $stored = agumail_product_stored_record($row);
    if ($stored) {
        return $stored;
    }
    $product = wc_get_product((int) $key);
    if (!$product || $product->is_type('variation') || $product->get_status() === 'auto-draft') {
        return null;
    }
    return agumail_product_record($product);
}

/**
 * The product as AguMail takes it. The price is the one WooCommerce stores: a
 * product on sale has its sale price there, and a variable product the lowest
 * price among its variations. Published and shown in the catalogue is active;
 * anything else (draft, private, the trash, hidden) is archived, so emails do
 * not offer it.
 *
 * AguMail keeps a field a record leaves out, so a removed image or SKU is sent
 * as an empty string, which its schema takes and every email reads as none. A
 * price can only be a number, so a product without one leaves AguMail's.
 */
function agumail_product_record($product) {
    $record = array(
        'externalId' => (string) $product->get_id(),
        'title'      => agumail_catalogue_text($product->get_name(), AGUMAIL_MAX_TITLE),
    );
    $price = $product->get_price('edit');
    if (is_numeric($price)) {
        $record['price'] = agumail_money($price);
    }
    $currency = agumail_currency(get_woocommerce_currency());
    if ($currency) {
        $record['currency'] = $currency;
    }
    $image = agumail_product_image_url($product);
    $record['imageUrl'] = $image ? $image : '';
    $url = agumail_clamp_url(get_permalink($product->get_id()));
    if ($url) {
        $record['productUrl'] = $url;
    }
    $record['categories'] = agumail_product_categories($product);
    $visible = $product->get_catalog_visibility() !== 'hidden';
    $record['status'] = $product->get_status() === 'publish' && $visible ? 'active' : 'archived';
    $sku = agumail_clamp_sku($product->get_sku());
    $record['sku'] = $sku !== null ? $sku : '';
    $record['source'] = 'woocommerce';
    return $record;
}

/** The product's main image at the size WooCommerce shows on a product page, which suits an email too. */
function agumail_product_image_url($product) {
    $image_id = (int) $product->get_image_id();
    if ($image_id <= 0) {
        return null;
    }
    $url = wp_get_attachment_image_url($image_id, 'woocommerce_single');
    if (!$url) {
        $url = wp_get_attachment_url($image_id);
    }
    return $url ? agumail_clamp_url($url) : null;
}

/**
 * The categories the product is filed under, by name. AguMail copies them onto
 * every order that contains the product, which is what makes "ordered from
 * Gifts in the last 90 days" a segment a merchant can write.
 */
function agumail_product_categories($product) {
    $terms = get_the_terms($product->get_id(), 'product_cat');
    $names = array();
    foreach (is_array($terms) ? $terms : array() as $term) {
        if (is_object($term) && isset($term->name)) {
            $names[] = html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8');
        }
    }
    return agumail_clamp_list($names, AGUMAIL_MAX_CATEGORIES, AGUMAIL_MAX_CATEGORY);
}
