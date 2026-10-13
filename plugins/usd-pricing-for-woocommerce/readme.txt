=== USD Pricing for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, usd, exchange rate, currency, tcmb
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

USD-priced products charged in the store currency from a manual or TCMB exchange rate.

== Description ==

Price products in US dollars and charge in the store currency. The price is calculated from a manual exchange rate (default, no outbound requests) or the daily TCMB rate (opt-in, cached 6 hours), with an optional markup. Inert until a rate is set.

Requires WooCommerce 9.0 or newer; compatible with HPOS. The admin interface is in Turkish.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Configure it in the WooCommerce admin menu. Nothing changes on the storefront until you do.

== Frequently Asked Questions ==

= What is removed on uninstall? =
Only the plugin's own options (and scheduled events). Data attached to products or orders is kept.

== Screenshots ==

1. Settings
2. Product page (desktop)
3. Product page (mobile)

== Changelog ==

= 1.0.0 =
* Initial public release.
