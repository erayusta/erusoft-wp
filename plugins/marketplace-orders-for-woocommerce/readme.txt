=== Marketplace Orders for WooCommerce ===
Contributors: erusoft
Tags: trendyol, hepsiburada, marketplace, orders, stock sync
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports Trendyol and Hepsiburada orders without duplicates and syncs stock and prices with rules.

== Description ==

* Pluggable marketplace adapters: Trendyol and Hepsiburada are included; register another one with the eru_mpo_adapters filter.
* Duplicate-safe import: an atomic per-package lock plus a unique map row (marketplace + package). A deleted WooCommerce order frees its package; a trashed one is left alone.
* Isolated save: the shop's new-order and order-status listeners (e-mails, payment, shipping, ERP automations) are muted while a marketplace order is saved. WooCommerce's own bookkeeping keeps running: order counts, HPOS sync and sales counters. A half-saved order is removed and retried with a backoff.
* Unified statuses (own "Pazaryeri: …" statuses or core statuses) that only move forward. Cargo, delivery, cancel, partial cancel and return events are applied once. Own statuses count as paid; cancelled and returned ones are left out of Analytics.
* WooCommerce stock on import: leave it (default; WooCommerce's own reductions are blocked for marketplace orders too) or reduce it once and restore it on cancel.
* Optional acknowledgement: Trendyol "Picking", Hepsiburada auto-pack. Modes: never (default), on import, or when the shop moves the order to a chosen status. It is queued in Action Scheduler with a backoff. After an ambiguous failure the package is looked up before anything is sent again.
* Trendyol re-created packages are linked to the same order; split packages become their own orders, and the earlier order is cancelled and zeroed.
* Stock bridge module, off by default per marketplace:
  * Stock and price changes are queued per product and pushed 15 seconds later in batches of 100: Trendyol price-and-inventory by barcode, Hepsiburada listing uploads by merchant SKU. No update is ever dropped: products without a listing wait only after a complete listings refresh, and are retried after the next one.
  * A 10-minute safety sweep and an hourly listings refresh run in the background. A full sync is enqueued in batches.
  * Rules: excluded categories, brands and SKUs; stock floor and ceiling; quantity for untracked stock; price multiplier, fixed amount and rounding to ,90 or ,99, computed in minor units.
  * Double-decrement warnings are shown.
* Observe mode logs what would be imported or sent and changes nothing.
* Admin (WooCommerce > Pazaryerleri):
  * Tabs: Genel, Trendyol, Hepsiburada, Stok köprüsü, Geçiş, Günlük.
  * The connection test and "Şimdi senkronla" are enqueued, never run inline.
  * The log viewer has filters, a purge and a raw payload view. Order list column and filter (HPOS and classic). Order box with "Yeniden çek" (Trendyol: that order only; Hepsiburada: "Pazaryerini şimdi çek" pulls the whole marketplace).
* Non-destructive, re-runnable migrator from the older Erusoft Trendyol and Hepsiburada plugins. A dry-run report comes first; legacy meta and tables are kept, and a re-run repairs statuses.
* WP-CLI: wp eru-mpo pull | lifecycle | push-stock [--all] | migrate [--dry-run] | status.
* Privacy: raw payloads live in their own table with identity numbers masked and a retention period (default 30 days). The identity number is copied to the order only on request. The log is redacted. A WordPress exporter and eraser are included; the eraser runs ahead of WooCommerce's order eraser and also cleans every order WooCommerce erases.

Inert until you switch it on: no outbound request, no scheduled job and no front-end change before the master switch is on and a marketplace is configured. Observe mode is on and stock/price push is off by default. HPOS compatible.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. Every marketplace call was verified against mocked responses of the documented shape only. The calls covered: Trendyol orders, package lookup, Picking, approved products and price-and-inventory; Hepsiburada orders, package endpoints, packing, listings and stock/price uploads. Fields that were not confirmed against a live account include the Hepsiburada open-package line fields, the packageable-lines response and the listing API answers. Verify with your own account, in the test environment and in observe mode first.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it. WooCommerce is required.
2. Deactivate the older Erusoft Trendyol or Hepsiburada plugin if it is installed.
3. Go to WooCommerce > Pazaryerleri, enter the marketplace credentials, run "Bağlantıyı test et", switch the master switch on and watch the log in observe mode before turning it off.
4. Coming from the older plugins: check the dry-run report on the Geçiş tab, then start the migration.

== Frequently Asked Questions ==

= Can an order be imported twice? =

No. Each package takes an atomic lock and a unique map row before an order is built. Re-runs only update the existing order, and statuses only move forward.

= Will stock be reduced twice? =

Not by this plugin. In "reduce" mode an order reduces WooCommerce stock once. The stock bridge sends the absolute WooCommerce stock, not a difference. Stock is reduced twice only if another system (an ERP) also reduces it for the same order; the Stok köprüsü tab warns about this.

= Does it create products on the marketplace? =

No. Use Product Push for Trendyol for that. This plugin keeps stock and prices in sync afterwards and can lend its Trendyol credentials to Product Push (only for fields left empty there, same account only).

= What is removed on uninstall? =

Settings, credentials, locks, run state, scheduled jobs and the raw, listings and stock queue tables. The order map and the log are business records and are kept unless ERU_MPO_REMOVE_DATA is defined as true. Order meta and order notes are always kept, and the older plugins' data is never touched.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
