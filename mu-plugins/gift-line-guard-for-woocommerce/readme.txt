=== Gift Line Guard for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, gift, mu-plugin, cart, security
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Locks the quantity and price of zero-priced gift cart lines.

== Description ==

A must-use plugin that protects gift cart lines: the quantity is locked to the first value seen, the price stays 0 and the order line is free. Works with Gift Product Rules for WooCommerce and, through the eru_glg_is_gift_line filter, with any other gift plugin.

Requires WooCommerce 9.0 or newer.

The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Do not install through Plugins > Add New.
2. Copy gift-line-guard-for-woocommerce.php into `/wp-content/mu-plugins/` (top level, no sub-folder).
3. No activation is needed.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Does it work without Gift Product Rules? =
Yes, if you mark gift lines with the eru_glg_is_gift_line filter.

== Changelog ==

= 1.0.0 =
* Initial public release.
