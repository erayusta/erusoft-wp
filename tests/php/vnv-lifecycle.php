<?php
/**
 * VakıfBank Vinov Integration — instruction lifecycle: the instruction table, the status sync (limit 50 in
 * last_checked_at order, pending-only re-arm, lock per run, status 2 → paid exactly once, 3 → cancelled,
 * give-up after N days, ambiguous attempts resolved only by the Task 9 matching rules), the order box
 * (query/cancel with capability, nonce and confirmation), the debtor query (the identity number is never
 * stored, cached or logged), the privacy exporter/eraser, the daily purge, deactivation and uninstall.
 *
 *   wp plugin activate vakifbank-vinov-integration-for-woocommerce
 *   wp eval-file tests/php/vnv-lifecycle.php
 */

require __DIR__ . '/vnv-helpers.php';
vnv_require_plugin();
foreach ( array( 'Eru_VNV_Instructions', 'Eru_VNV_Sync', 'Eru_VNV_Cron', 'Eru_VNV_Order_Box', 'Eru_VNV_Admin', 'Eru_VNV_Privacy' ) as $vnv_c ) {
	if ( ! class_exists( $vnv_c ) ) {
		echo "FAIL: {$vnv_c} missing\n";
		exit( 1 );
	}
}

global $wpdb;
$bank = vnv_install_bank();
$logs = vnv_capture_logs();
wc_load_cart();

$old_settings = get_option( Eru_VNV_Settings::OPTION, null );
$old_flag     = get_option( Eru_VNV_Settings::FLAG, null );
$old_user     = get_current_user_id();
$table        = Eru_VNV_Instructions::table();
$failed       = false;
$users        = array();

/** Invented instruction ids of this run (far above anything the fake bank or a shop would use). */
$base     = 990000000000 + random_int( 1, 899 ) * 1000;
$order_lo = 990000000 + random_int( 1, 899 ) * 1000;

$paid_hits = 0;
add_action(
	'woocommerce_payment_complete',
	function () use ( &$paid_hits ) {
		++$paid_hits;
	}
);

/** Unschedules every action of the plugin's group (pending and running leftovers). */
$unschedule = function () {
	foreach ( array( Eru_VNV_Cron::SYNC, Eru_VNV_Cron::DAILY, Eru_VNV_Cron::CONN, Eru_VNV_Cron::CANCEL ) as $h ) {
		as_unschedule_all_actions( $h, null, Eru_VNV_Cron::GROUP );
	}
};

/** Pending bank-cancel actions of one order. */
$cancel_jobs = function ( $order_id ) {
	$out = array();
	foreach ( as_get_scheduled_actions( array( 'hook' => Eru_VNV_Cron::CANCEL, 'group' => Eru_VNV_Cron::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500 ), 'ids' ) as $id ) {
		$a = ActionScheduler::store()->fetch_action( $id );
		if ( (int) ( $a->get_args()['order_id'] ?? 0 ) === (int) $order_id ) {
			$out[ (int) $id ] = $a->get_args();
		}
	}
	return $out;
};
/** Runs the pending bank-cancel actions of one order. */
$run_cancel = function ( $order_id ) use ( $cancel_jobs ) {
	$n = 0;
	foreach ( array_keys( $cancel_jobs( $order_id ) ) as $id ) {
		ActionScheduler::runner()->process_action( $id );
		++$n;
	}
	return $n;
};

/** A tagged order paid with Vinov. */
$make_order = function ( $status = 'on-hold', array $meta = array(), $email = null ) use ( &$product ) {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $product ), 1 );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( 'Alıcı' );
	$o->set_billing_email( $email ?? 'vnv-lc-' . VNV_RUN . '@example.com' );
	$o->set_billing_phone( vnv_phone() );
	$o->set_billing_country( 'TR' );
	$o->set_billing_city( 'Çankaya' );
	$o->set_payment_method( 'eru_vnv' );
	$o->set_payment_method_title( 'Vinov ile öde' );
	$o->calculate_totals( false );
	foreach ( $meta as $k => $v ) {
		$o->update_meta_data( $k, $v );
	}
	$o->update_meta_data( '_vnv_test', VNV_RUN );
	$o->set_status( $status );
	$o->save();
	return wc_get_order( $o->get_id() );
};

/** An order with a created instruction, recorded through the plugin's hook. */
$with_instruction = function ( $iid, $status = 'on-hold', $email = null ) use ( $make_order ) {
	$o = $make_order(
		$status,
		array(
			'_eru_vnv_instruction_id' => (string) $iid,
			'_eru_vnv_installments'   => 3,
			'_eru_vnv_env'            => 'test',
			'_eru_vnv_attempt_state'  => 'created',
			'_eru_vnv_tckn_mask'      => Eru_VNV_Identity::mask( vnv_fake_tckn() ),
			'_eru_vnv_phone'          => '5550000000',
		),
		$email
	);
	$o->set_transaction_id( (string) $iid );
	$o->save();
	do_action( 'eru_vnv_instruction_created', $o, (int) $iid, 3 );
	return wc_get_order( $o->get_id() );
};

$row = fn( $order_id ) => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ) ); // phpcs:ignore
$pending = fn() => as_get_scheduled_actions( array( 'hook' => Eru_VNV_Cron::SYNC, 'group' => Eru_VNV_Cron::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 20 ), 'ids' );
$foreign_open = fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE closed_at IS NULL AND ( instruction_id IS NULL OR instruction_id < %d )", 990000000000 ) ); // phpcs:ignore
$as_admin = function () {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	wp_set_current_user( (int) $admins[0] );
};

try {
	vnv_purge( true );
	vnv_clear_log_files();
	vnv_reset();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE instruction_id >= %d OR ( order_id >= %d AND order_id < %d )", 990000000000, 990000000, 991000000 ) ); // phpcs:ignore
	foreach ( get_users( array( 'search' => 'vnv-lc-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u->ID );
	}
	$unschedule();
	vnv_settings();
	$product = vnv_product( 1873.4, 'Demo Çanta' );

	// --- table ---------------------------------------------------------------------------------------
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore
	vnv_check( 'table exists with the lifecycle columns', ! array_diff( array( 'order_id', 'instruction_id', 'amount', 'installments', 'status', 'last_checked_at', 'created_at' ), $cols ) );
	vnv_check( 'table has no identity or raw-response column', ! preg_grep( '/tckn|identity|raw|response|phone/i', $cols ) );
	$uniq = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Non_unique = 0 AND Column_name = 'instruction_id'" ); // phpcs:ignore
	vnv_check( 'instruction_id is UNIQUE', count( $uniq ) > 0 );
	vnv_check( 'db version stored', '' !== (string) get_option( 'eru_vnv_db_version', '' ) );

	// --- inert: nothing scheduled while there is nothing to track ------------------------------------
	vnv_check( 'precondition: no foreign open rows in the demo table', 0 === $foreign_open() );
	Eru_VNV_Settings::update( array( 'enabled' => 'no' ) );
	Eru_VNV_Cron::sync_schedules();
	vnv_check( 'off and no rows → no sync or daily job scheduled', ! $pending() && ! as_get_scheduled_actions( array( 'hook' => Eru_VNV_Cron::DAILY, 'group' => Eru_VNV_Cron::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	delete_option( Eru_VNV_Settings::FLAG );
	vnv_settings();
	vnv_check( 'first enable (flag added, not updated) arms the daily clean-up', 1 === count( Eru_VNV_Cron::pending_ids( Eru_VNV_Cron::DAILY ) ) );

	// --- (a) 120 open rows, limit 50, last_checked_at order ------------------------------------------
	$ids = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$iid = $base + $i;
		$lc  = $i < 20 ? null : gmdate( 'Y-m-d H:i:s', 946684800 + ( 200 - $i ) * 60 ); // 2000-01-01, reverse of $i.
		$wpdb->insert( $table, array( 'order_id' => $order_lo + $i, 'instruction_id' => $iid, 'env' => 'test', 'amount' => '10.00', 'installments' => 1, 'status' => 1, 'last_checked_at' => $lc, 'created_at' => current_time( 'mysql', true ) ) ); // phpcs:ignore
		$ids[ $iid ] = $lc;
	}
	$expected = array_keys( $ids );
	usort(
		$expected,
		function ( $a, $b ) use ( $ids ) {
			if ( null === $ids[ $a ] || null === $ids[ $b ] ) {
				return null === $ids[ $a ] && null === $ids[ $b ] ? $a <=> $b : ( null === $ids[ $a ] ? -1 : 1 );
			}
			return strcmp( $ids[ $a ], $ids[ $b ] ) ?: $a <=> $b;
		}
	);
	foreach ( $expected as $iid ) {
		$bank->statuses[ $iid ] = 1;
	}
	vnv_check( '(a) Eru_VNV_Sync::LIMIT is 50', 50 === Eru_VNV_Sync::LIMIT );
	$seen = array();
	for ( $run = 1; $run <= 3; $run++ ) {
		$bank->reset();
		$s     = Eru_VNV_Sync::run();
		$asked = $bank->listed_ids();
		$want  = array_slice( $expected, ( $run - 1 ) * 50, 50 );
		vnv_check( "(a) run {$run} asks the bank first for the next " . count( $want ) . ' never/least recently checked rows, in last_checked_at order', array_slice( $asked, 0, count( $want ) ) === $want );
		vnv_check( "(a) run {$run} stops at the limit of 50 and reports the rows it touched", 50 === count( $asked ) && 50 === count( $s['touched'] ) && 'ok' === $s['status'] );
		$seen = array_merge( $seen, array_slice( $asked, 0, count( $want ) ) );
	}
	vnv_check( '(a) three runs touched all 120 rows', 120 === count( array_unique( $seen ) ) && ! array_diff( $expected, $seen ) );
	vnv_check( '(a) run 3 filled its last 30 slots with rows checked in run 1 (oldest check first), not with run 3 rows', ! array_intersect( array_slice( $asked, 20 ), array_slice( $expected, 100 ) ) && ! array_diff( array_slice( $asked, 20 ), array_slice( $expected, 0, 100 ) ) );
	$untouched = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE instruction_id BETWEEN %d AND %d AND ( last_checked_at IS NULL OR last_checked_at < %s )", $base, $base + 119, gmdate( 'Y-m-d H:i:s', time() - 600 ) ) ); // phpcs:ignore
	vnv_check( '(a) every row now has a fresh last_checked_at', 0 === $untouched );
	$aged = array_slice( $expected, 100, 20 );
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_checked_at = %s WHERE instruction_id IN (" . implode( ',', array_map( 'intval', $aged ) ) . ')', gmdate( 'Y-m-d H:i:s', time() - 120 ) ) ); // phpcs:ignore
	$bank->reset();
	Eru_VNV_Sync::run();
	$first = array_slice( $bank->listed_ids(), 0, 20 );
	$want  = array_slice( $expected, 100, 20 );
	sort( $first );
	sort( $want );
	vnv_check( '(a) run 4 starts with the least recently checked rows (the ones checked longest ago)', $want === $first );

	// Re-arm, pending only: simulate the running action.
	$unschedule();
	Eru_VNV_Cron::ensure();
	$p = $pending();
	vnv_check( '(a) ensure() schedules one pending sync while rows are open', 1 === count( $p ) );
	Eru_VNV_Cron::ensure();
	vnv_check( '(a) ensure() twice keeps one pending action', 1 === count( $pending() ) );
	$running = (int) $p[0];
	ActionScheduler::store()->log_execution( $running );
	vnv_check( '(a) simulated action is running (not pending)', ! in_array( $running, array_map( 'intval', $pending() ), true ) && as_has_scheduled_action( Eru_VNV_Cron::SYNC, null, Eru_VNV_Cron::GROUP ) );
	$bank->reset();
	Eru_VNV_Sync::run();
	$after = array_map( 'intval', $pending() );
	vnv_check( '(a) run inside the running action re-arms exactly one new pending action', 1 === count( $after ) && $running !== $after[0] );
	ActionScheduler::store()->mark_complete( $running );
	$unschedule();

	// Lock per run: the locked run does nothing, but the chain never dies (exactly one pending sync remains).
	Eru_VNV_Cron::ensure();
	$running = (int) $pending()[0];
	ActionScheduler::store()->log_execution( $running );
	$tok = Eru_VNV_Lock::acquire( 'sync', 900 );
	$bank->reset();
	$s     = Eru_VNV_Sync::run();
	$after = array_map( 'intval', $pending() );
	vnv_check( '(a) a second run while the run lock is held does nothing', 'locked' === $s['status'] && 0 === $bank->total() );
	vnv_check( '(a) I2: the locked run (inside the running action) still leaves exactly one pending sync', 1 === count( $after ) && $running !== $after[0] );
	Eru_VNV_Lock::release( 'sync', $tok );
	ActionScheduler::store()->mark_complete( $running );
	$unschedule();

	// Closing every row ends the chain.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE instruction_id BETWEEN %d AND %d", $base, $base + 119 ) ); // phpcs:ignore
	$unschedule();
	$bank->reset();
	Eru_VNV_Sync::run();
	vnv_check( '(a) no open rows → the run does not re-arm', ! $pending() );

	// --- (b) status 2 → paid exactly once; status 3 → cancelled --------------------------------------
	$iid2 = $base + 200;
	$o2   = $with_instruction( $iid2 );
	$r2   = $row( $o2->get_id() );
	vnv_check( '(b) instruction_created records a row (status 1, amount = order total, no TCKN)', $r2 && 1 === (int) $r2->status && (int) $r2->instruction_id === $iid2 && Eru_VNV_Installments::to_minor( $r2->amount ) === Eru_VNV_Installments::to_minor( $o2->get_total() ) && 3 === (int) $r2->installments );
	vnv_check( '(b) recording an instruction schedules the sync', 1 === count( $pending() ) );
	$bank->statuses[ $iid2 ] = 2;
	$paid_hits = 0;
	$bank->reset();
	$s  = Eru_VNV_Sync::run();
	$o2 = wc_get_order( $o2->get_id() );
	vnv_check( '(b) status 2 → processing via payment_complete', 'processing' === $o2->get_status() && 1 === $paid_hits && 1 === $s['paid'] && '' !== (string) $o2->get_date_paid() );
	vnv_check( '(b) approval note added', 1 === substr_count( vnv_notes( $o2->get_id() ), 'bankada onaylandı' ) );
	$r2 = $row( $o2->get_id() );
	vnv_check( '(b) row closed with status 2', 2 === (int) $r2->status && null !== $r2->closed_at );
	$bank->reset();
	Eru_VNV_Sync::run();
	vnv_check( '(b) second job run: no bank call for the closed row, still paid once', ! in_array( $iid2, $bank->listed_ids(), true ) && 1 === $paid_hits );
	$wpdb->update( $table, array( 'closed_at' => null, 'status' => 1 ), array( 'id' => $r2->id ) ); // phpcs:ignore
	$bank->reset();
	Eru_VNV_Sync::run();
	vnv_check( '(b) row reopened by a stale writer: order already paid → no second payment_complete or note', 1 === $paid_hits && 1 === substr_count( vnv_notes( $o2->get_id() ), 'bankada onaylandı' ) && null !== $row( $o2->get_id() )->closed_at );

	// I2: a run that throws (a third-party listener of the plugin's own action) still re-arms and releases its lock.
	$iidx = $base + 250;
	$ox   = $with_instruction( $iidx );
	$bank->statuses[ $iidx ] = 2;
	$oy = $with_instruction( $base + 251 ); // Another open row, so there is something to re-arm for.
	$bank->statuses[ $base + 251 ] = 1;
	$unschedule();
	Eru_VNV_Cron::ensure();
	$running = (int) $pending()[0];
	ActionScheduler::store()->log_execution( $running );
	$thrower = function () {
		throw new RuntimeException( 'demo hook failure' );
	};
	add_action( 'eru_vnv_instruction_paid', $thrower, 1 );
	$threw = false;
	try {
		Eru_VNV_Sync::run();
	} catch ( RuntimeException $e ) {
		$threw = 'demo hook failure' === $e->getMessage();
	}
	remove_action( 'eru_vnv_instruction_paid', $thrower, 1 );
	$after = array_map( 'intval', $pending() );
	$t2    = Eru_VNV_Lock::acquire( 'sync', 900 );
	vnv_check( '(a) I2: a run that throws still leaves exactly one pending sync and releases the run lock', $threw && 1 === count( $after ) && $running !== $after[0] && false !== $t2 );
	Eru_VNV_Lock::release( 'sync', $t2 );
	ActionScheduler::store()->mark_complete( $running );
	$unschedule();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE order_id IN (%d, %d)", $ox->get_id(), $oy->get_id() ) ); // phpcs:ignore
	$paid_hits = 1; // The thrower's order was paid before the throw.

	$iid3 = $base + 300;
	$o3   = $with_instruction( $iid3 );
	$bank->statuses[ $iid3 ] = 3;
	$bank->reset();
	$s  = Eru_VNV_Sync::run();
	$o3 = wc_get_order( $o3->get_id() );
	vnv_check( '(b) status 3 → cancelled with a note', 'cancelled' === $o3->get_status() && false !== strpos( vnv_notes( $o3->get_id() ), 'bankada iptal' ) && 1 === $s['cancelled'] );
	vnv_check( '(b) cancelled row closed with status 3', 3 === (int) $row( $o3->get_id() )->status && null !== $row( $o3->get_id() )->closed_at && 0 === $paid_hits - 1 );

	// --- (c) give-up after 30 days -------------------------------------------------------------------
	vnv_check( '(c) M1: with the default 30-day expiry the effective give-up is 31 days (expiry + 1)', 31 === Eru_VNV_Settings::giveup_days() );
	Eru_VNV_Settings::update( array( 'giveup_days' => '10' ) );
	$clamp_low = Eru_VNV_Settings::giveup_days();
	Eru_VNV_Settings::update( array( 'giveup_days' => '45', 'expiry_days' => '20' ) );
	$clamp_ok = Eru_VNV_Settings::giveup_days();
	Eru_VNV_Settings::update( array( 'giveup_days' => '30', 'expiry_days' => '30' ) );
	vnv_check( '(c) M1: give-up below expiry + 1 is clamped, a longer one is kept', 31 === $clamp_low && 45 === $clamp_ok );
	$iid4 = $base + 400;
	$o4   = $with_instruction( $iid4 );
	$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 32 * DAY_IN_SECONDS ) ), array( 'order_id' => $o4->get_id() ) ); // phpcs:ignore
	$bank->statuses[ $iid4 ] = 1;
	$olock = Eru_VNV_Lock::acquire( 'order_' . $o4->get_id(), 600 );
	Eru_VNV_Sync::run();
	vnv_check( '(c) M3: give-up waits for the order lock (no note, row still open while held)', false === strpos( vnv_notes( $o4->get_id() ), 'banka panel' ) && null === $row( $o4->get_id() )->closed_at );
	Eru_VNV_Lock::release( 'order_' . $o4->get_id(), $olock );
	$wpdb->update( $table, array( 'last_checked_at' => null ), array( 'order_id' => $o4->get_id() ) ); // phpcs:ignore
	$bank->reset();
	$s  = Eru_VNV_Sync::run();
	$o4 = wc_get_order( $o4->get_id() );
	$r4 = $row( $o4->get_id() );
	vnv_check( '(c) still waiting after 30 days → note telling the merchant to check the bank panel', false !== strpos( vnv_notes( $o4->get_id() ), 'banka panel' ) && 1 === $s['gave_up'] );
	vnv_check( '(c) row closed (gave_up), order status unchanged', null !== $r4->closed_at && 'gave_up' === $r4->close_reason && 'on-hold' === $o4->get_status() );
	$bank->reset();
	Eru_VNV_Sync::run();
	vnv_check( '(c) no further polling of the given-up row', ! in_array( $iid4, $bank->listed_ids(), true ) );
	$iid4b = $base + 401;
	$o4b   = $with_instruction( $iid4b );
	$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'order_id' => $o4b->get_id() ) ); // phpcs:ignore
	$bank->statuses[ $iid4b ] = 1;
	Eru_VNV_Sync::run();
	vnv_check( '(c) 30 days old → still polled, no note', null === $row( $o4b->get_id() )->closed_at && false === strpos( vnv_notes( $o4b->get_id() ), 'banka panel' ) );

	// Ambiguous attempts: resolved only by the Task 9 matching rules, never paid from the ambiguous state.
	$amb = function ( $key, $state = 'ambiguous', $at = null ) use ( $make_order ) {
		$o = $make_order(
			'pending',
			array(
				'_eru_vnv_attempt'       => $key,
				'_eru_vnv_attempt_at'    => $at ?? time() - 2 * HOUR_IN_SECONDS,
				'_eru_vnv_attempt_state' => $state,
				'_eru_vnv_env'           => 'test',
				'_eru_vnv_installments'  => 1,
				'_eru_vnv_tckn_mask'     => Eru_VNV_Identity::mask( vnv_fake_tckn() ),
				'_eru_vnv_phone'         => '5550000000',
			)
		);
		do_action( 'eru_vnv_attempt_started', $o );
		return wc_get_order( $o->get_id() );
	};
	$o5 = $amb( 'KEYAMB0005' );
	$r5 = $row( $o5->get_id() );
	vnv_check( '(c) attempt_started records an open status-0 row without an instruction id', $r5 && 0 === (int) $r5->status && null === $r5->instruction_id && null === $r5->closed_at );
	$iid5          = $base + 500;
	$bank->window  = array(
		array( 'Id' => $iid5, 'InvoiceNumber' => (string) $o5->get_order_number(), 'InstructionDescription' => 'Sipariş ' . $o5->get_order_number() . ' / KEYAMB0005', 'InstructionAmount' => (float) $o5->get_total(), 'InstructionStatusCode' => 2 ),
	);
	$paid_hits = 0;
	$bank->reset();
	$s  = Eru_VNV_Sync::run();
	$o5 = wc_get_order( $o5->get_id() );
	$r5 = $row( $o5->get_id() );
	vnv_check( '(c) ambiguous + lookup match → instruction adopted (meta, row status 1)', (string) $iid5 === (string) $o5->get_meta( '_eru_vnv_instruction_id' ) && 'created' === $o5->get_meta( '_eru_vnv_attempt_state' ) && (int) $r5->instruction_id === $iid5 && 1 === (int) $r5->status && 1 === $s['adopted'] );
	vnv_check( '(c) adopted order is on-hold, NOT paid in the same step (no status-2 poll yet)', 'on-hold' === $o5->get_status() && 0 === $paid_hits && false !== strpos( vnv_notes( $o5->get_id() ), 'banka kayıtlarında bulundu' ) );
	$bank->statuses[ $iid5 ] = 2;
	$bank->window            = array();
	Eru_VNV_Sync::run();
	vnv_check( '(c) next run polls the adopted id; status 2 → processing', 'processing' === wc_get_order( $o5->get_id() )->get_status() && 1 === $paid_hits );

	$o6           = $amb( 'KEYAMB0006' );
	$bank->window = array(
		array( 'Id' => $base + 600, 'InvoiceNumber' => (string) $o6->get_order_number(), 'InstructionDescription' => '', 'InstructionAmount' => (float) $o6->get_total(), 'InstructionStatusCode' => 2 ),
	);
	$bank->reset();
	Eru_VNV_Sync::run();
	$o6 = wc_get_order( $o6->get_id() );
	vnv_check( '(c) item without description → stays ambiguous, not adopted, not paid', 'ambiguous' === $o6->get_meta( '_eru_vnv_attempt_state' ) && '' === (string) $o6->get_meta( '_eru_vnv_instruction_id' ) && 'pending' === $o6->get_status() && null === $row( $o6->get_id() )->closed_at );
	$bank->window = array(
		array( 'Id' => $base + 601, 'InvoiceNumber' => (string) $o6->get_order_number(), 'InstructionDescription' => 'Sipariş ' . $o6->get_order_number() . ' / KEYAMB0006', 'InstructionAmount' => (float) $o6->get_total(), 'InstructionStatusCode' => 3 ),
	);
	Eru_VNV_Sync::run();
	vnv_check( '(c) cancelled match is never adopted', '' === (string) wc_get_order( $o6->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );
	$bank->window = array();
	$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 32 * DAY_IN_SECONDS ) ), array( 'order_id' => $o6->get_id() ) ); // phpcs:ignore
	Eru_VNV_Sync::run();
	$o6 = wc_get_order( $o6->get_id() );
	vnv_check( '(c) ambiguous for 30+ days → given up with a bank-panel note, still not paid', null !== $row( $o6->get_id() )->closed_at && false !== strpos( vnv_notes( $o6->get_id() ), 'banka panel' ) && 'pending' === $o6->get_status() && 'ambiguous' === $o6->get_meta( '_eru_vnv_attempt_state' ) );

	$o7 = $amb( 'KEYAMB0007', 'sending', time() );
	$bank->reset();
	Eru_VNV_Sync::run();
	vnv_check( '(c) a recent "sending" attempt (request in flight) is not looked up', ! in_array( 0, $bank->listed_ids(), true ) && null === $row( $o7->get_id() )->closed_at );
	$o7->update_meta_data( '_eru_vnv_attempt_state', 'failed' );
	$o7->save();
	$bank->reset();
	Eru_VNV_Sync::run();
	vnv_check( '(c) a failed attempt closes its row without a lookup', ! in_array( 0, $bank->listed_ids(), true ) && null !== $row( $o7->get_id() )->closed_at );

	// --- (d) order box: query and cancel ---------------------------------------------------------------
	$iid8 = $base + 800;
	$o8   = $with_instruction( $iid8 );
	$bank->statuses[ $iid8 ] = 1;
	$bank->identity          = vnv_fake_tckn();
	$as_admin();
	$req = fn( $do, $nonce, $extra = array() ) => Eru_VNV_Order_Box::request( array_merge( array( 'order_id' => (string) $o8->get_id(), 'do' => $do, 'nonce' => $nonce ), $extra ) );
	$bank->reset();
	$r = $req( 'cancel', '', array( 'confirm' => '1' ) );
	vnv_check( '(d) cancel without nonce → refused, no bank call', ! $r['ok'] && 'nonce' === $r['code'] && 0 === $bank->total() );
	$r = $req( 'cancel', 'forged', array( 'confirm' => '1' ) );
	vnv_check( '(d) cancel with a forged nonce → refused, no bank call', ! $r['ok'] && 'nonce' === $r['code'] && 0 === $bank->total() );
	$uid     = wp_insert_user( array( 'user_login' => 'vnv-lc-' . VNV_RUN, 'user_email' => 'vnv-lc-user-' . VNV_RUN . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'customer' ) );
	$users[] = $uid;
	wp_set_current_user( $uid );
	$r = $req( 'cancel', wp_create_nonce( 'eru_vnv_order_' . $o8->get_id() ), array( 'confirm' => '1' ) );
	vnv_check( '(d) cancel by a user without edit_shop_orders (valid nonce) → refused, no bank call', ! $r['ok'] && 'cap' === $r['code'] && 0 === $bank->total() );
	$as_admin();
	$nonce = wp_create_nonce( 'eru_vnv_order_' . $o8->get_id() );
	$r     = $req( 'cancel', $nonce );
	vnv_check( '(d) cancel without the confirmation step → refused, no bank call', ! $r['ok'] && 'confirm' === $r['code'] && 0 === $bank->total() );
	$r = $req( 'query', $nonce );
	vnv_check( '(d) query: status + detail from the bank', $r['ok'] && 1 === (int) $r['data']['status'] && 1 === $bank->count( '/vinov/listInstructionDetail' ) && 2 === count( $r['data']['debts'] ) );
	vnv_check( '(d) query answer carries no identity number and no debtor name', false === strpos( wp_json_encode( $r ), $bank->identity ) && false === strpos( wp_json_encode( $r ), 'Borçlu' ) );
	$bank->reset();
	$r     = $req( 'cancel', $nonce, array( 'confirm' => '1' ) );
	$calls = $bank->calls( '/vinov/cancelInstruction' );
	vnv_check( '(d) with cap + nonce + confirmation → exactly one cancel call', $r['ok'] && 1 === count( $calls ) );
	vnv_check( '(d) cancel body is the instruction id only (+ WorkPlace)', array( 'Id', 'WorkPlaceId' ) === ( function ( $b ) { $k = array_keys( $b ); sort( $k ); return $k; } )( $calls[0]['body'] ) && $iid8 === (int) $calls[0]['body']['Id'] );
	$o8 = wc_get_order( $o8->get_id() );
	vnv_check( '(d) note added, order cancelled, row status 3', false !== strpos( vnv_notes( $o8->get_id() ), 'iptal edildi' ) && 'cancelled' === $o8->get_status() && 3 === (int) $row( $o8->get_id() )->status );
	$bank->reset();
	$r = $req( 'cancel', $nonce, array( 'confirm' => '1' ) );
	vnv_check( '(d) second click while cancelled → no bank call', ! $r['ok'] && 'already_cancelled' === $r['code'] && 0 === $bank->total() );
	$r = Eru_VNV_Order_Box::request( array( 'order_id' => (string) $o2->get_id(), 'do' => 'cancel', 'confirm' => '1', 'nonce' => wp_create_nonce( 'eru_vnv_order_' . $o2->get_id() ) ) );
	vnv_check( '(d) a paid order is not cancelled from the box (refund outside)', ! $r['ok'] && 'paid' === $r['code'] && 0 === $bank->total() );
	ob_start();
	Eru_VNV_Order_Box::render( wc_get_order( $o8->get_id() ) );
	$box = (string) ob_get_clean();
	vnv_check( '(d) box shows the instruction id and a masked phone, never a full phone', false !== strpos( $box, (string) $iid8 ) && false === strpos( $box, '5550000000' ) && false !== strpos( $box, 'eru-vnv-box' ) );

	// --- (e) debtor query: the identity number is sent once and never kept ---------------------------
	$tckn = vnv_fake_tckn();
	$bank->identity = $tckn;
	vnv_clear_log_files();
	$bank->reset();
	$r = Eru_VNV_Admin::debtor_query( array( 'tckn' => $tckn, 'nonce' => '', 'start' => '2027-01-01', 'end' => '2027-03-31' ) );
	vnv_check( '(e) debtor query without nonce → refused, no bank call', ! $r['ok'] && 0 === $bank->total() );
	$r = Eru_VNV_Admin::debtor_query( array( 'tckn' => '12345678901', 'nonce' => wp_create_nonce( Eru_VNV_Admin::DEBTOR_NONCE ), 'start' => '2027-01-01', 'end' => '2027-03-31' ) );
	vnv_check( '(e) invalid identity number → refused, no bank call', ! $r['ok'] && 0 === $bank->total() );
	$r     = Eru_VNV_Admin::debtor_query( array( 'tckn' => $tckn, 'nonce' => wp_create_nonce( Eru_VNV_Admin::DEBTOR_NONCE ), 'start' => '2027-01-01', 'end' => '2027-03-31' ) );
	$calls = $bank->calls( '/vinov/debtorTransactionList' );
	vnv_check( '(e) valid query → one bank call carrying the number', $r['ok'] && 1 === count( $calls ) && $tckn === (string) ( $calls[0]['body']['IdentityTaxNumber'] ?? '' ) );
	vnv_check( '(e) result html masks the number (heading and identity column) and shows rows', false === strpos( $r['html'], $tckn ) && 2 <= substr_count( $r['html'], Eru_VNV_Identity::mask( $tckn ) ) && false !== strpos( $r['html'], '275' ) );
	$in_options = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s OR option_name LIKE %s", '%' . $wpdb->esc_like( $tckn ) . '%', '%' . $wpdb->esc_like( $tckn ) . '%' ) ); // phpcs:ignore
	vnv_check( '(e) number in no option or transient', 0 === $in_options );
	$in_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_value LIKE %s", '%' . $wpdb->esc_like( $tckn ) . '%' ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s", '%' . $wpdb->esc_like( $tckn ) . '%' ) ); // phpcs:ignore
	vnv_check( '(e) number in no user or post meta', 0 === $in_meta );
	vnv_check( '(e) number in no captured log line or log file', false === strpos( implode( "\n", (array) $logs->getArrayCopy() ), $tckn ) && false === strpos( vnv_log_files_text(), $tckn ) );
	vnv_check( '(e) logs were written (check is not vacuous)', false !== strpos( implode( "\n", (array) $logs->getArrayCopy() ), 'debtorTransactionList' ) );
	$cache = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_eru_vnv_cache' ) . '%' ) ); // phpcs:ignore
	vnv_check( '(e) debtor results are not cached', ! preg_grep( '/275\.25|520001/', $cache ) );
	// The 5-minute list caches never hold an identity number either.
	$bank->statuses = array();
	$bank->window   = array(
		array( 'Id' => $base + 900, 'InvoiceNumber' => 'DEMO-002', 'InstructionDescription' => 'Demo ' . $tckn, 'InstructionAmount' => 321.5, 'InstructionStatusCode' => 1, 'DebtorName' => 'Demo Borçlu Kişi', 'IdentityTaxNumber' => $tckn ),
	);
	$html = Eru_VNV_Admin::render( 'talimatlar', array() );
	$bank->window = array();
	vnv_check( '(e) Talimatlar renders the bank list', false !== strpos( $html, (string) ( $base + 900 ) ) && false === strpos( $html, $tckn ) );
	$cache = implode( "\n", $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_eru_vnv_cache' ) . '%' ) ) ); // phpcs:ignore
	vnv_check( '(e) Talimatlar cache exists and holds no identity number or debtor full name', false !== strpos( $cache, (string) ( $base + 900 ) ) && false === strpos( $cache, $tckn ) && false === strpos( $cache, 'Borçlu Kişi' ) );
	$bank->reset();
	Eru_VNV_Admin::render( 'talimatlar', array() );
	vnv_check( '(e) Talimatlar second view is served from the 5-minute cache', 0 === $bank->count( '/vinov/listInstruction' ) );
	$html = Eru_VNV_Admin::render( 'kampanyalar', array() );
	vnv_check( '(e) Kampanyalar renders read-only', false !== strpos( $html, 'Demo Kampanya' ) && ! preg_match( '/<input[^>]+type="(text|number|date)"/', $html ) );
	$html = Eru_VNV_Admin::render( 'hareketler', array() );
	vnv_check( '(e) Hareketler shows the debtor form (POST + nonce, empty identity field)', false !== strpos( $html, 'name="eru_vnv_debtor_tckn"' ) && false !== strpos( $html, 'method="post"' ) && false !== strpos( $html, Eru_VNV_Admin::DEBTOR_NONCE ) );
	$html = Eru_VNV_Admin::render( 'baglanti', array() );
	vnv_check( '(e) Bağlantı testi tab renders an enqueue button', false !== strpos( $html, 'conn_test' ) );

	$dup = $make_order( 'on-hold', array( '_eru_vnv_env' => 'test' ) );
	$wpdb->suppress_errors( false );
	$err0 = $wpdb->last_error;
	do_action( 'eru_vnv_instruction_created', $dup, $iid8, 1 );
	vnv_check( 'an instruction id already tracked for another order is not re-recorded (no DB error, first row intact)', null === $row( $dup->get_id() ) && (int) $row( $o8->get_id() )->instruction_id === $iid8 && 3 === (int) $row( $o8->get_id() )->status && $err0 === $wpdb->last_error );
	$tmp = $make_order( 'cancelled', array( '_eru_vnv_env' => 'test' ) );
	do_action( 'eru_vnv_instruction_created', $tmp, $base + 1100, 1 );
	Eru_VNV_Instructions::close( $row( $tmp->get_id() )->id, 3, 'cancelled' );
	$tid = $tmp->get_id();
	$tmp->delete( true );
	vnv_check( 'I-1: deleting an order drops its closed, unflagged row', null === $row( $tid ) );

	// --- (f) privacy -----------------------------------------------------------------------------------
	$email_a = 'vnv-lc-a-' . VNV_RUN . '@example.com';
	$email_b = 'vnv-lc-b-' . VNV_RUN . '@example.com';
	$oa      = $with_instruction( $base + 1000, 'on-hold', $email_a );
	$ob      = $with_instruction( $base + 1001, 'on-hold', $email_b );
	$ex      = Eru_VNV_Privacy::export( $email_a, 1 );
	$flat    = wp_json_encode( $ex );
	vnv_check( '(f) exporter returns the mask and phone of the customer\'s order', false !== strpos( $flat, (string) $oa->get_meta( '_eru_vnv_tckn_mask' ) ) && false !== strpos( $flat, '5550000000' ) && true === $ex['done'] );
	$er = Eru_VNV_Privacy::erase( $email_a, 1 );
	$oa = wc_get_order( $oa->get_id() );
	$ob = wc_get_order( $ob->get_id() );
	vnv_check( '(f) eraser removes mask and phone meta from the customer\'s orders', '' === (string) $oa->get_meta( '_eru_vnv_tckn_mask' ) && '' === (string) $oa->get_meta( '_eru_vnv_phone' ) && $er['items_removed'] >= 2 );
	vnv_check( '(f) instruction id kept (business record), another customer untouched', (string) ( $base + 1000 ) === (string) $oa->get_meta( '_eru_vnv_instruction_id' ) && '' !== (string) $ob->get_meta( '_eru_vnv_tckn_mask' ) && '' !== (string) $ob->get_meta( '_eru_vnv_phone' ) );
	vnv_check( '(f) exporter/eraser registered', isset( apply_filters( 'wp_privacy_personal_data_exporters', array() )['eru-vnv'] ) && isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['eru-vnv'] ) );

	// Daily purge: retention for meta and rows, claim rows older than a day.
	vnv_check( '(f) default retention is 365 days', 365 === Eru_VNV_Settings::retention_days() );
	$ob->set_date_created( time() - 400 * DAY_IN_SECONDS );
	$ob->save();
	$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ), 'closed_at' => gmdate( 'Y-m-d H:i:s', time() - 300 * DAY_IN_SECONDS ), 'status' => 2 ), array( 'order_id' => $ob->get_id() ) ); // phpcs:ignore
	$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ), array( 'order_id' => $o4b->get_id() ) ); // phpcs:ignore
	$old_claim = 'eru_vnv_vused_' . VNV_RUN . 'oldclaim00';
	$new_claim = 'eru_vnv_vused_' . VNV_RUN . 'newclaim00';
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no'), (%s, %s, 'no')", $old_claim, (string) ( time() - 2 * DAY_IN_SECONDS ), $new_claim, (string) time() ) ); // phpcs:ignore
	$unschedule();
	Eru_VNV_Cron::daily();
	vnv_check( '(f) I2: the daily job re-arms the sync while rows are open', 1 === count( $pending() ) );
	$ob = wc_get_order( $ob->get_id() );
	vnv_check( '(f) purge: mask/phone removed from orders older than retention', '' === (string) $ob->get_meta( '_eru_vnv_tckn_mask' ) && '' === (string) $ob->get_meta( '_eru_vnv_phone' ) );
	vnv_check( '(f) purge: closed rows older than retention deleted, open rows kept', null === $row( $ob->get_id() ) && null !== $row( $o4b->get_id() ) );
	vnv_check( '(f) purge: order notes kept', '' !== vnv_notes( $ob->get_id() ) );
	$left = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $old_claim, $new_claim ) ); // phpcs:ignore
	vnv_check( '(f) purge: claim rows older than a day deleted, recent kept', array( $new_claim ) === $left );

	// --- (g) a cancelled order is never charged silently ----------------------------------------------
	$mails = new ArrayObject();
	add_filter(
		'pre_wp_mail',
		function ( $r, $atts ) use ( $mails ) {
			$mails[] = $atts;
			return $r;
		},
		5,
		2
	);
	$pwc = array();
	add_action(
		'eru_vnv_paid_while_cancelled',
		function ( $order, $id, $order_id = 0 ) use ( &$pwc ) {
			$pwc[] = array( $order ? $order->get_id() : (int) $order_id, $id );
		},
		10,
		3
	);
	$bank->statuses = array();
	$bank->window   = array();

	// (g1) WooCommerce's unpaid-order auto-cancel skips Vinov orders that may have an instruction.
	$ga  = $amb( 'KEYAMBG001' );
	$gw  = $with_instruction( $base + 1201 );
	$gf  = $amb( 'KEYAMBG002', 'failed' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE order_id = %d", $gf->get_id() ) ); // phpcs:ignore
	$gn  = $make_order( 'pending' );
	$gn->set_payment_method( 'bacs' );
	$gn->save();
	vnv_check( '(g1) unpaid auto-cancel: ambiguous Vinov order is kept', false === apply_filters( 'woocommerce_cancel_unpaid_order', true, $ga ) );
	vnv_check( '(g1) unpaid auto-cancel: order with an open instruction row is kept', false === apply_filters( 'woocommerce_cancel_unpaid_order', true, $gw ) );
	vnv_check( '(g1) unpaid auto-cancel: failed Vinov attempt and other gateways are untouched', true === apply_filters( 'woocommerce_cancel_unpaid_order', true, $gf ) && true === apply_filters( 'woocommerce_cancel_unpaid_order', true, $gn ) );

	// (g2) an instruction found for an order that is already cancelled is cancelled at the bank.
	$ga->update_status( 'cancelled' );
	vnv_check( '(g2) cancelling an order without an instruction id queues nothing', ! $cancel_jobs( $ga->get_id() ) );
	$iid_g2       = $base + 1202;
	$bank->window = array(
		array( 'Id' => $iid_g2, 'InvoiceNumber' => (string) $ga->get_order_number(), 'InstructionDescription' => 'Sipariş ' . $ga->get_order_number() . ' / KEYAMBG001', 'InstructionAmount' => (float) $ga->get_total(), 'InstructionStatusCode' => 1 ),
	);
	$bank->statuses[ $iid_g2 ] = 1;
	$bank->reset();
	Eru_VNV_Sync::run();
	$bank->window = array();
	$ga           = wc_get_order( $ga->get_id() );
	vnv_check( '(g2) found on a cancelled order: id kept, order stays cancelled, bank cancel queued once', (string) $iid_g2 === (string) $ga->get_meta( '_eru_vnv_instruction_id' ) && 'cancelled' === $ga->get_status() && 1 === count( $cancel_jobs( $ga->get_id() ) ) && 0 === $bank->count( '/vinov/cancelInstruction' ) );
	$bank->reset();
	$run_cancel( $ga->get_id() );
	$calls = $bank->calls( '/vinov/cancelInstruction' );
	$ga    = wc_get_order( $ga->get_id() );
	vnv_check( '(g2) queued job cancels by instruction id at the bank, with a note; row closed status 3', 1 === count( $calls ) && $iid_g2 === (int) $calls[0]['body']['Id'] && false !== strpos( vnv_notes( $ga->get_id() ), 'iptal edildi' ) && 3 === (int) $row( $ga->get_id() )->status && 'cancelled' === $ga->get_status() );
	$bank->reset();
	Eru_VNV_Sync::bank_cancel_job( $ga->get_id(), 1 );
	vnv_check( '(g2) the cancel job is idempotent (second run: no bank call)', 0 === $bank->count( '/vinov/cancelInstruction' ) );

	// (g3) any path that cancels an order with a waiting instruction queues the bank cancel.
	$g3 = $with_instruction( $base + 1203 );
	$bank->statuses[ $base + 1203 ] = 1;
	$g3->update_status( 'cancelled', 'Demo yönetici iptali' );
	vnv_check( '(g3) status control → bank cancel queued exactly once', 1 === count( $cancel_jobs( $g3->get_id() ) ) );
	$g3 = wc_get_order( $g3->get_id() );
	$g3->update_status( 'cancelled' );
	vnv_check( '(g3) a repeated cancelled transition does not queue twice', 1 === count( $cancel_jobs( $g3->get_id() ) ) );
	$bank->reset();
	$run_cancel( $g3->get_id() );
	vnv_check( '(g3) N2: status 1 → the job reads the status first, then exactly one cancel call', 1 === count( array_filter( $bank->listed_ids(), fn( $i ) => $base + 1203 === $i ) ) );
	vnv_check( '(g3) job → one cancel call, row status 3, order not reopened', 1 === $bank->count( '/vinov/cancelInstruction' ) && 3 === (int) $row( $g3->get_id() )->status && 'cancelled' === wc_get_order( $g3->get_id() )->get_status() );
	vnv_check( '(g3) the box cancel path (d) queued no extra bank cancel', ! $cancel_jobs( $o8->get_id() ) );
	$g3r = $with_instruction( $base + 1210 );
	$g3r->update_status( 'cancelled' );
	$g3r = wc_get_order( $g3r->get_id() );
	$g3r->update_status( 'on-hold', 'Demo: sipariş yeniden açıldı' );
	$bank->reset();
	$run_cancel( $g3r->get_id() );
	vnv_check( '(g3) the shop reopened the order before the job ran → no bank cancel, row still waiting', 0 === $bank->count( '/vinov/cancelInstruction' ) && 1 === (int) $row( $g3r->get_id() )->status && null === $row( $g3r->get_id() )->closed_at );

	// (g4) bank cancel retries: an ambiguous reply is retried later, a refusal is flagged for review.
	$g4 = $with_instruction( $base + 1204 );
	$bank->statuses[ $base + 1204 ] = 1;
	$bank->statuses[ $base + 1205 ] = 1;
	$g4->update_status( 'cancelled' );
	$bank->reset();
	$bank->push( '/vinov/cancelInstruction', array( 503, '{}' ) );
	$run_cancel( $g4->get_id() );
	$next = $cancel_jobs( $g4->get_id() );
	vnv_check( '(g4) ambiguous bank reply → note, retry queued with try 2, row still open', 1 === $bank->count( '/vinov/cancelInstruction' ) && 1 === count( $next ) && 2 === (int) array_values( $next )[0]['try'] && 1 === (int) $row( $g4->get_id() )->status && false !== strpos( vnv_notes( $g4->get_id() ), 'yanıt alınamadı' ) );
	$bank->reset();
	$run_cancel( $g4->get_id() );
	vnv_check( '(g4) the retry cancels', 1 === $bank->count( '/vinov/cancelInstruction' ) && 3 === (int) $row( $g4->get_id() )->status );
	$g4b = $with_instruction( $base + 1205 );
	$g4b->update_status( 'cancelled' );
	$bank->reset();
	$bank->push( '/vinov/cancelInstruction', array( 200, vnv_fixture( 'error-generic.json' ) ) );
	$run_cancel( $g4b->get_id() );
	$r4b = $row( $g4b->get_id() );
	vnv_check( '(g4) definitive bank refusal → no retry, flagged "cancel_failed" for review, note', ! $cancel_jobs( $g4b->get_id() ) && 'cancel_failed' === $r4b->review && false !== strpos( vnv_notes( $g4b->get_id() ), 'reddedildi' ) );

	// (g5) status 2 on a cancelled order: loud, never reopened.
	$g5 = $with_instruction( $base + 1206 );
	$g5->update_status( 'cancelled' );
	foreach ( array_keys( $cancel_jobs( $g5->get_id() ) ) as $aid ) {
		ActionScheduler::store()->cancel_action( $aid ); // The bank cancel did not happen in time.
	}
	$bank->statuses[ $base + 1206 ] = 2;
	$mails->exchangeArray( array() );
	$paid_before = $paid_hits;
	$bank->reset();
	Eru_VNV_Sync::run();
	$g5  = wc_get_order( $g5->get_id() );
	$r5g = $row( $g5->get_id() );
	vnv_check( '(g5) cancelled order is not reopened and not paid', 'cancelled' === $g5->get_status() && $paid_before === $paid_hits );
	vnv_check( '(g5) row flagged "paid_while_cancelled" and closed', 'paid_while_cancelled' === $r5g->review && null !== $r5g->closed_at && 2 === (int) $r5g->status );
	$to_admin = array_values( array_filter( (array) $mails->getArrayCopy(), fn( $m ) => get_option( 'admin_email' ) === ( is_array( $m['to'] ) ? reset( $m['to'] ) : $m['to'] ) && false !== strpos( (string) $m['message'], (string) $g5->get_order_number() ) ) );
	vnv_check( '(g5) admin e-mail sent once, naming the order', 1 === count( $to_admin ) );
	vnv_check( '(g5) action eru_vnv_paid_while_cancelled fired', in_array( array( $g5->get_id(), $base + 1206 ), $pwc, true ) );
	$notice = Eru_VNV_Admin::review_notice_html();
	vnv_check( '(g5) persistent admin notice names the order', false !== strpos( $notice, '#' . $g5->get_order_number() ) );
	$bank->reset();
	$html = Eru_VNV_Admin::render( 'talimatlar', array( 'durum' => 'incelenmeli' ) );
	vnv_check( '(g5) Talimatlar "İncelenmeli" filter lists it from local data (no bank call)', false !== strpos( $html, (string) ( $base + 1206 ) ) && false !== strpos( $html, 'İncelenmeli' ) && 0 === $bank->total() );
	$r = Eru_VNV_Admin::dismiss_review( array( 'order_id' => (string) $g5->get_id(), 'nonce' => '' ) );
	vnv_check( '(g5) dismiss without nonce → refused, notice stays', ! $r['ok'] && false !== strpos( Eru_VNV_Admin::review_notice_html(), '#' . $g5->get_order_number() ) );
	$r = Eru_VNV_Admin::dismiss_review( array( 'order_id' => (string) $g5->get_id(), 'nonce' => wp_create_nonce( 'eru_vnv_dismiss_' . $g5->get_id() ) ) );
	vnv_check( '(g5) dismissed per order → notice gone, still listed under İncelenmeli', $r['ok'] && false === strpos( Eru_VNV_Admin::review_notice_html(), '#' . $g5->get_order_number() ) && false !== strpos( Eru_VNV_Admin::render( 'talimatlar', array( 'durum' => 'incelenmeli' ) ), (string) ( $base + 1206 ) ) );
	$bank->reset();
	Eru_VNV_Sync::bank_cancel_job( $g5->get_id(), 1 );
	vnv_check( '(g5) a late bank-cancel job for an approved instruction makes no call', 0 === $bank->count( '/vinov/cancelInstruction' ) );
	// N1: the alarm fires once; manual queries on the closed row only refresh the shown status.
	$g5n = wp_create_nonce( 'eru_vnv_order_' . $g5->get_id() );
	for ( $q = 0; $q < 3; $q++ ) {
		$qr = Eru_VNV_Order_Box::request( array( 'order_id' => (string) $g5->get_id(), 'do' => 'query', 'nonce' => $g5n ) );
	}
	Eru_VNV_Sync::run();
	vnv_check( '(g5) N1: three queries + a sync after the alarm → still 1 note, 1 e-mail, 1 action', 1 === substr_count( vnv_notes( $g5->get_id() ), 'DİKKAT' ) && 1 === count( array_filter( (array) $mails->getArrayCopy(), fn( $m ) => false !== strpos( (string) $m['message'], (string) $g5->get_order_number() ) ) ) && 1 === count( array_filter( $pwc, fn( $x ) => $x[0] === $g5->get_id() ) ) );
	vnv_check( '(g5) N1: the query shows the bank status (approved) and the dismissed notice stays dismissed', $qr['ok'] && 2 === (int) $qr['data']['status'] && false === strpos( Eru_VNV_Admin::review_notice_html(), '#' . $g5->get_order_number() ) && 'cancelled' === wc_get_order( $g5->get_id() )->get_status() );

	// (g6) M2: approved, but the order total no longer equals the instruction amount.
	$g6  = $with_instruction( $base + 1207 );
	$fee = new WC_Order_Item_Fee();
	$fee->set_name( 'Demo ek ücret' );
	$fee->set_amount( '15.25' );
	$fee->set_total( '15.25' );
	$fee->set_tax_status( 'none' );
	$g6->add_item( $fee );
	$g6->calculate_totals( false );
	$g6->save();
	$bank->statuses[ $base + 1207 ] = 2;
	$paid_before = $paid_hits;
	Eru_VNV_Sync::run();
	$g6 = wc_get_order( $g6->get_id() );
	vnv_check( '(g6) M2: amount mismatch → still paid, review note, flagged "amount_mismatch"', $paid_before + 1 === $paid_hits && 'processing' === $g6->get_status() && false !== strpos( vnv_notes( $g6->get_id() ), 'tutar' ) && 'amount_mismatch' === $row( $g6->get_id() )->review );

	// (g7) M8: status 3 on a paid order; cancel replies ambiguous and bank_refused.
	$g7 = $with_instruction( $base + 1208 );
	$g7->payment_complete( (string) ( $base + 1208 ) );
	$bank->statuses[ $base + 1208 ] = 3;
	Eru_VNV_Sync::run();
	$g7 = wc_get_order( $g7->get_id() );
	vnv_check( '(g7) M8: status 3 on a paid order → not cancelled, review note, flagged', 'processing' === $g7->get_status() && false !== strpos( vnv_notes( $g7->get_id() ), 'ödenmiş' ) && 'cancelled_while_paid' === $row( $g7->get_id() )->review );
	$g8n   = $with_instruction( $base + 1209 );
	$nonce = wp_create_nonce( 'eru_vnv_order_' . $g8n->get_id() );
	$bank->reset();
	$bank->push( '/vinov/cancelInstruction', array( 503, '{}' ) );
	$r = Eru_VNV_Order_Box::request( array( 'order_id' => (string) $g8n->get_id(), 'do' => 'cancel', 'nonce' => $nonce, 'confirm' => '1' ) );
	vnv_check( '(g7) M8: box cancel, ambiguous reply → "ambiguous", note, order and row unchanged', ! $r['ok'] && 'ambiguous' === $r['code'] && 'on-hold' === wc_get_order( $g8n->get_id() )->get_status() && 1 === (int) $row( $g8n->get_id() )->status && false !== strpos( vnv_notes( $g8n->get_id() ), 'yanıt alınamadı' ) );
	$bank->push( '/vinov/cancelInstruction', array( 200, vnv_fixture( 'error-generic.json' ) ) );
	$r = Eru_VNV_Order_Box::request( array( 'order_id' => (string) $g8n->get_id(), 'do' => 'cancel', 'nonce' => $nonce, 'confirm' => '1' ) );
	vnv_check( '(g7) M8: box cancel, bank refusal → "bank_refused", note, order unchanged', ! $r['ok'] && 'bank_refused' === $r['code'] && 'on-hold' === wc_get_order( $g8n->get_id() )->get_status() && false !== strpos( vnv_notes( $g8n->get_id() ), 'reddedildi' ) );

	// (g8) M8: WP-CLI smoke.
	$cli = fn( $cmd ) => WP_CLI::runcommand( $cmd, array( 'return' => 'all', 'launch' => false, 'exit_error' => false ) );
	$bank->statuses[ $base + 1209 ] = 1;
	$c = $cli( 'eru-vnv sync --order=' . $g8n->get_id() );
	vnv_check( '(g8) CLI sync --order runs', 0 === $c->return_code && false !== strpos( $c->stdout, 'status=ok' ) );
	$c = $cli( 'eru-vnv instruction ' . ( $base + 1209 ) );
	vnv_check( '(g8) CLI instruction shows status and debts, no identity number', 0 === $c->return_code && false !== strpos( $c->stdout, 'talimat=' . ( $base + 1209 ) ) && false === strpos( $c->stdout, (string) $bank->identity ) );
	$c = $cli( 'eru-vnv cancel 1 --yes' );
	vnv_check( '(g8) CLI cancel of an unknown instruction fails', 0 !== $c->return_code );
	$bank->reset();
	$c = $cli( 'eru-vnv cancel ' . ( $base + 1209 ) . ' --yes' );
	vnv_check( '(g8) CLI cancel --yes cancels at the bank and the order', 0 === $c->return_code && 1 === $bank->count( '/vinov/cancelInstruction' ) && 'cancelled' === wc_get_order( $g8n->get_id() )->get_status() && ! $cancel_jobs( $g8n->get_id() ) );

	// (g9) M5: the connection test does not drop the checkout's cached tokens.
	$ck = ( new Eru_VNV_API_Client( 'test' ) )->token_key( 'oob' );
	set_transient( $ck, 'demo-checkout-tok', 600 );
	$bank->reset();
	Eru_VNV_Admin::connection_test();
	$res = get_option( Eru_VNV_Admin::CONN_OPTION );
	vnv_check( '(g9) M5: connection test mints its own tokens and keeps the checkout token', 'demo-checkout-tok' === get_transient( $ck ) && count( $bank->grants ) >= 2 && 3 === count( array_filter( $res['checks'], fn( $x ) => ! empty( $x[1] ) ) ) );

	// (g10) M6: the receiver query needs a nonce.
	$bank->reset();
	Eru_VNV_Admin::render( 'hareketler', array( 'sorgu' => 'alici' ) );
	vnv_check( '(g10) M6: receiver query without nonce → no bank call', 0 === $bank->count( '/vinov/receiverTransactionList' ) );
	Eru_VNV_Admin::render( 'hareketler', array( 'sorgu' => 'alici', '_eru_vnv_q' => wp_create_nonce( Eru_VNV_Admin::RECEIVER_NONCE ) ) );
	vnv_check( '(g10) M6: receiver query with nonce → one bank call', 1 === $bank->count( '/vinov/receiverTransactionList' ) );

	// (g11) M7: the re-arm flag goes only after a successful re-arm.
	update_option( Eru_VNV_Cron::REARM, '1', true );
	$ok = Eru_VNV_Cron::maybe_rearm();
	vnv_check( '(g11) M7: maybe_rearm re-arms and only then drops the flag', true === $ok && false === get_option( Eru_VNV_Cron::REARM ) );

	// N1 guards one by one: a stale writer reopening the flagged row, re-flagging, and a read-only query.
	$wpdb->update( $table, array( 'closed_at' => null ), array( 'order_id' => $g5->get_id() ) ); // phpcs:ignore
	Eru_VNV_Sync::run();
	vnv_check( '(g5) N1: flagged row reopened by a stale writer → closed again, still 1 note/e-mail/action', null !== $row( $g5->get_id() )->closed_at && 1 === substr_count( vnv_notes( $g5->get_id() ), 'DİKKAT' ) && 1 === count( array_filter( $pwc, fn( $x ) => $x[0] === $g5->get_id() ) ) );
	Eru_VNV_Instructions::flag( $row( $g5->get_id() )->id, 'paid_while_cancelled' );
	vnv_check( '(g5) N1: raising the same flag again keeps the dismissal', null !== $row( $g5->get_id() )->review_ack );
	$bank->statuses[ $iid4 ] = 3;
	$qr = Eru_VNV_Order_Box::request( array( 'order_id' => (string) $o4->get_id(), 'do' => 'query', 'nonce' => wp_create_nonce( 'eru_vnv_order_' . $o4->get_id() ) ) );
	vnv_check( '(g5) N1: query on a closed (given-up) row only shows the bank status, changes nothing', $qr['ok'] && 3 === (int) $qr['data']['status'] && 'closed' === $qr['code'] && 'on-hold' === wc_get_order( $o4->get_id() )->get_status() && 'gave_up' === $row( $o4->get_id() )->close_reason );

	// --- (h) the bank cancel reads the instruction status first --------------------------------------
	$mails->exchangeArray( array() );
	// N2(a): approved before any sync, then the shop cancels → no cancel call, alarm once.
	$h1 = $with_instruction( $base + 1301 );
	$bank->statuses[ $base + 1301 ] = 2;
	$h1->update_status( 'cancelled' );
	$bank->reset();
	$run_cancel( $h1->get_id() );
	$rh1 = $row( $h1->get_id() );
	vnv_check( '(h) N2: approved before sync, then cancelled → no cancel call, flagged paid_while_cancelled', 0 === $bank->count( '/vinov/cancelInstruction' ) && 'paid_while_cancelled' === $rh1->review && null !== $rh1->closed_at && 'cancelled' === wc_get_order( $h1->get_id() )->get_status() );
	Eru_VNV_Sync::run();
	Eru_VNV_Sync::bank_cancel_job( $h1->get_id(), 1 );
	vnv_check( '(h) N2: alarm raised exactly once (1 note, 1 e-mail, 1 action)', 1 === substr_count( vnv_notes( $h1->get_id() ), 'DİKKAT' ) && 1 === count( array_filter( (array) $mails->getArrayCopy(), fn( $m ) => false !== strpos( (string) $m['message'], (string) $h1->get_order_number() ) ) ) && 1 === count( array_filter( $pwc, fn( $x ) => $x[0] === $h1->get_id() ) ) );
	// N2(b): the first cancel landed but its reply was ambiguous; the retry finds status 3.
	$h2 = $with_instruction( $base + 1302 );
	$bank->statuses[ $base + 1302 ] = 1;
	$h2->update_status( 'cancelled' );
	$bank->reset();
	$bank->push( '/vinov/cancelInstruction', array( 503, '{}' ) );
	$run_cancel( $h2->get_id() );
	$bank->statuses[ $base + 1302 ] = 3;
	$bank->reset();
	$run_cancel( $h2->get_id() );
	$rh2 = $row( $h2->get_id() );
	vnv_check( '(h) N2: retry after an ambiguous cancel sees status 3 → no second cancel call, row closed 3, no cancel_failed flag', 0 === $bank->count( '/vinov/cancelInstruction' ) && 3 === (int) $rh2->status && null !== $rh2->closed_at && 'cancel_failed' !== $rh2->review );
	// N2: unreadable status → retry within the bound, no cancel call.
	$h3 = $with_instruction( $base + 1303 );
	$h3->update_status( 'cancelled' );
	$bank->reset();
	$run_cancel( $h3->get_id() );
	$next = $cancel_jobs( $h3->get_id() );
	vnv_check( '(h) N2: instruction not readable → no cancel call, retry queued (try 2)', 0 === $bank->count( '/vinov/cancelInstruction' ) && 1 === count( $next ) && 2 === (int) array_values( $next )[0]['try'] );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE order_id = %d", $h3->get_id() ) ); // phpcs:ignore
	foreach ( array_keys( $cancel_jobs( $h3->get_id() ) ) as $aid ) {
		ActionScheduler::store()->cancel_action( $aid );
	}
	// N2: a stale cancel_failed flag is cleared when the sync later sees status 3.
	$h4 = $with_instruction( $base + 1304 );
	$h4->update_status( 'cancelled' );
	foreach ( array_keys( $cancel_jobs( $h4->get_id() ) ) as $aid ) {
		ActionScheduler::store()->cancel_action( $aid );
	}
	Eru_VNV_Instructions::flag( $row( $h4->get_id() )->id, 'cancel_failed' );
	$bank->statuses[ $base + 1304 ] = 3;
	Eru_VNV_Sync::run();
	vnv_check( '(h) N2: sync sees status 3 → row closed and the stale cancel_failed flag cleared', '' === $row( $h4->get_id() )->review && 3 === (int) $row( $h4->get_id() )->status && false === strpos( Eru_VNV_Admin::review_notice_html(), '#' . $h4->get_order_number() ) );
	// N3: cancelling a paid Vinov order → one note, no bank call.
	$h5 = $with_instruction( $base + 1305 );
	$bank->statuses[ $base + 1305 ] = 2;
	Eru_VNV_Sync::run();
	$h5 = wc_get_order( $h5->get_id() );
	$bank->reset();
	$h5->update_status( 'cancelled' );
	$h5 = wc_get_order( $h5->get_id() );
	$h5->update_status( 'on-hold' );
	wc_get_order( $h5->get_id() )->update_status( 'cancelled' );
	vnv_check( '(h) N3: paid order cancelled → note once, nothing queued, no bank call', 1 === substr_count( vnv_notes( $h5->get_id() ), 'Vinov talimatı ödenmiş; banka çağrılmadı. İade gerekiyorsa banka panelinden yapın.' ) && ! $cancel_jobs( $h5->get_id() ) && 0 === $bank->count( '/vinov/cancelInstruction' ) );
	// N4 + N5: refunded (unpaid, waiting instruction) → queued; the job cancels at the bank and leaves the order refunded.
	$h6 = $with_instruction( $base + 1306 );
	$bank->statuses[ $base + 1306 ] = 1;
	$h6->update_status( 'refunded' );
	vnv_check( '(h) N5: refunded unpaid order with a waiting instruction → bank cancel queued', 1 === count( $cancel_jobs( $h6->get_id() ) ) );
	$bank->reset();
	$run_cancel( $h6->get_id() );
	vnv_check( '(h) N4: the job cancels at the bank and never changes the order status (refunded stays refunded)', 1 === $bank->count( '/vinov/cancelInstruction' ) && 'refunded' === wc_get_order( $h6->get_id() )->get_status() && 3 === (int) $row( $h6->get_id() )->status );
	// N4: reopened between the job's check and the lock → refused on the fresh read, no call.
	$h7 = $with_instruction( $base + 1307 );
	$bank->statuses[ $base + 1307 ] = 1;
	$stale = wc_get_order( $h7->get_id() );
	$stale->set_status( 'cancelled' ); // In memory only: the stored order is still on-hold.
	$bank->reset();
	$r = Eru_VNV_Sync::cancel( $stale, 'test', 'auto_cancel', true );
	vnv_check( '(h) N4: cancel from the job re-checks the status under the lock → refused, no call, order untouched', ! $r['ok'] && 'reopened' === $r['code'] && 0 === $bank->count( '/vinov/cancelInstruction' ) && 'on-hold' === wc_get_order( $h7->get_id() )->get_status() );
	// N6: keep_unpaid needs the Vinov gateway.
	$h8 = $amb( 'KEYAMBH008' );
	$h8->set_payment_method( 'bacs' );
	$h8->save();
	vnv_check( '(h) N6: an ambiguous meta on an order now paid with another gateway does not block the auto-cancel', true === apply_filters( 'woocommerce_cancel_unpaid_order', true, wc_get_order( $h8->get_id() ) ) );
	// Exact duplicate check of queue_cancel (also beyond 100 pending cancels of other orders).
	for ( $k = 0; $k < 105; $k++ ) {
		as_schedule_single_action( time() + HOUR_IN_SECONDS, Eru_VNV_Cron::CANCEL, array( 'order_id' => 980000000 + $k, 'try' => 1 ), Eru_VNV_Cron::GROUP );
	}
	$h9 = $with_instruction( $base + 1309 );
	as_schedule_single_action( time() + HOUR_IN_SECONDS, Eru_VNV_Cron::CANCEL, array( 'order_id' => $h9->get_id(), 'try' => 3 ), Eru_VNV_Cron::GROUP );
	vnv_check( '(h) queue_cancel duplicate check is exact (sees a pending try-3 job behind 105 others)', false === Eru_VNV_Cron::queue_cancel( $h9->get_id() ) && 1 === count( $cancel_jobs( $h9->get_id() ) ) );
	as_unschedule_all_actions( Eru_VNV_Cron::CANCEL, null, Eru_VNV_Cron::GROUP );

	// --- (i) trashed or deleted orders, custom pending status, stock, paid elsewhere --------------------
	$mails->exchangeArray( array() );
	// I-1: trash a waiting order → one bank cancel by id; the order stays in the trash.
	$i1  = $with_instruction( $base + 1401 );
	$i1i = $i1->get_id();
	$bank->statuses[ $base + 1401 ] = 1;
	$i1->delete( false ); // WC_Data::delete() resets the object's id, also when trashing.
	$i1 = wc_get_order( $i1i );
	vnv_check( '(i) I-1: trashing a waiting order queues the bank cancel', 'trash' === wc_get_order( $i1->get_id() )->get_status() && 1 === count( $cancel_jobs( $i1->get_id() ) ) );
	$bank->reset();
	$run_cancel( $i1->get_id() );
	$calls = $bank->calls( '/vinov/cancelInstruction' );
	vnv_check( '(i) I-1: trashed → exactly one cancel by instruction id, row closed 3, order still trashed', 1 === count( $calls ) && $base + 1401 === (int) $calls[0]['body']['Id'] && 3 === (int) $row( $i1->get_id() )->status && 'trash' === wc_get_order( $i1->get_id() )->get_status() );
	// I-1: permanent delete → the row survives until the bank cancel is done.
	$i2  = $with_instruction( $base + 1402 );
	$i2i = $i2->get_id();
	$bank->statuses[ $base + 1402 ] = 1;
	$i2->delete( true );
	vnv_check( '(i) I-1: permanently deleted waiting order → row kept open, bank cancel queued', false === wc_get_order( $i2i ) && null !== $row( $i2i ) && null === $row( $i2i )->closed_at && 1 === count( $cancel_jobs( $i2i ) ) );
	$bank->reset();
	$run_cancel( $i2i );
	$calls = $bank->calls( '/vinov/cancelInstruction' );
	vnv_check( '(i) I-1: the job cancels by the row\'s instruction id, then closes the row', 1 === count( $calls ) && $base + 1402 === (int) $calls[0]['body']['Id'] && 3 === (int) $row( $i2i )->status && null !== $row( $i2i )->closed_at );
	// I-1: status 2 on a trashed order → alarm, never payment_complete.
	$i3  = $with_instruction( $base + 1403 );
	$i3i = $i3->get_id();
	$i3->delete( false );
	$i3 = wc_get_order( $i3i );
	foreach ( array_keys( $cancel_jobs( $i3->get_id() ) ) as $aid ) {
		ActionScheduler::store()->cancel_action( $aid );
	}
	$bank->statuses[ $base + 1403 ] = 2;
	$paid_before = $paid_hits;
	Eru_VNV_Sync::run();
	vnv_check( '(i) I-1: approved on a trashed order → alarm (flag, e-mail, action), no payment_complete, still trashed', $paid_before === $paid_hits && 'paid_while_cancelled' === $row( $i3->get_id() )->review && 'trash' === wc_get_order( $i3->get_id() )->get_status() && '' === (string) wc_get_order( $i3->get_id() )->get_meta( '_eru_vnv_paid_at' ) && 1 === count( array_filter( $pwc, fn( $x ) => $x[0] === $i3->get_id() ) ) && 1 === count( array_filter( (array) $mails->getArrayCopy(), fn( $m ) => false !== strpos( (string) $m['message'], (string) $i3->get_order_number() ) ) ) );
	// I-1: status 2 on a deleted order → alarm on the kept row.
	$i4  = $with_instruction( $base + 1404 );
	$i4i = $i4->get_id();
	$i4->delete( true );
	foreach ( array_keys( $cancel_jobs( $i4i ) ) as $aid ) {
		ActionScheduler::store()->cancel_action( $aid );
	}
	$bank->statuses[ $base + 1404 ] = 2;
	Eru_VNV_Sync::run();
	vnv_check( '(i) I-1: approved on a deleted order → row flagged paid_while_cancelled and closed, action and e-mail once', 'paid_while_cancelled' === $row( $i4i )->review && null !== $row( $i4i )->closed_at && 1 === count( array_filter( $pwc, fn( $x ) => $x[0] === $i4i ) ) && 1 === count( array_filter( (array) $mails->getArrayCopy(), fn( $m ) => false !== strpos( (string) $m['message'], '#' . $i4i ) ) ) && false !== strpos( Eru_VNV_Admin::review_notice_html(), '#' . $i4i ) );
	// I-1: an unresolved attempt on a deleted order cannot be looked up any more → flagged for review.
	$i5  = $amb( 'KEYAMBI005' );
	$i5i = $i5->get_id();
	$i5->delete( true );
	vnv_check( '(i) I-1: deleted order with an unresolved attempt → row kept, flagged deleted_unresolved', null !== $row( $i5i ) && 'deleted_unresolved' === $row( $i5i )->review );

	// I-2: a custom pending status still completes the payment.
	register_post_status( 'wc-vnv-onay', array( 'label' => 'Demo onay', 'public' => false, 'internal' => true ) );
	add_filter( 'wc_order_statuses', fn( $st ) => $st + array( 'wc-vnv-onay' => 'Demo onay bekliyor' ) );
	Eru_VNV_Settings::update( array( 'pending_status' => 'vnv-onay' ) );
	$i6 = $with_instruction( $base + 1406, 'vnv-onay' );
	$bank->statuses[ $base + 1406 ] = 2;
	Eru_VNV_Sync::run();
	$i6 = wc_get_order( $i6->get_id() );
	vnv_check( '(i) I-2: approved instruction on the custom pending status → processing with a paid date', 'vnv-onay' === Eru_VNV_Settings::pending_status() && 'processing' === $i6->get_status() && null !== $i6->get_date_paid() );
	Eru_VNV_Settings::update( array( 'pending_status' => 'on-hold' ) );

	// M-1: a late adoption after the stock hold ran out reduces stock and is flagged for review.
	$keep = $product;
	$sp   = new WC_Product_Simple();
	$sp->set_name( 'Demo Stoklu Ürün ' . VNV_RUN );
	$sp->set_regular_price( '845.90' );
	$sp->set_manage_stock( true );
	$sp->set_stock_quantity( 5 );
	$sp->set_status( 'publish' );
	$sp->update_meta_data( '_vnv_test', VNV_RUN );
	$product = $sp->save();
	$i7      = $amb( 'KEYAMBI007' );
	$i8      = $amb( 'KEYAMBI008' );
	$product = $keep;
	$wpdb->insert( $wpdb->prefix . 'wc_reserved_stock', array( 'order_id' => $i8->get_id(), 'product_id' => $sp->get_id(), 'stock_quantity' => 1, 'timestamp' => gmdate( 'Y-m-d H:i:s' ), 'expires' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) ); // phpcs:ignore
	$bank->window = array(
		array( 'Id' => $base + 1407, 'InvoiceNumber' => (string) $i7->get_order_number(), 'InstructionDescription' => 'Sipariş ' . $i7->get_order_number() . ' / KEYAMBI007', 'InstructionAmount' => (float) $i7->get_total(), 'InstructionStatusCode' => 1 ),
		array( 'Id' => $base + 1408, 'InvoiceNumber' => (string) $i8->get_order_number(), 'InstructionDescription' => 'Sipariş ' . $i8->get_order_number() . ' / KEYAMBI008', 'InstructionAmount' => (float) $i8->get_total(), 'InstructionStatusCode' => 1 ),
	);
	$bank->statuses[ $base + 1407 ] = 1;
	$bank->statuses[ $base + 1408 ] = 1;
	Eru_VNV_Sync::run();
	$bank->window = array();
	vnv_check( '(i) M-1: adopted after the hold expired → stock reduced, flagged stock_released, note', 'on-hold' === wc_get_order( $i7->get_id() )->get_status() && 'stock_released' === $row( $i7->get_id() )->review && false !== strpos( vnv_notes( $i7->get_id() ), 'stok' ) && 3 === (int) wc_get_product( $sp->get_id() )->get_stock_quantity() );
	vnv_check( '(i) M-1: adopted while the hold is still reserved → no flag', '' === $row( $i8->get_id() )->review );
	$wpdb->delete( $wpdb->prefix . 'wc_reserved_stock', array( 'order_id' => $i8->get_id() ) ); // phpcs:ignore

	// M-2: adopted onto an order already paid with another gateway → status-first bank cancel; approval → alarm once.
	$i9 = $amb( 'KEYAMBI009' );
	$i9->set_payment_method( 'bacs' );
	$i9->save();
	$i9 = wc_get_order( $i9->get_id() );
	$i9->payment_complete();
	$i10 = $amb( 'KEYAMBI010' );
	$i10->set_payment_method( 'bacs' );
	$i10->save();
	wc_get_order( $i10->get_id() )->payment_complete();
	$bank->window = array(
		array( 'Id' => $base + 1409, 'InvoiceNumber' => (string) $i9->get_order_number(), 'InstructionDescription' => 'Sipariş ' . $i9->get_order_number() . ' / KEYAMBI009', 'InstructionAmount' => (float) $i9->get_total(), 'InstructionStatusCode' => 1 ),
		array( 'Id' => $base + 1410, 'InvoiceNumber' => (string) $i10->get_order_number(), 'InstructionDescription' => 'Sipariş ' . $i10->get_order_number() . ' / KEYAMBI010', 'InstructionAmount' => (float) $i10->get_total(), 'InstructionStatusCode' => 1 ),
	);
	$bank->statuses[ $base + 1409 ] = 1;
	$bank->statuses[ $base + 1410 ] = 1;
	Eru_VNV_Sync::run();
	$bank->window = array();
	vnv_check( '(i) M-2: adopted onto an order paid elsewhere → bank cancel queued, order unchanged', 1 === count( $cancel_jobs( $i9->get_id() ) ) && 'processing' === wc_get_order( $i9->get_id() )->get_status() && 1 === count( $cancel_jobs( $i10->get_id() ) ) );
	$bank->reset();
	$run_cancel( $i9->get_id() );
	vnv_check( '(i) M-2: status 1 → one cancel call, order stays processing', 1 === $bank->count( '/vinov/cancelInstruction' ) && 'processing' === wc_get_order( $i9->get_id() )->get_status() && 3 === (int) $row( $i9->get_id() )->status );
	$bank->statuses[ $base + 1410 ] = 2;
	$bank->reset();
	$run_cancel( $i10->get_id() );
	Eru_VNV_Sync::run();
	Eru_VNV_Sync::bank_cancel_job( $i10->get_id(), 1 );
	vnv_check( '(i) M-2: approved while paid elsewhere → no cancel call, alarm once (paid_elsewhere)', 0 === $bank->count( '/vinov/cancelInstruction' ) && 'paid_elsewhere' === $row( $i10->get_id() )->review && 1 === count( array_filter( $pwc, fn( $x ) => $x[0] === $i10->get_id() ) ) && 1 === count( array_filter( (array) $mails->getArrayCopy(), fn( $m ) => false !== strpos( (string) $m['message'], (string) $i10->get_order_number() ) ) ) );

	// R2-1: reopened between the status read and the lock → mark_cancelled cancels the order, row closed 3.
	$i11 = $with_instruction( $base + 1411 );
	$i11->update_status( 'cancelled' );
	$bank->reset();
	$bank->push(
		'/vinov/listInstruction',
		function () use ( $i11, $base ) {
			wc_get_order( $i11->get_id() )->update_status( 'on-hold', 'Demo: yeniden açıldı' );
			return VNV_Fake_Bank::resp( 200, VNV_Fake_Bank::list_body( array( array( 'Id' => $base + 1411, 'InvoiceNumber' => 'DEMO-011', 'InstructionDescription' => 'Demo', 'InstructionAmount' => 10.0, 'InstructionStatusCode' => 3 ) ) ) );
		}
	);
	$run_cancel( $i11->get_id() );
	vnv_check( '(i) R2-1: status 3 read, order reopened before the lock → order cancelled, row closed 3, no cancel call', 'cancelled' === wc_get_order( $i11->get_id() )->get_status() && 3 === (int) $row( $i11->get_id() )->status && 0 === $bank->count( '/vinov/cancelInstruction' ) );

	// R2-2: a dismissed non-alarm flag raised again is visible again.
	$i12 = $with_instruction( $base + 1412 );
	$r12 = $row( $i12->get_id() );
	Eru_VNV_Instructions::flag( $r12->id, 'cancel_failed' );
	Eru_VNV_Instructions::ack( $i12->get_id() );
	vnv_check( '(i) R2-2: dismissed cancel_failed is hidden', false === strpos( Eru_VNV_Admin::review_notice_html(), '#' . $i12->get_order_number() ) );
	Eru_VNV_Instructions::flag( $r12->id, 'cancel_failed' );
	vnv_check( '(i) R2-2: raised again → visible again', false !== strpos( Eru_VNV_Admin::review_notice_html(), '#' . $i12->get_order_number() ) );

	// --- (j) manual cancel on an order paid with another gateway: status untouched -----------------------
	$keep = $product;
	$jp   = new WC_Product_Simple();
	$jp->set_name( 'Demo Stoklu Ürün J ' . VNV_RUN );
	$jp->set_regular_price( '459.90' );
	$jp->set_manage_stock( true );
	$jp->set_stock_quantity( 7 );
	$jp->set_status( 'publish' );
	$jp->update_meta_data( '_vnv_test', VNV_RUN );
	$product = $jp->save();
	$j1      = $with_instruction( $base + 1501 );
	$j2      = $with_instruction( $base + 1502 );
	$product = $keep;
	foreach ( array( $j1, $j2 ) as $jo ) {
		$jo = wc_get_order( $jo->get_id() );
		$jo->set_payment_method( 'bacs' );
		$jo->save();
		wc_get_order( $jo->get_id() )->payment_complete();
	}
	$bank->statuses[ $base + 1501 ] = 1;
	$bank->statuses[ $base + 1502 ] = 1;
	$stock0  = (int) wc_get_product( $jp->get_id() )->get_stock_quantity();
	$changes = array();
	$track   = function ( $id, $from, $to ) use ( &$changes ) {
		$changes[] = array( $id, $from, $to );
	};
	add_action( 'woocommerce_order_status_changed', $track, 10, 3 );
	$mails->exchangeArray( array() );
	$bank->reset();
	$c  = $cli( 'eru-vnv cancel ' . ( $base + 1501 ) . ' --yes' );
	$c2 = $cli( 'eru-vnv cancel ' . ( $base + 1501 ) . ' --yes' );
	$r  = Eru_VNV_Order_Box::request( array( 'order_id' => (string) $j2->get_id(), 'do' => 'cancel', 'confirm' => '1', 'nonce' => wp_create_nonce( 'eru_vnv_order_' . $j2->get_id() ) ) );
	vnv_check( '(j) CLI and ajax cancel on orders paid elsewhere → accepted, no bank call yet, status-first job queued', 0 === $c->return_code && 0 === $c2->return_code && $r['ok'] && 0 === $bank->count( '/vinov/cancelInstruction' ) && 1 === count( $cancel_jobs( $j1->get_id() ) ) && 1 === count( $cancel_jobs( $j2->get_id() ) ) );
	$run_cancel( $j1->get_id() );
	$run_cancel( $j2->get_id() );
	remove_action( 'woocommerce_order_status_changed', $track, 10 );
	$own = fn( $o ) => array_filter( $changes, fn( $x ) => (int) $x[0] === $o->get_id() );
	vnv_check( '(j) the job reads the status and cancels both instructions at the bank (rows closed 3)', 2 === $bank->count( '/vinov/cancelInstruction' ) && 3 === (int) $row( $j1->get_id() )->status && 3 === (int) $row( $j2->get_id() )->status );
	vnv_check( '(j) order status never changed (still processing, no transition)', 'processing' === wc_get_order( $j1->get_id() )->get_status() && 'processing' === wc_get_order( $j2->get_id() )->get_status() && ! $own( $j1 ) && ! $own( $j2 ) );
	vnv_check( '(j) no stock moved and no e-mail to the customer', $stock0 === (int) wc_get_product( $jp->get_id() )->get_stock_quantity() && ! array_filter( (array) $mails->getArrayCopy(), fn( $m ) => false !== strpos( wp_json_encode( $m['to'] ), 'vnv-lc-' . VNV_RUN ) ) );
	$j3 = $with_instruction( $base + 1503 );
	$j3->set_payment_method( 'bacs' );
	$j3->set_date_paid( time() - HOUR_IN_SECONDS );
	$j3->save();
	$bank->statuses[ $base + 1503 ] = 1;
	$bank->reset();
	$r = Eru_VNV_Order_Box::request( array( 'order_id' => (string) $j3->get_id(), 'do' => 'cancel', 'confirm' => '1', 'nonce' => wp_create_nonce( 'eru_vnv_order_' . $j3->get_id() ) ) );
	$run_cancel( $j3->get_id() );
	vnv_check( '(j) paid date set but status on-hold → the requested cancel still runs (one call), status stays on-hold', $r['ok'] && 'queued' === $r['code'] && 1 === $bank->count( '/vinov/cancelInstruction' ) && 'on-hold' === wc_get_order( $j3->get_id() )->get_status() && 3 === (int) $row( $j3->get_id() )->status );
	vnv_check( '(j) a single "status not changed" note per order, even after two CLI calls', 1 === substr_count( vnv_notes( $j1->get_id() ), 'durumu değiştirilmedi' ) && 1 === substr_count( vnv_notes( $j2->get_id() ), 'durumu değiştirilmedi' ) );

	// --- deactivation / reactivation -----------------------------------------------------------------
	$unschedule();
	Eru_VNV_Cron::ensure();
	Eru_VNV_Cron::sync_schedules();
	Eru_VNV_Cron::deactivate();
	$left = as_get_scheduled_actions( array( 'group' => Eru_VNV_Cron::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
	vnv_check( 'deactivation unschedules every pending action of the group', ! $left );
	Eru_VNV_Cron::sync_schedules();
	vnv_check( 'reactivation re-arms the sync while rows are open', 1 === count( $pending() ) );

	// --- uninstall (without ERU_VNV_REMOVE_DATA) ---------------------------------------------------------
	set_transient( 'eru_vnv_tok_test_oob_0000000000000000', 'demo-tok', 600 );
	Eru_VNV_Lock::acquire( 'demo_' . VNV_RUN, 60 );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'vakifbank-vinov-integration-for-woocommerce/vakifbank-vinov-integration-for-woocommerce.php' );
	}
	include WP_PLUGIN_DIR . '/vakifbank-vinov-integration-for-woocommerce/uninstall.php';
	wp_cache_flush();
	$rest = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_vnv\\_%' OR option_name LIKE '\\_transient\\_eru\\_vnv\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eru\\_vnv\\_%' OR option_name = 'woocommerce_eru_vnv_settings'" ); // phpcs:ignore
	vnv_check( 'uninstall: options, tokens, locks, claims removed', 0 === $rest );
	vnv_check( 'uninstall: no pending actions left', ! as_get_scheduled_actions( array( 'group' => Eru_VNV_Cron::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	vnv_check( 'uninstall: instruction table and order meta kept without ERU_VNV_REMOVE_DATA', $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) && (string) $iid8 === (string) wc_get_order( $o8->get_id() )->get_meta( '_eru_vnv_instruction_id' ) );
	$notes_before = vnv_notes( $o8->get_id() );
	define( 'ERU_VNV_REMOVE_DATA', true );
	include WP_PLUGIN_DIR . '/vakifbank-vinov-integration-for-woocommerce/uninstall.php';
	wp_cache_flush();
	$o8 = wc_get_order( $o8->get_id() );
	vnv_check( 'uninstall with ERU_VNV_REMOVE_DATA: the table goes', null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	vnv_check( 'uninstall with ERU_VNV_REMOVE_DATA: order meta and notes are still kept', (string) $iid8 === (string) $o8->get_meta( '_eru_vnv_instruction_id' ) && '' !== (string) $o8->get_meta( '_eru_vnv_env' ) && $notes_before === vnv_notes( $o8->get_id() ) );
} catch ( Throwable $e ) {
	$failed = true;
	echo 'FAIL: ' . get_class( $e ) . ' ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
} finally {
	wp_set_current_user( $old_user );
	Eru_VNV_Instructions::maybe_install();
	foreach ( $users as $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $u );
	}
	foreach ( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_vnv_test', 'meta_value' => VNV_RUN, 'return' => 'ids' ) ) as $oid ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE order_id = %d", $oid ) ); // phpcs:ignore
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE instruction_id >= %d OR ( order_id >= %d AND order_id < %d )", 990000000000, 990000000, 991000000 ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'eru_vnv_vused_' . VNV_RUN ) . '%' ) ); // phpcs:ignore
	foreach ( (array) $wpdb->get_col( "SELECT order_id FROM {$table}" ) as $oid ) { // phpcs:ignore
		if ( ! wc_get_order( (int) $oid ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE order_id = %d", $oid ) ); // phpcs:ignore -- rows of orders this run deleted.
		}
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_reserved_stock WHERE order_id NOT IN (SELECT id FROM {$wpdb->prefix}wc_orders)" ); // phpcs:ignore
	vnv_purge();
	vnv_reset();
	remove_all_actions( 'woocommerce_payment_complete' );
	foreach ( array( Eru_VNV_Cron::SYNC, Eru_VNV_Cron::DAILY, Eru_VNV_Cron::CONN, Eru_VNV_Cron::CANCEL ) as $h ) {
		as_unschedule_all_actions( $h, null, Eru_VNV_Cron::GROUP );
	}
	delete_option( 'eru_vnv_conn_result' );
	if ( null === $old_settings ) {
		delete_option( Eru_VNV_Settings::OPTION );
	} else {
		update_option( Eru_VNV_Settings::OPTION, $old_settings, false );
	}
	if ( null === $old_flag ) {
		delete_option( Eru_VNV_Settings::FLAG );
	} else {
		update_option( Eru_VNV_Settings::FLAG, $old_flag, true );
	}
	// Restoring the settings can re-arm the daily job through the flag hook: unschedule last.
	foreach ( array( Eru_VNV_Cron::SYNC, Eru_VNV_Cron::DAILY, Eru_VNV_Cron::CONN, Eru_VNV_Cron::CANCEL ) as $h ) {
		as_unschedule_all_actions( $h, null, Eru_VNV_Cron::GROUP );
	}
	vnv_clear_log_files();
}

if ( $failed || ! empty( $GLOBALS['vnv_soft_failed'] ) ) {
	exit( 1 );
}
echo "ALL PASS\n";
