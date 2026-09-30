<?php
/**
 * Store setup for the e2e stack. Idempotent. Run with:
 *   wp eval-file /e2e/wp/setup-store.php
 *
 * Writes /e2e/state/site.json (pages, products) for the specs.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// ── Store settings, onboarding skipped ──────────────────────────────────────

$options = array(
    'blogname'                                     => 'AguMail E2E Shop',
    'woocommerce_store_address'                    => '1 Test Street',
    'woocommerce_store_city'                       => 'San Francisco',
    'woocommerce_default_country'                  => 'US:CA',
    'woocommerce_store_postcode'                   => '94110',
    'woocommerce_currency'                         => 'USD',
    'woocommerce_price_num_decimals'               => '2',
    'woocommerce_calc_taxes'                       => 'no',
    'woocommerce_enable_guest_checkout'            => 'yes',
    'woocommerce_enable_checkout_login_reminder'   => 'no',
    'woocommerce_enable_signup_and_login_from_checkout' => 'no',
    'woocommerce_enable_myaccount_registration'    => 'yes',
    'woocommerce_registration_generate_password'   => 'yes',
    'woocommerce_registration_generate_username'   => 'yes',
    'woocommerce_coming_soon'                      => 'no',
    'woocommerce_store_pages_only'                 => 'no',
    'woocommerce_allow_tracking'                   => 'no',
    'woocommerce_show_marketplace_suggestions'     => 'no',
    'woocommerce_task_list_hidden'                 => 'yes',
    'woocommerce_task_list_complete'               => 'yes',
    'woocommerce_extended_task_list_hidden'        => 'yes',
    'woocommerce_ship_to_countries'                => '',
    'woocommerce_checkout_phone_field'             => 'optional',
    'woocommerce_checkout_company_field'           => 'hidden',
    'woocommerce_checkout_address_2_field'         => 'hidden',
);
foreach ($options as $name => $value) {
    update_option($name, $value);
}
update_option('woocommerce_onboarding_profile', array('skipped' => true, 'completed' => true));
delete_transient('_wc_activation_redirect');

// ── Payments: cash on delivery, cheque and bank transfer ─────────────────────

$cod = get_option('woocommerce_cod_settings', array());
update_option('woocommerce_cod_settings', array_merge(is_array($cod) ? $cod : array(), array(
    'enabled'            => 'yes',
    'title'              => 'Cash on delivery',
    'description'        => 'Pay with cash upon delivery.',
    'instructions'       => 'Pay with cash upon delivery.',
    'enable_for_methods' => array(),
    'enable_for_virtual' => 'yes',
)));
$cheque = get_option('woocommerce_cheque_settings', array());
update_option('woocommerce_cheque_settings', array_merge(is_array($cheque) ? $cheque : array(), array(
    'enabled'      => 'yes',
    'title'        => 'Check payments',
    'description'  => 'Please send a check to Store Name.',
    'instructions' => '',
)));
// Direct bank transfer: an order that waits on hold until the merchant marks it paid.
$bacs = get_option('woocommerce_bacs_settings', array());
update_option('woocommerce_bacs_settings', array_merge(is_array($bacs) ? $bacs : array(), array(
    'enabled'      => 'yes',
    'title'        => 'Direct bank transfer',
    'description'  => 'Pay by bank transfer. Your order ships once the money arrives.',
    'instructions' => '',
)));
update_option('woocommerce_gateway_order', array('cod' => 0, 'cheque' => 1, 'bacs' => 2));

// ── Shipping: a flat rate everywhere ────────────────────────────────────────

$zone      = new WC_Shipping_Zone(0);
$has_flat  = false;
foreach ($zone->get_shipping_methods() as $method) {
    if ($method->id === 'flat_rate') {
        $has_flat = true;
    }
}
if (!$has_flat) {
    $instance_id = $zone->add_shipping_method('flat_rate');
    update_option('woocommerce_flat_rate_' . $instance_id . '_settings', array(
        'title'      => 'Flat rate',
        'tax_status' => 'none',
        'cost'       => '5.00',
    ));
}

// ── Pages: WooCommerce's own (block) cart and checkout, plus classic copies ──

WC_Install::create_pages();

function agumail_e2e_page($slug, $title, $content) {
    $existing = get_page_by_path($slug, OBJECT, 'page');
    if ($existing) {
        if ($existing->post_status !== 'publish') {
            wp_update_post(array('ID' => $existing->ID, 'post_status' => 'publish'));
        }
        return (int) $existing->ID;
    }
    return (int) wp_insert_post(array(
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_name'    => $slug,
        'post_title'   => $title,
        'post_content' => $content,
    ));
}

$pages_option = get_option('agumail_e2e_pages', array());
if (empty($pages_option['blockCheckout'])) {
    // Remember WooCommerce's generated pages once, before any switch moves the page options.
    $pages_option['blockCheckout'] = (int) get_option('woocommerce_checkout_page_id');
    $pages_option['blockCart']     = (int) get_option('woocommerce_cart_page_id');
}
$pages_option['classicCheckout'] = agumail_e2e_page('checkout-classic', 'Checkout (classic)', "<!-- wp:shortcode -->\n[woocommerce_checkout]\n<!-- /wp:shortcode -->");
$pages_option['classicCart']     = agumail_e2e_page('cart-classic', 'Cart (classic)', "<!-- wp:shortcode -->\n[woocommerce_cart]\n<!-- /wp:shortcode -->");
$pages_option['myAccount']       = (int) get_option('woocommerce_myaccount_page_id');
$pages_option['shop']            = (int) get_option('woocommerce_shop_page_id');
update_option('agumail_e2e_pages', $pages_option, false);

$block_checkout = get_post($pages_option['blockCheckout']);
if (!$block_checkout || strpos($block_checkout->post_content, 'wp:woocommerce/checkout') === false) {
    WP_CLI::warning('WooCommerce\'s checkout page is not the checkout block; the "block" checkout will not be a block checkout.');
}

// ── Catalogue: simple and variable products, with images and SKUs ───────────

function agumail_e2e_term($name) {
    $term = term_exists($name, 'product_cat');
    if (!$term) {
        $term = wp_insert_term($name, 'product_cat');
    }
    return (int) (is_array($term) ? $term['term_id'] : $term);
}

function agumail_e2e_image($slug, $label, $rgb) {
    $existing = get_posts(array(
        'post_type'      => 'attachment',
        'meta_key'       => '_agumail_e2e_image',
        'meta_value'     => $slug,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ));
    if ($existing) {
        return (int) $existing[0];
    }
    $upload = wp_upload_dir();
    $file   = trailingslashit($upload['path']) . 'agumail-e2e-' . $slug . '.png';
    if (function_exists('imagecreatetruecolor')) {
        $img = imagecreatetruecolor(600, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]));
        $white = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 40, 290, $label, $white);
        imagepng($img, $file);
        imagedestroy($img);
    } else {
        // 1x1 PNG fallback.
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
    }
    $id = wp_insert_attachment(array(
        'post_mime_type' => 'image/png',
        'post_title'     => $label,
        'post_status'    => 'inherit',
    ), $file);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
    update_post_meta($id, '_agumail_e2e_image', $slug);
    return (int) $id;
}

$simple = array(
    array('sku' => 'AGM-TOTE', 'name' => 'Linen Tote', 'price' => '24.00', 'cat' => 'Accessories', 'rgb' => array(120, 94, 70)),
    array('sku' => 'AGM-MUG', 'name' => 'Ceramic Mug', 'price' => '14.00', 'cat' => 'Home', 'rgb' => array(46, 84, 120)),
    array('sku' => 'AGM-CANDLE', 'name' => 'Beeswax Candle', 'price' => '18.00', 'sale' => '15.00', 'cat' => 'Home', 'rgb' => array(196, 150, 60)),
    array('sku' => 'AGM-NOTE', 'name' => 'Dot Grid Notebook', 'price' => '9.50', 'cat' => 'Accessories', 'rgb' => array(60, 60, 60)),
);
$variable = array(
    array('sku' => 'AGM-TEE', 'name' => 'Organic Tee', 'attr' => 'Size', 'options' => array('S' => '25.00', 'M' => '25.00', 'L' => '27.00'), 'cat' => 'Apparel', 'rgb' => array(40, 120, 90)),
    array('sku' => 'AGM-BEANIE', 'name' => 'Wool Beanie', 'attr' => 'Colour', 'options' => array('Grey' => '19.00', 'Navy' => '19.00'), 'cat' => 'Apparel', 'rgb' => array(30, 40, 80)),
);

$catalogue = array();

foreach ($simple as $spec) {
    $id      = wc_get_product_id_by_sku($spec['sku']);
    $product = $id ? wc_get_product($id) : new WC_Product_Simple();
    $product->set_name($spec['name']);
    $product->set_sku($spec['sku']);
    $product->set_status('publish');
    $product->set_regular_price($spec['price']);
    $product->set_sale_price(isset($spec['sale']) ? $spec['sale'] : '');
    $product->set_category_ids(array(agumail_e2e_term($spec['cat'])));
    $product->set_image_id(agumail_e2e_image(strtolower($spec['sku']), $spec['name'], $spec['rgb']));
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');
    $product->set_short_description($spec['name'] . ' from the AguMail e2e catalogue.');
    $product->save();
    $catalogue[] = array(
        'id'    => $product->get_id(),
        'sku'   => $spec['sku'],
        'name'  => $spec['name'],
        'type'  => 'simple',
        'price' => $product->get_price(),
        'slug'  => get_post_field('post_name', $product->get_id()),
        'url'   => get_permalink($product->get_id()),
    );
}

foreach ($variable as $spec) {
    $id      = wc_get_product_id_by_sku($spec['sku']);
    $product = $id ? wc_get_product($id) : new WC_Product_Variable();
    $product->set_name($spec['name']);
    $product->set_sku($spec['sku']);
    $product->set_status('publish');
    $product->set_category_ids(array(agumail_e2e_term($spec['cat'])));
    $product->set_image_id(agumail_e2e_image(strtolower($spec['sku']), $spec['name'], $spec['rgb']));
    $attribute = new WC_Product_Attribute();
    $attribute->set_name($spec['attr']);
    $attribute->set_options(array_keys($spec['options']));
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    $product->set_attributes(array($attribute));
    $product->save();

    $variations = array();
    $attr_key   = sanitize_title($spec['attr']);
    foreach ($spec['options'] as $option => $price) {
        $sku = $spec['sku'] . '-' . strtoupper($option);
        $vid = wc_get_product_id_by_sku($sku);
        $variation = $vid ? wc_get_product($vid) : new WC_Product_Variation();
        $variation->set_parent_id($product->get_id());
        $variation->set_sku($sku);
        $variation->set_attributes(array($attr_key => $option));
        $variation->set_regular_price($price);
        $variation->set_status('publish');
        $variation->set_manage_stock(false);
        $variation->set_stock_status('instock');
        $variation->save();
        $variations[] = array('id' => $variation->get_id(), 'sku' => $sku, 'attribute' => 'attribute_' . $attr_key, 'value' => $option, 'price' => $price);
    }
    WC_Product_Variable::sync($product->get_id());
    wc_delete_product_transients($product->get_id());
    $catalogue[] = array(
        'id'         => $product->get_id(),
        'sku'        => $spec['sku'],
        'name'       => $spec['name'],
        'type'       => 'variable',
        'slug'       => get_post_field('post_name', $product->get_id()),
        'url'        => get_permalink($product->get_id()),
        'variations' => $variations,
    );
}

// ── site.json for the specs ─────────────────────────────────────────────────

$page_urls = array();
foreach ($pages_option as $key => $page_id) {
    $page_urls[$key] = array('id' => (int) $page_id, 'url' => $page_id ? get_permalink($page_id) : null);
}
$site = array(
    'url'      => home_url(),
    'admin'    => array('user' => 'admin', 'password' => 'admin'),
    'pages'    => $page_urls,
    'products' => $catalogue,
);
file_put_contents('/e2e/state/site.json', wp_json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
@chmod('/e2e/state/site.json', 0666);

WP_CLI::success(sprintf('Store ready: %d products, classic and block checkout pages.', count($catalogue)));
