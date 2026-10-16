=== Product Add-On Services for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, product add-ons, extra services, warranty, gift wrap
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets customers pick paid add-on services (warranty, gift wrap, installation...) on the product page.

== Description ==

Create service groups under WooCommerce > Ek Hizmetler. A group is single choice (radio) or multiple choice (checkbox), optional or required, with a minimum/maximum for multiple choice. It applies to all products, chosen categories, tags or products, with excluded products and a per-product switch.

Each option has a fixed price or a percentage of the product price, charged per unit (multiplied by quantity) or once per cart line (a separate taxable fee line). The server validates every choice and computes every price; the product page works without JavaScript and shows a live total when JavaScript is available. Choices appear in the cart, checkout, order and e-mails; "Order again" re-applies them at current prices and drops add-ons that are no longer offered.

Without groups the plugin changes nothing on the storefront. Requires WooCommerce 9.0 or newer; compatible with HPOS and the block cart/checkout. Choosing add-ons is supported on the classic add-to-cart form, which block themes render through the "Add to cart form" block (`woocommerce/add-to-cart-form`). The newer interactive "Add to Cart + Options" block is not supported in 1.0.0.

The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Add groups under WooCommerce > Ek Hizmetler. Settings: WooCommerce > Settings > Products > Ek Hizmetler.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= What is removed on uninstall? =
Service groups, the plugin settings and the per-product switch. Order item meta and fee lines are kept.

== Screenshots ==

1. Service group edit screen
2. Product page with the add-on fieldsets and live total
3. Product page on mobile
4. Cart with the chosen services and a per-line fee
5. Order items in the admin

== Changelog ==

= 1.0.0 =
* Initial public release.
