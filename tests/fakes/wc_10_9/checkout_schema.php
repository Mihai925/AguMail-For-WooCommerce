<?php
// WooCommerce 10.9 and later, as far as the checkbox's default looks: the
// Store API's checkout schema draws a checkout with no order yet
// (get_draft_response), because the draft order is only created when the
// shopper places it. Loaded only by the scenarios that need it.

namespace Automattic\WooCommerce\StoreApi\Schemas\V1;

if (!class_exists(__NAMESPACE__ . '\CheckoutSchema')) {
    class CheckoutSchema {
        public function get_draft_response($cart, $customer) {
            return array();
        }
    }
}
