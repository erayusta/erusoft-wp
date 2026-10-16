<?php
/**
 * Sales Team Commissions — calculator money math, refunds, idempotency, hold period and payouts.
 *   wp --path=$HOME/code/erusoft-wp-demo eval-file tests/php/stc-commissions.php
 *
 * (c) basis: ex-tax line, proportional shipping on/off, 2-decimal exact (largest remainder)
 * (d) refunds: partial pro-rata on one item only (sums exact, idempotent), full refund in void mode,
 *     refund after payout → negative adjustment row, cancel → unpaid rows void
 * (e) idempotency: the trigger twice, the flag removed, processing then completed → no duplicates
 * (f) hold: pending until hold days pass, approved by the batched job; re-arm checks pending only
 * plus the module-off guard and the payout claim (minimum, double request, cancel releases), and:
 * (r1) amount-only refunds spread by line total + tax (prorata; void mode reduces pro rata, full voids),
 *      shipping-only refunds count only when shipping was in the basis
 * (r2) cancelled then completed again: cancel-voided rows revive with a new hold, refunds re-applied,
 *      refund-voided rows stay void; a restored refund-void row re-enters the hold
 * (m1) a cancelled payout nets its adjustments into the rows; (m2) a row claimed mid-reconcile gets an
 *      adjustment instead; (m6) a fixed rule never pays more than the line basis;
 * (n0) netting a row to exactly 0; (c1) no revival while the order is still cancelled; (a2) a paid row
 *      through cancel → re-complete gets the reverse adjustment; (b) schema upgrade v1 → v2;
 * (i1) recalculation never adds a second agent for the same line and level (admin refuses another level-1
 *      agent while live rows exist); (m3) revived rows restart the hold with trigger "processing";
 * (m5) trashed / deleted orders void like a cancellation, untrash revives; (m7) no commission on an
 *      agent's own guest order (billing e-mail).
 */

require __DIR__ . '/stc-helpers.php';
stc_require_plugin();

$mails = array();
stc_guards( $mails );
$keep = stc_snapshot();
stc_purge( true );

global $wpdb;
$ct = $wpdb->prefix . 'eru_stc_commissions';

/** Amount of the row of (agent, item, entry). */
function stc_t_amount( array $rows, $agent_id, $item_id, $entry = '' ) {
	foreach ( $rows as $r ) {
		if ( (int) $r->agent_id === (int) $agent_id && (int) $r->item_id === (int) $item_id && $entry === $r->entry_key ) {
			return $r;
		}
	}
	return null;
}

/** Refund helper: [ item_id => ex-tax amount ]. */
function stc_t_refund( $order_id, array $lines, $amount = null ) {
	$items = array();
	$sum   = 0.0;
	foreach ( $lines as $item_id => $total ) {
		$items[ $item_id ] = array(
			'qty'          => 0,
			'refund_total' => $total,
			'refund_tax'   => array(),
		);
		$sum              += (float) $total;
	}
	$r = wc_create_refund(
		array(
			'order_id'       => $order_id,
			'amount'         => null === $amount ? $sum : $amount,
			'line_items'     => $items,
			'refund_payment' => false,
			'restock_items'  => false,
		)
	);
	if ( is_wp_error( $r ) ) {
		throw new RuntimeException( 'refund: ' . $r->get_error_message() );
	}
	return $r;
}

try {
	$product = stc_product( 'Urun', '100.00' );
	$prod2   = stc_product( 'Urun2', '50.00' );

	// ---------------------------------------------------------------- module off
	stc_settings( array( 'enabled' => 'no' ) );
	stc_rule( array( 'scope' => 'global', 'level' => 1, 'type' => 'percent', 'rate' => '10' ) );
	stc_rule( array( 'scope' => 'global', 'level' => 2, 'type' => 'percent', 'rate' => '5' ) );
	$top   = stc_agent( 'top' );
	$rep   = stc_agent( 'rep', $top );
	$buyer = stc_user( 'buyer' );
	Eru_STC_Links::link( $buyer, $rep, 'manual' );
	$o = stc_order( $buyer, array( array( $product, 1, '100.00', '20.00' ) ) );
	$o = stc_status( $o->get_id(), 'completed' );
	stc_check( 'module off: completing an order writes nothing', 0 === count( stc_rows( $o->get_id() ) ) && '' === (string) $o->get_meta( '_eru_stc_calculated' ) );

	// ---------------------------------------------------------------- (c) basis
	stc_settings( array( 'max_levels' => 1, 'include_shipping' => 'no' ) );
	$o     = stc_order( $buyer, array( array( $product, 1, '100.00', '20.00' ), array( $prod2, 1, '50.00', '10.00' ) ), array( '30.00', '6.00' ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	$rows  = stc_rows( $o->get_id() );
	stc_check( '(c) shipping off: basis is the ex-tax line (100.00 / 50.00)', '100.0000' === stc_t_amount( $rows, $rep, $items[0] )->basis && '50.0000' === stc_t_amount( $rows, $rep, $items[1] )->basis );
	stc_check( '(c) shipping off: 10.00 / 5.00', '10.0000' === stc_t_amount( $rows, $rep, $items[0] )->amount && '5.0000' === stc_t_amount( $rows, $rep, $items[1] )->amount );

	stc_settings( array( 'max_levels' => 1, 'include_shipping' => 'yes' ) );
	$o     = stc_order( $buyer, array( array( $product, 1, '100.00', '20.00' ), array( $prod2, 1, '50.00', '10.00' ) ), array( '30.00', '6.00' ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	$rows  = stc_rows( $o->get_id() );
	stc_check( '(c) shipping on: ex-tax shipping split by line (120.00 / 60.00)', '120.0000' === stc_t_amount( $rows, $rep, $items[0] )->basis && '60.0000' === stc_t_amount( $rows, $rep, $items[1] )->basis );
	stc_check( '(c) shipping on: 12.00 / 6.00', '12.0000' === stc_t_amount( $rows, $rep, $items[0] )->amount && '6.0000' === stc_t_amount( $rows, $rep, $items[1] )->amount );

	// Odd split: 33.33 + 66.67 with 10.00 shipping → 3.33 + 6.67 (sums to 10.00), 7.5 % rounded once per row.
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_stc_rules SET rate = '7.5' WHERE name = %s AND level = 1", 'STC Demo kural ' . STC_RUN ) ); // phpcs:ignore
	$o     = stc_order( $buyer, array( array( $product, 1, '33.33', '0' ), array( $prod2, 1, '66.67', '0' ) ), array( '10.00', '0' ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	$rows  = stc_rows( $o->get_id() );
	stc_check( '(c) odd split: basis 36.66 / 73.34 (shipping shares sum to 10.00)', '36.6600' === stc_t_amount( $rows, $rep, $items[0] )->basis && '73.3400' === stc_t_amount( $rows, $rep, $items[1] )->basis );
	stc_check( '(c) odd split: 7.5 % → 2.75 / 5.50 (half-up, 2 decimals)', '2.7500' === stc_t_amount( $rows, $rep, $items[0] )->amount && '5.5000' === stc_t_amount( $rows, $rep, $items[1] )->amount );
	// Three equal lines share 10.00 shipping: 3.34 + 3.33 + 3.33 (per-line rounding would lose a kuruş).
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_stc_rules SET rate = '10' WHERE name = %s AND level = 1", 'STC Demo kural ' . STC_RUN ) ); // phpcs:ignore
	$o3    = stc_order( $buyer, array( array( $product, 1, '10.00', '0' ), array( $prod2, 1, '10.00', '0' ), array( $product, 1, '10.00', '0' ) ), array( '10.00', '0' ) );
	$o3    = stc_status( $o3->get_id(), 'completed' );
	$i3    = array_keys( $o3->get_items() );
	$rows3 = stc_rows( $o3->get_id() );
	stc_check( '(c) three equal lines: basis 13.34 / 13.33 / 13.33 (shipping shares sum to 10.00)', '13.3400' === stc_t_amount( $rows3, $rep, $i3[0] )->basis && '13.3300' === stc_t_amount( $rows3, $rep, $i3[1] )->basis && '13.3300' === stc_t_amount( $rows3, $rep, $i3[2] )->basis );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_stc_rules SET rate = '7.5' WHERE name = %s AND level = 1", 'STC Demo kural ' . STC_RUN ) ); // phpcs:ignore
	stc_check( '(c) plan() matches what was written', array( '2.75', '5.50' ) === array_column( Eru_STC_Calculator::plan( $o ), 'amount' ) );
	stc_check( '(c) fixed rule × qty', '6.00' === Eru_STC_Money::from_minor( Eru_STC_Calculator::amount_minor( (object) array( 'type' => 'fixed', 'rate' => '2.00' ), 999999, 3 ) ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_stc_rules SET rate = '10' WHERE name = %s AND level = 1", 'STC Demo kural ' . STC_RUN ) ); // phpcs:ignore

	// Money helpers.
	stc_check( '(c) money: to_minor / from_minor round-trip and half-up', 1005 === Eru_STC_Money::to_minor( '10.045' ) && -1005 === Eru_STC_Money::to_minor( '-10.045' ) && '10.05' === Eru_STC_Money::from_minor( 1005 ) && '-0.07' === Eru_STC_Money::from_minor( -7 ) );
	stc_check( '(c) money: largest-remainder allocation sums exactly', array( 334, 333, 333 ) === Eru_STC_Money::allocate( 1000, array( 1, 1, 1 ) ) && array( 333, 667 ) === Eru_STC_Money::allocate( 1000, array( 3333, 6667 ) ) && array( 0, 0 ) === Eru_STC_Money::allocate( 5, array( 0, 0 ) ) );

	// ---------------------------------------------------------------- (e) idempotency
	stc_settings( array( 'max_levels' => 2 ) );
	$created = 0;
	add_action(
		'eru_stc_commission_created',
		function () use ( &$created ) {
			++$created;
		}
	);
	$o = stc_order( $buyer, array( array( $product, 2, '200.00', '0' ) ) );
	$o = stc_status( $o->get_id(), 'completed' );
	stc_check( '(e) first trigger: 2 rows, 2 created events, flag set', 2 === count( stc_rows( $o->get_id() ) ) && 2 === $created && '' !== (string) $o->get_meta( '_eru_stc_calculated' ) );
	do_action( 'woocommerce_order_status_completed', $o->get_id(), $o );
	stc_check( '(e) trigger twice: still 2 rows, no new event', 2 === count( stc_rows( $o->get_id() ) ) && 2 === $created );
	$o->delete_meta_data( '_eru_stc_calculated' );
	$o->save();
	$res = Eru_STC_Calculator::calculate( wc_get_order( $o->get_id() ) );
	stc_check( '(e) flag removed: UNIQUE key blocks duplicates', 2 === count( stc_rows( $o->get_id() ) ) && 2 === $created && is_array( $res ) && array() === $res );

	stc_settings( array( 'max_levels' => 2, 'trigger_status' => 'processing' ) );
	$o = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ) ) );
	stc_status( $o->get_id(), 'on-hold' );
	stc_check( '(e) processing trigger: on-hold writes nothing', 0 === count( stc_rows( $o->get_id() ) ) );
	stc_status( $o->get_id(), 'processing' );
	stc_status( $o->get_id(), 'completed' );
	stc_check( '(e) processing then completed: one set of rows', 2 === count( stc_rows( $o->get_id() ) ) );
	$o = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ) ) );
	stc_status( $o->get_id(), 'completed' );
	stc_check( '(e) processing trigger: a straight-to-completed order is also counted', 2 === count( stc_rows( $o->get_id() ) ) );
	stc_settings( array( 'max_levels' => 2, 'trigger_status' => 'completed' ) );
	$o = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ) ) );
	stc_status( $o->get_id(), 'processing' );
	stc_check( '(e) completed trigger: processing writes nothing', 0 === count( stc_rows( $o->get_id() ) ) );

	// ---------------------------------------------------------------- (d) refunds
	stc_settings( array( 'max_levels' => 2, 'refund_policy' => 'prorata' ) );
	$o     = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ), array( $prod2, 1, '200.00', '0' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	$rows  = stc_rows( $o->get_id() );
	stc_check( '(d) before refund: 10.00 + 5.00 on line 1, 20.00 + 10.00 on line 2', '10.0000' === stc_t_amount( $rows, $rep, $items[0] )->amount && '5.0000' === stc_t_amount( $rows, $top, $items[0] )->amount && '20.0000' === stc_t_amount( $rows, $rep, $items[1] )->amount );
	$ref  = stc_t_refund( $o->get_id(), array( $items[0] => '33.33' ) );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(d) partial refund 33.33 of line 1: 6.67 + 3.33 (reductions 3.33 + 1.67 = 5.00 exactly)', '6.6700' === stc_t_amount( $rows, $rep, $items[0] )->amount && '3.3300' === stc_t_amount( $rows, $top, $items[0] )->amount );
	stc_check( '(d) original amount kept on the row', '10.0000' === stc_t_amount( $rows, $rep, $items[0] )->original_amount );
	stc_check( '(d) line 2 untouched', '20.0000' === stc_t_amount( $rows, $rep, $items[1] )->amount && '10.0000' === stc_t_amount( $rows, $top, $items[1] )->amount );
	do_action( 'woocommerce_order_refunded', $o->get_id(), $ref->get_id() );
	stc_check( '(d) same refund event again: unchanged', $rows == stc_rows( $o->get_id() ) ); // phpcs:ignore
	// 0.35 of line 2 (200.00): reduction round(30.00 × 0.00175) = 0.05 split 0.03 + 0.02 (per-row rounding would take 0.04 + 0.02).
	$ref2 = stc_t_refund( $o->get_id(), array( $items[1] => '0.35' ) );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(d) tiny refund on line 2: 19.97 + 9.98, total reduction exactly 0.05', '19.9700' === stc_t_amount( $rows, $rep, $items[1] )->amount && '9.9800' === stc_t_amount( $rows, $top, $items[1] )->amount );
	$ref2->delete( true );
	do_action( 'woocommerce_refund_deleted', $ref2->get_id(), $o->get_id() );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(d) deleting that refund restores line 2 (20.00 + 10.00)', '20.0000' === stc_t_amount( $rows, $rep, $items[1] )->amount && '10.0000' === stc_t_amount( $rows, $top, $items[1] )->amount );
	stc_t_refund( $o->get_id(), array( $items[0] => '66.67' ) );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(d) rest of line 1 refunded: its rows void at 0.00', 'void' === stc_t_amount( $rows, $rep, $items[0] )->status && '0.0000' === stc_t_amount( $rows, $rep, $items[0] )->amount && 'void' === stc_t_amount( $rows, $top, $items[0] )->status );
	stc_check( '(d) line 2 still pending at 20.00', 'pending' === stc_t_amount( $rows, $rep, $items[1] )->status && '20.0000' === stc_t_amount( $rows, $rep, $items[1] )->amount );

	// Full refund, void mode.
	stc_settings( array( 'max_levels' => 2, 'refund_policy' => 'void' ) );
	$voided = array();
	add_action(
		'eru_stc_commissions_voided',
		function ( $order_id, $ids ) use ( &$voided ) {
			$voided[ $order_id ] = array_merge( $voided[ $order_id ] ?? array(), $ids );
		},
		10,
		2
	);
	$o     = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ), array( $prod2, 1, '200.00', '0' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	stc_t_refund( $o->get_id(), array( $items[0] => '100.00', $items[1] => '200.00' ) );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(d) full refund in void mode: order refunded, all 4 rows void', 'refunded' === wc_get_order( $o->get_id() )->get_status() && 4 === count( $rows ) && 4 === count( array_filter( $rows, fn( $r ) => 'void' === $r->status ) ) );
	stc_check( '(d) voided event lists the 4 rows', 4 === count( array_unique( $voided[ $o->get_id() ] ?? array() ) ) );

	// Void mode, partial refund: the refunded line's rows void, the other line keeps its rows.
	$o     = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ), array( $prod2, 1, '200.00', '0' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	stc_t_refund( $o->get_id(), array( $items[0] => '10.00' ) );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(d) void mode, partial: refunded line void, other line pending', 'void' === stc_t_amount( $rows, $rep, $items[0] )->status && 'pending' === stc_t_amount( $rows, $rep, $items[1] )->status );

	// Refund after payout: paid row stays, negative adjustment row for the next payout.
	stc_settings( array( 'max_levels' => 2, 'refund_policy' => 'prorata', 'min_payout' => '0' ) );
	$o     = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET earned_at = %s WHERE order_id = %d", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $o->get_id() ) ); // phpcs:ignore
	foreach ( stc_rows( $o->get_id() ) as $r ) {
		Eru_STC_Commissions::approve( (int) $r->id );
	}
	$repo_row = stc_t_amount( stc_rows( $o->get_id() ), $rep, $items[0] );
	$payout = Eru_STC_Payouts::create_request( $rep );
	stc_check( '(d) payout claims the approved row', ! is_wp_error( $payout ) && (int) Eru_STC_Commissions::get( (int) $repo_row->id )->payout_id === (int) $payout );
	stc_check( '(d) payout total 10.00', '10.0000' === Eru_STC_Payouts::get( (int) $payout )->amount );
	stc_check( '(d) mark paid', true === Eru_STC_Payouts::mark_paid( (int) $payout, 'DEMO-001' ) && 'paid' === Eru_STC_Commissions::get( (int) $repo_row->id )->status );
	$ref  = stc_t_refund( $o->get_id(), array( $items[0] => '40.00' ) );
	$rows = stc_rows( $o->get_id() );
	$paid = stc_t_amount( $rows, $rep, $items[0] );
	$adj  = array_values( array_filter( stc_rows_of( $rows, $rep ), fn( $r ) => '' !== $r->entry_key ) );
	stc_check( '(d) refund after paid: paid row unchanged (10.00, paid)', 'paid' === $paid->status && '10.0000' === $paid->amount );
	stc_check( '(d) refund after paid: one adjustment row −4.00, approved, linked to the paid row', 1 === count( $adj ) && '-4.0000' === $adj[0]->amount && 'approved' === $adj[0]->status && (int) $paid->id === (int) $adj[0]->parent_id && null === $adj[0]->payout_id );
	stc_check( '(d) refund after paid: unpaid upline row reduced in place (5.00 → 3.00)', '3.0000' === stc_t_amount( $rows, $top, $items[0] )->amount );
	do_action( 'woocommerce_order_refunded', $o->get_id(), $ref->get_id() );
	stc_check( '(d) refund after paid, event again: still one adjustment', 1 === count( array_filter( stc_rows_of( stc_rows( $o->get_id() ), $rep ), fn( $r ) => '' !== $r->entry_key ) ) );
	stc_check( '(d) balance: available −4.00 for the next payout', '-4.00' === Eru_STC_Commissions::balance( $rep )['available'] );
	stc_check( '(d) payout with a negative balance refused', is_wp_error( Eru_STC_Payouts::create_request( $rep ) ) );

	// Cancel: unpaid rows void.
	$o = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ) ) );
	stc_status( $o->get_id(), 'completed' );
	stc_status( $o->get_id(), 'cancelled' );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(d) cancelled: both unpaid rows void', 2 === count( $rows ) && 2 === count( array_filter( $rows, fn( $r ) => 'void' === $r->status ) ) );

	// ---------------------------------------------------------------- (f) hold + eligibility job
	stc_settings( array( 'max_levels' => 2, 'hold_days' => 14 ) );
	$approved = array();
	add_action(
		'eru_stc_commission_approved',
		function ( $id ) use ( &$approved ) {
			$approved[] = (int) $id;
		}
	);
	$old = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ) ) );
	$new = stc_order( $buyer, array( array( $product, 1, '100.00', '0' ) ) );
	stc_status( $old->get_id(), 'completed' );
	stc_status( $new->get_id(), 'completed' );
	Eru_STC_Eligibility::run();
	$rows = array_merge( stc_rows( $old->get_id() ), stc_rows( $new->get_id() ) );
	stc_check( '(f) fresh rows stay pending, approved_at null', 4 === count( $rows ) && 4 === count( array_filter( $rows, fn( $r ) => 'pending' === $r->status && null === $r->approved_at ) ) );
	// Backdate: the old order earned 15 days ago, the new one 13 days ago.
	$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET earned_at = %s WHERE order_id = %d", gmdate( 'Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS ), $old->get_id() ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET earned_at = %s WHERE order_id = %d", gmdate( 'Y-m-d H:i:s', time() - 13 * DAY_IN_SECONDS ), $new->get_id() ) ); // phpcs:ignore
	add_filter( 'eru_stc_eligibility_batch', fn() => 1 );
	$n = Eru_STC_Eligibility::run();
	stc_check( '(f) batch of 1: one row approved, the next run is pulled forward to about a minute', 1 === $n && 1 === count( stc_pending( 'eru_stc_eligibility' ) ) && as_next_scheduled_action( 'eru_stc_eligibility', array(), 'eru-stc' ) <= time() + 120 );
	Eru_STC_Eligibility::run();
	$rows_old = stc_rows( $old->get_id() );
	$rows_new = stc_rows( $new->get_id() );
	stc_check( '(f) after hold: both rows of the old order approved with approved_at', 2 === count( array_filter( $rows_old, fn( $r ) => 'approved' === $r->status && null !== $r->approved_at ) ) );
	stc_check( '(f) inside hold: the new order stays pending', 2 === count( array_filter( $rows_new, fn( $r ) => 'pending' === $r->status ) ) );
	stc_check( '(f) approved event per row', 2 === count( array_intersect( $approved, array_map( fn( $r ) => (int) $r->id, $rows_old ) ) ) );
	stc_settings( array( 'max_levels' => 2, 'hold_days' => 13 ) );
	Eru_STC_Eligibility::run();
	Eru_STC_Eligibility::run();
	stc_check( '(f) hold days read at run time (13 → new order approved)', 2 === count( array_filter( stc_rows( $new->get_id() ), fn( $r ) => 'approved' === $r->status ) ) );

	// Re-arm while the job itself is running: pending-only check.
	as_unschedule_all_actions( 'eru_stc_eligibility', array(), 'eru-stc' );
	Eru_STC_Eligibility::ensure_scheduled();
	$ids = stc_pending( 'eru_stc_eligibility' );
	stc_check( '(f) ensure_scheduled: exactly one pending job', 1 === count( $ids ) );
	ActionScheduler::store()->log_execution( $ids[0] );
	stc_check( '(f) simulated running action is no longer pending', 0 === count( stc_pending( 'eru_stc_eligibility' ) ) );
	Eru_STC_Eligibility::run();
	ActionScheduler::store()->mark_complete( $ids[0] );
	$next = stc_pending( 'eru_stc_eligibility' );
	stc_check( '(f) the running job re-armed itself (pending-only check)', 1 === count( $next ) && ! in_array( $ids[0], $next, true ) );
	stc_check( '(f) interval filter: next run about one day out by default', abs( as_next_scheduled_action( 'eru_stc_eligibility', array(), 'eru-stc' ) - ( time() + DAY_IN_SECONDS ) ) < 120 );
	add_filter( 'eru_stc_eligibility_interval', fn() => HOUR_IN_SECONDS );
	as_unschedule_all_actions( 'eru_stc_eligibility', array(), 'eru-stc' );
	Eru_STC_Eligibility::run();
	stc_check( '(f) interval filter: hourly', abs( as_next_scheduled_action( 'eru_stc_eligibility', array(), 'eru-stc' ) - ( time() + HOUR_IN_SECONDS ) ) < 120 );

	// ---------------------------------------------------------------- payouts (claim, minimum, double request)
	stc_settings( array( 'max_levels' => 2, 'min_payout' => '1000' ) );
	stc_check( 'payout below the minimum refused', is_wp_error( Eru_STC_Payouts::create_request( $top ) ) );
	stc_settings( array( 'max_levels' => 2, 'min_payout' => '0' ) );
	$p1 = Eru_STC_Payouts::create_request( $top );
	$p2 = Eru_STC_Payouts::create_request( $top );
	stc_check( 'payout requested; a second open request is blocked', ! is_wp_error( $p1 ) && is_wp_error( $p2 ) );
	$claimed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE payout_id = %d", $p1 ) ); // phpcs:ignore
	stc_check( 'cancel releases the claimed rows', $claimed > 0 && true === Eru_STC_Payouts::cancel( (int) $p1 ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE payout_id = %d", $p1 ) ) ); // phpcs:ignore

	// ---------------------------------------------------------------- fix round 1 (fresh agents: earlier balances stay untouched)
	$top2   = stc_agent( 'top2' );
	$rep2   = stc_agent( 'rep2', $top2 );
	$buyer2 = stc_user( 'buyer2' );
	Eru_STC_Links::link( $buyer2, $rep2, 'manual' );
	$void_reason = fn( array $rows, $agent, $item ) => stc_t_amount( $rows, $agent, $item )->void_reason;

	// Amount-only partial refund (no line items), prorata: 90.00 of 180.00 is spread by line total + tax.
	stc_settings( array( 'max_levels' => 2, 'refund_policy' => 'prorata', 'include_shipping' => 'no' ) );
	$o     = stc_order( $buyer2, array( array( $product, 1, '100.00', '20.00' ), array( $prod2, 1, '50.00', '10.00' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	$ref   = stc_t_refund( $o->get_id(), array(), '90.00' );
	$rows  = stc_rows( $o->get_id() );
	stc_check( '(r1) amount-only 90.00 of 180.00, prorata: line 1 5.00 + 2.50, line 2 2.50 + 1.25', '5.0000' === stc_t_amount( $rows, $rep2, $items[0] )->amount && '2.5000' === stc_t_amount( $rows, $top2, $items[0] )->amount && '2.5000' === stc_t_amount( $rows, $rep2, $items[1] )->amount && '1.2500' === stc_t_amount( $rows, $top2, $items[1] )->amount );
	do_action( 'woocommerce_order_refunded', $o->get_id(), $ref->get_id() );
	stc_check( '(r1) amount-only refund event again: unchanged', $rows == stc_rows( $o->get_id() ) ); // phpcs:ignore

	// Same in void mode: a partial amount-only refund reduces pro rata; only the full refund voids.
	stc_settings( array( 'max_levels' => 2, 'refund_policy' => 'void', 'include_shipping' => 'no' ) );
	$o     = stc_order( $buyer2, array( array( $product, 1, '100.00', '20.00' ), array( $prod2, 1, '50.00', '10.00' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	stc_t_refund( $o->get_id(), array(), '90.00' );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(r1) void mode, amount-only partial: pro rata (5.00 pending), not void', 'pending' === stc_t_amount( $rows, $rep2, $items[0] )->status && '5.0000' === stc_t_amount( $rows, $rep2, $items[0] )->amount && '1.2500' === stc_t_amount( $rows, $top2, $items[1] )->amount );
	stc_t_refund( $o->get_id(), array(), '90.00' );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(r1) void mode, amount-only rest: order refunded, all 4 rows void (reason refund)', 'refunded' === wc_get_order( $o->get_id() )->get_status() && 4 === count( array_filter( $rows, fn( $r ) => 'void' === $r->status && 'refund' === $r->void_reason ) ) );

	// Shipping-only refund: reduces only when shipping was in the basis.
	stc_settings( array( 'max_levels' => 1, 'refund_policy' => 'prorata', 'include_shipping' => 'yes' ) );
	$o    = stc_order( $buyer2, array( array( $product, 1, '100.00', '0' ) ), array( '30.00', '0' ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$item = array_keys( $o->get_items() )[0];
	stc_check( '(r1) shipping in basis: 13.00 on 130.00', '13.0000' === stc_t_amount( stc_rows( $o->get_id() ), $rep2, $item )->amount );
	stc_t_refund( $o->get_id(), array( array_keys( $o->get_items( 'shipping' ) )[0] => '30.00' ) );
	stc_check( '(r1) shipping in basis: shipping-only refund 30.00 → 10.00', '10.0000' === stc_t_amount( stc_rows( $o->get_id() ), $rep2, $item )->amount );
	stc_settings( array( 'max_levels' => 1, 'refund_policy' => 'prorata', 'include_shipping' => 'no' ) );
	$o    = stc_order( $buyer2, array( array( $product, 1, '100.00', '0' ) ), array( '30.00', '0' ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$item = array_keys( $o->get_items() )[0];
	stc_t_refund( $o->get_id(), array( array_keys( $o->get_items( 'shipping' ) )[0] => '30.00' ) );
	stc_check( '(r1) shipping not in basis: shipping-only refund leaves 10.00', '10.0000' === stc_t_amount( stc_rows( $o->get_id() ), $rep2, $item )->amount && 'pending' === stc_t_amount( stc_rows( $o->get_id() ), $rep2, $item )->status );

	// Cancelled, then completed again: cancel-voided rows revive (hold restarts), refunds re-applied,
	// refund-voided rows stay void.
	stc_settings( array( 'max_levels' => 2, 'refund_policy' => 'prorata', 'include_shipping' => 'no' ) );
	$o     = stc_order( $buyer2, array( array( $product, 1, '100.00', '0' ), array( $prod2, 1, '200.00', '0' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$items = array_keys( $o->get_items() );
	stc_t_refund( $o->get_id(), array( $items[1] => '200.00' ) );
	stc_t_refund( $o->get_id(), array( $items[0] => '30.00' ) );
	stc_status( $o->get_id(), 'cancelled' );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(r2) cancelled: line 1 void (reason cancel), line 2 void (reason refund)', 'cancel' === $void_reason( $rows, $rep2, $items[0] ) && 'cancel' === $void_reason( $rows, $top2, $items[0] ) && 'refund' === $void_reason( $rows, $rep2, $items[1] ) && 'refund' === $void_reason( $rows, $top2, $items[1] ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET earned_at = %s WHERE order_id = %d", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $o->get_id() ) ); // phpcs:ignore
	stc_status( $o->get_id(), 'completed' );
	$rows = stc_rows( $o->get_id() );
	$l1   = stc_t_amount( $rows, $rep2, $items[0] );
	stc_check( '(r2) completed again: line 1 revived pending at 7.00 + 3.50 (refund re-applied)', 'pending' === $l1->status && '7.0000' === $l1->amount && 'pending' === stc_t_amount( $rows, $top2, $items[0] )->status && '3.5000' === stc_t_amount( $rows, $top2, $items[0] )->amount && '' === $l1->void_reason );
	stc_check( '(r2) completed again: hold restarts (earned_at now, approved_at null)', abs( strtotime( $l1->earned_at . ' UTC' ) - time() ) < 120 && null === $l1->approved_at );
	stc_check( '(r2) completed again: refund-voided line 2 stays void, no duplicate rows', 'void' === stc_t_amount( $rows, $rep2, $items[1] )->status && 'refund' === $void_reason( $rows, $rep2, $items[1] ) && 4 === count( $rows ) );

	// A refund-voided row restored by deleting the refund re-enters the hold.
	stc_settings( array( 'max_levels' => 2, 'refund_policy' => 'void', 'include_shipping' => 'no' ) );
	$o    = stc_order( $buyer2, array( array( $product, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$item = array_keys( $o->get_items() )[0];
	$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET earned_at = %s WHERE order_id = %d", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $o->get_id() ) ); // phpcs:ignore
	foreach ( stc_rows( $o->get_id() ) as $r ) {
		Eru_STC_Commissions::approve( (int) $r->id );
	}
	$ref = stc_t_refund( $o->get_id(), array( $item => '10.00' ) );
	stc_check( '(r2) void mode line refund: approved rows void', 'void' === stc_t_amount( stc_rows( $o->get_id() ), $rep2, $item )->status );
	$ref->delete( true );
	do_action( 'woocommerce_refund_deleted', $ref->get_id(), $o->get_id() );
	$r = stc_t_amount( stc_rows( $o->get_id() ), $rep2, $item );
	stc_check( '(r2) refund deleted: row back to pending 10.00, earned_at now, approved_at null', 'pending' === $r->status && '10.0000' === $r->amount && null === $r->approved_at && abs( strtotime( $r->earned_at . ' UTC' ) - time() ) < 120 && '' === $r->void_reason );

	// Payout cancelled after an adjustment: the adjustment is netted into its row (no double clawback).
	$rep3   = stc_agent( 'rep3' );
	$buyer3 = stc_user( 'buyer3' );
	Eru_STC_Links::link( $buyer3, $rep3, 'manual' );
	stc_settings( array( 'max_levels' => 1, 'refund_policy' => 'prorata', 'include_shipping' => 'no', 'min_payout' => '0' ) );
	$o    = stc_order( $buyer3, array( array( $product, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$item = array_keys( $o->get_items() )[0];
	$row  = stc_t_amount( stc_rows( $o->get_id() ), $rep3, $item );
	Eru_STC_Commissions::approve( (int) $row->id );
	$p    = Eru_STC_Payouts::create_request( $rep3 );
	$ref  = stc_t_refund( $o->get_id(), array( $item => '40.00' ) );
	$adj  = array_values( array_filter( stc_rows( $o->get_id() ), fn( $r ) => '' !== $r->entry_key ) );
	stc_check( '(m1) claimed row refunded: row 10.00 untouched, adjustment −4.00', '10.0000' === Eru_STC_Commissions::get( (int) $row->id )->amount && 1 === count( $adj ) && '-4.0000' === $adj[0]->amount );
	Eru_STC_Payouts::cancel( (int) $p );
	$adj = Eru_STC_Commissions::get( (int) $adj[0]->id );
	stc_check( '(m1) payout cancelled: row netted to 6.00, adjustment void (netted)', '6.0000' === Eru_STC_Commissions::get( (int) $row->id )->amount && 'void' === $adj->status && 'netted' === $adj->void_reason );
	do_action( 'woocommerce_order_refunded', $o->get_id(), $ref->get_id() );
	stc_check( '(m1) refund event again: still 6.00, available 6.00 (clawback taken once)', '6.0000' === Eru_STC_Commissions::get( (int) $row->id )->amount && '6.00' === Eru_STC_Commissions::balance( $rep3 )['available'] );

	// Race: the row is claimed between the reconcile's read and its write → the change goes to an adjustment.
	$o     = stc_order( $buyer3, array( array( $product, 1, '100.00', '0' ) ) );
	$o     = stc_status( $o->get_id(), 'completed' );
	$item  = array_keys( $o->get_items() )[0];
	$stale = stc_t_amount( stc_rows( $o->get_id() ), $rep3, $item );
	Eru_STC_Commissions::approve( (int) $stale->id );
	$stale = Eru_STC_Commissions::get( (int) $stale->id );
	$p     = Eru_STC_Payouts::create_request( $rep3 );
	Eru_STC_Refunds::apply_row( $stale, 300, 'refund' );
	$rows = stc_rows( $o->get_id() );
	$adj  = array_values( array_filter( $rows, fn( $r ) => '' !== $r->entry_key ) );
	stc_check( '(m2) row claimed mid-way: row stays 10.00 in the payout, adjustment −3.00 written', '10.0000' === Eru_STC_Commissions::get( (int) $stale->id )->amount && (int) $p === (int) Eru_STC_Commissions::get( (int) $stale->id )->payout_id && 1 === count( $adj ) && '-3.0000' === $adj[0]->amount );
	Eru_STC_Payouts::cancel( (int) $p );

	// Fixed rule capped at the line basis.
	stc_check( '(m6) fixed 2.00 × 3 on a 5.00 basis pays 5.00', 500 === Eru_STC_Calculator::amount_minor( (object) array( 'type' => 'fixed', 'rate' => '2.00' ), 500, 3 ) );

	// ---------------------------------------------------------------- fix round 2
	$rep4   = stc_agent( 'rep4' );
	$buyer4 = stc_user( 'buyer4' );
	Eru_STC_Links::link( $buyer4, $rep4, 'manual' );
	stc_settings( array( 'max_levels' => 1, 'refund_policy' => 'prorata', 'include_shipping' => 'no', 'min_payout' => '0' ) );
	$live_adj = function ( $order_id ) {
		return array_values( array_filter( stc_rows( $order_id ), fn( $r ) => '' !== $r->entry_key && 'void' !== $r->status ) );
	};

	// (c) Netting a row to exactly 0: claimed row, its line fully refunded (−10.00 adjustment), payout cancelled.
	$o    = stc_order( $buyer4, array( array( $product, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$item = array_keys( $o->get_items() )[0];
	$row  = stc_t_amount( stc_rows( $o->get_id() ), $rep4, $item );
	Eru_STC_Commissions::approve( (int) $row->id );
	$p = Eru_STC_Payouts::create_request( $rep4 );
	stc_t_refund( $o->get_id(), array( $item => '100.00' ) );
	stc_check( '(n0) claimed row fully refunded: adjustment −10.00', 1 === count( $live_adj( $o->get_id() ) ) && '-10.0000' === $live_adj( $o->get_id() )[0]->amount );
	Eru_STC_Payouts::cancel( (int) $p );
	$row = Eru_STC_Commissions::get( (int) $row->id );
	stc_check( '(n0) payout cancelled: row netted to 0.00 → void (refund), no live adjustment, available 0.00', 'void' === $row->status && '0.0000' === $row->amount && 'refund' === $row->void_reason && 0 === count( $live_adj( $o->get_id() ) ) && '0.00' === Eru_STC_Commissions::balance( $rep4 )['available'] );

	// No revival while cancelled: cancel → clawback adjustment, payout cancel nets the row to 0, then a refund
	// event on the still-cancelled order must leave it void.
	$o    = stc_order( $buyer4, array( array( $product, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$item = array_keys( $o->get_items() )[0];
	$row  = stc_t_amount( stc_rows( $o->get_id() ), $rep4, $item );
	Eru_STC_Commissions::approve( (int) $row->id );
	$p = Eru_STC_Payouts::create_request( $rep4 );
	stc_status( $o->get_id(), 'cancelled' );
	Eru_STC_Payouts::cancel( (int) $p );
	stc_check( '(c1) cancelled while claimed, payout cancelled: row netted to 0.00, void', 'void' === Eru_STC_Commissions::get( (int) $row->id )->status && '0.0000' === Eru_STC_Commissions::get( (int) $row->id )->amount );
	do_action( 'woocommerce_order_refunded', $o->get_id(), 0 );
	$row = Eru_STC_Commissions::get( (int) $row->id );
	stc_check( '(c1) refund event while still cancelled: row stays void at 0.00', 'void' === $row->status && '0.0000' === $row->amount && 0 === count( $live_adj( $o->get_id() ) ) );
	stc_status( $o->get_id(), 'completed' );
	stc_check( '(c1) completed again: the row counts again (10.00 live)', '10.00' === stc_sum( array_merge( array_filter( stc_rows( $o->get_id() ), fn( $r ) => '' === $r->entry_key && 'void' !== $r->status ), $live_adj( $o->get_id() ) ) ) );

	// (a) A paid row through cancel → re-complete: −10.00 clawback, then the +10.00 reverse adjustment.
	$o    = stc_order( $buyer4, array( array( $product, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$item = array_keys( $o->get_items() )[0];
	$row  = stc_t_amount( stc_rows( $o->get_id() ), $rep4, $item );
	Eru_STC_Commissions::approve( (int) $row->id );
	$p      = Eru_STC_Payouts::create_request( $rep4 );
	Eru_STC_Payouts::mark_paid( (int) $p, 'DEMO-002' );
	stc_status( $o->get_id(), 'cancelled' );
	$adj = $live_adj( $o->get_id() );
	stc_check( '(a2) paid row cancelled: row stays paid 10.00, one −10.00 adjustment', 'paid' === Eru_STC_Commissions::get( (int) $row->id )->status && 1 === count( $adj ) && '-10.0000' === $adj[0]->amount && 'adj:1' === $adj[0]->entry_key );
	stc_status( $o->get_id(), 'completed' );
	$adj = $live_adj( $o->get_id() );
	stc_check( '(a2) completed again: reverse +10.00 adjustment (adj:2), live adjustments net to 0.00', 2 === count( $adj ) && '10.0000' === $adj[1]->amount && 'adj:2' === $adj[1]->entry_key && '0.00' === stc_sum( $adj ) && 'paid' === Eru_STC_Commissions::get( (int) $row->id )->status );
	stc_status( $o->get_id(), 'completed' );
	do_action( 'woocommerce_order_refunded', $o->get_id(), 0 );
	stc_check( '(a2) events again: still two adjustments', 2 === count( $live_adj( $o->get_id() ) ) );

	// (b) Schema upgrade v1 → v2: the void_reason column is added and the old note copied into it.
	$o = stc_order( $buyer4, array( array( $product, 1, '100.00', '0' ) ) );
	$wpdb->query( "ALTER TABLE {$ct} DROP COLUMN void_reason" ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$ct} (order_id, item_id, agent_id, level, entry_key, status, note, earned_at, created_at, updated_at) VALUES (%d, 1, %d, 1, '', 'void', 'cancel', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())", $o->get_id(), $rep4 ) ); // phpcs:ignore
	update_option( Eru_STC_Installer::OPTION, '1', true );
	Eru_STC_Installer::maybe_install();
	$has = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$ct} LIKE %s", 'void_reason' ) ); // phpcs:ignore
	stc_check( '(b) upgrade: void_reason column back, schema option 2', $has && '2' === get_option( Eru_STC_Installer::OPTION ) );
	stc_check( '(b) upgrade: an old cancel-voided row gets void_reason = cancel', 'cancel' === $wpdb->get_var( $wpdb->prepare( "SELECT void_reason FROM {$ct} WHERE order_id = %d", $o->get_id() ) ) ); // phpcs:ignore

	// ---------------------------------------------------------------- final wave
	stc_settings( array( 'max_levels' => 2, 'hold_days' => 14, 'trigger_status' => 'completed' ) );
	$fw_rules = function ( $agent ) {
		stc_rule( array( 'scope' => 'agent', 'target_id' => $agent, 'level' => 1, 'type' => 'percent', 'rate' => '10' ) );
		stc_rule( array( 'scope' => 'agent', 'target_id' => $agent, 'level' => 2, 'type' => 'percent', 'rate' => '5' ) );
	};
	$fw_lead  = stc_agent( 'fwlead' );
	$fw_a     = stc_agent( 'fwa', $fw_lead );
	$fw_b     = stc_agent( 'fwb' );
	$fw_lead2 = stc_agent( 'fwleadb' );
	foreach ( array( $fw_lead, $fw_a, $fw_b, $fw_lead2 ) as $x ) {
		$fw_rules( $x );
	}
	$live = function ( $order_id ) {
		return array_values( array_filter( stc_rows( $order_id ), fn( $r ) => '' === $r->entry_key && 'void' !== $r->status ) );
	};

	// (i1) Recalculation never adds a second agent for the same line and level.
	$fw_c = stc_user( 'fwcust' );
	Eru_STC_Links::link( $fw_c, $fw_a, 'manual' );
	$o = stc_status( stc_order( $fw_c, array( array( $product, 1, '100.00', '0' ) ) )->get_id(), 'completed' );
	stc_check( '(i1) completed: A level 1 (10.00) and A\'s leader level 2 (5.00)', 2 === count( $live( $o->get_id() ) ) && '10.0000' === stc_t_amount( stc_rows( $o->get_id() ), $fw_a, array_keys( $o->get_items() )[0] )->amount );
	stc_check( '(i1) conflict check: another level-1 agent conflicts, the same one does not', Eru_STC_Calculator::level1_conflict( $o->get_id(), $fw_b ) && ! Eru_STC_Calculator::level1_conflict( $o->get_id(), $fw_a ) );
	stc_trap_exits();
	$fw_admin = stc_user( 'fwadmin', 'administrator' );
	wp_set_current_user( $fw_admin );
	$_POST    = array(
		'order_id' => (string) $o->get_id(),
		'agent_id' => (string) $fw_b,
		'_wpnonce' => wp_create_nonce( 'eru_stc_recalc' ),
	);
	$_REQUEST = $_POST;
	$call     = stc_call( array( 'Eru_STC_Admin', 'handle_recalc' ) );
	$notice   = get_transient( 'eru_stc_notice_' . $fw_admin );
	stc_check( '(i1) admin recalc with another agent while live rows exist: refused with "önce … iptal edin", nothing written, order not re-stamped', $call['stop'] && 'redirect' === $call['stop']->getMessage() && is_array( $notice ) && 'error' === $notice['type'] && false !== mb_stripos( $notice['text'], 'iptal edin' ) && 2 === count( $live( $o->get_id() ) ) && $fw_b !== (int) wc_get_order( $o->get_id() )->get_meta( '_eru_stc_agent_id' ) );
	$fo = wc_get_order( $o->get_id() );
	$fo->update_meta_data( '_eru_stc_agent_id', $fw_b );
	$fo->save();
	stc_check( '(i1) forced recalculation with another agent stamped: no new rows (line/level slots are taken)', array() === Eru_STC_Calculator::calculate( $fo, true ) && 2 === count( $live( $o->get_id() ) ) );
	$fo->update_meta_data( '_eru_stc_agent_id', $fw_a );
	$fo->save();
	Eru_STC_Agents::update( $fw_a, array( 'parent_agent_id' => $fw_lead2 ) );
	stc_check( '(i1) hierarchy changed (A moved under another leader), recalculated: no second level-2 row', array() === Eru_STC_Calculator::calculate( wc_get_order( $o->get_id() ), true ) && 2 === count( $live( $o->get_id() ) ) && ! stc_rows_of( stc_rows( $o->get_id() ), $fw_lead2 ) );
	foreach ( $live( $o->get_id() ) as $r ) {
		Eru_STC_Commissions::void_manual( (int) $r->id );
	}
	$_POST['_wpnonce'] = wp_create_nonce( 'eru_stc_recalc' );
	$_REQUEST          = $_POST;
	stc_call( array( 'Eru_STC_Admin', 'handle_recalc' ) );
	$after = $live( $o->get_id() );
	stc_check( '(i1) after voiding by hand, recalc with B writes B\'s row (B has no leader)', 1 === count( $after ) && $fw_b === (int) $after[0]->agent_id && '10.0000' === $after[0]->amount && 'pending' === $after[0]->status );
	delete_transient( 'eru_stc_notice_' . $fw_admin );
	wp_set_current_user( 0 );
	$_POST    = array();
	$_REQUEST = array();

	// (m3) Trigger "processing": a cancelled order paid again restarts the hold (date_paid is not reset).
	stc_settings( array( 'max_levels' => 2, 'hold_days' => 14, 'trigger_status' => 'processing' ) );
	$fw_p = stc_user( 'fwproc' );
	Eru_STC_Links::link( $fw_p, $fw_b, 'manual' );
	$o = stc_status( stc_order( $fw_p, array( array( $product, 1, '100.00', '0' ) ) )->get_id(), 'processing' );
	$o->set_date_paid( time() - 30 * DAY_IN_SECONDS );
	$o->save();
	$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET earned_at = %s WHERE order_id = %d", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $o->get_id() ) ); // phpcs:ignore
	stc_status( $o->get_id(), 'cancelled' );
	stc_status( $o->get_id(), 'processing' );
	$rv = $live( $o->get_id() );
	stc_check( '(m3) processing trigger, cancelled then processing again: revived pending with earned_at ≈ now', 1 === count( $rv ) && 'pending' === $rv[0]->status && abs( strtotime( $rv[0]->earned_at . ' UTC' ) - time() ) < 300 );

	// (m5) Trashed or deleted orders void like a cancellation; untrash revives.
	stc_settings( array( 'max_levels' => 2, 'hold_days' => 14, 'trigger_status' => 'completed' ) );
	$untrash = function ( $id ) {
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class )->untrash_order( wc_get_order( $id ) );
		} else {
			wp_untrash_post( $id );
		}
	};
	$o = stc_status( stc_order( $fw_p, array( array( $product, 1, '100.00', '0' ) ) )->get_id(), 'completed' );
	wc_get_order( $o->get_id() )->delete( false );
	$tr = stc_rows( $o->get_id() );
	stc_check( '(m5) trashed: the unpaid row is void (reason cancel)', 1 === count( $tr ) && 'void' === $tr[0]->status && 'cancel' === $tr[0]->void_reason );
	$untrash( $o->get_id() );
	$rv = $live( $o->get_id() );
	stc_check( '(m5) untrashed back to completed: the row is revived (pending)', 'completed' === wc_get_order( $o->get_id() )->get_status() && 1 === count( $rv ) && 'pending' === $rv[0]->status );
	// A paid row: trashing writes the negative adjustment.
	$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET earned_at = %s WHERE order_id = %d", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $o->get_id() ) ); // phpcs:ignore
	Eru_STC_Commissions::approve( (int) $rv[0]->id );
	$pp = Eru_STC_Payouts::create_request( $fw_b, array( 'ignore_minimum' => true ) );
	Eru_STC_Payouts::mark_paid( $pp, 'DEMO-002' );
	wc_get_order( $o->get_id() )->delete( false );
	$adj = array_values( array_filter( stc_rows( $o->get_id() ), fn( $r ) => '' !== $r->entry_key && 'void' !== $r->status ) );
	stc_check( '(m5) trashed after payment: the paid row stays paid, one adjustment of -10.00', 'paid' === Eru_STC_Commissions::get( (int) $rv[0]->id )->status && 1 === count( $adj ) && '-10.0000' === $adj[0]->amount );
	wc_get_order( $o->get_id() )->delete( true );
	$o = stc_status( stc_order( $fw_p, array( array( $product, 1, '100.00', '0' ) ) )->get_id(), 'completed' );
	$oid = $o->get_id();
	$o->delete( true );
	$dl = stc_rows( $oid );
	stc_check( '(m5) deleted for good: the unpaid row is void (reason cancel) and kept', 1 === count( $dl ) && 'void' === $dl[0]->status && 'cancel' === $dl[0]->void_reason );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$ct} WHERE order_id = %d", $oid ) ); // phpcs:ignore

	// (m7) An agent earns nothing on their own guest order (billing e-mail = the agent's account e-mail).
	$fw_self = stc_agent( 'fwself' );
	$fw_rules( $fw_self );
	$self_row = Eru_STC_Agents::get( $fw_self );
	stc_settings( array( 'max_levels' => 2, 'assignment_mode' => 'referral' ) );
	$_COOKIE['eru_stc_ref'] = $self_row->referral_code;
	$g = stc_order( 0, array( array( $product, 1, '100.00', '0' ) ) );
	$g->set_billing_email( strtoupper( get_userdata( $self_row->user_id )->user_email ) );
	$g->save();
	Eru_STC_Referral::attach_to_order( wc_get_order( $g->get_id() ) );
	stc_check( '(m7) own referral code on a guest order with the agent\'s e-mail: the order is not attributed', 0 === (int) wc_get_order( $g->get_id() )->get_meta( '_eru_stc_agent_id' ) );
	unset( $_COOKIE['eru_stc_ref'] );
	$g = wc_get_order( $g->get_id() );
	$g->update_meta_data( '_eru_stc_agent_id', $fw_self );
	$g->save();
	$g = stc_status( $g->get_id(), 'completed' );
	stc_check( '(m7) even when stamped by hand: no row for the agent on their own order', array() === stc_rows_of( stc_rows( $g->get_id() ), $fw_self ) );

	echo "ALL PASS: stc-commissions\n";
	$status = 0;
} catch ( Throwable $e ) {
	echo 'FAIL: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	$status = 1;
} finally {
	// A failed upgrade test must not leave the table without its column.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$ct} LIKE %s", 'void_reason' ) ) ) { // phpcs:ignore
		update_option( Eru_STC_Installer::OPTION, '1', true );
		Eru_STC_Installer::maybe_install();
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_stc_eligibility', array(), 'eru-stc' );
	}
	stc_purge( false );
	stc_restore( $keep );
}
exit( $status );
