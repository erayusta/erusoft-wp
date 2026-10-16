<?php
/**
 * Campaign Engine — spend-and-earn rewards: pending → available (coupon: one use, bound to the e-mail,
 * expiry) → used / expired / reversed; maturation through Action Scheduler; full and pro-rata refunds;
 * the daily sweep re-arming itself while its own action is running; the "Ödülünüz hazır" e-mail; the
 * privacy exporter and eraser; deactivation clears the plugin's jobs.
 *
 *   wp eval-file tests/php/ce-rewards.php   (plugin active; creates and removes its own data)
 */

use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\Jobs;
use Erusoft\CampaignEngine\Infrastructure\Plugin;
use Erusoft\CampaignEngine\Infrastructure\Reward\RewardService;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;

require __DIR__ . '/ce-wc-helpers.php';
ce_wc_require();

$mails = array();
ce_wc_guards( $mails );
wc_load_cart();
$keep = ce_wc_snapshot_options();
$fail = false;
ce_wc_purge( true );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_coupons_enabled', 'yes' );
add_filter( 'eru_ce_coupon_prefix', fn() => 'CEDEMO' . strtoupper( CE_RUN ) );
global $wpdb;

try {
	$p = ce_wc_product( 'CE Demo Mont', '1000.00' );
	$q = ce_wc_product( 'CE Demo Bere', '59.90' );

	// Paid order 2.000 (invented) earns 100.
	$camp = ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'future_reward', 'reward' => 'fixed', 'value' => 10000, 'valid_days' => 21 ) ),
			'rules'   => array(
				'op'       => 'and',
				'children' => array( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 200000 ) ),
			),
		),
		'CE Demo Harca Kazan'
	);

	// ------------------------------------------------------------------ (e) maturation 0 → available at once
	Options::update( array( 'reward_maturation_days' => 0, 'reward_refund_policy' => 'pro_rata' ) );
	ce_wc_fill( array( array( $p, 2 ) ) );
	$o1 = ce_wc_checkout( ce_mail( 'a' ) );
	ce_ok( '(e) unpaid order earns nothing yet', array() === RewardRepository::forOrder( $o1->get_id() ) );
	$o1->payment_complete();
	$rows = RewardRepository::forOrder( $o1->get_id() );
	ce_ok( '(e) paid 2.000 → one reward of 100', 1 === count( $rows ) && 10000 === (int) $rows[0]['amount_minor'] && ce_mail( 'a' ) === $rows[0]['email'] );
	$r = $rows[0];
	ce_ok( '(e) maturation 0 → available with a coupon', 'available' === $r['status'] && (int) $r['coupon_id'] > 0 && '' !== $r['code'] );
	$coupon = new WC_Coupon( (int) $r['coupon_id'] );
	ce_ok( '(e) coupon: fixed 100, usage_limit 1, bound to the e-mail', 'fixed_cart' === $coupon->get_discount_type() && 100.0 === (float) $coupon->get_amount() && 1 === $coupon->get_usage_limit() && array( ce_mail( 'a' ) ) === $coupon->get_email_restrictions() );
	$exp = $coupon->get_date_expires();
	ce_ok( '(e) coupon expires after the action\'s 21 days', $exp && abs( $exp->getTimestamp() - ( time() + 21 * DAY_IN_SECONDS ) ) < 2 * DAY_IN_SECONDS && strtotime( $r['expires_at'] . ' UTC' ) > time() );
	$sent = array_filter( $mails, fn( $m ) => ce_mail( 'a' ) === ( is_array( $m['to'] ) ? reset( $m['to'] ) : $m['to'] ) && false !== mb_stripos( $m['subject'], 'Ödülünüz hazır' ) );
	ce_ok( '(e) "Ödülünüz hazır" e-mail sent once, with the code', 1 === count( $sent ) && false !== strpos( reset( $sent )['message'], $r['code'] ) );
	ce_ok( '(e) e-mail class registered with WooCommerce', isset( WC()->mailer()->get_emails()['eru_ce_reward_ready'] ) );

	$o1 = wc_get_order( $o1->get_id() );
	$o1->update_status( 'completed' );
	do_action( 'woocommerce_payment_complete', $o1->get_id() );
	ce_ok( '(e) repeated paid hooks: still one reward, one e-mail', 1 === count( RewardRepository::forOrder( $o1->get_id() ) ) && 1 === count( array_filter( $mails, fn( $m ) => false !== mb_stripos( $m['subject'], 'Ödülünüz hazır' ) ) ) );

	// Full refund → reversed, coupon disabled (never deleted).
	wc_create_refund( array( 'order_id' => $o1->get_id(), 'amount' => $o1->get_total(), 'reason' => 'CE Demo tam iade' ) );
	$r = RewardRepository::get( (int) $r['id'] );
	ce_ok( '(e) full refund → reward reversed', 'reversed' === $r['status'] && 0 === (int) $r['amount_minor'] );
	$cpost = get_post( (int) $r['coupon_id'] );
	ce_ok( '(e) coupon kept but disabled (not published, code no longer resolves)', $cpost && 'publish' !== $cpost->post_status && 0 === wc_get_coupon_id_by_code( $r['code'] ) );

	// ------------------------------------------------------------------ (f) partial refund, pro-rata, rounded to minor units
	Options::update( array( 'reward_maturation_days' => 7, 'reward_refund_policy' => 'pro_rata' ) );
	ce_wc_fill( array( array( $p, 2 ) ) );
	$o2 = ce_wc_checkout( ce_mail( 'b' ) );
	$o2->payment_complete();
	$r2 = RewardRepository::forOrder( $o2->get_id() )[0];
	ce_ok( '(f) maturation 7 → pending, no coupon yet', 'pending' === $r2['status'] && 0 === (int) $r2['coupon_id'] );
	ce_ok( '(f) maturation action scheduled for the reward', 1 === count( ce_wc_pending( 'eru_ce_reward_mature', array( (int) $r2['id'] ) ) ) );
	ce_ok( '(f) daily sweep armed once rewards exist', 1 === count( ce_wc_pending( Jobs::SWEEP_HOOK ) ) );
	wc_create_refund( array( 'order_id' => $o2->get_id(), 'amount' => '333.30', 'reason' => 'CE Demo kısmi iade' ) );
	$r2 = RewardRepository::get( (int) $r2['id'] );
	// 100 × (2000 − 333.30) / 2000 = 83.335 → 83.34 (half up to the minor unit).
	ce_ok( '(f) pro-rata: 100 × 1666.70 / 2000 = 83.335 → 83.34', 8334 === (int) $r2['amount_minor'] && 'pending' === $r2['status'] );
	do_action( 'woocommerce_order_refunded', $o2->get_id(), 0 );
	ce_ok( '(f) repeated refund hook: unchanged (absolute, not cumulative)', 8334 === (int) RewardRepository::get( (int) $r2['id'] )['amount_minor'] );
	// Mature it: due date reached (simulated), the scheduled action runs.
	$wpdb->update( RewardRepository::table(), array( 'available_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $r2['id'] ) );
	do_action( 'eru_ce_reward_mature', (int) $r2['id'] );
	$r2 = RewardRepository::get( (int) $r2['id'] );
	ce_ok( '(f) matured with the reduced amount: coupon 83.34', 'available' === $r2['status'] && 83.34 === (float) ( new WC_Coupon( (int) $r2['coupon_id'] ) )->get_amount() );
	do_action( 'eru_ce_reward_mature', (int) $r2['id'] );
	ce_ok( '(f) maturing twice issues no second coupon', 1 === count( get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'meta_key' => '_eru_ce_reward_id', 'meta_value' => (int) $r2['id'], 'fields' => 'ids' ) ) ) );
	wc_create_refund( array( 'order_id' => $o2->get_id(), 'amount' => '666.70', 'reason' => 'CE Demo ikinci iade' ) );
	$r2 = RewardRepository::get( (int) $r2['id'] );
	// Refunded 1000 of 2000 → 50.00; the available coupon follows.
	ce_ok( '(f) second partial refund on an available reward → coupon lowered to 50.00', 5000 === (int) $r2['amount_minor'] && 50.0 === (float) ( new WC_Coupon( (int) $r2['coupon_id'] ) )->get_amount() );

	// Policy "full": any refund reverses the whole reward.
	Options::update( array( 'reward_maturation_days' => 7, 'reward_refund_policy' => 'full' ) );
	ce_wc_fill( array( array( $p, 2 ) ) );
	$o3 = ce_wc_checkout( ce_mail( 'c' ) );
	$o3->payment_complete();
	wc_create_refund( array( 'order_id' => $o3->get_id(), 'amount' => '10.00', 'reason' => 'CE Demo küçük iade' ) );
	$r3 = RewardRepository::forOrder( $o3->get_id() )[0];
	ce_ok( '(f) policy full: a small refund reverses a pending reward', 'reversed' === $r3['status'] && 0 === (int) $r3['amount_minor'] );
	ce_ok( '(f) a reversed reward never matures (its action is unscheduled)', array() === ce_wc_pending( 'eru_ce_reward_mature', array( (int) $r3['id'] ) ) );

	// Used: the reward coupon on a later paid order.
	Options::update( array( 'reward_maturation_days' => 0, 'reward_refund_policy' => 'pro_rata' ) );
	ce_wc_fill( array( array( $p, 2 ) ) );
	$o4 = ce_wc_checkout( ce_mail( 'd' ) );
	$o4->payment_complete();
	$r4 = RewardRepository::forOrder( $o4->get_id() )[0];
	ce_wc_customer( ce_mail( 'd' ) );
	ce_wc_fill( array( array( $q, 2 ) ) );
	WC()->cart->apply_coupon( $r4['code'] );
	WC()->cart->calculate_totals();
	$o5 = ce_wc_checkout( ce_mail( 'd' ) );
	ce_ok( '(e) reward coupon applies for its owner', in_array( strtolower( $r4['code'] ), array_map( 'strtolower', $o5->get_coupon_codes() ), true ) );
	$o5->payment_complete();
	ce_ok( '(e) used: status used, order linked', 'used' === RewardRepository::get( (int) $r4['id'] )['status'] && (int) $o5->get_id() === (int) RewardRepository::get( (int) $r4['id'] )['used_order_id'] );
	$o5 = wc_get_order( $o5->get_id() );
	$o5->update_status( 'cancelled' );
	ce_ok( '(e) the order that used the coupon is cancelled -> reward available again', 'available' === RewardRepository::get( (int) $r4['id'] )['status'] && 0 === (int) RewardRepository::get( (int) $r4['id'] )['used_order_id'] );
	ce_wc_customer( ce_mail( 'd' ) );
	ce_wc_fill( array( array( $q, 2 ) ) );
	WC()->cart->apply_coupon( $r4['code'] );
	WC()->cart->calculate_totals();
	$o5 = ce_wc_checkout( ce_mail( 'd' ) );
	$o5->payment_complete();
	ce_ok( '(e) used again by a new paid order', 'used' === RewardRepository::get( (int) $r4['id'] )['status'] && (int) $o5->get_id() === (int) RewardRepository::get( (int) $r4['id'] )['used_order_id'] );
	wc_create_refund( array( 'order_id' => $o4->get_id(), 'amount' => $o4->get_total(), 'reason' => 'CE Demo iade' ) );
	ce_ok( '(e) refund after the coupon was used: stays used, logged for review', 'used' === RewardRepository::get( (int) $r4['id'] )['status'] );

	// Expired: the sweep marks available rewards whose coupon date passed.
	ce_wc_fill( array( array( $p, 2 ) ) );
	$o6 = ce_wc_checkout( ce_mail( 'c' ) );
	$o6->payment_complete();
	$r6 = RewardRepository::forOrder( $o6->get_id() )[0];
	$wpdb->update( RewardRepository::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => (int) $r6['id'] ) );
	Jobs::runDailySweep();
	ce_ok( '(e) sweep: past expiry → expired', 'expired' === RewardRepository::get( (int) $r6['id'] )['status'] );

	// ------------------------------------------------------------------ (g) sweep re-arm, pending only, while its own action runs
	as_unschedule_all_actions( Jobs::SWEEP_HOOK, array(), Jobs::GROUP );
	Jobs::ensureDailySweep();
	$ids = ce_wc_pending( Jobs::SWEEP_HOOK );
	ce_ok( '(g) one pending sweep', 1 === count( $ids ) );
	$running = (int) reset( $ids );
	ActionScheduler::store()->log_execution( $running );
	ce_ok( '(g) simulated: our sweep action is running (has_scheduled counts it, pending does not)', as_has_scheduled_action( Jobs::SWEEP_HOOK, null, Jobs::GROUP ) && array() === ce_wc_pending( Jobs::SWEEP_HOOK ) );
	// More due rewards than one batch → the running sweep re-arms a pending follow-up.
	add_filter( 'eru_ce_sweep_batch', fn() => 1 );
	ce_wc_fill( array( array( $p, 2 ) ) );
	Options::update( array( 'reward_maturation_days' => 7 ) );
	$due = array();
	foreach ( array( ce_mail( 'a' ), ce_mail( 'b' ) ) as $em ) {
		ce_wc_fill( array( array( $p, 2 ) ) );
		$o = ce_wc_checkout( $em );
		$o->payment_complete();
		$due[] = (int) RewardRepository::forOrder( $o->get_id() )[0]['id'];
	}
	$wpdb->query( 'UPDATE ' . RewardRepository::table() . " SET available_at = '" . gmdate( 'Y-m-d H:i:s', time() - 60 ) . "' WHERE id IN (" . implode( ',', $due ) . ')' );
	foreach ( ce_wc_pending( 'eru_ce_reward_mature' ) as $lost ) {
		ActionScheduler::store()->cancel_action( $lost ); // lost maturation actions: the sweep is the safety net
	}
	do_action( Jobs::SWEEP_HOOK );
	$avail = array_filter( $due, fn( $id ) => 'available' === RewardRepository::get( $id )['status'] );
	ce_ok( '(g) running sweep matured one batch (1 of 2 due rewards)', 1 === count( $avail ) );
	ce_ok( '(g) running sweep re-armed a PENDING follow-up although its own action is running', 1 === count( ce_wc_pending( Jobs::SWEEP_HOOK ) ) );
	ActionScheduler::store()->mark_complete( $running );
	do_action( Jobs::SWEEP_HOOK );
	ce_ok( '(g) the follow-up matures the rest', 2 === count( array_filter( $due, fn( $id ) => 'available' === RewardRepository::get( $id )['status'] ) ) );
	ce_ok( '(g) exactly one pending sweep afterwards (next day), no pile-up', 1 === count( ce_wc_pending( Jobs::SWEEP_HOOK ) ) );
	remove_all_filters( 'eru_ce_sweep_batch' );

	// ------------------------------------------------------------------ (h) privacy exporter / eraser
	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	ce_ok( '(h) exporter and eraser registered', isset( $exporters['eru-ce'] ) && isset( $erasers['eru-ce'] ) );
	$export = call_user_func( $exporters['eru-ce']['callback'], ce_mail( 'a' ), 1 );
	ce_ok( '(h) export lists the rewards and metrics of the e-mail', count( $export['data'] ) >= 2 );
	ce_ok( '(h) metrics exist before erasure', null !== MetricsRepository::forEmail( ce_mail( 'a' ) ) );
	$before = RewardRepository::byEmail( ce_mail( 'a' ) );
	$erase  = call_user_func( $erasers['eru-ce']['callback'], ce_mail( 'a' ), 1 );
	ce_ok( '(h) eraser removed the customer metrics', null === MetricsRepository::forEmail( ce_mail( 'a' ) ) && $erase['items_removed'] );
	$after = array_map( fn( $row ) => RewardRepository::get( (int) $row['id'] ), $before );
	ce_ok( '(h) reward rows kept but anonymised (no e-mail, no customer id)', count( $before ) > 0 && array() === RewardRepository::byEmail( ce_mail( 'a' ) ) && ! array_filter( $after, fn( $row ) => '' !== $row['email'] || 0 !== (int) $row['customer_id'] ) );
	$c_after = array_filter( array_map( fn( $row ) => (int) $row['coupon_id'] ? new WC_Coupon( (int) $row['coupon_id'] ) : null, $after ) );
	ce_ok( '(h) their coupons no longer carry the e-mail', ! array_filter( $c_after, fn( $c ) => in_array( ce_mail( 'a' ), $c->get_email_restrictions(), true ) ) );

	// ------------------------------------------------------------------ uninstall (data kept) and reinstall: orders are still followed
	Options::update( array( 'reward_maturation_days' => 0, 'reward_refund_policy' => 'pro_rata' ) );
	CampaignWriter::update( $camp, array( 'actions' => array( array( 'type' => 'future_reward', 'reward' => 'fixed', 'value' => 10000, 'valid_days' => 21 ) ), 'usage_limit' => 50 ), null, 'publish' );
	ce_wc_fill( array( array( $p, 2 ) ) );
	$u1 = ce_wc_checkout( ce_mail( 'b' ) );
	$u1->payment_complete();
	$ru = RewardRepository::forOrder( $u1->get_id() )[0];
	ce_wc_fill( array( array( $p, 2 ) ) );
	$u2  = ce_wc_checkout( ce_mail( 'c' ) );
	$tok = (string) $u2->get_meta( '_eru_ce_token' );
	ce_ok( 'reinstall set-up: reward available, a pending order holds a use', 'available' === $ru['status'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Erusoft\CampaignEngine\Infrastructure\Database\Schema::table( 'usage' ) . " WHERE token = %s AND status = 'reserved'", $tok ) ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'campaign-engine-for-woocommerce/campaign-engine-for-woocommerce.php' );
	}
	include dirname( __DIR__, 2 ) . '/plugins/campaign-engine-for-woocommerce/uninstall.php';
	Options::flush();
	ce_ok( 'uninstall without ERU_CE_REMOVE_DATA kept rewards and usage, removed the options', null !== RewardRepository::get( (int) $ru['id'] ) && false === get_option( MetricsRepository::SINCE_OPTION ) );
	Plugin::activate();
	ce_ok( 'reinstall: bookkeeping restarted because campaigns and records exist', MetricsRepository::since() > 0 );
	$u2 = wc_get_order( $u2->get_id() );
	$u2->update_status( 'cancelled' );
	ce_ok( 'reinstall: cancelling the pending order still releases its use', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Erusoft\CampaignEngine\Infrastructure\Database\Schema::table( 'usage' ) . " WHERE token = %s AND status = 'reserved'", $tok ) ) );
	wc_create_refund( array( 'order_id' => $u1->get_id(), 'amount' => $u1->get_total(), 'reason' => 'CE Demo iade' ) );
	ce_ok( 'reinstall: a refund still reverses the kept reward', 'reversed' === RewardRepository::get( (int) $ru['id'] )['status'] );

	// ------------------------------------------------------------------ deactivation clears the jobs; activation re-arms when work remains
	Plugin::deactivate();
	ce_ok( 'deactivation: no pending eru-ce job left', array() === as_get_scheduled_actions( array( 'group' => Jobs::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' ) );
	Plugin::activate();
	ce_ok( 'activation: daily sweep re-armed because rewards are open', 1 === count( ce_wc_pending( Jobs::SWEEP_HOOK ) ) );
	ce_ok( 'no outbound HTTP happened (guard would have exited)', true );
	CampaignWriter::update( $camp, null, null, 'draft' );
} catch ( RuntimeException $e ) {
	$fail = true;
	echo 'STOPPED at first failure: ' . $e->getMessage() . "\n";
} finally {
	ce_wc_purge();
	ce_wc_restore_options( $keep );
	WC()->session->set( 'order_awaiting_payment', 0 );
}

echo $fail ? "FAILED\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
