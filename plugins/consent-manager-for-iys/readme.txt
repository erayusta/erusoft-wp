=== Consent Manager for İYS ===
Contributors: erusoft
Tags: iys, consent, gdpr, kvkk, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A consent store with an audit trail for Turkey's İYS: checkout, registration and My Account capture, a rate-limited İYS sync queue, pull and nightly reconcile.

== Description ==

* Consent store: one record per recipient and channel (EPOSTA, MESAJ, ARAMA) with an append-only history (who, when, which source, evidence: consent text hash, salted IP/user-agent hashes, verified or not, admin note).
* Capture surfaces, all off by default: checkout box (classic and Checkout block), registration form box, My Account "İletişim tercihleri" tab. An unticked box writes nothing.
* An opt-out is never lifted by a logged-in account or a guest order: e-mail re-consent goes through an e-mailed, signed confirmation link; phone opt-outs only through İYS or an admin with a note.
* Signed, expiring opt-out links (`eru_cmi_optout_url()`); opening a link changes nothing, the button POST does.
* Batched, rate-limited İYS queue with the H175 (first record cannot be RET) and H467 (date older than 3 business days, with a 6-hour safety margin) rules: an aged opt-out is always sent dated now, an aged ONAY only with re-stamping on and after a look-up at İYS. Per-record results, backoff (5, 20, 60, 180, 600 minutes; a failed login only backs off), and look-up before resending after a lost answer. Per environment (sandbox/live).
* Hourly pull of changes made at İYS (pages of up to 999) and a nightly reconcile at a configurable hour that re-queues records missing at İYS once.
* Admin: consents list with detail and history, audit trail, queue with bulk retry/skip and redacted API errors, settings, tools. Recipients are masked everywhere.
* CSV import (consent date required) and masked CSV export, an importer for an append-only consent-log table (you map its columns), WP-CLI, and a write-only external endpoint signed with an API key and HMAC that answers with counts only.
* Privacy exporter and eraser (an erasure first withdraws active consents so the RET reaches İYS; records are anonymised, not deleted), retention settings for the API log and history hashes.
* With Growth Automations: e-mail opt-outs feed its suppression list, including a one-off copy of earlier opt-outs when it is activated later.

Inert until you switch it on and configure it. HPOS compatible.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. The İYS client, queue, pull and reconcile were verified against mocked HTTP responses of the documented shape only. Verify with your own İYS account in the sandbox environment first. The plugin sends no e-mail or SMS campaigns and does not block messages sent by other systems; ask `eru_cmi_is_consented()` before sending.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Go to WooCommerce > İYS İzinleri > Ayarlar > Bağlantı, enter your İYS credentials, ask İYS to whitelist your server's outgoing IP address and use "Bağlantıyı test et".

== Frequently Asked Questions ==

= What is removed on uninstall? =

Settings (including the external API key hash and signing secret), caches, locks, scheduled actions, the queue and the API log. The consent store, its history, the hash salt, the link-signing secret and the consent texts are kept unless `ERU_CMI_REMOVE_DATA` is defined as true in wp-config.php.

= Can an import lift an opt-out? =

No. Imports, the external API and logged-in customers cannot turn RET into ONAY.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
