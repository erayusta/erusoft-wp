=== Category Base Rewrite Guard for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, rewrite, permalinks, category, rank math
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Restores missing product-category rewrite rules when the category base is removed.

== Description ==

If an SEO plugin removes the product-category base (Rank Math "remove category base") and the rewrite rules get saved incomplete, all category pages 404. This guard regenerates the missing rules and self-heals stored rules (at most hourly). It does nothing when rules are complete or the setting is off. Filter: `eru_cbrg_mode`. No settings screen. The single file can also be dropped into mu-plugins.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).

== Frequently Asked Questions ==

= Does it override my SEO plugin settings? =
No. It only acts when the SEO plugin is configured to remove the base.

= What is removed on uninstall? =
Its `eru_cbrg_checked` transient. The plugin stores nothing else.

== Changelog ==

= 1.0.0 =
* Initial public release.
