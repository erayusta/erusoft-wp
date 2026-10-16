=== AI Post Generator for WordPress ===
Contributors: erusoft
Tags: ai, openai, anthropic, perplexity, content
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate post drafts with OpenAI, Anthropic or Perplexity using your own API key. Drafts only; no request without a key.

== Description ==

Generate post drafts from a topic and keywords with OpenAI, Anthropic or Perplexity. Bring your own key (password field, autoload off, never echoed back, bound to its provider host); output is saved as draft/pending only, sanitised with wp_kses_post, with max-token and daily-request guards. Site owners are responsible for AI output and provider terms.

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

1. Yazı üretme ekranı
2. Ayarlar

== Changelog ==

= 1.0.0 =
* Initial public release.
