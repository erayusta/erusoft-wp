=== Product Ribbons for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, product, ribbon, badge, sale
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds diagonal ribbons to product images.

== Description ==

Define ribbon rules (category, tag, on sale, new, low stock) with label and colours under WooCommerce > Ürün Şeritleri. The first matching rule wins; a manual per-product ribbon overrides rules. Filter: `eru_pr_ribbon`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).
3. Configure it under WooCommerce → Ürün Şeritleri.

== Frequently Asked Questions ==

= Is CSS loaded on every page? =
No. The stylesheet is only enqueued when a ribbon is actually rendered.

= What is removed on uninstall? =
Its own settings (`eru_pr_*` options). Per-product ribbon settings (`_eru_pr_*` product meta) are kept.

== Screenshots ==

1. Settings screen.
2. Front-end result.

== Changelog ==

= 1.0.0 =
* Initial public release.
