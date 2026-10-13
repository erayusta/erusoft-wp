=== Exit-Intent Coupons for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, coupon, exit intent, popup, discount
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows a coupon pop-up to visitors who are about to leave the page.

== Description ==

Shows a pop-up with a fixed coupon code, or a single-use time-limited coupon generated for each visitor within a daily budget, when the visitor is about to leave. Shown once per visitor. Disabled by default; makes no front-end change until enabled.

Requires WooCommerce 9.0 or newer; compatible with HPOS.

The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Configure it under WooCommerce > Çıkış Kuponu and enable it.

== Frequently Asked Questions ==

= Is it cache friendly? =
Yes. In generated mode the code is requested over REST when the pop-up opens and nothing visitor-specific is printed in the page.

== Screenshots ==

1. Settings
2. Pop-up on desktop
3. Pop-up on mobile

== Changelog ==

= 1.0.0 =
* Initial public release.
