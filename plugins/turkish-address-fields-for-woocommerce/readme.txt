=== Turkish Address Fields for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, checkout, turkey, address, il ilce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Province → district → neighbourhood → street pickers for the classic WooCommerce checkout, with bundled data.

== Description ==

Turkish address fields for the classic (shortcode) WooCommerce checkout and the My Account address form: province → district → neighbourhood → street pickers fed by bundled public-sector address data (NVİ address registry, July 2026 snapshot: 81 provinces, 970 districts, ~74,000 neighbourhoods, ~1.15 million streets), lazy-loaded per district through a read-only AJAX endpoint with no external API. Server-side validation, door-number check with a "no number" escape, address line cleanup, optional Turkish phone validation and order address lines built from the parts. Off by default. Block checkout is not supported.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required; the checkout page must use the `[woocommerce_checkout]` shortcode.
3. WooCommerce → Türkiye Adres: enable the fields.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Is anything changed on the storefront right after activation? =
No. The fields are added only after you enable them.

= Does it work with the block checkout? =
No. Use the classic `[woocommerce_checkout]` shortcode.

= What is removed on uninstall? =
Its option. Order and customer meta are kept.

== Screenshots ==

1. Settings.
2. Classic checkout with the address pickers.
3. Checkout on mobile.

== Changelog ==

= 1.0.0 =
* Initial public release.
