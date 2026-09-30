<?php
// Carts: what the carts scenarios need beyond the plain fakes in wc.php.
//   - a WooCommerce session that has not started yet, as in a browser that has
//     never visited the store;
//   - a cart that behaves as WC_Cart does: its changes fire WooCommerce's hooks,
//     it keeps the data a plugin adds to an item, and it has coupons and
//     set_cart_contents();
//   - products with WC_Product's stock and purchase-limit checks;
//   - WordPress's object cache counters, with a switch for whether a persistent
//     object cache is in use ($GLOBALS['agm']['ext_object_cache']).
//
// Objects are built when a scenario asks for them, after every fake has
// loaded, so this file does not depend on the order the fakes load in.

/** A session with no cookie yet: it starts only when the cookie is set. */
function agm_carts_fresh_session() {
    return new class extends WC_Session_Handler {
        public $started = false;
        public $customer_id = 't_fresh';
        public function has_session() {
            return $this->started;
        }
        public function set_customer_session_cookie($set) {
            if ($set) {
                $this->started = true;
            }
        }
    };
}

/** A cart that behaves as WC_Cart does, as far as the carts feature looks. */
function agm_carts_hooked_cart() {
    return new class extends WC_Cart {
        public $applied_coupons = array();
        public function add_to_cart($product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $data = array()) {
            $key = parent::add_to_cart($product_id, $quantity, $variation_id, $variation, $data);
            if ($key) {
                $this->cart_contents[$key] = array_merge((array) $data, $this->cart_contents[$key]);
                do_action('woocommerce_add_to_cart', $key, $product_id, $quantity, $variation_id, $variation, $data);
            }
            return $key;
        }
        public function remove_cart_item($key) {
            parent::remove_cart_item($key);
            do_action('woocommerce_cart_item_removed', $key, $this);
            return true;
        }
        public function set_quantity($key, $qty = 1, $refresh = true) {
            if ($qty <= 0) {
                return $this->remove_cart_item($key);
            }
            parent::set_quantity($key, $qty, $refresh);
            do_action('woocommerce_after_cart_item_quantity_update', $key, $qty, 1, $this);
            do_action('woocommerce_cart_item_set_quantity', $key, $qty, $this);
            return true;
        }
        public function empty_cart($clear_persistent = true) {
            parent::empty_cart($clear_persistent);
            $this->applied_coupons = array();
            do_action('woocommerce_cart_emptied', $clear_persistent);
        }
        public function set_cart_contents($contents) {
            $this->cart_contents = (array) $contents;
        }
        public function get_applied_coupons() {
            return $this->applied_coupons;
        }
        public function set_applied_coupons($coupons = array()) {
            $this->applied_coupons = (array) $coupons;
        }
    };
}

/** WC_Product's stock and purchase-limit checks, over the fake's props. */
trait Agm_Carts_Stock_Checks {
    public function has_enough_stock($quantity) {
        return empty($this->props['manage_stock']) || !empty($this->props['backorders_allowed'])
            || (int) $this->props['stock_quantity'] >= $quantity;
    }
    public function get_max_purchase_quantity() {
        return isset($this->props['max_purchase_quantity']) ? $this->props['max_purchase_quantity'] : -1;
    }
}

/** A product in the simulated store, as agm_product() makes, with the stock checks WC_Product has. */
function agm_carts_product($id, $name = null, $price = '10', array $props = array()) {
    $props = array_merge(array('name' => $name ?: 'Product ' . $id, 'price' => (string) $price, 'regular_price' => (string) $price), $props);
    if (isset($props['type']) && $props['type'] === 'variation') {
        $product = new class($id, $props) extends WC_Product_Variation {
            use Agm_Carts_Stock_Checks;
        };
    } else {
        $product = new class($id, $props) extends WC_Product_Simple {
            use Agm_Carts_Stock_Checks;
        };
    }
    return agm_wc_persist($product);
}

if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache($using = null) {
        return !empty($GLOBALS['agm']['ext_object_cache']);
    }
}

if (!function_exists('wp_cache_incr')) {
    function wp_cache_incr($key, $offset = 1, $group = '') {
        $value = wp_cache_get($key, $group);
        if ($value === false || !is_numeric($value)) {
            return false;
        }
        $value = max(0, (int) $value + (int) $offset);
        wp_cache_set($key, $value, $group);
        return $value;
    }
}
