=== Automatic Discounts for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, discount, coupon, cart, automatic, campaign
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Coupon-free automatic discounts by cart subtotal or category quantity.

== Description ==

Coupon-free automatic discounts. Rules grant a percentage or fixed amount off when the cart subtotal reaches a threshold or when enough items of a category are in the cart, with date ranges, priorities and a "stop further rules" flag. With no rules the plugin does nothing.

Requires WooCommerce 9.0 or newer; compatible with HPOS. The admin interface is in Turkish.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Configure it in the WooCommerce admin menu. Nothing changes on the storefront until you do.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= What is removed on uninstall? =
Only the plugin's own options. Data attached to products or orders is kept.

= How does it stack with other Erusoft discount plugins? =
Every plugin adds its own negative fee line, recomputed on each totals calculation, so discounts add up and are never applied twice. Each plugin has an `*_apply` filter to opt out.

== Screenshots ==

1. Kural tablosu

== Changelog ==

= 1.0.0 =
* Initial public release.
