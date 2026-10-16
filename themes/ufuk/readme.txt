=== Ufuk ===
Contributors: erusoft
Tags: blog, business, one-column, right-sidebar, custom-colors, custom-logo, custom-menu, featured-images, footer-widgets, theme-options, threaded-comments, translation-ready, wide-blocks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, fast corporate theme with a configurable front page, light/dark mode and a spam-protected contact form.

== Description ==

Ufuk is a classic (PHP) corporate theme. The static front page is assembled from Customizer settings and your own pages:
hero, figures band, services (the child pages of a page you choose), about (a page you choose), latest posts, a call-to-action
band and a contact block. Sections that have nothing to show stay hidden for visitors; administrators see a short hint with
a link to the right Customizer section.

* All settings in Appearance → Customize → Ufuk (colours, header button, hero, figures, sections, contact, social links, footer).
* Light and dark mode (follows the system setting, visitors can switch), or light only.
* Contact form: nonce, honeypot, per-IP rate limit, sanitised fields, sent with wp_mail; messages are never stored.
  Can be replaced by any form plugin's shortcode.
* Self-hosted Plus Jakarta Sans (SIL OFL), no external requests.
* Open Graph tags and Organization/WebSite JSON-LD, skipped automatically when Yoast SEO, Rank Math, SEOPress or
  All in One SEO is active.
* Skip link, visible focus, keyboard-operable menus (Escape closes), reduced-motion support.

The interface strings are Turkish and translation-ready (text domain `ufuk`). Diğer Erusoft eklentileri ve temaları: https://wordpress.erusoft.com

== Installation ==

1. Appearance → Themes → Add New → Upload Theme, choose ufuk.zip, then Activate.
2. Settings → Reading: choose a static front page.
3. Appearance → Customize → Ufuk: fill in the sections you want to show.

== Frequently Asked Questions ==

= Where can I get support? =
Check the FAQ and limitations in the README first. Report bugs on GitHub: https://github.com/erayusta/erusoft-wp/issues. For setup, configuration, custom development or integration support, e-mail hello@erusoft.com (https://erusoft.com). Never send passwords, API keys or customer data.

= Where are the contact messages stored? =

Nowhere. They are e-mailed to the address set in the Customizer (default: the site admin e-mail).

== Copyright ==

Ufuk WordPress Theme, (C) 2026 Erusoft. Ufuk is distributed under the terms of the GNU GPL v2 or later.

Plus Jakarta Sans, Copyright 2020 The Plus Jakarta Sans Project Authors, SIL Open Font License 1.1 (fonts/OFL.txt).
Feather icons, Copyright (c) 2013-2017 Cole Bemis, MIT License (assets/icons/LICENSE).
Screenshot image: Anders Jildén, Unsplash License, https://unsplash.com/photos/Sc5RKXLBjGg

== Changelog ==

= 1.0.0 =
* Initial public release.
