=== Content Automation for WordPress ===
Contributors: erusoft
Tags: ai, content automation, cron, queue, drafts
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A scheduled content pipeline: a topic queue becomes drafts via OpenAI, Anthropic or Perplexity on WP-Cron. Off by default.

== Description ==

A scheduled content pipeline: a topic queue is turned into posts by OpenAI, Anthropic or Perplexity on a WP-Cron schedule. Disabled by default; bring your own key (host-bound, never echoed back); drafts by default, bounded batch per run, overlap lock, daily request cap. Site owners are responsible for AI output and provider terms.

The admin interface is in Turkish. Diğer Erusoft eklentileri: https://wordpress.erusoft.com
Site owners are responsible for AI output and provider terms; review all generated text before publishing. API requests go only to the provider you configure, using your own key.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it.
3. Configure it under its Settings screen.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Does it send data anywhere by default? =
Only to the AI provider you choose, and only after you save an API key.

== Screenshots ==

1. Ayarlar ve kuyruk

== Changelog ==

= 1.0.0 =
* Initial public release.
