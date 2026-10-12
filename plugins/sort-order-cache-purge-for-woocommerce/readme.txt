=== Sort Order Cache Purge for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, cache, litespeed, sorting, menu_order
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Purges the page cache when product sort order is saved.

== Description ==

Product sorting plugins that write menu_order with raw SQL fire no WordPress hooks, so page caches keep serving the old order. This plugin watches the sorting AJAX actions (filter `eru_socp_actions`) and menu_order changes and purges the affected archive or the whole cache. LiteSpeed Cache is supported out of the box; other caches can hook `eru_socp_purge_all` and `eru_socp_purge_term`. No settings screen.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).

== Frequently Asked Questions ==

= Can a visitor trigger purges? =
No. Only requests from users with the manage_woocommerce capability are considered.

= What is removed on uninstall? =
Nothing. The plugin stores no options or other data.

== Changelog ==

= 1.0.0 =
* Initial public release.
