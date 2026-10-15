=== Server-Side Refunds for WooCommerce ===
Contributors: erusoft
Tags: ga4, matomo, measurement protocol, refund, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Report WooCommerce refunds and paid-order cancellations to Google Analytics 4 and/or Matomo from the server, through a queue with backoff and de-duplication.

== Description ==

Refunds are usually issued in the admin, when no browser is around to fire a tag. This plugin sends them server-side:

* Partial and full refunds, and cancelled/failed orders that were already paid (only the remaining amount: total minus already refunded).
* Google Analytics 4 Measurement Protocol `refund` event, with a test button that uses Google's validation endpoint.
* Matomo Tracking HTTP API over POST (`token_auth` in the body, never the URL). Mode A records a negative-revenue ecommerce order (raises Matomo's order count); mode B records a custom `ecommerce / refund` event.
* Queue table processed by Action Scheduler: unique de-duplication key, exponential backoff (max 6 hours, 10 attempts), permanent failure on 4xx, daily purge of sent and old failed rows.
* WooCommerce > Iade Kuyrugu screen (filter, retry, delete), an order metabox and `wp eru-ssr queue status|run|retry|purge`.
* Optional WordPress user ID, and a fallback client ID when there is no `_ga` cookie.
* HPOS compatible. Credentials are write-only and never logged.

Refund events only: no purchase tracking and no item-level refunds in 1.0.0. Nothing is sent until you enable and configure a destination.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. The GA4 and Matomo requests were verified against mocked HTTP responses only. Verify with your own accounts before relying on it.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Configure WooCommerce > Settings > Integration > Sunucu Tarafı İadeler.

== Frequently Asked Questions ==

= Is anything sent right after activation? =
No. A destination must be enabled and configured first.

= Why does Matomo show more orders after refunds? =
In mode A each refund is recorded as a negative-revenue ecommerce order because Matomo has no refund API. Use mode B to avoid this.

= What is removed on uninstall? =
Options, the queue table and scheduled actions. Order meta and notes are kept.

== Screenshots ==

1. Settings screen.
2. Queue list.
3. Order metabox.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
