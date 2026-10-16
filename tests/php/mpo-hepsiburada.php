<?php
/**
 * Marketplace Orders for WooCommerce — Hepsiburada adapter: OMS lines grouped into one order per order number with
 * commission and discount meta (d), auto-pack retried over Action Scheduler and capped (e), credentials and
 * User-Agent (f), lifecycle (shipped, cancelled lines, whole-order cancel) and cargo recovery.
 *
 * Every request is answered by MPO_Router from invented fixtures; any other URL ends the run.
 *
 *   wp eval-file tests/php/mpo-hepsiburada.php
 */

require __DIR__ . '/mpo-adapter-helpers.php';
mpo_require_plugin();
foreach ( array( 'Eru_MPO_Http', 'Eru_MPO_Adapter_Hepsiburada', 'Eru_MPO_Sync', 'Eru_MPO_Ack', 'Eru_MPO_Credentials' ) as $mpo_c ) {
	if ( ! class_exists( $mpo_c ) ) {
		echo "FAIL: {$mpo_c} missing\n";
		exit( 1 );
	}
}

global $wpdb;
global $wpdb;
$router       = new MPO_Router();
$email        = 'mpo.test.hb@example.com';
$old_settings = get_option( Eru_MPO_Settings::OPTION, null );
$old_mp       = get_option( 'eru_mpo_mp_hepsiburada', null );
$failed       = false;
$tag          = mpo_run_tag();
$sku_a        = 'MPO-TEST-' . $tag . '-HA';
$sku_b        = 'MPO-TEST-' . $tag . '-HB';
$pid_a        = 0;
$pid_b        = 0;
$rate_id      = 0;
$tckn         = mpo_fake_tckn();
$creds        = array(
	'merchant_id'  => 'demo-merchant-0001',
	'password'     => 'demo-' . 'sifre-' . strtolower( $tag ),
	'dev_username' => 'demo_gelistirici',
);
$basic        = 'Basic ' . base64_encode( $creds['merchant_id'] . ':' . $creds['password'] ); // phpcs:ignore
$base         = 'https://oms\.demo-pazaryeri\.invalid'; // Invented host (filter below): a leak never reaches a real API.
add_filter( 'eru_mpo_hepsiburada_base_url', fn() => 'https://oms.demo-pazaryeri.invalid' );
$mid          = 'demo-merchant-0001';

/** One OMS line from the template. */
$line = function ( $num, $lid, $sku, $qty, $unit, $md = 0, $hd = 0, $com = 0, $rate = 'null' ) use ( $tckn ) {
	return mpo_fixture(
		'hb-line.json',
		array(
			'{{LINE}}'     => $lid,
			'{{NUM}}'      => $num,
			'{{SKU}}'      => $sku,
			'{{SKU_TAG}}'  => substr( md5( $sku ), 0, 4 ),
			'{{NAME}}'     => 'Demo Ürün ' . $lid,
			'{{QTY}}'      => $qty,
			'{{UNIT}}'     => $unit,
			'{{TOTAL}}'    => round( $unit * $qty, 2 ),
			'{{MD}}'       => $md,
			'{{HD}}'       => $hd,
			'{{COM}}'      => $com,
			'{{COM_RATE}}' => $rate,
			'{{TCKN}}'     => $tckn,
			'{{DATE}}'     => wp_date( 'Y-m-d\TH:i:s', time() - HOUR_IN_SECONDS, new DateTimeZone( 'Europe/Istanbul' ) ),
		)
	);
};
$hb    = array(
	'orders'      => array(),
	'cancelled'   => array(),
	'shipped'     => array(),
	'delivered'   => array(),
	'undelivered' => array(),
	'open'        => array(),
	'detail'      => array(),
	'post'        => array(),
	'fail'        => array(),
	'pages'       => array(),
	'total'       => null,
);
$list  = fn( array $rows ) => mpo_resp( 200, array( 'items' => $rows, 'totalCount' => count( $rows ), 'pageCount' => 1 ) );
$posts = array();
$router->on(
	'GET',
	'#^' . $base . '/orders/merchantid/' . $mid . '\?#',
	function ( $r ) use ( &$hb, $list ) {
		if ( $hb['pages'] ) { // Paged: rows by offset, totalCount as given.
			$rows = $hb['pages'][ (int) ( $r['query']['offset'] ?? 0 ) ] ?? array();
			return mpo_resp( 200, array( 'items' => $rows, 'totalCount' => (int) $hb['total'] ) );
		}
		return $list( $hb['orders'] );
	}
);
$router->on(
	'GET',
	'#^' . $base . '/orders/merchantid/' . $mid . '/cancelled\?#',
	function ( $r ) use ( &$hb, $list ) {
		return $hb['cancelled'] ? $list( $hb['cancelled'] ) : mpo_resp( 404, '' );
	}
);
$router->on(
	'GET',
	'#^' . $base . '/packages/merchantid/' . $mid . '/(shipped|delivered|undelivered)\?#',
	function ( $r ) use ( &$hb, $list ) {
		preg_match( '#/(shipped|delivered|undelivered)\?#', $r['url'], $m );
		if ( ! empty( $hb['fail'][ $m[1] ] ) ) {
			return mpo_resp( $hb['fail'][ $m[1] ], array( 'message' => 'Demo parametre hatası' ) );
		}
		return $hb[ $m[1] ] ? $list( $hb[ $m[1] ] ) : mpo_resp( 404, '' );
	}
);
$router->on(
	'GET',
	'#^' . $base . '/packages/merchantid/' . $mid . '\?#',
	function ( $r ) use ( &$hb, $list ) {
		return $list( $hb['open'] );
	}
);
$router->on(
	'GET',
	'#^' . $base . '/packages/merchantid/' . $mid . '/packagenumber/[^/?]+$#',
	function ( $r ) use ( &$hb ) {
		$pn = rawurldecode( (string) substr( strrchr( $r['url'], '/' ), 1 ) );
		return isset( $hb['detail'][ $pn ] ) ? mpo_resp( 200, $hb['detail'][ $pn ] ) : mpo_resp( 404, '' );
	}
);
$router->on(
	'GET',
	'#^' . $base . '/lineitems/merchantid/' . $mid . '/packageablewith/lineitemid/[^/?]+$#',
	function ( $r ) use ( &$hb ) {
		$lid = rawurldecode( (string) substr( strrchr( $r['url'], '/' ), 1 ) );
		return isset( $hb['mates'][ $lid ] ) ? mpo_resp( 200, array( 'lineItems' => $hb['mates'][ $lid ] ) ) : mpo_resp( 404, '' );
	}
);
$router->on(
	'POST',
	'#^' . $base . '/packages/merchantid/' . $mid . '$#',
	function ( $r ) use ( &$hb, &$posts ) {
		$body  = json_decode( (string) $r['body'], true );
		$first = (string) ( $body['lineItemRequests'][0]['id'] ?? '' );
		$posts[ $first ] = ( $posts[ $first ] ?? 0 ) + 1;
		$code  = ! empty( $hb['post'][ $first ] ) ? array_shift( $hb['post'][ $first ] ) : 200;
		if ( 'throw' === $code ) {
			throw new RuntimeException( 'Demo: process died after sending' );
		}
		if ( 200 !== $code ) {
			return mpo_resp( $code, array( 'message' => 'Demo geçici hata' ) );
		}
		return mpo_resp( 200, array( 'packageNumber' => 'DEMO-PKT-' . $first ) );
	}
);

$order_of  = function ( $num ) {
	$ids = mpo_order_ids( $num, 'hepsiburada' );
	Eru_MPO_Importer::forget_order( $ids[0] ?? 0 );
	return $ids ? wc_get_order( $ids[0] ) : null;
};
$adapter   = function () {
	Eru_MPO_Registry::reset();
	return Eru_MPO_Registry::get( 'hepsiburada' );
};
$configure = function ( array $over = array() ) use ( $creds, $adapter ) {
	Eru_MPO_Settings::update( array_merge( Eru_MPO_Settings::defaults(), array( 'enabled' => 'yes', 'observe' => 'no', 'stock_mode' => 'reduce' ) ) );
	Eru_MPO_Credentials::update( $adapter(), array_merge( array( 'enabled' => 'yes', 'ack_mode' => 'never' ), $creds, $over ) );
	Eru_MPO_Settings::reset_cache();
};
$pending   = fn( $order ) => mpo_pending_args( Eru_MPO_Ack::HOOK, array( (int) $order->get_id() ) );
$post_n    = fn() => count( array_filter( $router->calls, fn( $c ) => 'POST' === $c['method'] ) );

// Restores the demo on every exit path, including exit() from the router's catch-all (shutdown).
$cleaned = false;
$cleanup = function () use ( &$cleaned, &$pid_a, &$pid_b, &$rate_id, $email, $old_settings, $old_mp ) {
	if ( $cleaned ) {
		return;
	}
	$cleaned = true;
	mpo_adapter_cleanup( 'hepsiburada', $email );
	foreach ( array( $pid_a, $pid_b ) as $pid ) {
		$p = $pid ? wc_get_product( $pid ) : null;
		if ( $p ) {
			$p->delete( true );
		}
	}
	if ( $rate_id ) {
		WC_Tax::_delete_tax_rate( $rate_id );
	}
	null === $old_settings ? delete_option( Eru_MPO_Settings::OPTION ) : update_option( Eru_MPO_Settings::OPTION, $old_settings, false );
	null === $old_mp ? delete_option( 'eru_mpo_mp_hepsiburada' ) : update_option( 'eru_mpo_mp_hepsiburada', $old_mp, false );
	Eru_MPO_Settings::reset_cache();
	Eru_MPO_Cron::unschedule_all();
};
register_shutdown_function( $cleanup );

try {
	mpo_adapter_cleanup( 'hepsiburada', $email );
	delete_option( 'eru_mpo_mp_hepsiburada' );
	mpo_delete_products();
	$configure();
	$pid_a   = mpo_product( $sku_a, 10, '199.90' );
	$pid_b   = mpo_product( $sku_b, 10, '79.90' );
	$rate_id = mpo_tax_rate( 20 );
	$a       = $adapter();
	mpo_check( 'set-up: the Hepsiburada adapter is registered and configured; the default OMS host is the public one, the suite uses an invented host', $a instanceof Eru_MPO_Adapter_Hepsiburada && $a->is_configured() && 'https://oms-external.hepsiburada.com' === Eru_MPO_Adapter_Hepsiburada::settings()['base_live'] && 'https://oms.demo-pazaryeri.invalid' === Eru_MPO_Adapter_Hepsiburada::base_url() );
	mpo_check( '(minor 9) the acknowledgement lock outlives the worst-case pack run', Eru_MPO_Ack::LOCK_TTL > Eru_MPO_Adapter_Hepsiburada::PACK_BUDGET + 2 * Eru_MPO_Http::TIMEOUT );

	// (d) Two OMS lines with one order number → one DTO, one order; commission and discounts on the lines.
	$n1  = mpo_id();
	$n2  = mpo_id();
	$l1  = mpo_id();
	$l2  = mpo_id();
	$l3  = mpo_id();
	$hb['orders'] = array(
		$line( $n1, $l1, $sku_a, 2, 199.9, 20, 10, 37.98 ),
		$line( $n2, $l3, $sku_a, 1, 199.9, 0, 0, 15.99, '8' ),
		$line( $n1, $l2, $sku_b, 1, 79.9, 0, 0, 9.59 ),
	);
	$dtos = $a->fetch_orders( time() - DAY_IN_SECONDS, time() );
	mpo_check( '(d) three OMS lines, two order numbers → two DTOs', is_array( $dtos ) && 2 === count( $dtos ) && $n1 === $dtos[0]->remote_ref && 2 === count( $dtos[0]->lines ) && 1 === count( $dtos[1]->lines ) );
	$d  = $dtos[0];
	$q  = $router->calls[0]['query'];
	mpo_check( '(d) request: dates in Turkish time "Y-m-d H:i", offset/limit, merchant ID in the path only', preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', (string) ( $q['begindate'] ?? '' ) ) && '100' === (string) ( $q['limit'] ?? '' ) && '0' === (string) ( $q['offset'] ?? '' ) );
	mpo_check( '(d) DTO line: unit 199.90 × 2, discount = seller 20 + marketplace 10, rate = 37.98 / (399.80 - 20) = 10 %', 199.9 === $d->lines[0]->unit_price && 30.0 === $d->lines[0]->discount && 10.0 === $d->lines[0]->commission_rate && $l1 === $d->lines[0]->remote_line_id && $sku_a === $d->lines[0]->sku );
	mpo_check( '(d) DTO total 449.70 (369.80 + 79.90); reported rate kept when given (8)', 449.7 === $d->totals->total && 8.0 === $dtos[1]->lines[0]->commission_rate );
	mpo_check( '(d) buyer and İzmir/Konak address; order date back from Turkish time', 'mpo.test.hb@example.com' === $d->customer->email && 'Konak' === $d->shipping->district && 'İzmir' === $d->shipping->city && abs( $d->ordered_at - ( time() - HOUR_IN_SECONDS ) ) < 120 );
	mpo_check( '(d) headers: Basic auth and the developer username as User-Agent', $basic === ( $router->calls[0]['headers']['Authorization'] ?? '' ) && 'demo_gelistirici' === ( $router->calls[0]['headers']['User-Agent'] ?? '' ) && false === strpos( $router->calls[0]['url'], $creds['password'] ) );

	$r = Eru_MPO_Importer::pull( 'hepsiburada' );
	mpo_check( '(d) pull: two orders created', 'ok' === $r['result'] && 2 === $r['created'] );
	$o1    = $order_of( $n1 );
	$items = array_values( $o1->get_items() );
	$gross = fn( $i ) => round( (float) $i->get_total() + (float) $i->get_total_tax(), 2 );
	mpo_check( '(d) one order for the order number with both lines; total 449.70', $o1 && 1 === count( mpo_order_ids( $n1, 'hepsiburada' ) ) && 2 === count( $items ) && '449.70' === wc_format_decimal( $o1->get_total(), 2 ) && 369.8 === $gross( $items[0] ) && 79.9 === $gross( $items[1] ) );
	mpo_check( '(d) commission meta on the lines: rate 10, the reported amount 37.98, both discounts and the Hepsiburada SKU', '10' === (string) $items[0]->get_meta( '_eru_mpo_commission_rate' ) && '37.98' === (string) $items[0]->get_meta( '_eru_mpo_commission_amount' ) && '20.00' === (string) $items[0]->get_meta( '_eru_mpo_hb_merchant_discount' ) && '10.00' === (string) $items[0]->get_meta( '_eru_mpo_hb_marketplace_discount' ) && '' !== (string) $items[0]->get_meta( '_eru_mpo_hb_sku' ) && '9.59' === (string) $items[1]->get_meta( '_eru_mpo_commission_amount' ) );
	mpo_check( '(d) payment shows the marketplace: method and title "Hepsiburada"', 'Hepsiburada' === $o1->get_payment_method_title() && 'Hepsiburada' === $o1->get_payment_method() );
	mpo_check( '(d) order: status new, remote order id, İzmir (TR35), identity number not stored', 'eru-mpo-new' === $o1->get_status() && 'DEMO-HB-ORDER-' . $n1 === $o1->get_meta( '_eru_mpo_remote_order_id' ) && 'TR35' === $o1->get_shipping_state() && '' === (string) $o1->get_meta( '_eru_mpo_identity_number' ) );
	$r = Eru_MPO_Importer::pull( 'hepsiburada' );
	mpo_check( '(d) the same lines again: nothing new, still one order per order number', 0 === $r['created'] && 1 === count( mpo_order_ids( $n1, 'hepsiburada' ) ) );

	// Lifecycle: shipped with tracking; a cancelled line; later the whole order cancelled.
	$stock_b      = mpo_stock( $pid_b );
	$hb['orders'] = array();
	$hb['shipped'] = array(
		mpo_fixture(
			'hb-status-row.json',
			array(
				'{{NUM}}'    => $n2,
				'{{PN}}'     => 'DEMO-PKT-' . $n2,
				'{{STATUS}}' => 'Shipped',
				'{{TRACK}}'  => 'DEMO-TRK-' . $n2,
			)
		),
	);
	$hb['cancelled'] = array( mpo_fixture( 'hb-cancelled-row.json', array( '{{NUM}}' => $n1, '{{LINE}}' => $l2, '{{QTY}}' => 1 ) ) );
	$r               = Eru_MPO_Importer::pull( 'hepsiburada', array( 'lifecycle' => true ) );
	$o1              = $order_of( $n1 );
	$o2              = $order_of( $n2 );
	mpo_check( '(lifecycle) shipped endpoint → eru-mpo-shipped with company, tracking and link', 'eru-mpo-shipped' === $o2->get_status() && 'DEMO-TRK-' . $n2 === $o2->get_meta( '_eru_mpo_cargo_tracking' ) && 'Demo Kargo' === $o2->get_meta( '_eru_mpo_cargo_provider' ) && 'DEMO-PKT-' . $n2 === $o2->get_meta( '_eru_mpo_hb_package_number' ) );
	$events = (array) $o1->get_meta( '_eru_mpo_events' );
	mpo_check( '(lifecycle) one cancelled line → cancel-line-<line id> event, its unit restored once, order kept open', in_array( 'cancel-line-' . $l2, $events, true ) && $stock_b + 1 === mpo_stock( $pid_b ) && 'eru-mpo-new' === $o1->get_status() );
	Eru_MPO_Importer::pull( 'hepsiburada', array( 'lifecycle' => true ) );
	mpo_check( '(lifecycle) the same cancellation again restores nothing more', $stock_b + 1 === mpo_stock( $pid_b ) );
	$hb['shipped']   = array();
	$hb['cancelled'] = array(
		mpo_fixture( 'hb-cancelled-row.json', array( '{{NUM}}' => $n1, '{{LINE}}' => $l2, '{{QTY}}' => 1 ) ),
		mpo_fixture( 'hb-cancelled-row.json', array( '{{NUM}}' => $n1, '{{LINE}}' => $l1, '{{QTY}}' => 2 ) ),
	);
	$stock_a = mpo_stock( $pid_a );
	Eru_MPO_Importer::pull( 'hepsiburada', array( 'lifecycle' => true ) );
	$o1 = $order_of( $n1 );
	mpo_check( '(lifecycle) every line cancelled → the whole order is cancelled, the rest of its stock restored', 'eru-mpo-cancelled' === $o1->get_status() && $stock_a + 2 === mpo_stock( $pid_a ) && $stock_b + 1 === mpo_stock( $pid_b ) );
	$hb['cancelled'] = array();

	// Cargo recovery: an order without a tracking number is re-polled through its package.
	$n3           = mpo_id();
	$l4           = mpo_id();
	$hb['orders'] = array( $line( $n3, $l4, $sku_a, 1, 199.9 ) );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$hb['orders']             = array();
	$hb['open']               = array(
		mpo_fixture(
			'hb-status-row.json',
			array(
				'{{NUM}}'    => $n3,
				'{{PN}}'     => 'DEMO-PKT-' . $n3,
				'{{STATUS}}' => 'Open',
				'{{TRACK}}'  => '',
			)
		),
	);
	$hb['detail'][ 'DEMO-PKT-' . $n3 ] = mpo_fixture(
		'hb-status-row.json',
		array(
			'{{NUM}}'    => $n3,
			'{{PN}}'     => 'DEMO-PKT-' . $n3,
			'{{STATUS}}' => 'InTransit',
			'{{TRACK}}'  => 'DEMO-TRK-' . $n3,
		)
	);
	$o3 = $order_of( $n3 );
	mpo_check( '(cargo) imported without cargo company or tracking', '' === (string) $o3->get_meta( '_eru_mpo_cargo_tracking' ) && '' === (string) $o3->get_meta( '_eru_mpo_cargo_provider' ) );
	Eru_MPO_Importer::pull( 'hepsiburada', array( 'lifecycle' => true ) );
	$o3 = $order_of( $n3 );
	mpo_check( '(cargo) lifecycle recovers package number, cargo company and tracking; in transit → shipped', 'DEMO-TRK-' . $n3 === $o3->get_meta( '_eru_mpo_cargo_tracking' ) && 'Demo Kargo' === $o3->get_meta( '_eru_mpo_cargo_provider' ) && 'DEMO-PKT-' . $n3 === $o3->get_meta( '_eru_mpo_hb_package_number' ) && 'eru-mpo-shipped' === $o3->get_status() && '' !== (string) $o3->get_meta( '_eru_mpo_hb_track_at' ) );
	$before = $router->count( 'GET', '#/packagenumber/#' );
	Eru_MPO_Importer::pull( 'hepsiburada', array( 'lifecycle' => true ) );
	mpo_check( '(cargo) once tracked it is not asked again', $before === $router->count( 'GET', '#/packagenumber/#' ) );
	$hb['open'] = array();

	// (e) Auto-pack: 503 twice then 200 → three POSTs over Action Scheduler retries, state packed.
	$configure( array( 'ack_mode' => 'import' ) );
	$n4                = mpo_id();
	$l5                = mpo_id();
	$hb['orders']      = array( $line( $n4, $l5, $sku_b, 1, 79.9 ) );
	$hb['post'][ $l5 ] = array( 503, 503, 200 );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$o4 = $order_of( $n4 );
	mpo_check( '(e) packing queued on import (ack mode "on import"), nothing sent yet', 1 === count( $pending( $o4 ) ) && 'queued' === $o4->get_meta( '_eru_mpo_hb_pack_state' ) && 0 === ( $posts[ $l5 ] ?? 0 ) );
	$delays = array();
	$opens  = $router->count( 'GET', '#/packages/merchantid/' . $mid . '\?#' );
	for ( $i = 0; $i < 3; $i++ ) {
		$ids = $pending( $o4 );
		if ( ! $ids ) {
			break;
		}
		$t0 = time();
		mpo_run_action( $ids[0] );
		$next = $pending( $order_of( $n4 ) );
		if ( $next ) {
			$delays[] = mpo_action_time( $next[0] ) - $t0;
		}
	}
	$o4 = $order_of( $n4 );
	mpo_check( '(e) three POSTs in three Action Scheduler runs; the retries waited 5 and 15 minutes', 3 === ( $posts[ $l5 ] ?? 0 ) && 2 === count( $delays ) && abs( $delays[0] - 300 ) <= 5 && abs( $delays[1] - 900 ) <= 5 );
	mpo_check( '(e) state packed, package number and packed line kept, order moved to ready, nothing pending', 'packed' === $o4->get_meta( '_eru_mpo_hb_pack_state' ) && array( 'DEMO-PKT-' . $l5 ) === $o4->get_meta( '_eru_mpo_hb_packages' ) && array( $l5 ) === $o4->get_meta( '_eru_mpo_hb_packed_lines' ) && 'eru-mpo-ready' === $o4->get_status() && array() === $pending( $o4 ) );
	mpo_check( '(e) each retry first looked for an open package of the order (ambiguous 5xx)', $opens + 2 === $router->count( 'GET', '#/packages/merchantid/' . $mid . '\?#' ) );
	Eru_MPO_Ack::run( $o4->get_id() );
	mpo_check( '(e) a packed order is never packed again', 3 === $posts[ $l5 ] );

	// Max attempts → failed + order note + review flag.
	$n5                = mpo_id();
	$l6                = mpo_id();
	$hb['orders']      = array( $line( $n5, $l6, $sku_b, 1, 79.9 ) );
	$hb['post'][ $l6 ] = array( 503, 503, 503, 503, 503, 503 );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$o5 = $order_of( $n5 );
	for ( $i = 0; $i < 8 && $pending( $o5 ); $i++ ) {
		mpo_run_action( $pending( $o5 )[0] );
		$o5 = $order_of( $n5 );
	}
	$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $o5->get_id() ) ), 'content' ) );
	mpo_check( '(e) five failures: state failed, five POSTs, no further retry', 'failed' === $o5->get_meta( '_eru_mpo_hb_pack_state' ) && 5 === ( $posts[ $l6 ] ?? 0 ) && 5 === (int) $o5->get_meta( '_eru_mpo_hb_pack_tries' ) && array() === $pending( $o5 ) );
	mpo_check( '(e) the order gets a note and the review flag', false !== strpos( $notes, '5 denemede' ) && 'yes' === $o5->get_meta( '_eru_mpo_review' ) && 1 === (int) mpo_map_row( $n5, 'hepsiburada' )->review );

	// An ambiguous failure that did pack: the retry finds the open package and posts nothing.
	$n6                = mpo_id();
	$l7                = mpo_id();
	$hb['orders']      = array( $line( $n6, $l7, $sku_b, 1, 79.9 ) );
	$hb['post'][ $l7 ] = array( 500 );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$o6 = $order_of( $n6 );
	mpo_run_action( $pending( $o6 )[0] );
	$hb['open'] = array(
		mpo_fixture(
			'hb-status-row.json',
			array(
				'{{NUM}}'    => $n6,
				'{{PN}}'     => 'DEMO-PKT-X' . $n6,
				'{{STATUS}}' => 'Open',
				'{{TRACK}}'  => '',
			)
		) + array( 'lineItems' => array( array( 'lineItemId' => $l7, 'quantity' => 1 ) ) ),
	);
	mpo_run_action( $pending( $order_of( $n6 ) )[0] );
	$o6 = $order_of( $n6 );
	mpo_check( '(e) 500 then an open package with this line is found: packed without a second POST', 'packed' === $o6->get_meta( '_eru_mpo_hb_pack_state' ) && 1 === $posts[ $l7 ] && array( 'DEMO-PKT-X' . $n6 ) === $o6->get_meta( '_eru_mpo_hb_packages' ) );
	$hb['open'] = array();

	// Minor 2: an open package of the order that does not show the line: stay ambiguous, no POST, review flag.
	$n11                = mpo_id();
	$l16                = mpo_id();
	$hb['orders']       = array( $line( $n11, $l16, $sku_b, 1, 79.9 ) );
	$hb['post'][ $l16 ] = array( 500 );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	mpo_run_action( $pending( $order_of( $n11 ) )[0] );
	$hb['open'] = array(
		mpo_fixture(
			'hb-status-row.json',
			array(
				'{{NUM}}'    => $n11,
				'{{PN}}'     => 'DEMO-PKT-Y' . $n11,
				'{{STATUS}}' => 'Open',
				'{{TRACK}}'  => '',
			)
		),
	);
	mpo_run_action( $pending( $order_of( $n11 ) )[0] );
	$o11 = $order_of( $n11 );
	mpo_check( '(minor 2) open package without matching lines: not marked packed, no second POST, still ambiguous, review flag', 'retry' === $o11->get_meta( '_eru_mpo_hb_pack_state' ) && 1 === $posts[ $l16 ] && 'yes' === $o11->get_meta( '_eru_mpo_hb_pack_check' ) && 'yes' === $o11->get_meta( '_eru_mpo_review' ) && array() === (array) ( $o11->get_meta( '_eru_mpo_hb_packed_lines' ) ?: array() ) );
	as_unschedule_all_actions( Eru_MPO_Ack::HOOK, array( (int) $o11->get_id() ), Eru_MPO_Cron::GROUP );
	$hb['open'] = array();

	// Minor 5: the run dies right after the POST; the check flag was stored before sending, the retry looks first.
	$n13                = mpo_id();
	$l18                = mpo_id();
	$hb['orders']       = array( $line( $n13, $l18, $sku_b, 1, 79.9 ) );
	$hb['post'][ $l18 ] = array( 'throw' );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	mpo_run_action( $pending( $order_of( $n13 ) )[0] );
	$o13 = $order_of( $n13 );
	mpo_check( '(minor 5) died after sending: check flag already stored, retry queued', 'yes' === $o13->get_meta( '_eru_mpo_hb_pack_check' ) && 'retry' === $o13->get_meta( '_eru_mpo_hb_pack_state' ) && 1 === $posts[ $l18 ] );
	$hb['open'] = array(
		mpo_fixture(
			'hb-status-row.json',
			array(
				'{{NUM}}'    => $n13,
				'{{PN}}'     => 'DEMO-PKT-Z' . $n13,
				'{{STATUS}}' => 'Open',
				'{{TRACK}}'  => '',
			)
		) + array( 'lineItems' => array( array( 'lineItemId' => $l18, 'quantity' => 1 ) ) ),
	);
	mpo_run_action( $pending( $order_of( $n13 ) )[0] );
	$o13 = $order_of( $n13 );
	mpo_check( '(minor 5) the retry finds the package and does not POST again', 'packed' === $o13->get_meta( '_eru_mpo_hb_pack_state' ) && 1 === $posts[ $l18 ] );
	$hb['open'] = array();

	// Two lines that may share a package: one POST with both.
	$n7                = mpo_id();
	$l8                = mpo_id();
	$l9                = mpo_id();
	$hb['orders']      = array( $line( $n7, $l8, $sku_a, 1, 199.9 ), $line( $n7, $l9, $sku_b, 1, 79.9 ) );
	$hb['mates'][ $l8 ] = array( array( 'lineItemId' => $l8, 'quantity' => 1 ), array( 'lineItemId' => $l9, 'quantity' => 1 ) );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$o7 = $order_of( $n7 );
	$pc = $post_n();
	mpo_run_action( $pending( $o7 )[0] );
	$o7   = $order_of( $n7 );
	$last = array_values( array_filter( $router->calls, fn( $c ) => 'POST' === $c['method'] ) );
	$body = json_decode( (string) end( $last )['body'], true );
	mpo_check( '(e) lines that may share a package are packed in one POST', $pc + 1 === $post_n() && 2 === count( $body['lineItemRequests'] ?? array() ) && 'packed' === $o7->get_meta( '_eru_mpo_hb_pack_state' ) );
	$configure( array( 'ack_mode' => 'never' ) );
	$hb['orders'] = array();

	// I4: offset paging where a row shifts onto page 2 as well: one WooCommerce line per Hepsiburada line.
	$n9  = mpo_id();
	$l10 = mpo_id();
	$l11 = mpo_id();
	$l12 = mpo_id();
	add_filter( 'eru_mpo_hepsiburada_page_limit', $two = fn() => 2 );
	$hb['pages'] = array(
		0 => array( $line( $n9, $l10, $sku_a, 1, 199.9 ), $line( $n9, $l11, $sku_b, 1, 79.9 ) ),
		2 => array( $line( $n9, $l11, $sku_b, 1, 79.9 ), $line( $n9, $l12, $sku_b, 2, 79.9 ) ),
	);
	$hb['total'] = 4;
	$r           = Eru_MPO_Importer::pull( 'hepsiburada' );
	remove_filter( 'eru_mpo_hepsiburada_page_limit', $two );
	$hb['pages'] = array();
	$o9          = $order_of( $n9 );
	$lids        = array_map( fn( $i ) => (string) $i->get_meta( '_eru_mpo_remote_line_id' ), array_values( $o9->get_items() ) );
	mpo_check( '(I4) the overlapping row is counted once: three lines, total 439.60', 1 === $r['created'] && $router->count( 'GET', '#/orders/merchantid/' . $mid . '\?.*offset=2&#' ) >= 1 && array( $l10, $l11, $l12 ) === $lids && 43960 === Eru_MPO_Builder::minor( $o9->get_total() ) );

	// Minor 3: a line that appears later for a known order is not added: review flag and one note listing it.
	$n12          = mpo_id();
	$l14          = mpo_id();
	$l15          = mpo_id();
	$hb['orders'] = array( $line( $n12, $l14, $sku_a, 1, 199.9 ) );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$hb['orders'] = array( $line( $n12, $l14, $sku_a, 1, 199.9 ), $line( $n12, $l15, $sku_b, 1, 79.9 ) );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$o12   = $order_of( $n12 );
	$notes = array_filter( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $o12->get_id() ) ), 'content' ), fn( $n ) => false !== strpos( $n, 'Demo Ürün ' . $l15 ) );
	mpo_check( '(minor 3) later line: not added, order flagged for review, one note naming it', 1 === count( $o12->get_items() ) && 'yes' === $o12->get_meta( '_eru_mpo_review' ) && 1 === count( $notes ) );
	$hb['orders'] = array();

	// I3: one lifecycle endpoint fails (400); the others are still applied; the failed one backs off on its own.
	$n8           = mpo_id();
	$l13          = mpo_id();
	$n10          = mpo_id();
	$l17          = mpo_id();
	$hb['orders'] = array( $line( $n8, $l13, $sku_a, 1, 199.9 ), $line( $n10, $l17, $sku_a, 1, 199.9 ) );
	Eru_MPO_Importer::pull( 'hepsiburada' );
	$hb['orders']            = array();
	$hb['fail']['shipped']   = 400;
	$hb['delivered']         = array(
		mpo_fixture(
			'hb-status-row.json',
			array(
				'{{NUM}}'    => $n8,
				'{{PN}}'     => 'DEMO-PKT-' . $n8,
				'{{STATUS}}' => 'Delivered',
				'{{TRACK}}'  => 'DEMO-TRK-' . $n8,
			)
		),
	);
	$hb['undelivered']       = array(
		mpo_fixture(
			'hb-status-row.json',
			array(
				'{{NUM}}'    => $n10,
				'{{PN}}'     => 'DEMO-PKT-' . $n10,
				'{{STATUS}}' => 'UnDelivered',
				'{{TRACK}}'  => 'DEMO-TRK-' . $n10,
			)
		),
	);
	$log_max = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}eru_mpo_log" ); // phpcs:ignore
	$r       = Eru_MPO_Importer::pull( 'hepsiburada', array( 'lifecycle' => true ) );
	$rows    = $wpdb->get_col( $wpdb->prepare( "SELECT message FROM {$wpdb->prefix}eru_mpo_log WHERE id > %d AND marketplace = 'hepsiburada'", $log_max ) ); // phpcs:ignore
	mpo_check( '(I3) /shipped answers 400: the run still applies /delivered (eru-mpo-delivered)', 'ok' === $r['result'] && 'eru-mpo-delivered' === $order_of( $n8 )->get_status() );
	mpo_check( '(minor 1) undelivered → shipped (not the terminal returned)', 'eru-mpo-shipped' === $order_of( $n10 )->get_status() && 'UnDelivered' === $order_of( $n10 )->get_meta( '_eru_mpo_remote_status' ) );
	mpo_check( '(I3) the failed endpoint gets its own log entry and backoff', 1 === count( array_filter( $rows, fn( $m ) => false !== strpos( $m, '/shipped' ) && false !== strpos( $m, 'yeniden denenecek' ) ) ) );
	$shipped_calls = $router->count( 'GET', '#/shipped\?#' );
	$deliv_calls   = $router->count( 'GET', '#/delivered\?#' );
	Eru_MPO_Importer::pull( 'hepsiburada', array( 'lifecycle' => true ) );
	mpo_check( '(I3) inside its backoff the failed endpoint is skipped; the others are asked again', $shipped_calls === $router->count( 'GET', '#/shipped\?#' ) && $deliv_calls + 1 === $router->count( 'GET', '#/delivered\?#' ) );
	$hb['fail']        = array();
	$hb['delivered']   = array();
	$hb['undelivered'] = array();
	delete_option( 'eru_mpo_sync_endpoints_hepsiburada' );

	// (f) Auth: no developer username → not configured, no request; the Basic header is never logged.
	$calls = count( $router->calls );
	Eru_MPO_Credentials::update( $adapter(), array( 'dev_username' => '', 'enabled' => 'yes' ) + $creds );
	$a = $adapter();
	$f = $a->fetch_orders( time() - DAY_IN_SECONDS, time() );
	mpo_check( '(f) developer username missing: not configured, error, no request', ! $a->is_configured() && is_wp_error( $f ) && 'not_ready' === Eru_MPO_Importer::pull( 'hepsiburada' )['result'] && count( $router->calls ) === $calls );
	$configure();
	$all = mpo_all_stored_text( 'hepsiburada' );
	mpo_check( '(f) the password and the Basic header never reach the log or raw tables; identity numbers masked', false === strpos( $all, $creds['password'] ) && false === strpos( $all, substr( $basic, 6 ) ) && false === strpos( $all, $tckn ) );
	$mp_opt = get_option( 'eru_mpo_mp_hepsiburada' );
	mpo_check( '(f) a blank password on save keeps the stored one', $creds['password'] === Eru_MPO_Credentials::sanitize( Eru_MPO_Adapter_Hepsiburada::settings_fields(), array( 'password' => '' ), $mp_opt )['values']['password'] );
	$bad = Eru_MPO_Credentials::sanitize( Eru_MPO_Adapter_Hepsiburada::settings_fields(), array( 'base_live' => 'https://example.com/oms' ), $mp_opt );
	mpo_check( '(f) a base URL outside hepsiburada.com is refused (credentials go to that host)', Eru_MPO_Adapter_Hepsiburada::BASE_LIVE === $bad['values']['base_live'] && 1 === count( $bad['errors'] ) );
} catch ( Throwable $e ) {
	$failed = true;
	// mpo_check() printed its own FAIL line (labels start with "(" or "set-up"); anything else is unexpected.
	if ( ! $e instanceof RuntimeException || ! preg_match( '/^(\(|set-up)/', $e->getMessage() ) ) {
		echo 'FAIL: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	}
} finally {
	$cleanup();
}
if ( $failed ) {
	exit( 1 );
}
echo "OK: mpo-hepsiburada\n";
