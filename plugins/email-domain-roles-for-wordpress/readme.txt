=== Email Domain Roles for WordPress ===
Contributors: erusoft
Tags: users, roles, registration, email, domain
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Assigns a user role automatically based on the e-mail domain at registration.

== Description ==

Define a table of e-mail domains and roles under Settings > E-posta Alan Adı Rolleri. New users whose e-mail domain matches get the mapped role; the table is empty by default so nothing changes until you add rows. Wildcards like `*.example.org` are supported, an optional fallback role can be set, and roles with administrative or privilege-escalation capabilities (such as manage_options, promote_users, edit_users, unfiltered_html, install_plugins, manage_woocommerce) are never offered. Users added manually by an administrator are not remapped. Roles explicitly chosen by an administrator are left alone.

Filters: `eru_edr_allowed_roles`, `eru_edr_replaceable_roles`. Action: `eru_edr_role_assigned`.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it.
3. Add domain mappings under Settings > E-posta Alan Adı Rolleri.

== Frequently Asked Questions ==

= Is the e-mail address verified? =
No. Registration e-mails are unverified, so avoid mapping privileged roles unless you verify addresses.

= What is removed on uninstall? =
Its own settings (`eru_edr_*` options). Roles already assigned to users are not changed.

== Screenshots ==

1. Settings screen.

== Changelog ==

= 1.0.0 =
* Initial public release.
