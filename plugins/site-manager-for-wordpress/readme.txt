=== Site Manager for WordPress ===
Contributors: erusoft
Tags: llms.txt, robots.txt, schema, local business, ai crawlers
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Three small opt-in modules: a virtual llms.txt, LocalBusiness JSON-LD, and per-crawler AI rules for robots.txt. All off until enabled.

== Description ==

* llms.txt (and optional llms-full.txt) built from your pages, posts and, when WooCommerce is active, products. Password-protected, private and noindex content, and products hidden from the catalogue or search, are left out.
* LocalBusiness JSON-LD with a type select, address, hours, geo and sameAs. Empty fields are omitted. Skipped when an SEO plugin's local module already prints it.
* robots.txt rules per AI crawler (default / allow / block) for GPTBot, ClaudeBot, PerplexityBot, Google-Extended and others.
* WP-CLI: `wp eru-sm llms|schema|robots`.

The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it.
3. Open Settings > Site Yönetimi and switch on the modules you want.

== Frequently Asked Questions ==

= Does it send data anywhere? =
No. It makes no outbound requests.

= Does it change my site after activation? =
No. Every module is off by default.

= A robots.txt file already exists in my site root. =
The physical file wins and the virtual rules are not served; the settings screen warns you.

== Screenshots ==

1. llms.txt ayarları
2. Yerel işletme şeması
3. robots.txt yapay zekâ kuralları
4. /llms.txt çıktısı

== Changelog ==

= 1.0.0 =
* Initial release.
