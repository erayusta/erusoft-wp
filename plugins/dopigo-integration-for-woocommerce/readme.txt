=== Dopigo Integration for WooCommerce ===
Contributors: erusoft
Tags: dopigo, stock sync, order sync, erp, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync stock, prices, orders and order statuses between WooCommerce and Dopigo, with a time-budgeted product importer and SKU audit tools.

== Description ==

* Stock and price sync with a direction per field: off, Dopigo to Woo, Woo to Dopigo or both.
* Woo to Dopigo updates are queued in Action Scheduler (one action per product); nothing is sent during the product save.
* Orders are pushed with VAT-inclusive lines, Turkish province names and 10-digit phone numbers, under a per-order lock and a persisted pending state, so concurrent events create one Dopigo order.
* Order status sync in both directions through a mapping table; an unmapped status changes nothing.
* Cursor-based, time-budgeted product import: simple and variable products, deterministic unique SKUs, categories under a parent, two-phase image sideload with hash de-duplication.
* SKU audit, relink-by-SKU and duplicate finder.
* Dashboard, filterable log (credentials, e-mail addresses and phone numbers masked), order metabox with resend, WP-CLI `wp eru-dpg test|pull|push-stock|import|order`.
* 429/503/5xx are retried through Action Scheduler with 1-30 minute backoff; the client never sleeps.
* A warning and a one-click preset when Woo to Dopigo stock and order push are both on (double stock decrement).
* Credentials are write-only, stored with autoload off and never logged. Dry-run mode reads only.

Inert until you switch it on and save credentials. HPOS compatible.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. Dopigo has no public sandbox; the API client and every sync flow were verified against mocked HTTP responses of the documented shape only. Verify with your own Dopigo account, starting in dry-run mode, before relying on it.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Go to WooCommerce > Dopigo > Bağlantı, enter your credentials, switch the integration on and use "Bağlantıyı test et".

== Frequently Asked Questions ==

= Why is there a warning about double stock decrement? =

When an order is pushed, Dopigo reduces its own stock. If WooCommerce also sends its reduced stock to Dopigo, one sale is subtracted twice. Use "Yalnızca sipariş gönder" to keep order push and take stock from Dopigo instead.

= What is removed on uninstall? =

Settings, the token cache, the log table, locks, scheduled actions and product meta. Order meta and order notes are kept.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
