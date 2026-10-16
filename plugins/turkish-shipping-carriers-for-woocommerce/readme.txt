=== Turkish Shipping Carriers for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, shipping, turkey, cargo, tracking
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turkish cargo carriers as a zone-compatible shipping method with per-carrier rates and tracking links.

== Description ==

Turkish cargo carriers (Yurtiçi, Aras, MNG, PTT, Sürat, HepsiJET, Trendyol Express, Sendeo and two free slots) as one zone-compatible WooCommerce shipping method: every enabled carrier is a separate rate with its own flat, base + per-desi or free-over-amount pricing. Staff enter the carrier and tracking number on the order; the tracking link (editable URL template with `{takip_no}`) appears in customer e-mails, the thank-you page and My Account, optionally with a customer note e-mail. No carrier API or credentials: label creation and automatic tracking are not included. Nothing is offered until a carrier is enabled. Carrier names are used as integration names only; this plugin is not affiliated with the carriers.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. WooCommerce → Kargo Firmaları: enable carriers and enter rates.
4. WooCommerce → Settings → Shipping: add "Kargo firmaları" to your zone.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Is anything changed on the storefront right after activation? =
No. No carrier is enabled by default, so the method is not offered.

= Does it create shipping labels? =
No. Carrier APIs are not included; the tracking number is entered on the order.

= What is removed on uninstall? =
Its own options. Order meta is kept.

== Screenshots ==

1. Carriers, rates and tracking URL templates.
2. Order screen: carrier shipping line and tracking box.
3. Checkout: one rate per carrier.
4. Checkout on mobile.

== Changelog ==

= 1.0.1 =
* New `eru_tsc_set_tracking()` / `Eru_TSC_Tracking::save_tracking()`: other plugins store a tracking number with the same customer note and `eru_tsc_tracking_saved` action as a manual entry.
* Saving an order screen opened before a background tracking update no longer blanks that tracking number.

= 1.0.0 =
* Initial public release.
