=== High-Traffic Fixes for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, performance, redis, cloudflare, cache
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Eleven standalone must-use plugins that fix performance and stability problems on high-traffic WooCommerce stores.

== Description ==

High-Traffic Fixes is a pack of eleven small mu-plugin files. Each one fixes a single problem that appears on busy WooCommerce stores; copy only the ones you need into `wp-content/mu-plugins/`. Every file does nothing when the plugin it targets is not installed, and every fix can be turned off with a filter.

* **Action Scheduler Sleep** - shortens the 5 second pause between async queue runs to 1 second so PHP workers are freed sooner.
* **AutomateWoo Redis Flush** - replaces a full key-space SCAN with a version-counter invalidation.
* **Cart Fragments Guard** - loads WooCommerce cart fragments only for visitors with a cart or session cookie.
* **Checkout Login Gate Events** - stops GTM4WP `begin_checkout` and PixelYourSite `InitiateCheckout` events while guests only see the checkout login wall.
* **Category Rewrite Flush** - avoids a full object cache flush when a category edit does not change its slug or parent (Rank Math).
* **Cloudflare Purge Frontend** - blocks synchronous Cloudflare API purge calls on front-end requests.
* **Plugin Diet** - does not load admin-only plugins on front-end requests (you provide the list; empty by default).
* **IndexNow Non-Blocking** - sends IndexNow pings without waiting for the response.
* **Order Status Webhook Topics** - adds `order.processing`, `order.completed`, `order.cancelled` and `order.refunded` webhook topics.
* **PixelYourSite Fragment Fix** - fixes an undefined `pys_generate_token()` call in an add-to-cart fragment.
* **Redis Flush Tracer** - logs who triggers a full Redis flush; changes no behaviour.

== Installation ==

Do not upload this pack via Plugins > Add New; that installs nothing that runs.

1. Unzip the pack and copy the `.php` files you need into `wp-content/mu-plugins/` (top level: files must sit directly in that folder, not in a subfolder).
2. There is nothing to activate. Delete a file to remove its fix.
3. To disable a fix without deleting it: `add_filter( 'eru_htf_<fix>_enabled', '__return_false' );`

Plugin Diet example:

`add_filter( 'eru_htf_plugin_diet_rules', function ( $rules ) { return array_merge( $rules, array( 'example-importer/example-importer.php' ) ); } );`

== Frequently Asked Questions ==

= Does it work without WooCommerce? =

Yes. Files that depend on WooCommerce or a third-party plugin do nothing when that plugin is absent.

= Which filter disables a fix? =

`eru_htf_action_scheduler_sleep_enabled`, `eru_htf_automatewoo_redis_flush_enabled`, `eru_htf_cart_fragments_guard_enabled`, `eru_htf_checkout_login_gate_events_enabled`, `eru_htf_category_rewrite_flush_enabled`, `eru_htf_cloudflare_purge_frontend_enabled`, `eru_htf_plugin_diet_enabled`, `eru_htf_indexnow_nonblocking_enabled`, `eru_htf_order_status_webhook_topics_enabled`, `eru_htf_pixelyoursite_fragment_fix_enabled`, `eru_htf_redis_flush_tracer_enabled`.

= Does the flush tracer log personal data? =

It logs the request path, user ID and a backtrace. No IP address is stored. The log sits above the web root by default and stops growing at 5 MB.

== Changelog ==

= 1.0.0 =
* Initial public release.
