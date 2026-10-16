=== Sales Team Commissions for WooCommerce ===
Contributors: erusoft
Tags: woocommerce, commissions, sales team, agents, payouts
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sales rep hierarchy, rule-based commissions, a hold period and a payout request flow for an in-house sales team.

== Description ==

* Agents in a hierarchy capped at 1–5 levels (default 2), cycle-guarded; pending / active / suspended; optional self-registration with admin approval.
* Customer assignment: referral link (latest code or first-touch lock), round-robin, a customer-confirmed e-mail invitation (signed, expiring, single-use link sent in the background; GET shows a button, POST confirms) or by an admin. Agents can never attach customers directly, and the invitation answer never reveals whether an address has an account.
* One rule system: product, category, brand, customer role, agent or global scope, per level, percentage or fixed amount per unit, priority and date range.
* Commissions per order line on the chosen status (completed or processing), ex-tax basis with optional shipping share, exact to the minor unit; refunds and cancellations adjust rows (pro rata or void; adjustment rows after a payout); a hold period before approval.
* My Account panel: balance, referral link, customer invitations, team, commissions, payout requests (one open request per agent, minimum amount, race-safe) and payout details.
* Payout details (holder, IBAN, optional TCKN) encrypted with libsodium using the ERU_STC_ENCRYPTION_KEY constant from wp-config.php; masked everywhere; without the key the fields are hidden.
* Admin: dashboard, agents (list, detail, HPOS order counts), rules, commissions (bulk approve / void, recalculation), payouts (mark paid with method and reference, cancel), reports by agent and month, settings. CSV exports neutralise formulas; the payout CSV is masked unless the full-IBAN export is confirmed server side, and every full export is audited.
* Read-only REST (namespace eru-stc/v1) and WP-CLI: `wp eru-stc recalc <order> [--agent=<id>] [--dry-run]`, `wp eru-stc approve-due`, `wp eru-stc report --month=YYYY-MM`.
* Privacy exporter and eraser. HPOS compatible.

Inert after activation: only the tables are created. No storefront change, cookie, scheduled job or outbound request until the module is switched on.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. For payout details, add the key: `wp config set ERU_STC_ENCRYPTION_KEY "$(php -r 'echo base64_encode(random_bytes(32));')" --type=constant` and back it up.
3. Add a rule under Satış Ekibi > Kurallar, add agents under Satış Ekibi > Temsilciler, then switch the module on under Satış Ekibi > Ayarlar.

== Frequently Asked Questions ==

= What happens while the module is off? =

No new commissions, no referral cookie, no My Account panel or REST routes, and approvals pause: rows whose hold has passed stay pending until the module is on again (or run `wp eru-stc approve-due`). Refunds and cancellations still adjust existing rows.

= An order had no agent when it was completed. =

It is not revisited automatically. Link the customer to an agent and run `wp eru-stc recalc <order>`, or pass `--agent=<id>`; the same form is on the Komisyonlar screen.

= Does the referral link work behind a page cache? =

Only if requests with `?ref=` (or the eru_stc_ref cookie) bypass the full-page cache, because the cookie is set by PHP.

= What is removed on uninstall? =

Settings, the round-robin pointer, the approval job and its lock, queued invitation mails, invitation counters and ids, and admin notices. Agents, customer links, rules, commissions, payouts and the full-export audit are kept unless `ERU_STC_REMOVE_DATA` is defined as true. Order meta and order notes are never deleted.

== Changelog ==

= 1.0.0 =
* Initial public release.
