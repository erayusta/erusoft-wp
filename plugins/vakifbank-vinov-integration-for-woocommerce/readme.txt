=== VakıfBank Vinov Integration for WooCommerce ===
Contributors: erusoft
Tags: vakifbank, vinov, payment gateway, otp, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

VakıfBank Vinov payments with a bank-verified SMS code, instruction tracking and cancellation, without storing identity numbers.

== Description ==

* VakıfBank Vinov payment method for the classic checkout. The customer's identity number and SMS code are verified by the bank on the server (sendOTP, verifyOTP, checkDebtor); there is no local bypass, also in test mode.
* process_payment re-checks a 10-minute, single-use verification bound to the identity number (HMAC), phone, installment count, order total and cart hash. Posted "verified" flags are ignored.
* The installment commission is a visible, non-taxable fee line, so the instruction amount equals the order total. The plan splits the total exactly in minor units.
* An ambiguous create (timeout, 5xx, unparsable reply) is looked up in the bank's list before one retry with the same attempt key.
* Status sync with Action Scheduler (every 15, 30 or 60 minutes while instructions are open): at most 50 rows per run, least recently checked first, under a run lock. Approved → payment_complete once; cancelled → order cancelled; still waiting after the give-up period (default 30 days, at least the single-payment expiry + 1) → order note and tracking stops. Every run, also a locked or failing one, and the daily job re-arm the sync. Unresolved attempts are looked up with the same matching rules and never marked paid without an approved status.
* A cancelled order is never charged silently: Vinov orders that may have an instruction are excluded from WooCommerce's unpaid-order auto-cancel; cancelling or refunding an unpaid order with a waiting instruction (any path) queues a bank cancel by instruction id that first reads the instruction's status (waiting → cancel; approved → alarm; already cancelled → close; unreadable → retry) and never changes the order status; an approval that still lands on such an order is never completed; it is flagged "İncelenmeli", mailed to the admin, shown as a persistent notice and fired as eru_vnv_paid_while_cancelled, once per instruction. Orders are never reopened automatically. Trashed and permanently deleted orders count as cancelled (the row is kept until the bank cancel is done), and an instruction found for an order paid with another gateway is cancelled the same way. Ambiguous Vinov orders are not auto-cancelled, but WooCommerce's hold-stock timer still releases their stock; if the attempt is found later, stock is reduced then (it can go negative) and the order is flagged for review.
* Order screen box: masked details, "Durumu sorgula" and "Talimatı iptal et" (confirmation step, edit_shop_orders and a nonce; by instruction id only; paid instructions are not cancelled here).
* WooCommerce > Vinov: instruction list with filters (5-minute cache), campaigns (read-only), receiver and debtor movements (not cached), queued connection test. No API explorer.
* The identity number is never stored: the session holds an HMAC, the order a mask (first 2 + last 2 digits). Logs are redacted. The admin debtor query sends the identity number once and never stores, caches or logs it.
* Privacy exporter and eraser for the masked identity number and phone; retention purge (default 365 days).
* WP-CLI: wp eru-vnv sync [--order=<id>], instruction <id>, cancel <id> [--yes].

Inert until you enable it and enter the bank-issued API address, credentials and merchant details: no default host, no outbound request and no scheduled job before that. HPOS compatible.

Diğer Erusoft eklentileri: https://wordpress.erusoft.com

== Beta ==

This is a beta release. Every bank call (OTP, debtor check, instruction create, list, detail, cancel, campaigns, movements) was verified against mocked responses of the documented shape only. The look-up after an ambiguous create relies on the bank echoing the order number and the attempt key in the instruction list. Verify with your own account in the bank's test environment first.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` and activate it. WooCommerce is required.
2. Make sure the checkout page is the classic `[woocommerce_checkout]` shortcode.
3. Go to WooCommerce > Settings > Payments > VakıfBank Vinov, keep test mode on and enter the API address, client ID and secret, WorkPlace ID, consent ID, payment purpose code and OTP transaction code your bank gave you, plus the installment rates.
4. Run WooCommerce > Vinov > Bağlantı testi, then place a test order.

== Frequently Asked Questions ==

= Does the plugin store the customer's identity number? =

No. The session keeps an HMAC of it and the order keeps a mask with the first two and last two digits. The phone is kept on the order for support; both are removed by the eraser and after the retention period.

= How does the installment commission reach the order? =

As a fee line "Vinov taksit farkı (N taksit, %x)" on the discounted items plus shipping, so the order total and the instruction amount are the same.

= Does it work with the block checkout? =

Not in 1.0.0. The method is hidden on the block checkout, the order-pay page and the Store API.

= What happens when a cancelled order's instruction is approved anyway? =

The order is not reopened. The instruction is flagged "İncelenmeli", the site admin gets an e-mail, a persistent admin notice shows until dismissed for that order, and the eru_vnv_paid_while_cancelled action fires. Refund in the bank panel if needed.

= Can I refund through the bank? =

No. Cancelling a waiting instruction is an admin action in the order box; refunds are handled in the bank panel or outside the shop.

= What happens to an order whose instruction was never confirmed? =

The status sync stops after the give-up period (default 30 days), adds an order note asking you to check the bank panel and leaves the order status alone.

= What is removed on uninstall? =

Settings, cached tokens, list caches, rate-limit counters, lock and claim rows and scheduled actions. The instruction table is kept unless `ERU_VNV_REMOVE_DATA` is defined as true. Order meta and order notes are always kept.

== Changelog ==

= 1.0.0 =
* Initial public release (beta).
