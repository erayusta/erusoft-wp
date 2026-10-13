=== High-Value Cart Offer for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, whatsapp, cart, quote, offer, wholesale
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offer box with a WhatsApp link for high-value carts.

== Description ==

When the cart total reaches a configurable amount, shows an offer box whose button opens a WhatsApp chat (`wa.me`) pre-filled with the cart summary. Number, threshold and all texts are settings; nothing is shown until a WhatsApp number is set.

Requires WooCommerce 9.0 or newer; compatible with HPOS. The admin interface is in Turkish.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Configure it in the WooCommerce admin menu. Nothing changes on the storefront until you do.

== Frequently Asked Questions ==

= What is removed on uninstall? =
Only the plugin's own options. Data attached to products or orders is kept.

= Does it send messages automatically? =
No. It only renders a `https://wa.me/<number>?text=...` link; the customer sends the message.

== Screenshots ==

1. Ayarlar

== Changelog ==

= 1.0.0 =
* Initial public release.
