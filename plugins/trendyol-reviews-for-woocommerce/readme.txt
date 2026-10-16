=== Trendyol Reviews for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, reviews, trendyol, import, marketplace
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Imports the product reviews of your own Trendyol store into WooCommerce, matched by barcode or SKU, with masked reviewer names.

== Description ==

Store import: enter your Trendyol seller ID, API key and API secret under Products > Trendyol Yorumları and click "İçe aktar". Your product list (barcode, stock code, content ID) is read from the official Trendyol Seller API page by page in the background (Action Scheduler). Each product is matched to a WooCommerce product by priority: manual mapping table, barcode meta (default `_global_unique_id`, the WooCommerce GTIN/EAN field), then SKU, and every matched product gets its own background import action. A run without progress for 30 minutes is marked as failed and can be restarted. Unmatched products are listed with an inline product picker and a "Eşleştir" (map) button.

Reviews are read from Trendyol's public but undocumented review endpoint (the Seller API has no review endpoint), inserted as native WooCommerce reviews (`review` comments with `rating` meta), de-duplicated by Trendyol review ID and, when the review payload names a seller, limited to your own seller ID. Reviewer names are always masked ("A*** B***"); no e-mail or IP address is stored. Imported reviews are excluded from product structured data by default (Google does not allow reviews collected on other sites in review snippets); a setting can include them. Photo download is off by default and limited to Trendyol's CDN.

Also included: single-product import from a Trendyol product URL and bulk mapping from a two-column CSV (neither needs API credentials), optional daily sync, a results table, a log and WP-CLI commands (`wp trendyol-reviews import|status|reset`). Products with imported reviews get a rating summary and a sortable, filterable, paginated review list; other products keep the theme template. Nothing is requested until you start an import.

The store import was tested with synthetic Seller API responses; verify it on your store with your own credentials.

Legal: only import reviews from your own Trendyol store into your own products. Trendyol is a third-party trademark; this plugin is not affiliated with or endorsed by Trendyol.

Filters: `eru_tyr_match`, `eru_tyr_endpoint`, `eru_tyr_include_item`, `eru_tyr_photo_hosts`, `eru_tyr_request_delay`, `eru_tyr_store_page_size`, `eru_tyr_use_custom_template`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it. WooCommerce is required.
3. Open Products > Trendyol Yorumları, enter your seller ID, API key and API secret (Trendyol seller panel > Account Information > Integration Information) and click "İçe aktar".

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Do I need Trendyol API credentials? =
For the store import, yes: your own seller ID, API key and API secret. The URL and CSV modes need none.

= Are reviews imported twice? =
No. A review whose Trendyol ID already exists on the site is skipped.

= Are customer names stored? =
Only masked initials ("A*** B***"). No e-mail or IP address is stored.

= Do imported reviews appear in structured data? =
Not by default. Enable "Yapısal veri" in the settings to include them.

== Screenshots ==

1. Store import, results table and unmatched products with inline mapping.
2. Imported reviews on a product page.

== Changelog ==

= 1.0.0 =
* Initial public release.
