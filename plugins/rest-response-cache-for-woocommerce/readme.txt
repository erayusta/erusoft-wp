=== REST Response Cache for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, rest api, cache, store api, performance
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Caches anonymous GET responses of allow-listed public REST routes.

== Description ==

List the public REST routes to cache (for example /wc/store/v1/products) under WooCommerce > Ayarlar > REST Önbelleği. Authenticated, credentialed, session-cookie and non-GET requests are never cached, and neither are responses with Set-Cookie or no-store. Keys include the full normalised route, sorted query and locale. The cache is flushed on product and term changes and by a manual button. An X-Eru-Cache header shows HIT or MISS. The default list is empty, so nothing is cached until you opt in. Filters: `eru_rrc_routes`, `eru_rrc_ttl`, `eru_rrc_cacheable`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).
3. Add routes under WooCommerce → Ayarlar → REST Önbelleği.

== Frequently Asked Questions ==

= Why do I never see HIT when logged in? =
Logged-in requests are never cached.

= What is removed on uninstall? =
Its own settings and transients (`eru_rrc_*` options/transients). The plugin stores nothing else.

== Screenshots ==

1. Settings tab.

== Changelog ==

= 1.0.0 =
* Initial public release.
