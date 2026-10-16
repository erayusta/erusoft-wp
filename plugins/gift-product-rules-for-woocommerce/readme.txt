=== Gift Product Rules for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, gift, free product, cart, rules
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a free gift product to the cart automatically when a rule matches, and removes it when it stops matching.

== Description ==

Rules can match the cart total, a product in the cart or a category in the cart. The gift line is priced 0, its quantity is locked and it disappears when the rule no longer matches. With no rules the plugin does nothing.

Requires WooCommerce 9.0 or newer; compatible with HPOS and the block cart.

The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Add rules under WooCommerce > Hediye Kuralları. Nothing changes on the storefront until you do.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= What is removed on uninstall? =
Only the plugin's own option. Order item meta is kept.

== Screenshots ==

1. Rule table
2. Gift line in the cart

== Changelog ==

= 1.0.0 =
* Initial public release.
