=== Bundle Stock Sync for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, bundle, stock, inventory
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calculate bundle stock from component SKUs and keep it in sync with sales, refunds and restocks.

== Description ==

Define a product as a bundle of component SKUs and quantities. Bundle stock is the minimum of component stock divided by quantity. Selling a bundle reduces the components; selling, restocking, unpublishing, cancelling or refunding a component or bundle recalculates stock. Bundles cannot contain bundles, and recalculation is guarded against loops.

Requires WooCommerce 9.0 or newer; compatible with HPOS. The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).
3. Configure it as described in the Turkish README; until configured the plugin leaves the store unchanged.

== Screenshots ==

1. Paket içeriği sekmesi
2. Ayarlar ve tanımlı paketler

== Changelog ==

= 1.0.0 =
* Initial public release.
