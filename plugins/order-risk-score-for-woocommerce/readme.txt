=== Order Risk Score for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, orders, fraud, risk, security
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scores orders from 0 to 100 with configurable signals and shows a badge in the orders list.

== Description ==

Each new order is evaluated against signals you control: disposable e-mail domains, billing/shipping address or country mismatch, first or guest order, high amount, risky countries, manual payment above a threshold and a watchlist. Weights, thresholds and lists are options on WooCommerce > Settings > Risk Skoru; all lists are empty by default. The score is shown as a column in the orders list (HPOS and legacy) and as a box on the order screen with a recalculate button. The plugin never changes an order automatically and prints nothing on the storefront.

Filters: `eru_ors_signals`, `eru_ors_score`. Action: `eru_ors_scored`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Configure weights and lists under WooCommerce > Settings > Risk Skoru.

== Frequently Asked Questions ==

= Does it cancel suspicious orders? =
No. It only displays a score.

= What is removed on uninstall? =
Its own settings (`eru_ors_*` options). Risk scores stored on orders (`_eru_ors_*` order meta) are kept.

== Screenshots ==

1. Settings tab.

== Changelog ==

= 1.0.0 =
* Initial public release.
