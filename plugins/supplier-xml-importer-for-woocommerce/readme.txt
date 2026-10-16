=== Supplier XML Importer for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, xml, import, suppliers, products
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import and update WooCommerce products from supplier XML feeds with mapping, price rules and a dry run.

== Description ==

Import products from supplier XML feeds (URL or uploaded file). Map XML elements or XPath to product fields, apply markup, a fixed rate and .90/.99 rounding, preview with a dry run, and run batched imports manually or on a schedule via Action Scheduler. XML is parsed safely: streaming reader, no DOCTYPE or external entities, no network access from the parser, configurable size limit, safe remote requests.

Requires WooCommerce 9.0 or newer; compatible with HPOS. The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it (WooCommerce must be active).
3. Configure it as described in the Turkish README; until configured the plugin leaves the store unchanged.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

== Screenshots ==

1. Besleme ayarları, alan eşleme ve kuru çalıştırma önizlemesi

== Changelog ==

= 1.0.0 =
* Initial public release.
