=== Product Push for Trendyol ===
Contributors: erusoft
Tags: trendyol, marketplace, product feed, variable products, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push simple and variable WooCommerce products to Trendyol over the Product V2 API, with validation, approval-aware updates and batch result polling.

== Description ==

* Trendyol box on the product screen: title and description overrides, category picker with Ajax search over a cached category tree, the category's attribute form, brand, barcode, list price and per-item status.
* Category map (WooCommerce category to Trendyol category, deepest mapped category wins, product override first) and attribute map with value mapping for variations.
* Variable products: one item per published variation, all sharing one productMainId (parent SKU or ppt-<parent id>), each with its own barcode and mapped variant attributes.
* Validation before sending (category, brand, cargo company, barcode, required attributes, values, https images, price, title, description) with Turkish messages.
* Bulk actions "Trendyol'a gönder" (queued in Action Scheduler) and "Trendyol: doğrula" with an explicit capability and nonce check.
* Routing by approval state, refreshed from Trendyol's approved / unapproved listings: create (V2), content + variant update for approved items, unapproved update for items waiting for approval. Batches of at most 100 items.
* Never creates the same item twice: one push lock, the item is marked unknown before the create request, and ambiguous failures are looked up by barcode before any re-send; a failed lookup means "needs review" until an admin reset.
* Own 10-minute Action Scheduler job polls batch results and writes each item result to its own product or variation; approval refresh; admin notice summary and a status column.
* Page requests (product box, bulk validation, attribute form) read category data from the cache only; a miss queues a background refresh. Failed fetches are negatively cached for 10 minutes; partial value lists are never cached as complete.
* Open batches are stored one row each, so pushes and the poller never overwrite each other.
* Exact float encoding (serialize_precision -1, restored afterwards).
* Own write-only credentials, or the eru_ppt_credentials filter. Live / stage environment.
* WP-CLI: wp eru-ppt categories|attributes|brands|addresses|validate|push|poll.

Inert until you switch it on and save the credentials. Nothing is sent when a product is saved. HPOS compatible.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. Every Trendyol call (create, updates, listings, batch status, categories, attributes, brands, addresses) was verified against mocked responses of the documented shape only. Verify the listings' barcode filter, the batch result format and the update field lists with your own seller account (stage environment first). Endpoint paths can be changed with the eru_ppt_paths filter.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Go to WooCommerce > Trendyol Ürün Gönderimi, enter seller ID, API key and API secret, switch the integration on and save. Pick the default brand, cargo company and addresses, then map your categories.

== Frequently Asked Questions ==

= Does it sync stock and prices? =

No. Stock and price are only sent when an item is created. Continuous price/stock sync is out of scope.

= What is removed on uninstall? =

Settings, request log, open batch list, caches, locks and scheduled actions. The product/variation meta you entered (barcodes, attribute answers, the approval link) is kept unless `ERU_PPT_REMOVE_DATA` is defined as true. Nothing on Trendyol is changed.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
