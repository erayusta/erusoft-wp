=== Narin ===
Contributors: erusoft
Tags: e-commerce, blog, one-column, custom-colors, custom-logo, custom-menu, featured-images, theme-options, threaded-comments, translation-ready, wide-blocks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An elegant, calm WooCommerce theme with an arched hero, a filter drawer, a free-shipping progress bar and card-style cart and checkout.

== Description ==

Narin is a classic (PHP) theme for small WooCommerce shops such as beauty, self-care, home and gift stores.

* Front page from Customizer settings: hero with an arched image, featured product categories, newest products,
  the page's own content, latest posts and a highlights strip. Every section can be switched off.
* Shop and category pages with a sub-category list and an optional filter widget area (a drawer on small screens).
* Product page with gallery and sticky summary, quantity buttons, a free-shipping progress bar, an optional
  shipping note and check-mark list, "recently viewed" (stored only in the visitor's browser) and a mobile buy bar.
* The free-shipping threshold is read from the "Free shipping" method of the customer's shipping zone; the bar is
  hidden when no such method applies, when it needs a coupon or when shipping is already free.
* Card-style cart, two-column checkout with item thumbnails, order-received summary cards and account dashboard cards.
* No WooCommerce template overrides: everything is done with hooks and CSS, so notices, structured data and
  other plugins' output keep working. Works without WooCommerce too.
* Self-hosted Cormorant Garamond and Jost (SIL OFL), inline SVG icons, no external requests.
* Open Graph tags and Organization/WebSite JSON-LD, skipped automatically when Yoast SEO, Rank Math, SEOPress or
  All in One SEO is active.
* Skip link, visible focus, keyboard-operable menus, focus-trapped drawers that close with Escape.

The interface strings are Turkish and translation-ready (text domain `narin`). Diğer Erusoft eklentileri ve temaları: https://wordpress.erusoft.com

== Installation ==

Requires WooCommerce 9.0 or later for the shop features; the theme also runs without WooCommerce.

1. Appearance → Themes → Add New → Upload Theme, choose narin.zip, then Activate.
2. Settings → Reading: choose a static front page.
3. WooCommerce → Settings → Shipping: add a "Free shipping" method with a minimum order amount to show the progress bar.
4. Appearance → Customize → Narin: fill in the sections you want to show.

== Frequently Asked Questions ==

= Why is the free-shipping bar not shown? =

The bar needs an enabled "Free shipping" method with a minimum order amount in the shipping zone that matches the
customer's address. It is hidden when the method requires a coupon or when shipping is already free.

= Does the theme have a contact form? =

No. The "İletişim" page template shows your contact details and the shortcode of any form plugin you enter in the Customizer.

== Copyright ==

Narin WordPress Theme, (C) 2026 Erusoft. Narin is distributed under the terms of the GNU GPL v2 or later.

Cormorant Garamond, Copyright 2015 The Cormorant Project Authors, SIL Open Font License 1.1 (fonts/OFL.txt).
Jost, Copyright 2020 The Jost Project Authors, SIL Open Font License 1.1 (fonts/OFL.txt).
Feather icons, Copyright (c) 2013-2017 Cole Bemis, MIT License (assets/icons/LICENSE).
Screenshot image: Sixteen Miles Out, Unsplash License, https://unsplash.com/photos/BaDjCICkyag

== Changelog ==

= 1.0.0 =
* Initial public release.
