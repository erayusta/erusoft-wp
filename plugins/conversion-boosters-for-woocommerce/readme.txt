=== Conversion Boosters for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, free shipping bar, welcome coupon, newsletter, cart
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free-shipping bar with gap-filling product suggestions, consent-based welcome coupon and a genuine expiring cart coupon.

== Description ==

Four independent modules for the classic cart and checkout, all off by default:

* Free-shipping progress bar. The threshold is read from the customer's shipping-zone free shipping method (or set manually). Optional suggestions: in-stock simple products priced between the remaining amount and the remaining amount plus a limit you choose, from cross-sells, the cart's categories, or a manual list, added with AJAX. One bounded query, cached for ten minutes. Shortcode `[eru_free_shipping_bar]` for mini carts.
* Cart recommendations: when the cart has no cross-sells, products from the same categories.
* Newsletter `[eru_cb_newsletter]` with a required consent checkbox and double opt-in: a confirmation e-mail first, and only after the link is followed a unique single-use coupon bound to the address with an expiry date is created and e-mailed. The same mailbox (case and +tag ignored) never receives a second code. Honeypot, same-origin check, a 5-per-hour limit per hashed IP and a global hourly cap protect the form on cached pages. Privacy exporter/eraser (including coupons) and retention purge.
* Timed cart coupon: a real WooCommerce coupon created per session on click, expiring on the server. The countdown shows the coupon's real remaining time. After expiry the session gets no new coupon for a configurable cool-down.

Honest marketing: no fake timers, no fake scarcity, no invented social proof. Cart and checkout blocks are not hooked (shortcodes only). E-mail campaigns are out of scope.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Enable the modules you want under WooCommerce > Dönüşüm Araçları.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Does the countdown reset on reload? =
No. It shows the remaining time of the coupon itself, computed on the server.

= What is removed on uninstall? =
Options, subscribers, scheduled actions and expired unused generated coupons. Unexpired or used generated coupons and orders are kept unless `ERU_CB_REMOVE_DATA` is defined as true.

== Screenshots ==

1. Settings.
2. Cart with the free-shipping bar and suggestions.
3. Mobile cart.
4. Timed coupon box with countdown.
5. Newsletter form with consent.

== Changelog ==

= 1.0.0 =
* Initial public release.
