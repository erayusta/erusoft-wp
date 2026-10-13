=== Order SMS & Delivery Codes for İleti Merkezi ===
Contributors: erusoft
Tags: woocommerce, sms, iletimerkezi, delivery, otp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Order status SMS through İleti Merkezi, with optional delivery verification codes.

== Description ==

Sends SMS through the İleti Merkezi JSON API when WooCommerce order statuses change. Rules (from status → to status, optional shipping method) with Turkish templates such as `{ad}`, `{siparis_no}`, `{kargo_takip}`. A rule containing `{teslimat_kodu}` is a delivery-code rule: the customer gets a one-time 6-digit code (only its hash is stored) and staff can only move the order to the delivery status by entering the code in the order screen. Master switch off and test mode on by default: in test mode the rendered text is logged and nothing is sent to any number. Delivery codes expire after 48 hours (filterable). Credentials are stored in a separate non-autoloaded option, never printed back and only sent to https://api.iletimerkezi.com. İleti Merkezi is named as an integration only; this plugin is not affiliated with it.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. WooCommerce → SMS (İleti Merkezi): enter credentials and sender, switch it on, set up rules.

== Frequently Asked Questions ==

= Is anything sent right after activation? =
No. The master switch is off and test mode is on by default.

= What is removed on uninstall? =
Its two tables and options. Order notes are kept.

== Screenshots ==

1. Settings.
2. Rules with SMS length counter.
3. Order screen: delivery code box and SMS history.
4. Send log.

== Changelog ==

= 1.0.0 =
* Initial public release.
