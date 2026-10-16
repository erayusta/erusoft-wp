=== Device Trade-In for WooCommerce ===
Contributors: erusoft
Tags: trade-in, woocommerce, device, discount, csv
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers trade in an old device: pick brand/model/capacity and condition from your value table and get the value deducted in the cart.

== Description ==

* Configurable level labels (default brand / model / capacity; the third level is optional) and a condition list with description and percentage (four generic levels by default).
* Admin-managed device and value table with CSV import (1.234,56 and 1234.56) and export (spreadsheet-formula safe). Nothing is seeded.
* Live estimate on the product page; the value is re-resolved from the database on every cart calculation and capped at the per-unit ex-tax product price.
* Applied as a non-taxable negative fee or as a line discount (reduces the tax base). Ask your accountant which fits your invoicing.
* One trade-in per cart, nonce-protected remove link, stale or deactivated devices are removed with a notice.
* Per-order trade-in record with statuses (pending, received, approved, rejected), order metabox and an optional order hold until approval.
* Daily overdue reminders through Action Scheduler (customer and team e-mail).
* "Device not listed" request form with nonce, honeypot and an IP rate limit.
* Privacy exporters/erasers and a retention purge. HPOS compatible.

Nothing appears on the storefront until you enable categories or products. No carrier integration. The block-based single product template is not supported for the selector.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Add devices under WooCommerce > Takas Cihazları, then enable categories or products under WooCommerce > Takas.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Is a device list included? =
No. You enter your own devices and values or import a CSV.

= Fee or line discount? =
A fee leaves the tax base unchanged; a line discount lowers it. This is an accounting decision; ask your accountant.

= What is removed on uninstall? =
Options, the three tables and scheduled actions. Order meta and notes are kept.

== Screenshots ==

1. Device table.
2. Settings (conditions).
3. Product page selector.
4. Mobile product page.
5. Cart with the trade-in credit.
6. Trade-in list.

== Changelog ==

= 1.0.0 =
* Initial public release.
