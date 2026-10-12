=== Category Archive H1 for WordPress ===
Contributors: erusoft
Tags: seo, h1, heading, category, taxonomy
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds an editable H1 heading field to category, tag and other taxonomy archives.

== Description ==

Adds an "Arşiv H1 başlığı" field to term screens of the enabled taxonomies. The heading is passed through the archive title filters; in auto mode an H1 is added only if the page has none, in replace mode a theme H1 that hardcodes the term name is replaced. Filter: `eru_cah_heading`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it.
3. Configure it under Ayarlar → Arşiv H1.

== Frequently Asked Questions ==

= Does it duplicate my theme heading? =
No. Auto mode only injects an H1 when the rendered page has none.

= What is removed on uninstall? =
Its own settings (`eru_cah_*` options). Headings entered on terms (`eru_cah_heading` term meta) are kept.

== Screenshots ==

1. Settings screen.

== Changelog ==

= 1.0.0 =
* Initial public release.
