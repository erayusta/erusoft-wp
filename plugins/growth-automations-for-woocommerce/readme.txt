=== Growth Automations for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, abandoned cart, back in stock, win-back, review request
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bank-transfer reminders, consent-based abandoned-cart e-mails, win-back coupons, review requests and variation-aware back-in-stock alerts.

== Description ==

Five automations, each with its own switch (all off by default) under WooCommerce > Büyüme Otomasyonları:

* Bank-transfer (BACS) reminder for on-hold orders after a delay, with the bank details from WooCommerce. One per order.
* Abandoned cart: the cart is stored only after the checkout consent box is ticked (or the account opt-in, or a consent filter). One e-mail after the delay with a single-use restore link that merges into the current cart.
* Win-back: customers whose latest completed order is older than N days and who have consent get one e-mail per period, with a unique single-use coupon bound to their address that is created before the mail is sent.
* Review request after delivery, linking to each product's reviews. Once per order.
* Back in stock alerts for simple products and single variations with double opt-in; each subscriber is notified once.

Processing runs in Action Scheduler batches that re-arm while work remains, so a large backlog is completed. Every marketing e-mail has a signed unsubscribe link (a click shows a button, the POST confirms). Personal data has an exporter, an eraser and a retention purge. No Turkish İYS registration is performed; a filter, `eru_gra_has_marketing_consent`, lets a consent platform grant consent. Classic checkout only for the consent box. WP-CLI: `wp eru-gra run <automation> [--dry-run]`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Enable the automations you want under WooCommerce > Büyüme Otomasyonları.

== Frequently Asked Questions ==

= Does anything happen on activation? =
No. Only two tables are created. Nothing is scheduled or sent until you enable an automation.

= What is removed on uninstall? =
Options, tables, alerts, scheduled actions and expired unused generated coupons. The opt-out list and unexpired or used generated coupons are kept unless `ERU_GRA_REMOVE_DATA` is defined as true. Order meta and notes are always kept.

== Screenshots ==

1. Abandoned cart settings.
2. E-mail preview.
3. Consent checkbox on the mobile checkout.
4. Back-in-stock form on a variation.
5. Log screen.

== Changelog ==

= 1.0.0 =
* Initial public release.
