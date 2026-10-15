<?php
/**
 * Yurtiçi Kargo Integration — safety paths: explicit not-found before re-create, cancel/reset dead ends,
 * cancel response strictness, wrong-account cancel, lock contention, pending-only re-arm with a running
 * action, admin handler (capability, nonce, wrong order), label escaping, test endpoint requirement.
 * Every HTTP call is answered from invented fixtures; anything else exits 1.
 *
 *   wp plugin activate yurtici-kargo-integration-for-woocommerce
 *   wp eval-file tests/php/yk-safety.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

foreach ( array( 'Eru_YK_Settings', 'Eru_YK_Dispatcher', 'Eru_YK_Tracker', 'Eru_YK_Cron' ) as $eru_yk_class ) {
	if ( ! class_exists( $eru_yk_class ) ) {
		echo "FAIL: {$eru_yk_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}
foreach ( array( 'admin', 'label' ) as $eru_yk_f ) {
	require_once ERU_YK_PATH . 'includes/class-eru-yk-' . $eru_yk_f . '.php';
}

$fx = function ( $name, $key ) {
	return str_replace( array( '{{KEY}}', '{{INVOICE}}' ), array( htmlspecialchars( $key, ENT_XML1 ), 'X' ), file_get_contents( __DIR__ . '/yk-fixtures/' . $name ) );
};

$reqs  = array();
$modes = array( 'create' => 'ok', 'query' => 'notfound', 'cancel' => 'ok' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$reqs, &$modes, $fx ) {
		if ( wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) && false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_yk_test', 'loopback cron blocked in tests' ); // Not an outbound call.
		}
		if ( ! in_array( wp_parse_url( $url, PHP_URL_HOST ), array( 'ws.yurticikargo.com', 'test.example.com' ), true ) ) {
			echo 'FAIL unmocked HTTP ' . $url . "\n";
			exit( 1 );
		}
		$dom = new DOMDocument();
		$dom->loadXML( (string) $args['body'], LIBXML_NONET );
		$xp  = new DOMXPath( $dom );
		$op  = $xp->query( '//*[local-name()="Body"]/*' )->item( 0 )->localName;
		$v   = function ( $n ) use ( $xp ) {
			$l = $xp->query( '//*[local-name()="' . $n . '"]' );
			return $l->length ? $l->item( 0 )->textContent : '';
		};
		$key = $v( 'cargoKey' ) . $v( 'keys' ) . $v( 'cargoKeys' );
		$reqs[] = array( 'op' => $op, 'key' => $key, 'user' => $v( 'wsUserName' ), 'host' => wp_parse_url( $url, PHP_URL_HOST ) );
		$mode = array( 'createShipment' => $modes['create'], 'queryShipment' => $modes['query'], 'cancelShipment' => $modes['cancel'] )[ $op ] ?? null;
		$ok   = fn( $body, $code = 200 ) => array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
		if ( 'timeout' === $mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		if ( 'fault' === $mode ) {
			return $ok( $fx( 'fault.xml', $key ), 500 );
		}
		switch ( $op ) {
			case 'createShipment':
				return $ok( $fx( 'create-' . $mode . '.xml', $key ) );
			case 'queryShipment':
				return $ok( $fx( 'query-' . $mode . '.xml', $key ) );
			case 'cancelShipment':
				return $ok( $fx( 'cancel-' . $mode . '.xml', $key ) );
		}
		echo 'FAIL unmocked SOAP operation ' . $op . "\n";
		exit( 1 );
	},
	10,
	3
);
$ops = function ( $op ) use ( &$reqs ) {
	return count( array_filter( $reqs, fn( $r ) => $r['op'] === $op ) );
};
$pending_ids = function ( $hook, $args = null ) {
	$q = array( 'hook' => $hook, 'group' => 'eru-yk', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 20 );
	if ( null !== $args ) {
		$q['args'] = $args;
	}
	return as_get_scheduled_actions( $q, 'ids' );
};

// Settings backup / restore (also on exit(1)).
if ( false === get_option( 'eru_yk_test_backup', false ) ) {
	add_option( 'eru_yk_test_backup', array( 'value' => get_option( Eru_YK_Settings::OPTION, null ) ), '', false );
}
$cleanup = function () {
	global $wpdb;
	foreach ( wc_get_orders( array( 'created_via' => 'eru-yk-test', 'limit' => -1 ) ) as $o ) {
		Eru_YK_Lock::release( 'order_' . $o->get_id() );
		as_unschedule_all_actions( 'eru_yk_dispatch', array( $o->get_id() ), 'eru-yk' );
		as_unschedule_all_actions( 'eru_yk_dispatch', array( $o->get_id(), 1 ), 'eru-yk' );
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
	foreach ( array( 'eru_yk_track', 'eru_yk_daily' ) as $h ) {
		as_unschedule_all_actions( $h, array(), 'eru-yk' );
	}
};
register_shutdown_function( $restore );
$cleanup();

$settings = array_merge(
	Eru_YK_Settings::defaults(),
	array(
		'enabled'  => 'yes',
		'accounts' => array(
			array( 'id' => 'demoa', 'label' => 'Ana hesap', 'username' => 'demo-user-a', 'password' => implode( '-', array( 'demo', 'pass', 'a' ) ), 'prefix' => 'SA', 'customer_code' => '' ),
			array( 'id' => 'demob', 'label' => 'Yüksek tutar', 'username' => 'demo-user-b', 'password' => implode( '-', array( 'demo', 'pass', 'b' ) ), 'prefix' => 'SB', 'customer_code' => '' ),
		),
		'rules'    => array( array( 'account' => 'demob', 'min' => '1000', 'max' => '', 'methods' => array() ) ),
		'default_account' => 'demoa',
	)
);
$apply = function ( array $extra = array() ) use ( $settings ) {
	update_option( Eru_YK_Settings::OPTION, array_merge( $settings, $extra ), false );
	Eru_YK_Settings::flush();
};
$apply();

$make = function ( $total = 400, $last = 'Alıcı' ) {
	$o = new WC_Order();
	$o->set_created_via( 'eru-yk-test' );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( $last );
	$o->set_billing_address_1( 'Demo Sokak No: 1' );
	$o->set_billing_city( 'Konak' );
	$o->set_billing_state( 'TR35' );
	$o->set_billing_country( 'TR' );
	$o->set_billing_email( 'ornek@example.com' );
	$o->set_total( $total );
	$o->set_status( 'on-hold' );
	$o->save();
	return $o;
};
$fresh = fn( $o ) => wc_get_order( $o->get_id() );
$meta  = fn( $o, $k ) => (string) $fresh( $o )->get_meta( $k );

// ---------------------------------------------------------------------------------------------
// I1. After an ambiguous failure only an explicit not-found answer allows a new createShipment.
// ---------------------------------------------------------------------------------------------
$o = $make();
$modes['create'] = 'timeout';
Eru_YK_Dispatcher::dispatch( $o->get_id() );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $o->get_id() ), 'eru-yk' );
$check( '(I1) timeout → unknown', 'unknown' === $meta( $o, '_eru_yk_state' ) );
$modes['create'] = 'ok';
$modes['query']  = 'autherror';
$c0 = $ops( 'createShipment' );
$r  = Eru_YK_Dispatcher::dispatch( $o->get_id() );
$check( '(I1) lookup answered with a non-not-found error → needs_review, no create', 'needs_review' === $r && $c0 === $ops( 'createShipment' ) );
$modes['query'] = 'fault';
$r = Eru_YK_Dispatcher::dispatch( $o->get_id(), true );
$check( '(I1) lookup answered with a SOAP fault → needs_review, no create', 'needs_review' === $r && $c0 === $ops( 'createShipment' ) );
$modes['query'] = 'notfound';
$r = Eru_YK_Dispatcher::dispatch( $o->get_id(), true );
$check( '(I1) explicit not-found code → one create', 'created' === $r && $c0 + 1 === $ops( 'createShipment' ) );
$check( '(I1) not-found codes are a filterable constant', in_array( '820', Eru_YK_Client::not_found_codes(), true ) );

// ---------------------------------------------------------------------------------------------
// Cancel: response strictness, failure, wrong account.
// ---------------------------------------------------------------------------------------------
$key = $meta( $o, '_eru_yk_cargo_key' );
$modes['cancel'] = 'error';
$r = Eru_YK_Dispatcher::cancel( $o->get_id() );
$check( '(cancel) per-key errCode in shippingCancelDetailVO → failure, meta kept', ! $r['ok'] && $key === $meta( $o, '_eru_yk_cargo_key' ) && 'dispatched' === $meta( $o, '_eru_yk_state' ) && '' === $meta( $o, '_eru_yk_seq' ) );
$modes['cancel'] = 'unrecognised';
$r = Eru_YK_Dispatcher::cancel( $o->get_id() );
$check( '(cancel) unrecognised answer (no outFlag 0 / no detail for the key) → failure, meta kept', ! $r['ok'] && $key === $meta( $o, '_eru_yk_cargo_key' ) );
$modes['cancel'] = 'fault';
$r = Eru_YK_Dispatcher::cancel( $o->get_id() );
$check( '(cancel) SOAP fault → failure, meta kept', ! $r['ok'] && $key === $meta( $o, '_eru_yk_cargo_key' ) );

$x = $fresh( $o );
$x->set_total( 5000 ); // routing would now pick account B.
$x->save();
$modes['cancel'] = 'ok';
$n0 = count( $reqs );
$r  = Eru_YK_Dispatcher::cancel( $o->get_id() );
$check( '(cancel) uses the recorded account, not the current route', $r['ok'] && 'demo-user-a' === $reqs[ $n0 ]['user'] && $key === $reqs[ $n0 ]['key'] && '1' === $meta( $o, '_eru_yk_seq' ) );

$ow = $make();
Eru_YK_Dispatcher::dispatch( $ow->get_id() );
$x = $fresh( $ow );
$x->update_meta_data( '_eru_yk_account', 'removed-account' );
$x->save();
$n0 = count( $reqs );
$r  = Eru_YK_Dispatcher::cancel( $ow->get_id() );
$check( '(cancel) recorded account no longer exists → refused without HTTP, meta kept', ! $r['ok'] && count( $reqs ) === $n0 && '' !== $meta( $ow, '_eru_yk_cargo_key' ) );

// A queued dispatch job does not survive a cancel.
$oq = $make();
Eru_YK_Dispatcher::dispatch( $oq->get_id() );
as_schedule_single_action( time() + 300, 'eru_yk_dispatch', array( $oq->get_id(), 1 ), 'eru-yk' );
Eru_YK_Dispatcher::cancel( $oq->get_id() );
$check( '(cancel) pending dispatch jobs of the order are unscheduled', ! $pending_ids( 'eru_yk_dispatch', array( $oq->get_id(), 1 ) ) );

// ---------------------------------------------------------------------------------------------
// I2. Local reset: provider-cancelled and needs_review dead ends; the old key is never reused.
// ---------------------------------------------------------------------------------------------
$oc = $make();
Eru_YK_Dispatcher::dispatch( $oc->get_id() );
$oldkey = $meta( $oc, '_eru_yk_cargo_key' );
$x = $fresh( $oc );
$x->update_meta_data( '_eru_yk_state', 'cancelled' ); // as the tracker writes it on CNL.
$x->save();
$n0 = count( $reqs );
$check( '(I2) a provider-cancelled order is not re-dispatched with the old key', 'cancelled' === Eru_YK_Dispatcher::dispatch( $oc->get_id(), true ) && count( $reqs ) === $n0 );
$r = Eru_YK_Dispatcher::reset_local( $oc->get_id(), false );
$check( '(I2) local reset of a cancelled shipment: no HTTP, meta cleared, seq bumped, note', $r['ok'] && count( $reqs ) === $n0 && '' === $meta( $oc, '_eru_yk_cargo_key' ) && '1' === $meta( $oc, '_eru_yk_seq' ) && (bool) array_filter( wc_get_order_notes( array( 'order_id' => $oc->get_id() ) ), fn( $n ) => false !== strpos( $n->content, 'sıfırlandı' ) ) );
Eru_YK_Dispatcher::dispatch( $oc->get_id() );
$check( '(I2) next dispatch uses a new key (K1), never the old one', $oldkey . 'K1' === $meta( $oc, '_eru_yk_cargo_key' ) );

$on = $make();
$modes['create'] = 'timeout';
Eru_YK_Dispatcher::dispatch( $on->get_id() );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $on->get_id() ), 'eru-yk' );
$modes['query'] = 'timeout';
Eru_YK_Dispatcher::dispatch( $on->get_id() );
$check( '(I2) setup: needs_review', 'needs_review' === $meta( $on, '_eru_yk_state' ) );
$r = Eru_YK_Dispatcher::reset_local( $on->get_id(), false );
$check( '(I2) needs_review reset without the admin confirmation is refused', ! $r['ok'] && 'needs_review' === $meta( $on, '_eru_yk_state' ) );
wp_set_current_user( 1 );
$check( '(I2) admin reset of a needs_review order without confirm=1 → 409', 409 === Eru_YK_Admin::handle( 'reset', $on->get_id(), wp_create_nonce( 'eru_yk_order_' . $on->get_id() ) )['code'] && 'needs_review' === $meta( $on, '_eru_yk_state' ) );
$r = Eru_YK_Admin::handle( 'reset', $on->get_id(), wp_create_nonce( 'eru_yk_order_' . $on->get_id() ), true );
$r['ok'] = ! empty( $r['ok'] );
$check( '(I2) confirmed "gönderi oluşmadı" → reset, seq bumped', $r['ok'] && '' === $meta( $on, '_eru_yk_state' ) && '1' === $meta( $on, '_eru_yk_seq' ) );
$modes['create'] = 'ok';
$modes['query']  = 'notfound';
$od = $make();
Eru_YK_Dispatcher::dispatch( $od->get_id() );
$check( '(I2) a live shipment cannot be reset locally', ! Eru_YK_Dispatcher::reset_local( $od->get_id(), true )['ok'] && 'dispatched' === $meta( $od, '_eru_yk_state' ) );

// gave_up: reset only after a fresh lookup answers with an explicit not-found code.
$og = $make();
Eru_YK_Dispatcher::dispatch( $og->get_id() );
$gkey = $meta( $og, '_eru_yk_cargo_key' );
$x = $fresh( $og );
$x->update_meta_data( '_eru_yk_state', 'gave_up' );
$x->save();
$modes['query'] = 'transit';
$n0 = count( $reqs );
$r  = Eru_YK_Dispatcher::reset_local( $og->get_id(), true );
$check( '(N1) gave_up + lookup finds the record → reset refused, meta kept', ! $r['ok'] && 'gave_up' === $meta( $og, '_eru_yk_state' ) && $gkey === $meta( $og, '_eru_yk_cargo_key' ) && 'queryShipment' === ( $reqs[ $n0 ]['op'] ?? '' ) && false !== strpos( $r['message'], 'İptal' ) );
$modes['query'] = 'autherror';
$r = Eru_YK_Dispatcher::reset_local( $og->get_id(), true );
$check( '(N1) gave_up + other answered error → refused, meta kept', ! $r['ok'] && $gkey === $meta( $og, '_eru_yk_cargo_key' ) && '' === $meta( $og, '_eru_yk_seq' ) );
$modes['query'] = 'timeout';
$r = Eru_YK_Dispatcher::reset_local( $og->get_id(), true );
$check( '(N1) gave_up + no answer → refused, meta kept', ! $r['ok'] && $gkey === $meta( $og, '_eru_yk_cargo_key' ) );
$modes['query'] = 'notfound';
$r = Eru_YK_Dispatcher::reset_local( $og->get_id(), true );
$check( '(N1) gave_up + explicit not-found → reset ok, seq bumped', $r['ok'] && '' === $meta( $og, '_eru_yk_cargo_key' ) && '1' === $meta( $og, '_eru_yk_seq' ) );

foreach ( array( 'in_transit', 'delivered' ) as $st ) {
	$oi = $make();
	Eru_YK_Dispatcher::dispatch( $oi->get_id() );
	$x = $fresh( $oi );
	$x->update_meta_data( '_eru_yk_state', $st );
	$x->save();
	$n0 = count( $reqs );
	$check( '(N1) ' . $st . ' cannot be reset locally (no HTTP)', ! Eru_YK_Dispatcher::reset_local( $oi->get_id(), true )['ok'] && $st === $meta( $oi, '_eru_yk_state' ) && count( $reqs ) === $n0 );
}

// N2: reset_local itself refuses while the integration is off.
$oz = $make();
Eru_YK_Dispatcher::dispatch( $oz->get_id() );
$x = $fresh( $oz );
$x->update_meta_data( '_eru_yk_state', 'cancelled' );
$x->save();
$apply( array( 'enabled' => 'no' ) );
$check( '(N2) reset_local refuses while disabled', ! Eru_YK_Dispatcher::reset_local( $oz->get_id(), true )['ok'] && 'cancelled' === $meta( $oz, '_eru_yk_state' ) );
$apply();

// ---------------------------------------------------------------------------------------------
// Lock contention and a fresh "pending" from another worker.
// ---------------------------------------------------------------------------------------------
$ol = $make();
Eru_YK_Lock::acquire( 'order_' . $ol->get_id(), 60 );
$n0 = count( $reqs );
$check( '(lock) a second worker gets "locked" and sends nothing', 'locked' === Eru_YK_Dispatcher::dispatch( $ol->get_id() ) && count( $reqs ) === $n0 );
Eru_YK_Lock::release( 'order_' . $ol->get_id() );
$x = $fresh( $ol );
$x->update_meta_data( '_eru_yk_state', 'pending' );
$x->update_meta_data( '_eru_yk_pending_at', time() );
$x->save();
$check( '(lock) fresh pending → skipped and re-queued, no HTTP', 'pending' === Eru_YK_Dispatcher::dispatch( $ol->get_id() ) && count( $reqs ) === $n0 && (bool) $pending_ids( 'eru_yk_dispatch', array( $ol->get_id() ) ) );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $ol->get_id() ), 'eru-yk' );

// ---------------------------------------------------------------------------------------------
// Pending-only re-arm with a simulated RUNNING action.
// ---------------------------------------------------------------------------------------------
$or      = $make();
$running = as_schedule_single_action( time(), 'eru_yk_dispatch', array( $or->get_id() ), 'eru-yk' );
ActionScheduler::store()->log_execution( $running );
$modes['create'] = 'timeout';
Eru_YK_Dispatcher::dispatch( $or->get_id() );
$ids = $pending_ids( 'eru_yk_dispatch', array( $or->get_id() ) );
$check( '(re-arm) retry is scheduled while the current action is still running', 1 === count( $ids ) && (int) reset( $ids ) !== (int) $running );
ActionScheduler::store()->mark_complete( $running );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $or->get_id() ), 'eru-yk' );
$modes['create'] = 'ok';

Eru_YK_Cron::sync();
$track = $pending_ids( 'eru_yk_track' );
$check( '(re-arm) tracker scheduled once when enabled', 1 === count( $track ) );
ActionScheduler::store()->log_execution( reset( $track ) );
Eru_YK_Cron::sync();
$check( '(re-arm) a running recurring tracker is not duplicated by sync', 0 === count( $pending_ids( 'eru_yk_track' ) ) );
ActionScheduler::store()->mark_complete( reset( $track ) );
Eru_YK_Cron::sync();
$check( '(re-arm) after completion with nothing pending, sync re-creates it', 1 === count( $pending_ids( 'eru_yk_track' ) ) );
as_unschedule_all_actions( 'eru_yk_track', array(), 'eru-yk' );

// ---------------------------------------------------------------------------------------------
// Admin handler: capability, nonce, wrong order, reset confirmation.
// ---------------------------------------------------------------------------------------------
$oa = $make();
$ob = $make();
wp_set_current_user( 0 );
$check( '(admin) no capability → 403', 403 === Eru_YK_Admin::handle( 'dispatch', $oa->get_id(), 'x' )['code'] );
wp_set_current_user( 1 );
$check( '(admin) bad nonce → 400', 400 === Eru_YK_Admin::handle( 'dispatch', $oa->get_id(), 'bad' )['code'] );
$check( '(admin) nonce of another order → 400', 400 === Eru_YK_Admin::handle( 'dispatch', $ob->get_id(), wp_create_nonce( 'eru_yk_order_' . $oa->get_id() ) )['code'] );
$check( '(admin) missing order → 404', 404 === Eru_YK_Admin::handle( 'dispatch', 99999999, wp_create_nonce( 'eru_yk_order_99999999' ) )['code'] );
$check( '(admin) unknown action → 400', 400 === Eru_YK_Admin::handle( 'drop', $oa->get_id(), wp_create_nonce( 'eru_yk_order_' . $oa->get_id() ) )['code'] );
$r = Eru_YK_Admin::handle( 'dispatch', $oa->get_id(), wp_create_nonce( 'eru_yk_order_' . $oa->get_id() ) );
$check( '(admin) valid dispatch only queues (no HTTP)', 200 === $r['code'] && (bool) $pending_ids( 'eru_yk_dispatch', array( $oa->get_id(), 1 ) ) );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $oa->get_id(), 1 ), 'eru-yk' );
$apply( array( 'enabled' => 'no' ) );
$check( '(admin) disabled → 409', 409 === Eru_YK_Admin::handle( 'dispatch', $oa->get_id(), wp_create_nonce( 'eru_yk_order_' . $oa->get_id() ) )['code'] );
$apply();
$res = Eru_YK_Admin::bulk_dispatch( array( $oa->get_id(), $od->get_id() ) );
$check( '(admin) bulk: live shipment skipped, other queued', 1 === $res['queued'] && 1 === $res['skipped'] );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $oa->get_id(), 1 ), 'eru-yk' );

// ---------------------------------------------------------------------------------------------
// Label: values are escaped.
// ---------------------------------------------------------------------------------------------
$oe = $make( 400, '<script>alert(1)</script>"&' );
Eru_YK_Dispatcher::dispatch( $oe->get_id() );
$html = Eru_YK_Label::html( $fresh( $oe ) );
$check( '(label) receiver name escaped, barcode value in data-value', false === strpos( $html, '<script>alert(1)' ) && false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && false !== strpos( $html, 'data-value="' . esc_attr( $meta( $oe, '_eru_yk_cargo_key' ) ) . '"' ) );
$check( '(label) refused for an order without a shipment', is_wp_error( Eru_YK_Label::html( $fresh( $oa ) ) ) );

// ---------------------------------------------------------------------------------------------
// I3. Test mode needs an explicit test endpoint.
// ---------------------------------------------------------------------------------------------
$check( '(I3) no default test endpoint', '' === Eru_YK_Settings::defaults()['endpoint_test'] );
$apply( array( 'test_mode' => 'yes', 'endpoint_test' => '' ) );
$n0 = count( $reqs );
$ot = $make();
$check( '(I3) test mode without a test endpoint → integration off, no HTTP', ! Eru_YK_Settings::is_enabled() && 'disabled' === Eru_YK_Dispatcher::dispatch( $ot->get_id() ) && count( $reqs ) === $n0 );
$apply( array( 'test_mode' => 'yes', 'endpoint_test' => 'https://test.example.com/ws' ) );
Eru_YK_Dispatcher::dispatch( $ot->get_id() );
$check( '(I3) test mode with an endpoint uses it', 'test.example.com' === end( $reqs )['host'] );

$restore();
echo "PASS: all yk-safety checks\n";
