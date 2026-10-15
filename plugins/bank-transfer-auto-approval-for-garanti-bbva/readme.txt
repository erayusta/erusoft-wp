=== Bank Transfer Auto-Approval for Garanti BBVA ===
Contributors: erusoft
Tags: bank transfer, garanti bbva, bacs, havale, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Approve bank transfer orders automatically from Garanti BBVA account transactions, read-only and with your consent.

== Description ==

* Polls the Garanti BBVA Account Transactions API read-only with OAuth2 client credentials and your consent ID. A fresh token per run; a 401 on a page re-mints once and retries that page.
* Pages of 100, at most 30 pages, de-duplicated by transaction id; the list ends on an empty page (a short page is not the end). A failed page or a truncated list aborts the run before any matching.
* Matches incoming credits to orders by order number (configurable regex with one capture group, default a digit-bounded 3-10 digit number, or a prefix template such as SIP-{number}) and amount (tolerance setting, default 0). Optional split payments (off by default; only transfers from one sender are summed). Credits with an amount of zero or less never count.
* Watched payment methods (default bacs), watched statuses (default on-hold and pending) and the approved status (default processing) are settings.
* Used-transaction ledger with a unique key: a bank transaction approves at most one order, even after a status rollback, and an order is approved by at most one transaction set. Claimed before the status change, under a run lock, with the status re-read from the database.
* Ambiguous matches (one transfer fits two orders, or two transfers fit one order) are never approved; the order is flagged for review and the shop manager is e-mailed once.
* Approval note with payer initials and the IBAN's last 4 digits only, order meta, e-mail to the notification list.
* Dry-run mode (on by default) logs "would approve" rows and changes nothing. While the master switch is off, every run ("Run now", WP-CLI) is a dry-run and manual approval is refused.
* Transactions screen with "approve with this transaction" (server-side lookup by id), match log, ledger with a release that requires a note.
* Action Scheduler polling every 5, 15 or 30 minutes; "Run now" only enqueues. No webhook, no REST route.
* WP-CLI: wp eru-gha check [--dry-run], transactions [--hours=48], ledger.
* Log retention purge and privacy exporter/eraser.

Inert until you switch it on and save the credentials. HPOS compatible.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. No public sandbox is known for the Garanti BBVA Account Transactions API. The token flow, paging, 401 handling, failure and truncation aborts, matching, ledger and dry-run were verified against mocked responses of the documented shape only. Verify with your own account in dry-run mode first.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Go to WooCommerce > Garanti Havale Onayı > Ayarlar, enter the client ID, client secret, consent ID, account number, unit number and IBAN, keep dry-run on and click "Şimdi çalıştır".
4. Check the "Eşleşirdi" rows on the Eşleşmeler tab, then enable the schedule and switch dry-run off.

== Frequently Asked Questions ==

= Can one transfer approve two orders? =

No. Every approval first writes the transaction id to the ledger table, whose unique key refuses a second active row. A status rollback does not free it; only a manual release with a note does.

= Does the bank call my site? =

No. The plugin only polls; it registers no webhook and no REST route.

= What is removed on uninstall? =

Settings, the log table, locks, transients and scheduled actions. Order meta, order notes and the used-transaction ledger (it prevents a transfer from approving a second order after a reinstall) are kept; define `ERU_GHA_REMOVE_DATA` as true to drop the ledger too.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
