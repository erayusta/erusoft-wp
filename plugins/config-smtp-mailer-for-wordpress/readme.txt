=== Config SMTP Mailer for WordPress ===
Contributors: erusoft
Tags: smtp, email, wp_mail, mail, wp-config
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress mail through SMTP configured by wp-config.php constants.

== Description ==

Define `ERU_SMTP_HOST`, `ERU_SMTP_PORT`, `ERU_SMTP_USER`, `ERU_SMTP_PASS`, `ERU_SMTP_SECURE`, `ERU_SMTP_FROM` and `ERU_SMTP_FROM_NAME` in wp-config.php. The plugin aligns From/Return-Path and adds a plain-text alternative body to HTML mail. Without `ERU_SMTP_HOST` it does nothing. No settings screen; the password never touches the database. Filters: `eru_smtp_config`, `eru_smtp_add_alt_body`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Add the constants to wp-config.php (see README for an example with placeholders).

== Frequently Asked Questions ==

= Is there a settings page? =
No. Configuration is constants-only on purpose.

= What is removed on uninstall? =
Nothing is stored in the database. Remove the SMTP constants from wp-config.php yourself.

== Changelog ==

= 1.0.0 =
* Initial public release.
