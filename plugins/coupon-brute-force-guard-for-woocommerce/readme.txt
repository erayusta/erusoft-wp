=== Coupon Brute-Force Guard for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, coupon, security, brute force, rate limit
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stops coupon guessing attacks by locking out clients after repeated failed attempts.

== Description ==

Counts failed coupon attempts (only explicit apply requests: classic AJAX/form and the Store API apply-coupon route; re-validation of coupons already in the cart is not counted) per logged-in user or hashed IP. After the configured number of failures inside the time window (default 5 attempts / 15 minutes) the client is locked out for a configurable time (default 60 minutes). Coupon error messages that reveal whether a code exists (expired, usage limit reached, not yours) are replaced by a single generic message. Shop managers are exempt.

Settings: WooCommerce > Settings > Kupon Koruması. Filters: `eru_cbg_limits`, `eru_cbg_is_exempt`, `eru_cbg_client_ip`. Action: `eru_cbg_locked`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Review the limits under WooCommerce > Settings > Kupon Koruması.

== Frequently Asked Questions ==

= Does it work behind a proxy or CDN? =
Only the REMOTE_ADDR address is used by default. Use the `eru_cbg_client_ip` filter to supply the real client IP.

= What is removed on uninstall? =
Its own settings and transients (`eru_cbg_*` options/transients). The plugin stores nothing else.

== Screenshots ==

1. Settings tab.

== Changelog ==

= 1.0.0 =
* Initial public release.
