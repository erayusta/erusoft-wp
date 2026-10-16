=== Yurtiçi Kargo Integration for WooCommerce ===
Contributors: erusoft
Tags: yurtici kargo, shipping, tracking, label, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create Yurtiçi Kargo shipments from WooCommerce orders, print barcode labels and track deliveries on a schedule, with several sender accounts.

== Description ==

* Auto-dispatch when an order reaches a status you choose (Action Scheduler, never in the status-change request), or manually from the order box, a bulk action or WP-CLI.
* Several sender accounts (write-only passwords, unique cargo-key prefixes) with first-match routing rules by order total and shipping method.
* One shipment per order and cargo key: per-order lock, pending state saved before the request, 1/5/15 minute retries without sleeping, and a queryShipment lookup before any re-send after an ambiguous failure; only an explicit not-found code allows a re-send, anything else needs review.
* Strict cancel handling (success only on the documented success fields for the requested key) and a confirmed local reset for provider-cancelled or never-created shipments; an old cargo key is never reused.
* "Already exists" answers are recognised by error code only, then recovered with queryShipment.
* Printable 100x150 mm or A6 label with a CODE128 barcode (bundled JsBarcode, no CDN).
* Tracking every 5 minutes, oldest poll first, per-phase intervals, exponential back-off and give-up for shipments that are never found, account self-heal.
* Optional order status changes after dispatch, in transit and delivery (default: no change; a note is always added). Works with custom order statuses.
* Cancel and re-dispatch with a new key (K1, K2 ...), order timeline, request log with the password redacted, retention purge, privacy exporter/eraser.
* Hands tracking numbers to Turkish Shipping Carriers for WooCommerce (1.0.1+) through its public save path when that plugin is active, so its customer notification and `eru_tsc_tracking_saved` action run as for manual entries.
* SOAP envelopes escape every value; responses are parsed without network access or DTDs.

Inert until you switch it on and save an account. HPOS compatible.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. The client and every flow (create, query, cancel, duplicate recovery, retries, ambiguous failures, tracking) were verified against mocked SOAP responses of the documented shape only. Verify the endpoints, the duplicate error code and the tracking codes against your contract documentation and test with your own Yurtiçi account in test mode first.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Go to WooCommerce > Settings > Yurtiçi Kargo, add an account, enable test mode and the integration, and save.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Are my credentials sent in clear text? =

The default live endpoint uses HTTPS (the address in the service WSDL). There is no default test endpoint: take it from your contract documentation; test mode does nothing until it is set. Yurtiçi's legacy endpoints are plain HTTP; if you enter one of them, the web service user name and password travel unencrypted. The settings screen warns about it.

= What is removed on uninstall? =

Settings, both tables, locks and scheduled actions. Order meta and order notes are kept.

== Third-party code ==

JsBarcode 3.11.6 (MIT), bundled in assets/vendor.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
