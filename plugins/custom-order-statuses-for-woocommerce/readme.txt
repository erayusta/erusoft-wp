=== Custom Order Statuses for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, order status, custom order status, orders, webhooks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create custom WooCommerce order statuses with colours, order, groups, paid/stock flags, e-mails, bulk actions and safe delete with order migration.

== Description ==

Adds custom WooCommerce order statuses from the admin (WooCommerce > Sipariş Durumları):

* Label, slug (auto-generated, Turkish letters folded, max 17 characters, core and foreign statuses rejected), colour badge with automatic text contrast, group, drag-to-reorder.
* Flags: counts as paid, reduces stock (once, like processing), editable, downloads permitted, bulk action, quick action button for orders in a chosen status.
* Optional customer and admin e-mails per status as real WC_Email classes, editable under WooCommerce > Settings > Emails; templates overridable from the theme.
* Colour badges and grouped tab boxes with counts on the HPOS and legacy order lists.
* A status that still has orders cannot be deleted until a target status is chosen; orders are migrated (in Action Scheduler batches above 50).
* Optional webhook topics: order.status_<slug> per status plus order.processing, order.completed, order.cancelled, order.refunded.
* WP-CLI: wp eru-cos list|add|update|delete|migrate|export|import.

Nothing changes until you add a status.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Add statuses under WooCommerce > Sipariş Durumları.

== Frequently Asked Questions ==

= Is anything changed on the storefront right after activation? =
No. Customers only see the status name WooCommerce already shows in My Account.

= What happens to orders when a status is deleted? =
Deletion is refused while orders use the status unless you choose a target status; the orders are then moved there.

= What is removed on uninstall? =
The plugin's options and e-mail settings. Orders keep their status; move them to another status before uninstalling.

== Screenshots ==

1. Status list with groups and settings.
2. Edit form.
3. Order list with colour badges and group boxes.
4. Status dropdown on the order screen.

== Changelog ==

= 1.0.0 =
* Initial public release.
