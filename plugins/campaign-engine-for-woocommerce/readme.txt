=== Campaign Engine for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, campaigns, discounts, promotions, rewards
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A condition-and-action campaign engine: four-step wizard, ready templates, live preview, smart cart offers and spend-and-earn reward coupons.

== Description ==

* Rule engine: AND/OR condition groups over 30 conditions (cart amount and quantity, product, category, tag, brand, attribute, stock, price, customer role, paid order count, first order, total spent, payment and shipping method, date, weekday, time window).
* Actions: product percentage or fixed discount, campaign price, cart percentage or fixed discount, quantity or amount tiers, "buy 3 pay 2" style sets and "second unit at 30 %", free shipping, spend-and-earn reward coupons.
* Four-step wizard (type, conditions, reward, schedule and limits) with field-level validation by the engine's own readers, ten templates and a live preview on a sample cart built from your products. Nothing is saved by the preview.
* Schedules with start and end, daily, weekly, weekly-span and monthly windows; total and per-customer usage limits with race-safe reservations.
* Priorities, exclusive campaigns, a store-wide stacking policy (best, first, stack), per-campaign and per-cart caps and a unit price floor. Money is exact to the minor unit; nothing is written to products.
* Storefront output is the same for every visitor (page-cache safe): only campaigns without visitor conditions change shown prices; first-order, role, weekday/time, payment-method and per-customer-limited campaigns appear with a condition note. The cart decides what applies.
* Storefront modules, all off by default: list badges, struck-through prices, product page gain box with a countdown to the campaign's real end, smart cart offers with a progress bar (classic cart), cart and checkout summary row (classic), thank-you box, campaign archive and detail pages, My Account rewards tab. No fake scarcity, viewer counts or invented deadlines.
* Status screen, overlap notice for Automatic Discounts / Cart Campaigns / Payment Method Discounts, WP-CLI `wp eru-ce doctor | campaign evaluate | rewards expire`.
* Privacy exporter and eraser, and a retention setting (default 365 days) with a daily batched purge of e-mails and hashes of closed records and of old audit rows. HPOS compatible.

Inert after activation: no storefront change, no scheduled job and no outbound request until you publish a campaign and enable a module.

Filters: `eru_ce_price_priority`, `eru_ce_item_excluded`, `eru_ce_payment_bound`, `eru_ce_free_shipping_rate`, `eru_ce_coupon_prefix`, `eru_ce_templates`, `eru_ce_admin_asset_allowlist`, `eru_ce_cache_adaptor`, `eru_ce_clock_rate_limit`, `eru_ce_clock_client`. Actions: `eru_ce_cache_purge`, `eru_ce_campaign_compiled`, `eru_ce_config_error`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce 9.9+ and PHP 8.1+ are required; MySQL 5.7.5+ or MariaDB 10.0.2+.
3. Go to Kampanyalar > Yeni kampanya, pick a template or start empty, preview and publish.
4. Enable the storefront modules you want under Kampanyalar > Ayarlar.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= How does it work with other price plugins? =

Price changes made before the `eru_ce_price_priority` priority (default 9999) are the campaign's base. A plugin that changes the price after it withdraws the campaign discount on that line; the status screen lists such hooks.

= Can it run next to the smaller Erusoft discount plugins? =

Yes, but do not define the same rule in both: each applies its own discount. An admin notice appears when Automatic Discounts, Cart Campaigns or Payment Method Discounts is active.

= What is removed on uninstall? =

Options, scheduled actions, the customer metrics table, transients and a per-user notice flag. Campaigns, usage records, rewards and the audit log are kept unless `ERU_CE_REMOVE_DATA` is defined as true. Reward coupons, order meta and order notes are never deleted.

= Do the Cart and Checkout blocks work? =

Yes, discounts show in the line prices. The smart cart box and the summary row are for the classic cart and checkout.

== Changelog ==

= 1.0.0 =
* Initial public release.
