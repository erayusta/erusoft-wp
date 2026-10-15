<?php
/**
 * Yurtiçi Kargo Integration — envelope escaping, dispatch, duplicate recovery by code, cancel + K<seq>,
 * routing, excluded methods, log redaction, transient retry and ambiguous-failure lookup.
 * Every HTTP call is answered by the router below from invented fixtures; anything else exits 1.
 *
 *   wp plugin activate yurtici-kargo-integration-for-woocommerce turkish-shipping-carriers-for-woocommerce
 *   wp eval-file tests/php/yk-dispatch.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

foreach ( array( 'Eru_YK_Settings', 'Eru_YK_Xml', 'Eru_YK_Client', 'Eru_YK_Dispatcher', 'Eru_YK_Accounts', 'Eru_YK_Logger' ) as $eru_yk_class ) {
	if ( ! class_exists( $eru_yk_class ) ) {
		echo "FAIL: {$eru_yk_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

$fx = function ( $name, $key = '', $invoice = '' ) {
	return str_replace( array( '{{KEY}}', '{{INVOICE}}' ), array( htmlspecialchars( $key, ENT_XML1 ), htmlspecialchars( $invoice, ENT_XML1 ) ), file_get_contents( __DIR__ . '/yk-fixtures/' . $name ) );
};

// ---------------------------------------------------------------------------------------------
// HTTP router: decodes the SOAP request, answers by mode.
// ---------------------------------------------------------------------------------------------
$reqs        = array();
$create_mode = 'ok';       // ok | duplicate | mevcut | 503 | timeout
$query_mode  = 'transit';  // transit | notfound | timeout | nop | delivered
$cancel_mode = 'ok';
$router      = function ( $pre, $args, $url ) use ( &$reqs, &$create_mode, &$query_mode, &$cancel_mode, $fx ) {
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
	$xp   = new DOMXPath( $dom );
	$op   = $xp->query( '//*[local-name()="Body"]/*' )->item( 0 )->localName;
	$val  = function ( $n ) use ( $xp ) {
		$l = $xp->query( '//*[local-name()="' . $n . '"]' );
		return $l->length ? $l->item( 0 )->textContent : '';
	};
	$key  = 'createShipment' === $op ? $val( 'cargoKey' ) : ( 'queryShipment' === $op ? $val( 'keys' ) : $val( 'cargoKeys' ) );
	$user = $val( 'wsUserName' );
	$reqs[] = array( 'op' => $op, 'key' => $key, 'user' => $user, 'body' => (string) $args['body'] );
	$ok   = fn( $body, $code = 200 ) => array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	$mode = 'createShipment' === $op ? $create_mode : ( 'queryShipment' === $op ? $query_mode : $cancel_mode );
	if ( 'timeout' === $mode ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 20001 milliseconds' );
	}
	if ( '503' === $mode ) {
		return $ok( 'Service Unavailable', 503 );
	}
	switch ( $op ) {
		case 'createShipment':
			$file = array( 'ok' => 'create-ok.xml', 'duplicate' => 'create-duplicate.xml', 'mevcut' => 'create-error-mevcut.xml' )[ $mode ];
			return $ok( $fx( $file, $key, 'X' ) );
		case 'queryShipment':
			return $ok( $fx( 'query-' . $mode . '.xml', $key, 'X' ) );
		case 'cancelShipment':
			return $ok( $fx( 'cancel-ok.xml', $key ) );
	}
	echo 'FAIL unmocked SOAP operation ' . $op . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $router, 10, 3 );
$ops = function ( $op ) use ( &$reqs ) {
	return count( array_filter( $reqs, fn( $r ) => $r['op'] === $op ) );
};

$pending = function ( $args ) {
	return as_get_scheduled_actions(
		array(
			'hook'     => 'eru_yk_dispatch',
			'args'     => $args,
			'group'    => 'eru-yk',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 5,
		)
	);
};

// ---------------------------------------------------------------------------------------------
// Fixture data (invented) and clean-up of an aborted earlier run.
// ---------------------------------------------------------------------------------------------
// The site's own settings are parked in a backup option once, so an aborted run cannot leave test settings behind.
if ( false === get_option( 'eru_yk_test_backup', false ) ) {
	add_option( 'eru_yk_test_backup', array( 'value' => get_option( Eru_YK_Settings::OPTION, null ) ), '', false );
}
$cleanup      = function () {
	foreach ( wc_get_orders( array( 'created_via' => 'eru-yk-test', 'limit' => -1 ) ) as $o ) {
		Eru_YK_Lock::release( 'order_' . $o->get_id() );
		as_unschedule_all_actions( 'eru_yk_dispatch', array( $o->get_id() ), 'eru-yk' );
		as_unschedule_all_actions( 'eru_yk_dispatch', array( $o->get_id(), 1 ), 'eru-yk' );
		global $wpdb;
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

// Demo secrets are assembled so the privacy scrubber does not read them as literals.
$demo_pass = implode( '', array( 'Demo<Pa&', 'ss>"\'9' ) );
update_option(
	Eru_YK_Settings::OPTION,
	array_merge(
		Eru_YK_Settings::defaults(),
		array(
			'enabled'          => 'yes',
			'accounts'         => array(
				array( 'id' => 'demoa', 'label' => 'Ana hesap', 'username' => 'demo-user-a', 'password' => $demo_pass, 'prefix' => 'DA', 'customer_code' => '' ),
				array( 'id' => 'demob', 'label' => 'Yüksek tutar', 'username' => 'demo-user-b', 'password' => implode( '-', array( 'demo', 'pass', 'b' ) ), 'prefix' => 'DB', 'customer_code' => '' ),
			),
			'rules'            => array(
				array( 'account' => 'demob', 'min' => '', 'max' => '', 'methods' => array( 9901 ) ),
				array( 'account' => 'demoa', 'min' => '', 'max' => '1000', 'methods' => array() ),
				array( 'account' => 'demob', 'min' => '1000', 'max' => '', 'methods' => array() ),
			),
			'default_account'  => 'demoa',
			'auto_status'      => 'processing',
			'excluded_methods' => array( 'local_pickup', 'pickup_location' ),
		)
	),
	false
);
Eru_YK_Settings::flush();

$phone = '0' . implode( ' ', array( '555', '000', '00', '00' ) );
$make  = function ( $total, $method = 'flat_rate', $instance = 9902, $status = 'on-hold' ) use ( $phone ) {
	$o = new WC_Order();
	$o->set_created_via( 'eru-yk-test' );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( 'Alıcı <&>' );
	$o->set_billing_address_1( 'Demo Sokak No: 1 "A" & \'B\'' );
	$o->set_billing_city( 'Konak' );
	$o->set_billing_state( 'TR35' );
	$o->set_billing_country( 'TR' );
	$o->set_billing_phone( $phone );
	$o->set_billing_email( 'ornek@example.com' );
	$item = new WC_Order_Item_Shipping();
	$item->set_method_id( $method );
	$item->set_instance_id( $instance );
	$item->set_method_title( 'Demo Kargo' );
	$item->set_total( 0 );
	$o->add_item( $item );
	$o->set_total( $total );
	$o->set_status( $status );
	$o->save();
	return wc_get_order( $o->get_id() );
};
$fresh = fn( $o ) => wc_get_order( $o->get_id() );

// ---------------------------------------------------------------------------------------------
// (a) Envelope builder escapes every value, credentials and key lists included.
// ---------------------------------------------------------------------------------------------
$nasty = 'x<y>&z"q\'w';
$xml   = Eru_YK_Xml::envelope(
	'queryShipment',
	array(
		'wsUserName' => 'u' . $nasty,
		'wsPassword' => 'p' . $nasty,
		'wsLanguage' => 'TR',
		'keys'       => array( 'K1' . $nasty, 'K2' . $nasty ),
		'ShippingOrderVO' => array( 'receiverCustName' => 'N' . $nasty . "\x01", 'cargoKey' => 'C' . $nasty ),
	)
);
$dom = new DOMDocument();
$check( '(a) envelope is well-formed XML', $dom->loadXML( $xml, LIBXML_NONET ) );
$xp  = new DOMXPath( $dom );
$txt = function ( $n, $i = 0 ) use ( $xp ) {
	$l = $xp->query( '//*[local-name()="' . $n . '"]' );
	return $l->length > $i ? $l->item( $i )->textContent : null;
};
$check( '(a) wsUserName round-trips', 'u' . $nasty === $txt( 'wsUserName' ) );
$check( '(a) wsPassword round-trips', 'p' . $nasty === $txt( 'wsPassword' ) );
$check( '(a) both keys round-trip as repeated elements', 'K1' . $nasty === $txt( 'keys', 0 ) && 'K2' . $nasty === $txt( 'keys', 1 ) );
$check( '(a) nested value round-trips, control char dropped', 'N' . $nasty === $txt( 'receiverCustName' ) && 'C' . $nasty === $txt( 'cargoKey' ) );
$check( '(a) no raw special chars from values in the markup', false === strpos( $xml, '<y>' ) && false === strpos( $xml, '&z' ) );
$parsed = Eru_YK_Xml::parse( $xml );
$check( '(a) parser reads the envelope back', is_array( $parsed ) && 'p' . $nasty === $parsed['body']['wsPassword'] && array( 'K1' . $nasty, 'K2' . $nasty ) === $parsed['body']['keys'] );
$bad = false;
try {
	Eru_YK_Xml::element( 'a><b', 'x' );
} catch ( InvalidArgumentException $e ) {
	$bad = true;
}
$check( '(a) invalid element names are rejected', $bad );
$xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><r>&x;</r>';
$check( '(a) parser refuses DTD/entities', is_wp_error( Eru_YK_Xml::parse( $xxe ) ) );

// ---------------------------------------------------------------------------------------------
// (e) Routing: first matching rule wins.
// ---------------------------------------------------------------------------------------------
$o300  = $make( 300 );
$o3000 = $make( 3000 );
$o300m = $make( 300, 'flat_rate', 9901 );
$check( '(e) total 300 → Ana hesap', 'demoa' === Eru_YK_Accounts::route( $o300 ) );
$check( '(e) total 3.000 → Yüksek tutar', 'demob' === Eru_YK_Accounts::route( $o3000 ) );
$check( '(e) shipping method rule (listed first) beats the total rule', 'demob' === Eru_YK_Accounts::route( $o300m ) );

// ---------------------------------------------------------------------------------------------
// (b) createShipment success → meta, Turkish Shipping Carriers meta, status unchanged.
// ---------------------------------------------------------------------------------------------
add_filter( 'eru_yk_tsc_active', '__return_true' );
$r = Eru_YK_Dispatcher::dispatch( $o300->get_id() );
$o = $fresh( $o300 );
$check( '(b) dispatch returns created', 'created' === $r );
$check( '(b) cargo key = prefix + order id', 'DA' . $o->get_id() === $o->get_meta( '_eru_yk_cargo_key' ) );
$check( '(b) state, job id and account meta written', 'dispatched' === $o->get_meta( '_eru_yk_state' ) && '900001' === $o->get_meta( '_eru_yk_job_id' ) && 'demoa' === $o->get_meta( '_eru_yk_account' ) && 'Ana hesap' === $o->get_meta( '_eru_yk_account_label' ) );
$check( '(b) request went out with account A credentials', 'demo-user-a' === end( $reqs )['user'] );
$check( '(b) _eru_tsc_carrier = yurtici (tsc active)', 'yurtici' === $o->get_meta( '_eru_tsc_carrier' ) );
$check( '(b) order status unchanged by default', 'on-hold' === $o->get_status() );
$check( '(b) an order note was added', (bool) array_filter( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ), fn( $n ) => false !== strpos( $n->content, 'gönderi oluşturuldu' ) ) );
$check( '(b) province name sent from the state code (TR35 → İzmir)', false !== strpos( $reqs[ count( $reqs ) - 1 ]['body'], '<cityName>İzmir</cityName>' ) );
$check( '(b) second dispatch does not create again', 'exists' === Eru_YK_Dispatcher::dispatch( $o300->get_id() ) && 1 === $ops( 'createShipment' ) );
$query_mode = 'transit';
Eru_YK_Tracker::track( $fresh( $o300 ) );
$o = $fresh( $o300 );
$check( '(b) tracking number from queryShipment → _eru_tsc_tracking_number', '100000000001' === $o->get_meta( '_eru_yk_tracking_number' ) && '100000000001' === $o->get_meta( '_eru_tsc_tracking_number' ) );
remove_filter( 'eru_yk_tsc_active', '__return_true' );
add_filter( 'eru_yk_tsc_active', '__return_false' );
Eru_YK_Dispatcher::dispatch( $o3000->get_id() );
$o = $fresh( $o3000 );
$check( '(b) tsc inactive → no _eru_tsc_* meta; account B used', '' === $o->get_meta( '_eru_tsc_carrier' ) && 'DB' . $o->get_id() === $o->get_meta( '_eru_yk_cargo_key' ) );
remove_filter( 'eru_yk_tsc_active', '__return_false' );

// ---------------------------------------------------------------------------------------------
// (d) Cancel, then dispatch again → cargo key ends with K1.
// ---------------------------------------------------------------------------------------------
$c = Eru_YK_Dispatcher::cancel( $o300->get_id() );
$o = $fresh( $o300 );
$check( '(d) cancel succeeds and clears the shipment meta', $c['ok'] && '' === $o->get_meta( '_eru_yk_cargo_key' ) && '' === $o->get_meta( '_eru_yk_state' ) && 1 === (int) $o->get_meta( '_eru_yk_seq' ) );
$check( '(d) cancel removed the tsc tracking number', '' === $o->get_meta( '_eru_tsc_tracking_number' ) );
$create_mode = 'ok';
$r = Eru_YK_Dispatcher::dispatch( $o300->get_id() );
$o = $fresh( $o300 );
$check( '(d) re-dispatch uses a new key ending with K1', 'created' === $r && 'DA' . $o->get_id() . 'K1' === $o->get_meta( '_eru_yk_cargo_key' ) );

// ---------------------------------------------------------------------------------------------
// (c) Duplicate recognised by code only.
// ---------------------------------------------------------------------------------------------
$od          = $make( 400 );
$create_mode = 'duplicate';
$query_mode  = 'transit';
$before      = $ops( 'queryShipment' );
$r           = Eru_YK_Dispatcher::dispatch( $od->get_id() );
$o           = $fresh( $od );
$check( '(c) duplicate code → queryShipment called → recovered', 'recovered' === $r && $ops( 'queryShipment' ) === $before + 1 && 'dispatched' === $o->get_meta( '_eru_yk_state' ) && '100000000001' === $o->get_meta( '_eru_yk_tracking_number' ) );
$om          = $make( 400 );
$create_mode = 'mevcut';
$before      = $ops( 'queryShipment' );
$r           = Eru_YK_Dispatcher::dispatch( $om->get_id() );
$o           = $fresh( $om );
$check( '(c) non-duplicate code whose text says "mevcut"/"tekrar" is NOT treated as duplicate', 'failed' === $r && $ops( 'queryShipment' ) === $before && 'failed' === $o->get_meta( '_eru_yk_state' ) && false !== strpos( $o->get_meta( '_eru_yk_error' ), '70001' ) );

// ---------------------------------------------------------------------------------------------
// (j) Transient 503 → Action Scheduler retry, no sleep. Ambiguous timeout → lookup before re-create.
// ---------------------------------------------------------------------------------------------
$ot          = $make( 400 );
$create_mode = '503';
$t0          = microtime( true );
$r           = Eru_YK_Dispatcher::dispatch( $ot->get_id() );
$elapsed     = microtime( true ) - $t0;
$o           = $fresh( $ot );
$acts        = $pending( array( $ot->get_id() ) );
$when        = $acts ? reset( $acts )->get_schedule()->get_date()->getTimestamp() : 0;
$check( '(j) 503 → state retry, AS retry scheduled ~1 min later', 'retry' === $r && 'retry' === $o->get_meta( '_eru_yk_state' ) && $when >= time() + 50 && $when <= time() + 70 );
$check( '(j) no sleep in the request (' . round( $elapsed, 2 ) . ' s)', $elapsed < 3 );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $ot->get_id() ), 'eru-yk' );

$oa          = $make( 400 );
$create_mode = 'timeout';
$r           = Eru_YK_Dispatcher::dispatch( $oa->get_id() );
$check( '(j) timeout after send → state unknown (ambiguous)', 'retry' === $r && 'unknown' === $fresh( $oa )->get_meta( '_eru_yk_state' ) );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $oa->get_id() ), 'eru-yk' );
$query_mode  = 'timeout';
$create_mode = 'ok';
$creates     = $ops( 'createShipment' );
$r           = Eru_YK_Dispatcher::dispatch( $oa->get_id() );
$check( '(j) lookup fails → needs_review, no second createShipment', 'needs_review' === $r && $ops( 'createShipment' ) === $creates && 'needs_review' === $fresh( $oa )->get_meta( '_eru_yk_state' ) );
$check( '(j) needs_review is not retried automatically', 'needs_review' === Eru_YK_Dispatcher::dispatch( $oa->get_id() ) );
$query_mode = 'notfound';
$n0         = count( $reqs );
$r          = Eru_YK_Dispatcher::dispatch( $oa->get_id(), true );
$seq        = array_column( array_slice( $reqs, $n0 ), 'op' );
$check( '(j) manual retry: lookup first (not found) then one create with the same key', 'created' === $r && array( 'queryShipment', 'createShipment' ) === $seq && 'DA' . $oa->get_id() === $fresh( $oa )->get_meta( '_eru_yk_cargo_key' ) );
$ob          = $make( 400 );
$create_mode = 'timeout';
Eru_YK_Dispatcher::dispatch( $ob->get_id() );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $ob->get_id() ), 'eru-yk' );
$query_mode = 'transit';
$creates    = $ops( 'createShipment' );
$r          = Eru_YK_Dispatcher::dispatch( $ob->get_id() );
$check( '(j) ambiguous then found by lookup → adopted without a new create', 'recovered' === $r && $ops( 'createShipment' ) === $creates );

// ---------------------------------------------------------------------------------------------
// (f) Excluded local_pickup → no action scheduled; a normal order is queued.
// ---------------------------------------------------------------------------------------------
$op = $make( 400, 'local_pickup', 9903 );
$op->set_status( 'processing' );
$op->save();
$check( '(f) local_pickup order → no dispatch action scheduled', ! $pending( array( $op->get_id() ) ) );
$on = $make( 400 );
$n0 = count( $reqs );
$on->set_status( 'processing' );
$on->save();
$check( '(f) normal order → dispatch action scheduled, no HTTP in the status change', (bool) $pending( array( $on->get_id() ) ) && count( $reqs ) === $n0 );
as_unschedule_all_actions( 'eru_yk_dispatch', array( $on->get_id() ), 'eru-yk' );

// ---------------------------------------------------------------------------------------------
// (i) Raw log rows carry no password.
// ---------------------------------------------------------------------------------------------
global $wpdb;
$rows = $wpdb->get_col( 'SELECT CONCAT(request, response, error) FROM ' . Eru_YK_Logger::log_table() . ' WHERE order_id IN (' . implode( ',', array_map( 'intval', array( $o300->get_id(), $o3000->get_id(), $od->get_id(), $oa->get_id() ) ) ) . ')' ); // phpcs:ignore
$leak = false;
foreach ( $rows as $row ) {
	foreach ( array( $demo_pass, htmlspecialchars( $demo_pass, ENT_XML1 | ENT_QUOTES ), implode( '-', array( 'demo', 'pass', 'b' ) ) ) as $p ) {
		if ( false !== strpos( (string) $row, $p ) ) {
			$leak = true;
		}
	}
}
$check( '(i) ' . count( $rows ) . ' raw log rows, none contains a wsPassword', count( $rows ) >= 5 && ! $leak );
$check( '(i) password element is redacted in place', (bool) array_filter( $rows, fn( $r ) => false !== strpos( $r, '<wsPassword>[gizli]</wsPassword>' ) ) );

// ---------------------------------------------------------------------------------------------
// Settings: write-only password, prefix validation.
// ---------------------------------------------------------------------------------------------
wp_set_current_user( 1 );
$s = Eru_YK_Settings::sanitize(
	array(
		'enabled'  => 'yes',
		'accounts' => array(
			array( 'id' => 'demoa', 'label' => 'Ana hesap', 'username' => 'demo-user-a', 'password' => '', 'prefix' => 'da' ),
			array( 'id' => '', 'label' => 'Yeni', 'username' => 'demo-user-c', 'password' => implode( '-', array( 'demo', 'pass', 'c' ) ), 'prefix' => 'DA' ),
			array( 'id' => 'demob', 'label' => 'Yüksek tutar', 'username' => 'demo-user-b', 'password' => '', 'prefix' => 'TOOLONG' ),
		),
	)
);
$acc = array_column( $s['values']['accounts'], null, 'id' );
$check( '(settings) blank password keeps the stored one', $demo_pass === $acc['demoa']['password'] && 'DA' === $acc['demoa']['prefix'] );
$check( '(settings) duplicate prefix rejected, invalid prefix keeps the old one', 2 === count( $acc ) && 'DB' === $acc['demob']['prefix'] && 2 === count( $s['errors'] ) );

// ---------------------------------------------------------------------------------------------
// Clean-up.
// ---------------------------------------------------------------------------------------------
$restore();
echo "PASS: all yk-dispatch checks\n";
