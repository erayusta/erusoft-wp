=== Voltaj ===
Contributors: erusoft
Tags: e-commerce, blog, two-columns, left-sidebar, custom-colors, custom-logo, custom-menu, featured-images, theme-options, threaded-comments, translation-ready, wide-blocks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A WooCommerce theme for technical and electrical supply stores: product search and category catalogue first, spec-first cards, quote and contact forms.

== Description ==

Voltaj is a classic (PHP) theme for WooCommerce stores that sell technical products such as lighting, cables,
switches and sockets or hand tools.

* One sticky header: logo, a "Kategoriler" button, a full-width product search and quote / account / cart links.
* A two-pane catalogue panel built from your product categories (up to three levels, cached, keyboard accessible);
  a left drawer with accordions on small screens. A menu assigned to the "catalog" location replaces the automatic tree.
* Front page from Customizer settings: search band, category grid, featured products, brand strip, info cards,
  latest posts and the page's own content. Every section can be switched off.
* Spec-first product cards: SKU, stock state, the first visible attributes as "label: value" rows, price with a
  small discount chip. Products without a price show "Fiyat teklifle belirlenir" and link to the quote form.
* Shop and category pages with sub-categories and attribute filters with counts (only products the catalogue lists).
* Product page with gallery, a code/category/brand line, a spec table, a framed buy box with a bulk-quote link and a
  free-shipping progress bar, tabs, related products, "recently viewed" (stored only in the visitor's browser) and a
  mobile buy bar.
* The free-shipping threshold is read from the "Free shipping" method of the customer's shipping zone; the bar is
  hidden when no such method applies, when it needs a coupon or when shipping is already free.
* Checkout steps on cart, checkout and order-received pages; two-column cart and checkout; for bank transfer orders
  the order-received page shows the amount, the order number and your BACS accounts with copy buttons.
* "Markalar" (brands, WooCommerce product_brand by default), "Teklif İste" (quote request) and "İletişim" (contact)
  page templates. Forms are e-mailed and never stored; nonce, honeypot and a per-IP rate limit; work with and without JavaScript.
* Blog table of contents and reading time.
* No WooCommerce template overrides: everything is done with hooks and CSS, so notices, structured data and other
  plugins' output keep working. Works without WooCommerce too.
* Self-hosted Archivo and JetBrains Mono (SIL OFL), inline SVG icons, no external requests.
* Open Graph tags and Organization/WebSite JSON-LD, skipped automatically when Yoast SEO, Rank Math, SEOPress or
  All in One SEO is active.

The interface strings are Turkish and translation-ready (text domain `voltaj`). Diğer Erusoft eklentileri ve temaları: https://wordpress.erusoft.com

== Installation ==

Requires WooCommerce 9.6 or later for the shop features (brands use WooCommerce's product_brand taxonomy); the theme also runs without WooCommerce.

1. Appearance → Themes → Add New → Upload Theme, choose voltaj.zip, then Activate.
2. Settings → Reading: choose a static front page.
3. Create pages with the "Markalar", "Teklif İste" and "İletişim" templates.
4. WooCommerce → Settings → Shipping: add a "Free shipping" method with a minimum order amount to show the progress bar.
5. Appearance → Customize → Voltaj: fill in the sections and the company details you want to show.

== Frequently Asked Questions ==

= Why is the free-shipping bar not shown? =

The bar needs an enabled "Free shipping" method with a minimum order amount in the shipping zone that matches the
customer's address. It is hidden when the method requires a coupon or when shipping is already free.

= Where do quote and contact messages go? =

To the e-mail address in Customizer → Voltaj → Teklif ve iletişim formları (the site admin e-mail when empty).
Messages are not stored in the database. You can show a form plugin's shortcode on the contact template instead.

= Can customers search by SKU? =

The theme uses the standard WordPress search (product title and content). Use a search plugin for SKU or brand search.

== Copyright ==

Voltaj WordPress Theme, (C) 2026 Erusoft. Voltaj is distributed under the terms of the GNU GPL v2 or later.

Archivo, Copyright 2020 The Archivo Project Authors, SIL Open Font License 1.1 (fonts/OFL.txt).
JetBrains Mono, Copyright 2020 The JetBrains Mono Project Authors, SIL Open Font License 1.1 (fonts/OFL.txt).
Feather icons, Copyright (c) 2013-2017 Cole Bemis, MIT License (assets/icons/LICENSE).
The X logo used for the social link is a trademark of X Corp. and is not covered by the MIT License.
Images in screenshot.png, all under the Unsplash License (https://unsplash.com/license):
* Hero photo (cables and tools on a table) by maks_d (https://unsplash.com/@maks_d), https://unsplash.com/photos/YGqDAQMVor0
* Pendant lamp by Phil Desforges, https://unsplash.com/photos/KP7p0-DRGbg
* Braided cables by Viacheslav Shatunov, https://unsplash.com/photos/4oGjsPLvLEA
* Wall socket by Noir Bakh, https://unsplash.com/photos/jVEIvNhk0cs
* Screwdriver by Steve A Johnson, https://unsplash.com/photos/lH-UZuoG-aY

== Changelog ==

= 1.0.0 =
* Initial public release.
