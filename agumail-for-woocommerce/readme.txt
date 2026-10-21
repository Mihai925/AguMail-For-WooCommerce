=== AguMail for WooCommerce ===
Contributors: agumail
Tags: email marketing, woocommerce, abandoned cart, newsletter, elementor
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.0.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your store to AguMail in one click. Orders, products and customers sync in the background, with cart reminders, signup forms and your Elementor, Contact Form 7 and WPForms signups.

== Description ==

AguMail is email marketing for online stores. This plugin connects your WooCommerce store to your AguMail account and keeps the two in step.

= One-click connect =

Press **Connect to AguMail**, approve the store in AguMail, and you are back in WordPress, connected. There is no key to copy. If you prefer, an API key field is still there under **Advanced**.

= Orders, products and customers, without slowing checkout =

* **Orders** reach AguMail with their line items once they are paid, and again after a refund or cancellation, so revenue and campaign attribution stay correct.
* **Products** sync with the price, SKU, image and categories they have after each save, whether you edit them on the product screen, with quick edit, through an import or through the REST API. A stock change alone sends nothing.
* **Customers** who register, place an order or change their choice under My Account are added with the answer they gave.

Nothing is sent while a shopper waits. The storefront only notes what changed, and a background task (Action Scheduler, part of WooCommerce) sends it about once a minute. If AguMail is slow or unreachable, checkout takes exactly as long as it would without the plugin.

= Abandoned carts with restore links =

When a shopper types their email at checkout and leaves, their cart is kept against that email. AguMail's cart reminders link back with a restore link that rebuilds the cart in any browser, on any device, and opens checkout with the campaign details kept. It works on the classic checkout, the Cart and Checkout blocks, and CheckoutWC.

= Signup forms =

Add an AguMail form anywhere with the `[agumail_form slug="..."]` shortcode or the AguMail form block, or show one as a popup across the store: after a delay, after scrolling, or when the visitor moves to leave. The popup stays off the cart, checkout and account pages, and is not shown to signed-in customers who already subscribed.

= Your Elementor, Contact Form 7 and WPForms forms =

Keep the forms you already have. Once the store is connected, each of these can send its signups to AguMail, with no key to paste:

* **Elementor Pro:** add **AguMail** under **Actions After Submit**, in a page form or a popup.
* **Contact Form 7:** switch it on in the form's **AguMail** tab.
* **WPForms:** switch it on under **Settings > AguMail** in the form builder.

Each form chooses its tags, whether signups confirm by email first, and which fields hold the email, name and phone (found for you when you leave them). A form with an optional checkbox for your emails sends only the visitors who tick it. The visitor's submission never waits on AguMail: the signup is queued and sent a moment later.

= Website tracking =

The AguMail site script records which products a recognized visitor looks at, so a browse reminder can follow up. Signed-in customers are recognized with a signed identifier. You can switch it off.

= Your store's history =

**Sync store data** brings in your customers, products and past orders: all of your history, or the last few months. Customers who opted in go first. Past orders arrive as history and start no emails.

== Installation ==

1. In WordPress, go to Plugins > Add New, search for AguMail and install it, or upload the ZIP. Activate it. WooCommerce must be active.
2. Go to WooCommerce > AguMail and press **Connect to AguMail**. Sign in to AguMail if asked, check the store address and approve.
3. Back in WordPress, press **Sync store data now** to bring in your customers, products and past orders.
4. Optional: add signup forms (Forms), change the words next to the checkout checkbox or tick it by default (Checkout), choose whether registration and My Account ask about marketing emails (Customer accounts), and send signups from your Elementor, Contact Form 7 or WPForms forms.

Requirements: WordPress 6.0 or later, WooCommerce 8.0 or later (the checkbox on the block checkout needs WooCommerce 8.9), PHP 7.4 or later. Sending runs on Action Scheduler, so WP-Cron or a server cron must be running, as it is on most hosts.

== Frequently Asked Questions ==

= Who gets emailed? =

Shoppers who gave their email at checkout, or bought without ticking the marketing checkbox, are added as "Not subscribed". They receive only your abandoned-cart and browse reminders. Campaigns and every other flow go to subscribers only.

To send cart and browse reminders to subscribers only, add the condition "Status is active" to the flow's trigger or to an email step. Every reminder carries an unsubscribe link, and unsubscribing stops every email that would have followed.

= Is the email captured before the order is placed? =

Yes. When a shopper leaves the email field at checkout, or on the My Account login and registration forms, the address is saved with their cart so the cart can be recovered. Only the email is kept: the marketing checkbox counts only when the order is placed. You are responsible for any notice or consent your local law requires for this, for example in your privacy policy or next to the field.

= Will syncing my store's history email my old customers? =

No. Every request of the store data sync is marked as history. AguMail stores the orders, revenue and answers, and starts no flow for any of it: no welcome email, no post-purchase email, and no date-based email for a moment that had already passed. AguMail also treats any order or opt-in older than 72 hours as history, whatever sends it.

= How are cash on delivery orders counted? =

An order counts as a purchase once it is paid: when it has a paid date, or reaches a paid status such as processing or completed. A cash on delivery order counts as soon as it is processing. A bank transfer or cheque on hold waits until you mark it processing, and then counts from that moment. Cancelled, failed and fully refunded orders count for nothing, and a partial refund lowers the order's value.

= What happens if AguMail is down? =

Checkout is not affected: the plugin never calls AguMail while a shopper is on your site. Changes wait in a queue in your database and are tried again with a growing delay, up to six hours between tries. When AguMail asks the plugin to slow down, it waits as long as asked. Anything AguMail refuses is listed under Status, with Retry and Discard. The plugin's log is under WooCommerce > Status > Logs, source agumail.

= How does revenue attribution work? =

Links in AguMail emails carry an `agm_cid` parameter. The plugin keeps it in a 30-day cookie and adds it to the order, so campaign reports show the revenue they brought in. Restore links keep it too.

= Does it work with High-Performance Order Storage and the Cart and Checkout blocks? =

Yes, with both.

== External services ==

This plugin connects your store to AguMail (https://agumail.com), an email marketing service. Nothing is sent to AguMail until you connect the store.

**AguMail API** (https://api.agumail.com). Once the store is connected, your server sends, in the background and never during a shopper's visit:

* customers: email address, first and last name, their answer to the marketing checkbox and when they gave it, and when they became a customer;
* orders: order number, email address, total, currency, line items (product id, SKU, name, quantity, price), the campaign that brought the shopper, and when the order was paid;
* products: id, name, price, currency, image and product links, categories, SKU and whether it is published;
* carts of shoppers whose email is known: items, total, the restore link and when the cart last changed;
* signups from your Elementor, Contact Form 7 and WPForms forms, once you switch a form on: email address, name and phone as the form collected them, the form's name and tags, whether it asks for a confirmation email, the words of its consent checkbox, the page it was on, the visitor's IP address and when they submitted.

It also checks the connection, the list of your published forms and which customers are subscribed, about once a day. Connecting sends your store's address and currency.

**AguMail approval page** (https://agumail.com/connect/woocommerce). Opened in your browser when you press Connect to AguMail. It receives your store's address, the address to return to, and a one-time challenge that proves the approval came back to this site.

**AguMail site script** (served from agupage.com, AguMail's pages domain). Loaded in visitors' browsers on storefront pages while website tracking is on. It sends page and product views of visitors AguMail recognizes to https://api.agumail.com, and for signed-in customers their email address with a signature.

**AguMail forms loader** (https://api.agumail.com/embed/agumail-forms.js). Loaded in visitors' browsers on pages that show an AguMail form or popup. The form itself comes from AguMail, and what a visitor types into it goes to AguMail.

AguMail's terms of service: https://agumail.com/terms
AguMail's privacy policy: https://agumail.com/privacy

== Changelog ==

= 1.0.0 =
* Initial release.
