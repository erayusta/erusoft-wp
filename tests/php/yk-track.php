<?php
/**
 * Yurtiçi Kargo Integration — tracker: delivered / NOP / no-detail back-off / self-heal and the
 * oldest-last-poll-first ordering. Every HTTP call is answered from invented fixtures; anything else exits 1.
 *
 *   wp plugin activate yurtici-kargo-integration-for-woocommerce
 *   wp eval-file tests/php/yk-track.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

foreach ( array( 'Eru_YK_Settings', 'Eru_YK_Tracker', 'Eru_YK_Dispatcher' ) as $eru_yk_class ) {
	if ( ! class_exists( $eru_yk_class ) ) {
		echo "FAIL: {$eru_yk_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

$fx = function ( $name, $key ) {
	return str_replace( array( '{{KEY}}', '{{INVOICE}}' ), array( htmlspecialchars( $key, ENT_XML1 ), 'X' ), file_get_contents( __DIR__ . '/yk-fixtures/' . $name ) );
};

$reqs   = array();
$answer = array(); // cargo key => fixture mode (per account user: "key|user").
$router = function ( $pre, $args, $url ) use ( &$reqs, &$answer, $fx ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( wp_parse_url( home_url(), PHP_URL_HOST ) === $host && false !== strpos( $url, 'wp-cron.php' ) ) {
		return new WP_Error( 'eru_yk_test', 'loopback cron blocked in tests' ); // Core's cron spawn, not a plugin call.
	}
	if ( ! in_array( $host, array( 'ws.yurticikargo.com', 'testws.yurticikargo.com' ), true ) ) {
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	}
	$dom = new DOMDocument();
	$dom->loadXML( (string) $args['body'], LIBXML_NONET );
	$xp  = new DOMXPath( $dom );
	$op  = $xp->query( '//*[local-name()="Body"]/*' )->item( 0 )->localName;
	$key = $xp->query( '//*[local-name()="keys"]' )->item( 0 )->textContent ?? '';
	$usr = $xp->query( '//*[local-name()="wsUserName"]' )->item( 0 )->textContent ?? '';
	if ( 'queryShipment' !== $op ) {
		echo 'FAIL unexpected operation ' . $op . "\n";
		exit( 1 );
	}
	$reqs[] = array( 'key' => $key, 'user' => $usr );
	$mode   = $answer[ $key . '|' . $usr ] ?? ( $answer[ $key ] ?? 'notfound' );
	return array( 'headers' => array(), 'body' => $fx( 'query-' . $mode . '.xml', $key ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $router, 10, 3 );

// The site's own settings are parked in a backup option once, so an aborted run cannot leave test settings behind.
if ( false === get_option( 'eru_yk_test_backup', false ) ) {
	add_option( 'eru_yk_test_backup', array( 'value' => get_option( Eru_YK_Settings::OPTION, null ) ), '', false );
}
$cleanup      = function () {
	global $wpdb;
	Eru_YK_Lock::release( 'track' );
	foreach ( wc_get_orders( array( 'created_via' => 'eru-yk-test', 'limit' => -1 ) ) as $o ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_YK_Logger::log_table() . ' WHERE order_id = %d', $o->get_id() ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_YK_Logger::event_table() . ' WHERE order_id = %d', $o->get_id() ) ); // phpcs:ignore
		$o->delete( true );
	}
};
$restore = function () use ( $cleanup ) {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$cleanup();
	$saved = get_option( 'eru_yk_test_backup', false );
	if ( false !== $saved ) {
		if ( null === $saved['value'] ) {
			delete_option( Eru_YK_Settings::OPTION );
		} else {
			update_option( Eru_YK_Settings::OPTION, $saved['value'], false );
		}
		delete_option( 'eru_yk_test_backup' );
	}
	Eru_YK_Settings::flush();
	if ( ! Eru_YK_Settings::is_enabled() ) {
		foreach ( array( 'eru_yk_track', 'eru_yk_daily' ) as $h ) {
			as_unschedule_all_actions( $h, array(), 'eru-yk' );
		}
	}
};
// Runs on exit(1) too, so a failed run never leaves test settings or test orders behind.
register_shutdown_function( $restore );
$cleanup();
update_option(
	Eru_YK_Settings::OPTION,
	array_merge(
		Eru_YK_Settings::defaults(),
		array(
			'enabled'          => 'yes',
			'accounts'         => array(
				array( 'id' => 'demoa', 'label' => 'Ana hesap', 'username' => 'demo-user-a', 'password' => implode( '-', array( 'demo', 'pass', 'a' ) ), 'prefix' => 'TA', 'customer_code' => '' ),
				array( 'id' => 'demob', 'label' => 'Yüksek tutar', 'username' => 'demo-user-b', 'password' => implode( '-', array( 'demo', 'pass', 'b' ) ), 'prefix' => 'TB', 'customer_code' => '' ),
			),
			'delivered_status' => 'completed',
			'poll_dispatched'  => 30,
			'poll_transit'     => 60,
			'giveup'           => 100,
		)
	),
	false
);
Eru_YK_Settings::flush();

// A dispatched shipment, written the way the dispatcher writes it.
$make = function ( $suffix, $last_polled, $account = 'demoa', $status = 'processing' ) {
	$o = new WC_Order();
	$o->set_created_via( 'eru-yk-test' );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( 'Alıcı' );
	$o->set_billing_email( 'ornek@example.com' );
	$o->set_status( $status );
	$o->save();
	$o->update_meta_data( '_eru_yk_state', 'dispatched' );
	$o->update_meta_data( '_eru_yk_cargo_key', 'TA' . $o->get_id() . $suffix );
	$o->update_meta_data( '_eru_yk_account', $account );
	$o->update_meta_data( '_eru_yk_last_polled_at', $last_polled );
	$o->update_meta_data( '_eru_yk_next_poll_at', time() - 60 );
	$o->update_meta_data( '_eru_yk_no_detail', 0 );
	$o->save();
	return $o;
};
$fresh = fn( $o ) => wc_get_order( $o->get_id() );
$key   = fn( $o ) => (string) $fresh( $o )->get_meta( '_eru_yk_cargo_key' );

// ---------------------------------------------------------------------------------------------
// (g) Delivered → target status + note; NOP → unchanged; no-detail ×21 → back-off spacing.
// ---------------------------------------------------------------------------------------------
$od = $make( '', 0 );
$answer[ $key( $od ) ] = 'delivered';
$r = Eru_YK_Tracker::track( $fresh( $od ) );
$o = $fresh( $od );
$check( '(g) delivered fixture → delivered state, target status completed', 'delivered' === $r && 'delivered' === $o->get_meta( '_eru_yk_state' ) && 'completed' === $o->get_status() );
$check( '(g) delivered adds a note', (bool) array_filter( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ), fn( $n ) => false !== strpos( $n->content, 'teslim edildi' ) ) );

$on = $make( '', 0 );
$answer[ $key( $on ) ] = 'nop';
$r = Eru_YK_Tracker::track( $fresh( $on ) );
$o = $fresh( $on );
$check( '(g) NOP → ignored, state and status unchanged', 'ignored' === $r && 'dispatched' === $o->get_meta( '_eru_yk_state' ) && 'processing' === $o->get_status() );
$check( '(g) NOP → next poll after the dispatched interval (30 min)', abs( (int) $o->get_meta( '_eru_yk_next_poll_at' ) - ( time() + 1800 ) ) <= 5 );

$ox = $make( '', 0 );
$o  = $fresh( $ox );
$o->update_meta_data( '_eru_yk_no_detail', 20 );
$o->save();
$n0 = count( $reqs );
$r  = Eru_YK_Tracker::track( $fresh( $ox ) );
$o  = $fresh( $ox );
$check( '(g) no-detail: every account asked once', 'no_detail' === $r && 2 === count( $reqs ) - $n0 && 'demo-user-a' === $reqs[ $n0 ]['user'] && 'demo-user-b' === $reqs[ $n0 + 1 ]['user'] );
$check( '(g) 21st no-detail poll → count 21, next poll 2 × 30 min later', 21 === (int) $o->get_meta( '_eru_yk_no_detail' ) && abs( (int) $o->get_meta( '_eru_yk_next_poll_at' ) - ( time() + 3600 ) ) <= 5 );
$check( '(g) back-off schedule: 25th → 16 × base, capped at 1 day', 16 * 1800 === Eru_YK_Tracker::interval( 'dispatched', 24 ) && DAY_IN_SECONDS === Eru_YK_Tracker::interval( 'dispatched', 60 ) && 1800 === Eru_YK_Tracker::interval( 'dispatched', 20 ) );
$o->update_meta_data( '_eru_yk_no_detail', 99 );
$o->update_meta_data( '_eru_yk_next_poll_at', time() - 1 );
$o->save();
$check( '(g) give-up after 100 no-detail polls', 'gave_up' === Eru_YK_Tracker::track( $fresh( $ox ) ) && 'gave_up' === $fresh( $ox )->get_meta( '_eru_yk_state' ) );

// An auth/service error is not "no record": it never counts toward giving up.
$oe = $make( '', 0 );
$answer[ $key( $oe ) ] = 'autherror';
$r = Eru_YK_Tracker::track( $fresh( $oe ) );
$check( '(g) answered auth error → error, no-detail counter unchanged', 'error' === $r && 0 === (int) $fresh( $oe )->get_meta( '_eru_yk_no_detail' ) && 'dispatched' === $fresh( $oe )->get_meta( '_eru_yk_state' ) );
$o = $fresh( $oe );
$o->update_meta_data( '_eru_yk_state', 'done-test' );
$o->save();

// Self-heal: the record lives in the other account.
$oh = $make( '', 0, 'demoa' );
$answer[ $key( $oh ) . '|demo-user-b' ] = 'transit';
$r = Eru_YK_Tracker::track( $fresh( $oh ) );
$o = $fresh( $oh );
$check( '(g) found in the other account → account meta healed, in transit', 'in_transit' === $r && 'demob' === $o->get_meta( '_eru_yk_account' ) && 'in_transit' === $o->get_meta( '_eru_yk_state' ) && 'processing' === $o->get_status() );

foreach ( array( $od, $on, $ox, $oh ) as $o ) {
	$o = $fresh( $o );
	$o->update_meta_data( '_eru_yk_state', 'done-test' );
	$o->save();
}

// ---------------------------------------------------------------------------------------------
// (h) Oldest last_polled_at first, even when statuses change between runs.
// ---------------------------------------------------------------------------------------------
$base = time() - 10 * DAY_IN_SECONDS;
$o1   = $make( '', $base + 300 );
$o2   = $make( '', $base + 100 );
$o3   = $make( '', $base + 200 );
foreach ( array( $o1, $o2, $o3 ) as $o ) {
	$answer[ $key( $o ) ] = 'nop';
}
$o = $fresh( $o2 );
$o->set_status( 'on-hold' );
$o->save();
$one = fn() => 1;
add_filter( 'eru_yk_track_batch_size', $one );
$n0 = count( $reqs );
Eru_YK_Tracker::run();
$check( '(h) run 1 polls the oldest last_polled_at (status changed meanwhile)', count( $reqs ) === $n0 + 1 && $key( $o2 ) === $reqs[ $n0 ]['key'] );
$o = $fresh( $o3 );
$o->set_status( 'completed' );
$o->save();
$n0 = count( $reqs );
Eru_YK_Tracker::run();
$check( '(h) run 2 polls the next oldest', count( $reqs ) === $n0 + 1 && $key( $o3 ) === $reqs[ $n0 ]['key'] );
$n0 = count( $reqs );
Eru_YK_Tracker::run();
$check( '(h) run 3 polls the remaining one', count( $reqs ) === $n0 + 1 && $key( $o1 ) === $reqs[ $n0 ]['key'] );
$n0 = count( $reqs );
Eru_YK_Tracker::run();
$check( '(h) nothing due → no request', count( $reqs ) === $n0 );
remove_filter( 'eru_yk_track_batch_size', $one );
$check( '(h) tracker lock released', Eru_YK_Lock::acquire( 'track', 60 ) );
Eru_YK_Lock::release( 'track' );

$restore();
echo "PASS: all yk-track checks\n";
