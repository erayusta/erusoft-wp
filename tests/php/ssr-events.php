<?php
/**
 * Server-Side Refunds — capture, refund/cancel events, dedupe, paid detection, payload shapes.
 *
 *   wp eval-file tests/php/ssr-events.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_SSR_Events' ) ) {
	echo "FAIL: Eru_SSR_Events missing (plugin not active?)\n";
	exit( 1 );
}

// Catch-all: any HTTP request is a failure in the events test (nothing may be sent here).
$http = array();
$guard = function ( $pre, $args, $url ) use ( &$http ) {
	$http[] = $url;
	echo 'FAIL unmocked HTTP ' . $url . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $guard, 10, 3 );

$demo_ga_key = 'demo-' . 'secret-abc';
$demo_mt_key = 'demo' . 'token123';
$opt      = Eru_SSR_Settings::OPTION;
$old_opt  = get_option( $opt, null );
$orders   = array();
$old_ck   = $_COOKIE;

$make_order = function () use ( &$orders ) {
	$order = wc_create_order();
	$fee   = new WC_Order_Item_Fee();
	$fee->set_name( 'Demo Ürün' );
	$fee->set_amount( 1000 );
	$fee->set_total( 1000 );
	$fee->set_tax_status( 'none' );
	$order->add_item( $fee );
	$order->calculate_totals( false );
	$order->set_status( 'pending' );
	$order->save();
	$orders[] = $order->get_id();
	return $order;
};
$paid_order = function () use ( $make_order ) {
	$order = $make_order();
	$order->payment_complete( 'DEMO-TX' );
	return wc_get_order( $order->get_id() );
};
$rows = function ( $order_id, $dest = null ) {
	global $wpdb;
	$t = Eru_SSR_Queue::table();
	$q = $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d" . ( $dest ? ' AND destination = %s' : '' ) . ' ORDER BY id', ...( $dest ? array( $order_id, $dest ) : array( $order_id ) ) ); // phpcs:ignore
	return $wpdb->get_results( $q ); // phpcs:ignore
};
$base = array(
	'ga4_enabled'         => 'yes',
	'ga4_measurement_id'  => 'G-DEMO000000',
	'ga4_api_secret'      => $demo_ga_key,
	'matomo_enabled'      => 'yes',
	'matomo_url'          => 'https://analytics.example.com/',
	'matomo_site_id'      => '1',
	'matomo_token'        => $demo_mt_key,
	'matomo_mode'         => 'a',
	'ev_partial'          => 'yes',
	'ev_full'             => 'yes',
	'ev_cancelled'        => 'yes',
	'ev_failed'           => 'yes',
	'send_user_id'        => 'no',
	'fallback_client_id'  => 'yes',
	'retention_days'      => '30',
);

try {
	Eru_SSR_Queue::maybe_install();
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Eru_SSR_Queue::table() ); // phpcs:ignore

	// (a) Both destinations off: no row, no HTTP, no scheduled action.
	update_option( $opt, array_merge( $base, array( 'ga4_enabled' => 'no', 'matomo_enabled' => 'no' ) ), false );
	$order = $paid_order();
	wc_create_refund( array( 'amount' => 100, 'order_id' => $order->get_id(), 'reason' => 'demo' ) );
	$check( '(a) both off: no queue row', 0 === count( $rows( $order->get_id() ) ) );
	$check( '(a) both off: no HTTP', 0 === count( $http ) );
	$check( '(a) both off: nothing scheduled', ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'eru_ssr_sweep' ) );

	// (b) client id capture.
	update_option( $opt, $base, false );
	$o = $make_order();
	$_COOKIE['_ga'] = 'GA1.1.111.222';
	Eru_SSR_Events::capture( $o );
	$o = wc_get_order( $o->get_id() );
	$check( '(b) _ga cookie GA1.1.111.222 -> client_id 111.222', '111.222' === $o->get_meta( '_eru_ssr_client_id' ) && '111.222' === Eru_SSR_Events::client_id( $o ) );
	unset( $_COOKIE['_ga'] );
	$o2 = $make_order();
	Eru_SSR_Events::capture( $o2 );
	$o2 = wc_get_order( $o2->get_id() );
	$fb = $o2->get_id() . '.' . $o2->get_date_created()->getTimestamp();
	$check( '(b) no cookie: fallback <id>.<ts>', '' === (string) $o2->get_meta( '_eru_ssr_client_id' ) && $fb === Eru_SSR_Events::client_id( $o2 ) );
	$check( '(b) user id absent when disabled', '' === (string) $o->get_meta( '_eru_ssr_user_id' ) );
	update_option( $opt, array_merge( $base, array( 'fallback_client_id' => 'no' ) ), false );
	$check( '(b) fallback off and no cookie: no client id', '' === Eru_SSR_Events::client_id( $o2 ) );
	update_option( $opt, array_merge( $base, array( 'send_user_id' => 'yes' ) ), false );
	$u = $make_order();
	$u->set_customer_id( 1 );
	$u->save();
	$_COOKIE['_ga'] = 'GA1.2.333.444';
	Eru_SSR_Events::capture( $u );
	unset( $_COOKIE['_ga'] );
	$u = wc_get_order( $u->get_id() );
	$check( '(b) user id stored only when enabled', '1' === (string) $u->get_meta( '_eru_ssr_user_id' ) && '333.444' === $u->get_meta( '_eru_ssr_client_id' ) );

	// (c) partial refund 300.
	update_option( $opt, $base, false );
	$order = $paid_order();
	$_COOKIE['_ga'] = 'GA1.1.111.222';
	Eru_SSR_Events::capture( $order );
	unset( $_COOKIE['_ga'] );
	$order = wc_get_order( $order->get_id() );
	$refund = wc_create_refund( array( 'amount' => 300, 'order_id' => $order->get_id(), 'reason' => 'demo' ) );
	$check( '(c) refund created', ! is_wp_error( $refund ) );
	$ga = $rows( $order->get_id(), 'ga4' );
	$mt = $rows( $order->get_id(), 'matomo' );
	$check( '(c) exactly one row per destination', 1 === count( $ga ) && 1 === count( $mt ) );
	$num = $order->get_order_number();
	$gb  = Eru_SSR_GA4::build_body( json_decode( $ga[0]->payload, true ) );
	$check( '(c) GA4 body: client_id, refund event, value 300, transaction_id = order number', '111.222' === $gb['client_id'] && 'refund' === $gb['events'][0]['name'] && 300.0 === (float) $gb['events'][0]['params']['value'] && (string) $num === (string) $gb['events'][0]['params']['transaction_id'] && ! isset( $gb['user_id'] ) );
	$check( '(c) payload records event type partial', 'partial' === json_decode( $ga[0]->payload, true )['eru_ssr_event_type'] );
	$mp = Eru_SSR_Matomo::build_params( json_decode( $mt[0]->payload, true ) );
	$check( '(c) Matomo mode A: revenue -300, deterministic ec_id', -300.0 === (float) $mp['revenue'] && $num . '-refund-' . $refund->get_id() === $mp['ec_id'] && '0' === (string) $mp['idgoal'] );
	update_option( $opt, array_merge( $base, array( 'matomo_mode' => 'b' ) ), false );
	$mb = Eru_SSR_Matomo::build_params( json_decode( $mt[0]->payload, true ) );
	$check( '(c) Matomo mode B: custom event', 'ecommerce' === $mb['e_c'] && 'refund' === $mb['e_a'] && (string) $num === (string) $mb['e_n'] && 300.0 === (float) $mb['e_v'] && ! isset( $mb['revenue'] ) );
	update_option( $opt, $base, false );

	// (f) double-fired hook is deduped.
	do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
	do_action( 'woocommerce_order_refunded', $order->get_id(), $refund->get_id() );
	$check( '(f) same refund fired again: still one row per destination', 1 === count( $rows( $order->get_id(), 'ga4' ) ) && 1 === count( $rows( $order->get_id(), 'matomo' ) ) );

	// (d) cancel after partial refund reverses only the remainder.
	$order = wc_get_order( $order->get_id() );
	$order->update_status( 'cancelled' );
	$ga = $rows( $order->get_id(), 'ga4' );
	$check( '(d) cancel adds one more GA4 row', 2 === count( $ga ) );
	$p = json_decode( $ga[1]->payload, true );
	$check( '(d) reversal is 700, not 1000', 700.0 === (float) $p['value'] && 'cancelled' === $p['eru_ssr_event_type'] );
	$mc = Eru_SSR_Matomo::build_params( json_decode( $rows( $order->get_id(), 'matomo' )[1]->payload, true ) );
	$check( '(d) Matomo cancel ec_id <number>-cancel, revenue -700', $num . '-cancel' === $mc['ec_id'] && -700.0 === (float) $mc['revenue'] );
	$order->update_status( 'processing' );
	$order->update_status( 'cancelled' );
	$check( '(d) second cancel is deduped', 2 === count( $rows( $order->get_id(), 'ga4' ) ) );

	// (e) cancel of an unpaid order: no row; cancel after paid: row.
	$unpaid = $make_order();
	$unpaid->update_status( 'on-hold' );
	$unpaid = wc_get_order( $unpaid->get_id() );
	$unpaid->update_status( 'cancelled' );
	$check( '(e) unpaid pending/on-hold order cancelled: no row', 0 === count( $rows( $unpaid->get_id() ) ) );
	$paid = $paid_order();
	$paid->update_status( 'cancelled' );
	$r = $rows( $paid->get_id(), 'ga4' );
	$check( '(e) paid order cancelled: row with full 1000', 1 === count( $r ) && 1000.0 === (float) json_decode( $r[0]->payload, true )['value'] );
	$failed = $paid_order();
	$failed->update_status( 'failed' );
	$check( '(e) paid order failed: row', 1 === count( $rows( $failed->get_id(), 'ga4' ) ) );

	// Event toggles + filter.
	update_option( $opt, array_merge( $base, array( 'ev_partial' => 'no' ) ), false );
	$t = $paid_order();
	wc_create_refund( array( 'amount' => 50, 'order_id' => $t->get_id(), 'reason' => 'demo' ) );
	$check( 'partial refund toggle off: no row', 0 === count( $rows( $t->get_id() ) ) );
	update_option( $opt, $base, false );
	$veto = function ( $send, $dest ) {
		return 'ga4' === $dest ? false : $send;
	};
	add_filter( 'eru_ssr_should_send', $veto, 10, 2 );
	$t2 = $paid_order();
	wc_create_refund( array( 'amount' => 50, 'order_id' => $t2->get_id(), 'reason' => 'demo' ) );
	remove_filter( 'eru_ssr_should_send', $veto, 10 );
	$check( 'eru_ssr_should_send can veto one destination', 0 === count( $rows( $t2->get_id(), 'ga4' ) ) && 1 === count( $rows( $t2->get_id(), 'matomo' ) ) );

	// Inert default: capture writes nothing when no destination is on.
	update_option( $opt, array_merge( $base, array( 'ga4_enabled' => 'no', 'matomo_enabled' => 'no', 'send_user_id' => 'yes' ) ), false );
	$q = $make_order();
	$q->set_customer_id( 1 );
	$q->save();
	$_COOKIE['_ga'] = 'GA1.1.555.666';
	Eru_SSR_Events::capture( $q );
	unset( $_COOKIE['_ga'] );
	$q = wc_get_order( $q->get_id() );
	$check( 'capture() stores no order meta while both destinations are off', '' === (string) $q->get_meta( '_eru_ssr_client_id' ) && '' === (string) $q->get_meta( '_eru_ssr_user_id' ) );
	update_option( $opt, $base, false );

	// Cancel after paid, then a later refund on the same order: the money is reversed once.
	$cr = $paid_order();
	$cr->update_status( 'cancelled' );
	$check( 'cancel-then-refund: cancel row written (1000)', 1 === count( $rows( $cr->get_id(), 'ga4' ) ) );
	$cr = wc_get_order( $cr->get_id() );
	wc_create_refund( array( 'amount' => 400, 'order_id' => $cr->get_id(), 'reason' => 'demo' ) );
	$check( 'cancel-then-refund: later refund adds no second reversal', 1 === count( $rows( $cr->get_id(), 'ga4' ) ) && 1 === count( $rows( $cr->get_id(), 'matomo' ) ) );

	// (j) guard is active: nothing in this file reached HTTP.
	$check( '(j) no HTTP requests were made by capture/event code', 0 === count( $http ) );
	$check( '(j) catch-all guard installed', false !== has_filter( 'pre_http_request', $guard ) );
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
	$failed_run = true;
} finally {
	remove_filter( 'pre_http_request', $guard, 10 );
	$_COOKIE = $old_ck;
	foreach ( $orders as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
	}
	$wpdb->query( 'DELETE FROM ' . Eru_SSR_Queue::table() ); // phpcs:ignore
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_ssr_sweep' );
		as_unschedule_all_actions( 'eru_ssr_process' );
		as_unschedule_all_actions( 'eru_ssr_purge' );
	}
	null === $old_opt ? delete_option( $opt ) : update_option( $opt, $old_opt, false );
}
if ( ! empty( $failed_run ) ) {
	exit( 1 );
}
echo "ALL PASS\n";
