=== Store Closure Switch for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, maintenance, closed, 503, vacation
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Close the store to buyers with one switch: 503 plus Retry-After, admins bypass.

== Description ==

Under WooCommerce > Ayarlar > Mağaza Kapanışı you can close the shop: add-to-cart is disabled and cart/checkout (and Store API write routes) answer 503 with a Retry-After header. Optionally close the whole front end. Admins and shop managers bypass the closure. Optional reopen date; generic default message. Off by default. Filters: `eru_scs_is_closed`, `eru_scs_bypass`, `eru_scs_retry_after`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).
3. Enable the switch under WooCommerce → Ayarlar → Mağaza Kapanışı.

== Frequently Asked Questions ==

= Does activating the plugin close my shop? =
No. The switch is off by default.

= What is removed on uninstall? =
Its own settings (`eru_scs_*` options). The plugin stores nothing else.

== Screenshots ==

1. Settings tab.
2. Closed page shown to visitors.

== Changelog ==

= 1.0.0 =
* Initial public release.
