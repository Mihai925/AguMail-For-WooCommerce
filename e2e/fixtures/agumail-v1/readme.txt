=== AguMail for WooCommerce ===
Contributors: agumail
Tags: email marketing, woocommerce, abandoned cart, newsletter
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to AguMail: consent-aware contact sync, order revenue attribution, abandoned-cart recovery and a product picker in the email editor.

== Description ==

AguMail for WooCommerce keeps your store and your email marketing in sync:

* **Contacts**: customers flow into AguMail at registration and checkout. A consent checkbox at checkout decides whether they are mailable; AguMail never assumes permission.
* **Orders**: every paid order is pushed with its line items, and refunds and cancellations update it. If the customer arrived from an AguMail campaign link, the revenue is attributed to that campaign automatically.
* **Abandoned carts**: cart snapshots, including a guest's once they type an email at checkout, are buffered and flushed every 15 minutes; AguMail detects idle carts and triggers your recovery automation.
* **Products**: published products appear in AguMail's email editor product picker.

== Installation ==

1. Upload the `agumail` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload.
2. Activate the plugin.
3. In AguMail, go to Settings → API keys and create a key used for “WooCommerce plugin”. The key is shown once, so copy it.
4. In WordPress, go to WooCommerce → AguMail, paste your AguMail URL and the API key, then click **Test connection**.
5. Click **Sync store data now** to import existing customers (non-mailable until they opt in), products, and the last 90 days of orders.

== Frequently Asked Questions ==

= Are imported customers emailed automatically? =
No. Customers imported by the bulk sync are stored as non-mailable. Only shoppers who tick the consent checkbox at checkout (or opt in through an AguMail form) can receive marketing email.

= How does revenue attribution work? =
Links in AguMail campaigns carry an `agm_cid` parameter. The plugin stores it in a 30-day cookie and sends it back with any order, so campaign reports show real revenue.

== Changelog ==

= 1.2.0 =
* Works with the Cart and Checkout blocks: the consent checkbox, campaign attribution and guest carts are captured on the block checkout as well as the classic one.
* Compatible with High-Performance Order Storage.
* Orders reach AguMail once they are paid, and refunds and cancellations update their value, so an unpaid order never starts a post-purchase flow.
* Every cart is its own cart: a shopper who comes back after a purchase or an emptied cart can be reminded again, and an emptied cart is never treated as abandoned.
* A signed-in customer who opts in at checkout is remembered on their account.

= 1.1.0 =
* Website tracking: the AguMail script, with signed-in customers identified.
* Order and cart lines carry the product SKU; products carry their categories.

= 1.0.1 =
* Test connection now shows why AguMail refused to connect the store (for example, a plan that already has a store connected) instead of reporting success.
* Cart snapshots carry each product's image and id, so abandoned-cart emails can show the cart.

= 1.0.0 =
* Initial release: contact/order/cart/product sync, checkout consent checkbox, campaign revenue attribution, bulk sync tools.
