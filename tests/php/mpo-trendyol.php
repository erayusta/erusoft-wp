<?php
/**
 * Marketplace Orders for WooCommerce — Trendyol adapter: packages page 1+2 → DTOs, totals, lines, statuses (a),
 * picking acknowledgement per ack mode, retried without duplicates (b), cancelled and re-created package (c),
 * credentials, User-Agent and request-log redaction (f), 429 backoff without sleeping (g), Product Push bridge (h),
 * scheduler pending-only re-arm and master switch (i).
 *
 * Every request is answered by MPO_Router from invented fixtures; any other URL ends the run.
 *
 *   wp eval-file tests/php/mpo-trendyol.php
 */

require __DIR__ . '/mpo-adapter-helpers.php';
mpo_require_plugin();
foreach ( array( 'Eru_MPO_Http', 'Eru_MPO_Adapter_Trendyol', 'Eru_MPO_Sync', 'Eru_MPO_Ack', 'Eru_MPO_PPT_Bridge', 'Eru_MPO_Credentials' ) as $mpo_c ) {
	if ( ! class_exists( $mpo_c ) ) {
		echo "FAIL: {$mpo_c} missing\n";
		exit( 1 );
	}
}

global $wpdb;
$router       = new MPO_Router();
$host         = 'https://api.demo-pazaryeri.invalid/integration'; // Invented: a leak can never reach a real API.
add_filter( 'eru_mpo_trendyol_base_url', fn() => $host );
$email        = 'mpo.test.ty@example.com';
$old_settings = get_option( Eru_MPO_Settings::OPTION, null );
$old_mp       = get_option( 'eru_mpo_mp_trendyol', null );
$failed       = false;
$tag          = mpo_run_tag();
$start        = gmdate( 'Y-m-d H:i:s', time() - 1 );
$sku_a        = 'MPO-TEST-' . $tag . '-A';
$sku_b        = 'MPO-TEST-' . $tag . '-B';
$pid_a        = 0;
$pid_b        = 0;
$rate_id      = 0;
$tckn         = mpo_fake_tckn();
$taxno        = mpo_tax_number();
$creds        = array(
	'seller_id'       => '1000',
	'api_key'         => 'demo-' . 'key-' . strtolower( $tag ),
	'api_secret'      => 'demo-' . 'secret-' . strtolower( $tag ),
	'integration_ref' => 'DemoEntegrasyon',
);
$basic        = 'Basic ' . base64_encode( $creds['api_key'] . ':' . $creds['api_secret'] ); // phpcs:ignore
$ordered      = time() - HOUR_IN_SECONDS;

/** One package from the template. */
$pkg = function ( $id, $num, $status = 'Created', array $o = array() ) use ( $tckn, $taxno, $sku_a, $sku_b, $ordered ) {
	$p = mpo_fixture(
		'ty-package.json',
		array(
			'{{PKG}}'           => $id,
			'{{NUM}}'           => $num,
			'{{STATUS}}'        => $status,
			'{{DATE_MS}}'       => ( $ordered + 3 * HOUR_IN_SECONDS ) * 1000,
			'{{TCKN}}'          => $tckn,
			'{{TAXNO}}'         => $taxno,
			'{{TRACK}}'         => $o['track'] ?? '',
			'{{LINE_A}}'        => $id . '1',
			'{{LINE_B}}'        => $id . '2',
			'{{LINE_A_STATUS}}' => $o['line_a'] ?? 'Created',
			'{{SKU_A}}'         => $sku_a,
			'{{SKU_B}}'         => $sku_b,
		)
	);
	if ( isset( $o['lines'] ) ) { // Only some of the template's lines (a split package).
		$p['lines']             = array_values( array_intersect_key( $p['lines'], array_flip( $o['lines'] ) ) );
		$p['packageTotalPrice'] = round( array_sum( array_map( fn( $l ) => $l['lineUnitPrice'] * $l['quantity'], $p['lines'] ) ), 2 );
	}
	return $p;
};
$page = fn( array $content, $n = 0, $pages = 1 ) => array(
	'page'       => $n,
	'size'       => 200,
	'totalPages' => $pages,
	'content'    => $content,
);

// The order endpoint: status lists by status and page, packages by order number.
$book = array(
	'status' => array(),
	'number' => array(),
	'fail'   => array(),
);
$orders_route = function ( $req ) use ( &$book, $page ) {
	$q = $req['query'];
	if ( isset( $q['orderNumber'] ) ) {
		$n = (string) $q['orderNumber'];
		if ( isset( $book['fail'][ 'number:' . $n ] ) ) {
			return mpo_resp( array_shift( $book['fail'][ 'number:' . $n ] ), array( 'errors' => array( array( 'message' => 'Demo hata' ) ) ) );
		}
		return mpo_resp( 200, $page( $book['number'][ $n ] ?? array() ) );
	}
	$s = (string) ( $q['status'] ?? '' );
	if ( isset( $book['spy'] ) ) {
		( $book['spy'] )();
	}
	if ( isset( $book['throw'][ 'status:' . $s ] ) ) {
		$fn = $book['throw'][ 'status:' . $s ];
		unset( $book['throw'][ 'status:' . $s ] );
		$fn();
	}
	if ( ! empty( $book['fail'][ 'status:' . $s ] ) ) {
		$f = array_shift( $book['fail'][ 'status:' . $s ] );
		return is_array( $f ) ? mpo_resp( $f[0], $f[1], $f[2] ?? array() ) : mpo_resp( $f, '' );
	}
	$p = (int) ( $q['page'] ?? 0 );
	return mpo_resp( 200, $book['status'][ $s ][ $p ] ?? $page( array() ) );
};
$puts       = array();
$put_route  = function ( $req ) use ( &$puts ) {
	preg_match( '#/shipment-packages/(\d+)#', $req['url'], $m );
	$id = $m[1] ?? '';
	if ( ! empty( $puts[ $id ] ) ) {
		$code = array_shift( $puts[ $id ] );
		if ( 'throw' === $code ) {
			throw new RuntimeException( 'Demo: process died after sending' );
		}
		return mpo_resp( $code, 200 === $code ? '' : array( 'errors' => array( array( 'message' => 'Demo hata' ) ) ) );
	}
	return mpo_resp( 200, '' );
};
$routes = function () use ( $router, $orders_route, $put_route ) {
	$router->reset();
	$router->on( 'GET', '#^https://api\.demo-pazaryeri\.invalid/integration/order/sellers/1000/orders\?#', $orders_route );
	$router->on( 'PUT', '#^https://api\.demo-pazaryeri\.invalid/integration/order/sellers/1000/shipment-packages/\d+$#', $put_route );
};
$put_count = fn( $id ) => $router->count( 'PUT', '#/shipment-packages/' . $id . '$#' );
$order_of  = function ( $ref ) {
	$ids = mpo_order_ids( $ref, 'trendyol' );
	Eru_MPO_Importer::forget_order( $ids[0] ?? 0 );
	return $ids ? wc_get_order( $ids[0] ) : null;
};
$ack_pending = fn( $order ) => mpo_pending_args( Eru_MPO_Ack::HOOK, array( (int) $order->get_id() ) );
$adapter     = function () {
	Eru_MPO_Registry::reset();
	return Eru_MPO_Registry::get( 'trendyol' );
};
$configure = function ( array $over = array() ) use ( $creds, $adapter ) {
	Eru_MPO_Settings::update( array_merge( Eru_MPO_Settings::defaults(), array( 'enabled' => 'yes', 'observe' => 'no', 'stock_mode' => 'reduce' ) ) );
	Eru_MPO_Credentials::update( $adapter(), array_merge( array( 'enabled' => 'yes', 'ack_mode' => 'never' ), $creds, $over ) );
	Eru_MPO_Settings::reset_cache();
};

// Restores the demo on every exit path, including exit() from the router's catch-all (shutdown).
$cleaned = false;
$cleanup = function () use ( &$cleaned, &$pid_a, &$pid_b, &$rate_id, $email, $old_settings, $old_mp ) {
	if ( $cleaned ) {
		return;
	}
	$cleaned = true;
	remove_all_filters( 'eru_mpo_ppt_active' );
	mpo_adapter_cleanup( 'trendyol', $email );
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
	null === $old_mp ? delete_option( 'eru_mpo_mp_trendyol' ) : update_option( 'eru_mpo_mp_trendyol', $old_mp, false );
	Eru_MPO_Settings::reset_cache();
	Eru_MPO_Cron::unschedule_all();
};
register_shutdown_function( $cleanup );

try {
	mpo_adapter_cleanup( 'trendyol', $email );
	delete_option( 'eru_mpo_mp_trendyol' );
	mpo_delete_products();
	$routes();
	$configure();
	$pid_a   = mpo_product( $sku_a, 10, '149.90' );
	$pid_b   = mpo_product( $sku_b, 10, '89.90' );
	$rate_id = mpo_tax_rate( 20 );
	$ty      = $adapter();
	mpo_check( 'set-up: the Trendyol adapter is registered and configured; default hosts are the documented gateways, the suite uses an invented host', $ty instanceof Eru_MPO_Adapter_Trendyol && $ty->is_configured() && $ty->is_enabled() && Eru_MPO_Adapter_Trendyol::BASE_LIVE === Eru_MPO_Adapter_Trendyol::settings()['base_live'] && 'https://apigw.trendyol.com/integration' === Eru_MPO_Adapter_Trendyol::BASE_LIVE && $host === Eru_MPO_Adapter_Trendyol::base_url() );

	// (a) Packages page 1 + 2 → DTOs with correct totals and lines; Created → new; Delivered → delivered, forward only.
	$p1 = mpo_id();
	$p2 = mpo_id();
	$p3 = mpo_id();
	$n1 = mpo_id();
	$book['status']['Created'] = array(
		0 => mpo_fixture(
			'ty-packages-p1.json',
			array(
				'{{PKG_1}}' => wp_json_encode( $pkg( $p1, $n1 ) ),
				'{{PKG_2}}' => wp_json_encode( $pkg( $p2, mpo_id() ) ),
			)
		),
		1 => mpo_fixture( 'ty-packages-p2.json', array( '{{PKG_3}}' => wp_json_encode( $pkg( $p3, mpo_id() ) ) ) ),
	);
	$dtos = $ty->fetch_orders( time() - DAY_IN_SECONDS, time() );
	mpo_check( '(a) fetch_orders reads page 1 and page 2: three DTOs', is_array( $dtos ) && 3 === count( $dtos ) && 1 === $router->count( 'GET', '#status=Created.*page=1|page=1.*status=Created#' ) );
	mpo_check( '(a) one request per configured status (Created, Picking, Invoiced)', $router->count( 'GET', '#status=Picking#' ) >= 1 && $router->count( 'GET', '#status=Invoiced#' ) >= 1 && 0 === $router->count( 'GET', '#status=Delivered#' ) );
	$d1 = $dtos[0];
	mpo_check( '(a) DTO: package id is the reference, order number, status new, remote status Created', $p1 === $d1->remote_ref && $p1 === $d1->package_id && $n1 === $d1->order_number && 'new' === $d1->status && 'Created' === $d1->remote_status );
	mpo_check( '(a) DTO lines: unit price before discount, line discount = (149.90 - 139.90) × 2, commission 12.5, VAT 20', 2 === count( $d1->lines ) && 149.9 === $d1->lines[0]->unit_price && 20.0 === $d1->lines[0]->discount && 2 === $d1->lines[0]->quantity && 12.5 === $d1->lines[0]->commission_rate && 20.0 === $d1->lines[0]->vat_rate && $sku_a === $d1->lines[0]->sku && $p1 . '1' === $d1->lines[0]->remote_line_id );
	mpo_check( '(a) DTO total 369.70 = 279.80 + 89.90; buyer, Ankara plate 6, ordered_at back in UTC', 369.7 === $d1->totals->total && 'mpo.test.ty@example.com' === $d1->customer->email && 6 === $d1->shipping->city_code && abs( $d1->ordered_at - $ordered ) < 2 );

	$router->calls = array();
	$r             = Eru_MPO_Importer::pull( 'trendyol' );
	mpo_check( '(a) pull imports the three packages', 'ok' === $r['result'] && 3 === $r['fetched'] && 3 === $r['created'] );
	mpo_check( '(a) headers: Basic auth and the User-Agent "1000 - DemoEntegrasyon"; no credential in any URL', $basic === ( $router->calls[0]['headers']['Authorization'] ?? '' ) && '1000 - DemoEntegrasyon' === ( $router->calls[0]['headers']['User-Agent'] ?? '' ) && ! array_filter( $router->calls, fn( $c ) => false !== strpos( $c['url'], $creds['api_key'] ) || false !== strpos( $c['url'], $creds['api_secret'] ) ) );
	$o1 = $order_of( $p1 );
	mpo_check( '(a) one order per package; page 2 package imported too', $o1 && 1 === count( mpo_order_ids( $p1, 'trendyol' ) ) && 1 === count( mpo_order_ids( $p3, 'trendyol' ) ) );
	$items = array_values( $o1->get_items() );
	$gross = fn( $i ) => round( (float) $i->get_total() + (float) $i->get_total_tax(), 2 );
	$sub   = fn( $i ) => round( (float) $i->get_subtotal() + (float) $i->get_subtotal_tax(), 2 );
	mpo_check( '(a) order total 369.70; line A 299.80 before and 279.80 after discount; line B 89.90; VAT split', '369.70' === wc_format_decimal( $o1->get_total(), 2 ) && 2 === count( $items ) && 299.8 === $sub( $items[0] ) && 279.8 === $gross( $items[0] ) && 89.9 === $gross( $items[1] ) && (float) $items[0]->get_total_tax() > 0 );
	mpo_check( '(a) line meta: SKU, line id, commission 12.5 and the discount kept', $sku_a === $items[0]->get_meta( '_eru_mpo_sku' ) && $p1 . '1' === $items[0]->get_meta( '_eru_mpo_remote_line_id' ) && '12.5' === (string) $items[0]->get_meta( '_eru_mpo_commission_rate' ) && '20.00' === (string) $items[0]->get_meta( '_eru_mpo_discount' ) );
	mpo_check( '(a) payment shows the marketplace: method and title "Trendyol" (never the internal id)', 'Trendyol' === $o1->get_payment_method_title() && 'Trendyol' === $o1->get_payment_method() );
	mpo_check( '(a) status Created → eru-mpo-new; order meta: order number, remote status, tax number; identity number not stored', 'eru-mpo-new' === $o1->get_status() && $n1 === $o1->get_meta( '_eru_mpo_order_number' ) && 'Created' === $o1->get_meta( '_eru_mpo_remote_status' ) && $taxno === $o1->get_meta( '_eru_mpo_tax_number' ) && '' === (string) $o1->get_meta( '_eru_mpo_identity_number' ) && 'TR06' === $o1->get_billing_state() );
	$raw = Eru_MPO_Raw::get( 'trendyol', $p1 );
	mpo_check( '(a) raw payload kept with the identity number masked', is_array( $raw ) && false === strpos( wp_json_encode( $raw ), $tckn ) );

	$book['status']['Delivered'] = array( 0 => $page( array( $pkg( $p1, $n1, 'Delivered', array( 'track' => 'DEMO-TRK-1' ) ) ) ) );
	$r                           = Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	$o1                          = $order_of( $p1 );
	mpo_check( '(a) lifecycle Delivered → eru-mpo-delivered with the tracking number', 1 === $r['updated'] && 'eru-mpo-delivered' === $o1->get_status() && 'DEMO-TRK-1' === $o1->get_meta( '_eru_mpo_cargo_tracking' ) );
	Eru_MPO_Importer::pull( 'trendyol' ); // Created again (stale list).
	$book['status']['Delivered'] = array();
	$book['status']['Shipped']   = array( 0 => $page( array( $pkg( $p1, $n1, 'Shipped' ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	$o1 = $order_of( $p1 );
	mpo_check( '(a) a stale Created and a later Shipped never move it back (rank)', 'eru-mpo-delivered' === $o1->get_status() && 'Delivered' === $o1->get_meta( '_eru_mpo_remote_status' ) );
	$book['status'] = array();

	// (b) Acknowledgement per ack mode: one PUT per package, none in "never", retried without duplicates.
	$configure( array( 'ack_mode' => 'import' ) );
	$p4                        = mpo_id();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p4, mpo_id() ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$o4 = $order_of( $p4 );
	$pa = $ack_pending( $o4 );
	mpo_check( '(b) ack mode "on import": one acknowledgement queued for the new order, nothing sent yet', 1 === count( $pa ) && 0 === $put_count( $p4 ) && 'queued' === $o4->get_meta( '_eru_mpo_ack_state' ) );
	mpo_run_action( $pa[0] );
	$o4   = $order_of( $p4 );
	$put  = array_values( array_filter( $router->calls, fn( $c ) => 'PUT' === $c['method'] ) );
	$body = json_decode( (string) ( $put[0]['body'] ?? '' ), true );
	mpo_check( '(b) one PUT: status Picking with both line ids and quantities', 1 === $put_count( $p4 ) && 'Picking' === ( $body['status'] ?? '' ) && array( array( 'lineId' => (int) ( $p4 . '1' ), 'quantity' => 2 ), array( 'lineId' => (int) ( $p4 . '2' ), 'quantity' => 1 ) ) === ( $body['lines'] ?? null ) );
	mpo_check( '(b) state done, time stamped, order moved to accepted, note added, nothing pending', 'done' === $o4->get_meta( '_eru_mpo_ack_state' ) && '' !== (string) $o4->get_meta( '_eru_mpo_ack_at' ) && 'eru-mpo-accepted' === $o4->get_status() && array() === $ack_pending( $o4 ) );
	mpo_check( '(b) a second run or enqueue sends nothing more', 'idle' === Eru_MPO_Ack::run( $o4->get_id() ) && false === Eru_MPO_Ack::enqueue( $o4 ) && 1 === $put_count( $p4 ) );

	$configure( array( 'ack_mode' => 'never' ) );
	$p5                        = mpo_id();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p5, mpo_id() ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$o5 = $order_of( $p5 );
	mpo_check( '(b) ack mode "never": nothing queued, nothing sent', $o5 && array() === $ack_pending( $o5 ) && '' === (string) $o5->get_meta( '_eru_mpo_ack_state' ) && 0 === $put_count( $p5 ) );

	$configure( array( 'ack_mode' => 'import' ) );
	$p6                        = mpo_id();
	$n6                        = mpo_id();
	$p7                        = mpo_id();
	$n7                        = mpo_id();
	$puts[ $p6 ]               = array( 500 );
	$puts[ $p7 ]               = array( 503, 200 );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p6, $n6 ), $pkg( $p7, $n7 ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$o6 = $order_of( $p6 );
	$o7 = $order_of( $p7 );
	$t0 = time();
	mpo_run_action( $ack_pending( $o6 )[0] );
	mpo_run_action( $ack_pending( $o7 )[0] );
	$o6 = $order_of( $p6 );
	$a6 = $ack_pending( $o6 );
	mpo_check( '(b) 500 on the PUT: state retry, one retry scheduled about a minute later, first-failure note', 'retry' === $o6->get_meta( '_eru_mpo_ack_state' ) && 1 === count( $a6 ) && abs( mpo_action_time( $a6[0] ) - ( $t0 + 60 ) ) <= 5 && 1 === (int) $o6->get_meta( '_eru_mpo_ack_tries' ) );
	// The first PUT did reach Trendyol: the package is Picking now. The retry reads it first and sends nothing.
	$book['number'][ $n6 ] = array( $pkg( $p6, $n6, 'Picking' ) );
	$book['number'][ $n7 ] = array( $pkg( $p7, $n7, 'Created' ) );
	mpo_run_action( $a6[0] );
	$o6 = $order_of( $p6 );
	mpo_check( '(b) retry after an ambiguous failure looks the package up first: Picking → done, still one PUT', 'done' === $o6->get_meta( '_eru_mpo_ack_state' ) && 1 === $put_count( $p6 ) && 1 === $router->count( 'GET', '#orderNumber=' . $n6 . '#' ) );
	mpo_run_action( $ack_pending( $o7 )[0] );
	$o7 = $order_of( $p7 );
	mpo_check( '(b) still Created after a 503: the retry sends once more and is done (two PUTs in all)', 'done' === $o7->get_meta( '_eru_mpo_ack_state' ) && 2 === $put_count( $p7 ) && array() === $ack_pending( $o7 ) );
	Eru_MPO_Ack::run( $o6->get_id() );
	Eru_MPO_Ack::run( $o7->get_id() );
	mpo_check( '(b) done orders are never sent again', 1 === $put_count( $p6 ) && 2 === $put_count( $p7 ) );
	// Minor 5: the run dies right after sending. The check flag was stored before the PUT, so the retry reads first.
	$p8                        = mpo_id();
	$n8                        = mpo_id();
	$puts[ $p8 ]               = array( 'throw' );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p8, $n8 ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$o8 = $order_of( $p8 );
	mpo_run_action( $ack_pending( $o8 )[0] );
	$o8 = $order_of( $p8 );
	mpo_check( '(b) died after sending: the check flag was stored before the PUT, a retry is queued', 'yes' === $o8->get_meta( '_eru_mpo_ack_check' ) && 'retry' === $o8->get_meta( '_eru_mpo_ack_state' ) && 1 === $put_count( $p8 ) );
	$book['number'][ $n8 ] = array( $pkg( $p8, $n8, 'Picking' ) );
	mpo_run_action( $ack_pending( $o8 )[0] );
	$o8 = $order_of( $p8 );
	mpo_check( '(b) the retry reads the package first (Picking) and does not send again', 'done' === $o8->get_meta( '_eru_mpo_ack_state' ) && 1 === $put_count( $p8 ) && '' === (string) $o8->get_meta( '_eru_mpo_ack_check' ) );

	// Status mode: queued when the shop moves the order to the chosen status.
	$configure( array( 'ack_mode' => 'status', 'ack_status' => 'eru-mpo-ready' ) );
	$o5 = $order_of( $p5 );
	$o5->update_status( 'eru-mpo-ready' );
	mpo_check( '(b) ack mode "on status": moving the order to the chosen status queues it', 1 === count( $ack_pending( $o5 ) ) );
	foreach ( $ack_pending( $o5 ) as $aid ) {
		as_unschedule_action( Eru_MPO_Ack::HOOK, array( (int) $o5->get_id() ), Eru_MPO_Cron::GROUP );
	}
	$configure( array( 'ack_mode' => 'never' ) );
	$book['status'] = array();

	// (c) Cancelled and re-created package with the same order number: the existing order gains the new package.
	$p9                          = mpo_id();
	$p10                         = mpo_id();
	$n9                          = mpo_id();
	$stock0a                     = mpo_stock( $pid_a );
	$stock0b                     = mpo_stock( $pid_b );
	$book['status']['Created']   = array( 0 => $page( array( $pkg( $p9, $n9 ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$o9 = $order_of( $p9 );
	mpo_check( '(c) first package imported, stock reduced once (reduce mode)', $o9 && $stock0a - 2 === mpo_stock( $pid_a ) && $stock0b - 1 === mpo_stock( $pid_b ) );
	$book['status']              = array();
	$book['status']['Cancelled'] = array( 0 => $page( array( $pkg( $p9, $n9, 'Cancelled' ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	$o9 = $order_of( $p9 );
	mpo_check( '(c) the package is cancelled on Trendyol: order cancelled, stock restored', 'eru-mpo-cancelled' === $o9->get_status() && $stock0a === mpo_stock( $pid_a ) && $stock0b === mpo_stock( $pid_b ) );
	$book['status']            = array();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p10, $n9 ) ) ) );
	$r                         = Eru_MPO_Importer::pull( 'trendyol' );
	$o9                        = $order_of( $p10 );
	$row                       = mpo_map_row( $p10, 'trendyol' );
	mpo_check( '(c) the new package with the same order number joins the existing order: no second order', 0 === $r['created'] && 1 === $r['updated'] && $o9 && 1 === count( Eru_MPO_Adapter_Trendyol::orders_by_number( $n9 ) ) && $row && (int) $row->wc_order_id === $o9->get_id() && 'done' === $row->status );
	mpo_check( '(c) the order follows the new package: reference, package list, reopened as new, stock reduced again', $p10 === $o9->get_meta( '_eru_mpo_remote_ref' ) && $p10 === $o9->get_meta( '_eru_mpo_package_id' ) && array( $p9, $p10 ) === $o9->get_meta( '_eru_mpo_packages' ) && 'eru-mpo-new' === $o9->get_status() && $stock0a - 2 === mpo_stock( $pid_a ) );
	$notes = implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $o9->get_id() ) ), 'content' ) );
	mpo_check( '(c) an order note names both packages', false !== strpos( $notes, $p9 ) && false !== strpos( $notes, $p10 ) );
	$book['status']              = array();
	$book['status']['Cancelled'] = array( 0 => $page( array( $pkg( $p9, $n9, 'Cancelled' ) ) ) );
	$r                           = Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	$o9                          = $order_of( $p10 );
	mpo_check( '(c) a later payload of the earlier, cancelled package changes nothing (superseded)', 1 === $r['unchanged'] && 'eru-mpo-new' === $o9->get_status() && $stock0a - 2 === mpo_stock( $pid_a ) );
	$book['status'] = array();

	// (c) The earlier package is not cancelled locally yet: Trendyol is asked by order number.
	$p11                       = mpo_id();
	$p12                       = mpo_id();
	$n11                       = mpo_id();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p11, $n11 ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$o11                       = $order_of( $p11 );
	$o11->update_meta_data( '_eru_mpo_ack_state', 'done' ); // The earlier package was acknowledged.
	$o11->save_meta_data();
	$configure( array( 'ack_mode' => 'import' ) );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p12, $n11 ) ) ) );
	$book['number'][ $n11 ]    = array( $pkg( $p11, $n11, 'UnPacked' ), $pkg( $p12, $n11 ) );
	$r                         = Eru_MPO_Importer::pull( 'trendyol' );
	$o11 = $order_of( $p12 );
	mpo_check( '(c) the new package of a re-linked order is acknowledged again (state reset and queued)', 'queued' === $o11->get_meta( '_eru_mpo_ack_state' ) && 1 === count( $ack_pending( $o11 ) ) );
	as_unschedule_all_actions( Eru_MPO_Ack::HOOK, array( (int) $o11->get_id() ), Eru_MPO_Cron::GROUP );
	$configure( array( 'ack_mode' => 'never' ) );
	mpo_check( '(c) earlier package read as UnPacked on Trendyol: linked to the same order, one lookup', 0 === $r['created'] && 1 === count( Eru_MPO_Adapter_Trendyol::orders_by_number( $n11 ) ) && $p12 === $order_of( $p12 )->get_meta( '_eru_mpo_remote_ref' ) && $order_of( $p12 )->get_id() === $o11->get_id() && 1 === $router->count( 'GET', '#orderNumber=' . $n11 . '#' ) );

	// A genuine split (both packages active) gets its own order; an unreadable predecessor is retried, never duplicated.
	$p13                       = mpo_id();
	$p14                       = mpo_id();
	$n13                       = mpo_id();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p13, $n13 ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p14, $n13 ) ) ) );
	$book['number'][ $n13 ]    = array( $pkg( $p13, $n13 ), $pkg( $p14, $n13 ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	mpo_check( '(c) both packages active (a split): the second package gets its own order', 2 === count( Eru_MPO_Adapter_Trendyol::orders_by_number( $n13 ) ) );
	$p15                       = mpo_id();
	$p16                       = mpo_id();
	$n15                       = mpo_id();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $p15, $n15 ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$book['status']['Created']         = array( 0 => $page( array( $pkg( $p16, $n15 ) ) ) );
	$book['fail'][ 'number:' . $n15 ]  = array( 503 );
	$r                                 = Eru_MPO_Importer::pull( 'trendyol' );
	mpo_check( '(c) predecessor unreadable (503): no order created, counted as an error', 0 === $r['created'] && 1 === $r['errors'] && 1 === count( Eru_MPO_Adapter_Trendyol::orders_by_number( $n15 ) ) );
	$book['number'][ $n15 ] = array( $pkg( $p15, $n15, 'Cancelled' ), $pkg( $p16, $n15 ) );
	Eru_MPO_Registry::reset();
	$r = Eru_MPO_Importer::pull( 'trendyol' );
	mpo_check( '(c) next pull reads it and links the package: still one order', 0 === $r['created'] && 1 === count( Eru_MPO_Adapter_Trendyol::orders_by_number( $n15 ) ) );
	$book['status'] = array();

	// I1: package A (two lines) is unpacked into B (line 1) and C (line 2): one order each, every line counted once.
	$pa                        = mpo_id();
	$pb                        = mpo_id();
	$pc                        = mpo_id();
	$ns                        = mpo_id();
	$sa0                       = mpo_stock( $pid_a );
	$sb0                       = mpo_stock( $pid_b );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pa, $ns ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pb, $ns, 'Created', array( 'lines' => array( 0 ) ) ), $pkg( $pc, $ns, 'Created', array( 'lines' => array( 1 ) ) ) ) ) );
	$book['number'][ $ns ]     = array( $pkg( $pa, $ns, 'UnPacked' ), $pkg( $pb, $ns, 'Created', array( 'lines' => array( 0 ) ) ), $pkg( $pc, $ns, 'Created', array( 'lines' => array( 1 ) ) ) );
	$r                         = Eru_MPO_Importer::pull( 'trendyol' );
	$oa                        = $order_of( $pa );
	$ob                        = $order_of( $pb );
	$oc                        = $order_of( $pc );
	$sum                       = fn( ...$os ) => array_sum( array_map( fn( $o ) => Eru_MPO_Builder::minor( $o->get_total() ), $os ) );
	mpo_check( '(I1) split into packages with different lines: each new package gets its own order (no relink)', 2 === $r['created'] && 3 === count( Eru_MPO_Adapter_Trendyol::orders_by_number( $ns ) ) && $ob && $oc && $ob->get_id() !== $oa->get_id() && 1 === count( $ob->get_items() ) && 1 === count( $oc->get_items() ) );
	mpo_check( '(I1) the unpacked order is replaced: cancelled, total 0, its lines zeroed, linked to the new order, with a note', 'eru-mpo-cancelled' === $oa->get_status() && 0 === Eru_MPO_Builder::minor( $oa->get_total() ) && in_array( $ob->get_id(), array_map( 'intval', (array) $oa->get_meta( '_eru_mpo_replaced_by' ) ), true ) && ! array_filter( $oa->get_items(), fn( $i ) => 'replaced' !== $i->get_meta( '_eru_mpo_line_status' ) || 0.0 !== (float) $i->get_total() ) && false !== strpos( implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $oa->get_id() ) ), 'content' ) ), $pb ) );
	mpo_check( '(I1) totals count each line once: 279.80 + 89.90 = the original 369.70, the replaced order 0', 36970 === $sum( $oa, $ob, $oc ) && 27980 === Eru_MPO_Builder::minor( $ob->get_total() ) && 8990 === Eru_MPO_Builder::minor( $oc->get_total() ) );
	mpo_check( '(I1) stock counts each line once (A −2, B −1 in all)', $sa0 - 2 === mpo_stock( $pid_a ) && $sb0 - 1 === mpo_stock( $pid_b ) );
	$book['status']              = array();
	$book['status']['UnPacked']  = array( 0 => $page( array( $pkg( $pa, $ns, 'UnPacked' ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	mpo_check( '(I1) the later UnPacked payload of A changes nothing (stock still counted once)', $sa0 - 2 === mpo_stock( $pid_a ) && $sb0 - 1 === mpo_stock( $pid_b ) && 'eru-mpo-cancelled' === $order_of( $pa )->get_status() );
	// Cancelled first (stock already restored), then a package with fewer lines: no reopen, no second restore.
	$px                          = mpo_id();
	$py                          = mpo_id();
	$nx                          = mpo_id();
	$book['status']              = array();
	$book['status']['Created']   = array( 0 => $page( array( $pkg( $px, $nx ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$book['status']              = array();
	$book['status']['Cancelled'] = array( 0 => $page( array( $pkg( $px, $nx, 'Cancelled' ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	$sa1                         = mpo_stock( $pid_a );
	$sb1                         = mpo_stock( $pid_b );
	$book['status']              = array();
	$book['status']['Created']   = array( 0 => $page( array( $pkg( $py, $nx, 'Created', array( 'lines' => array( 0 ) ) ) ) ) );
	$r                           = Eru_MPO_Importer::pull( 'trendyol' );
	$ox                          = $order_of( $px );
	mpo_check( '(I1) cancelled order, new package with other lines: not reopened, own order, stock reduced once for it only', 1 === $r['created'] && 'eru-mpo-cancelled' === $ox->get_status() && 0 === Eru_MPO_Builder::minor( $ox->get_total() ) && $sa1 - 2 === mpo_stock( $pid_a ) && $sb1 === mpo_stock( $pid_b ) );
	$book['status'] = array();

	// N2: a local (admin) cancel alone never makes an order replaceable: Trendyol still reports the package active.
	$pl                        = mpo_id();
	$pm                        = mpo_id();
	$nl                        = mpo_id();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pl, $nl ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$ol = $order_of( $pl );
	Eru_MPO_Statuses::advance( $ol, 'cancelled', 'Demo: elle iptal' );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pm, $nl, 'Created', array( 'lines' => array( 0 ) ) ) ) ) );
	$book['number'][ $nl ]     = array( $pkg( $pl, $nl, 'Created' ), $pkg( $pm, $nl, 'Created', array( 'lines' => array( 0 ) ) ) );
	$r                         = Eru_MPO_Importer::pull( 'trendyol' );
	$ol                        = $order_of( $pl );
	mpo_check( '(N2) locally cancelled but active on Trendyol: the new package gets its own order, the old one is not replaced or zeroed', 1 === $r['created'] && '' === (string) $ol->get_meta( '_eru_mpo_replaced_by' ) && 36970 === Eru_MPO_Builder::minor( $ol->get_total() ) && 1 === $router->count( 'GET', '#orderNumber=' . $nl . '#' ) );
	$book['status'] = array();

	// N3: the replace fails (the cancel cannot be saved): both orders flagged with a note, one retry queued with a
	// backoff; the retry completes it and stock is counted once.
	$pq                        = mpo_id();
	$pr                        = mpo_id();
	$nq                        = mpo_id();
	$sa2                       = mpo_stock( $pid_a );
	$sb2                       = mpo_stock( $pid_b );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pq, $nq ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$oq    = $order_of( $pq );
	$block = function ( $o ) use ( $oq ) {
		if ( $o instanceof WC_Order && $o->get_id() === $oq->get_id() && 'eru-mpo-cancelled' === $o->get_status() ) {
			throw new Error( 'Demo: kayıt engellendi' );
		}
	};
	add_action( 'woocommerce_before_order_object_save', $block );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pr, $nq, 'Created', array( 'lines' => array( 0 ) ) ) ) ) );
	$book['number'][ $nq ]     = array( $pkg( $pq, $nq, 'UnPacked' ), $pkg( $pr, $nq, 'Created', array( 'lines' => array( 0 ) ) ) );
	$t0                        = time();
	Eru_MPO_Importer::pull( 'trendyol' );
	remove_action( 'woocommerce_before_order_object_save', $block );
	$oq   = $order_of( $pq );
	$or   = $order_of( $pr );
	$rq   = mpo_pending_args( Eru_MPO_Importer::REPLACE_HOOK, array( $oq->get_id(), $or->get_id(), 2 ) );
	$note = fn( $o, $frag ) => false !== strpos( implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $o->get_id() ) ), 'content' ) ), $frag );
	mpo_check( '(N3) replace failed: both orders flagged for review with a note naming the double count', 'yes' === $oq->get_meta( '_eru_mpo_review' ) && 'yes' === $or->get_meta( '_eru_mpo_review' ) && $note( $oq, 'iki kez' ) && $note( $or, 'iki kez' ) && 'eru-mpo-new' === $oq->get_status() );
	mpo_check( '(N3) one retry queued through Action Scheduler with a backoff (5 minutes)', 1 === count( $rq ) && abs( mpo_action_time( $rq[0] ) - ( $t0 + 300 ) ) <= 5 && 1 === count( mpo_pending( Eru_MPO_Importer::REPLACE_HOOK ) ) );
	mpo_run_action( $rq[0] );
	$oq = $order_of( $pq );
	mpo_check( '(N3) the retry replaces the old order: cancelled, zeroed, stock counted once (A −2, B −1 → A −2 only for the new package)', 'eru-mpo-cancelled' === $oq->get_status() && 0 === Eru_MPO_Builder::minor( $oq->get_total() ) && $sa2 - 2 === mpo_stock( $pid_a ) && $sb2 === mpo_stock( $pid_b ) && array() === mpo_pending( Eru_MPO_Importer::REPLACE_HOOK ) );

	// M2: the replace also takes the OLD package's lock. While another run holds it (e.g. a lifecycle update of the
	// old package), nothing is cancelled twice: both orders are flagged and one retry is queued; the retry replaces it.
	$pu                        = mpo_id();
	$pv                        = mpo_id();
	$nu                        = mpo_id();
	$sa3                       = mpo_stock( $pid_a );
	$sb3                       = mpo_stock( $pid_b );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pu, $nu ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$held                      = Eru_MPO_Lock::acquire( Eru_MPO_Importer::lock_key( 'trendyol', $pu ), 300 );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pv, $nu, 'Created', array( 'lines' => array( 0 ) ) ) ) ) );
	$book['number'][ $nu ]     = array( $pkg( $pu, $nu, 'UnPacked' ), $pkg( $pv, $nu, 'Created', array( 'lines' => array( 0 ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$ou = $order_of( $pu );
	$ov = $order_of( $pv );
	$rv = $ov ? mpo_pending_args( Eru_MPO_Importer::REPLACE_HOOK, array( $ou->get_id(), $ov->get_id(), 2 ) ) : array();
	mpo_check( '(M2) old package locked by another run: not replaced now, both orders flagged, one retry queued', $held && $ov && 'eru-mpo-new' === $ou->get_status() && 'yes' === $ou->get_meta( '_eru_mpo_review' ) && 'yes' === $ov->get_meta( '_eru_mpo_review' ) && 1 === count( $rv ) && $sa3 - 4 === mpo_stock( $pid_a ) );
	Eru_MPO_Lock::release( Eru_MPO_Importer::lock_key( 'trendyol', $pu ), $held );
	mpo_run_action( $rv[0] );
	$ou = $order_of( $pu );
	mpo_check( '(M2) the retry takes the lock and replaces: cancelled, zeroed, stock counted once', 'eru-mpo-cancelled' === $ou->get_status() && 0 === Eru_MPO_Builder::minor( $ou->get_total() ) && $sa3 - 2 === mpo_stock( $pid_a ) && $sb3 === mpo_stock( $pid_b ) );

	// M1: the relink save fails once: the new package's map row is removed, so the next pull links again and
	// reopens the order (stock reduced once) instead of treating it as an ordinary, still-cancelled order.
	$pw                          = mpo_id();
	$px                          = mpo_id();
	$nw                          = mpo_id();
	$sa4                         = mpo_stock( $pid_a );
	$sb4                         = mpo_stock( $pid_b );
	$book['status']              = array();
	$book['status']['Created']   = array( 0 => $page( array( $pkg( $pw, $nw ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol' );
	$book['status']              = array();
	$book['status']['Cancelled'] = array( 0 => $page( array( $pkg( $pw, $nw, 'Cancelled' ) ) ) );
	Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	$ow    = $order_of( $pw );
	$once  = true;
	$block = function ( $o ) use ( $ow, &$once ) {
		if ( $once && $o instanceof WC_Order && $o->get_id() === $ow->get_id() && 'eru-mpo-new' === $o->get_status() ) {
			$once = false;
			throw new Error( 'Demo: relink kaydı engellendi' );
		}
	};
	add_action( 'woocommerce_before_order_object_save', $block );
	$book['status']            = array();
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $px, $nw ) ) ) );
	$r1                        = Eru_MPO_Importer::pull( 'trendyol' );
	remove_action( 'woocommerce_before_order_object_save', $block );
	mpo_check( '(M1) the relink save fails: error, no second order, the new package has no map row left', 1 === $r1['errors'] && 0 === $r1['created'] && null === mpo_map_row( $px, 'trendyol' ) && 'eru-mpo-cancelled' === $order_of( $pw )->get_status() );
	mpo_clear_backoff( $px, 'trendyol' );
	$r2 = Eru_MPO_Importer::pull( 'trendyol' );
	$ow = $order_of( $px );
	mpo_check( '(M1) the next pull links again: one order, reopened, following the new package, stock reduced once', 0 === $r2['created'] && 1 === $r2['updated'] && $ow && 'eru-mpo-new' === $ow->get_status() && $px === $ow->get_meta( '_eru_mpo_remote_ref' ) && 1 === count( Eru_MPO_Adapter_Trendyol::orders_by_number( $nw ) ) && $sa4 - 2 === mpo_stock( $pid_a ) && $sb4 - 1 === mpo_stock( $pid_b ) );
	$book['status'] = array();
	$book['status'] = array();

	// N1: migrated and adopted legacy Trendyol orders keep their lines: an update inside the window adds no
	// "new lines" note and no review flag. The migrator takes the line ids from the legacy raw package.
	$legacy = function ( $pkgid, $num, $with_raw ) use ( $sku_a, $sku_b, $email, $pkg ) {
		$o = new WC_Order();
		foreach ( array( array( $sku_a, 'DEMO-BC-A', 2, '279.80' ), array( $sku_b, 'DEMO-BC-B', 1, '89.90' ) ) as $l ) {
			$it = new WC_Order_Item_Product();
			$it->set_name( 'Demo eski ürün ' . $l[0] );
			$it->set_quantity( $l[2] );
			$it->set_subtotal( $l[3] );
			$it->set_total( $l[3] );
			$it->add_meta_data( 'trendyol_stock_code', $l[0], true );
			$it->add_meta_data( 'trendyol_barcode', $l[1], true );
			$o->add_item( $it );
		}
		$o->set_billing_email( $email );
		$o->set_payment_method( 'trendyol' );
		$o->set_payment_method_title( 'Trendyol' );
		$o->update_meta_data( '_eru_trendyol_package_id', $pkgid );
		$o->update_meta_data( '_eru_trendyol_order_number', $num );
		if ( $with_raw ) {
			$o->update_meta_data( '_eru_trendyol_raw', wp_json_encode( $pkg( $pkgid, $num ) ) );
		}
		$o->set_total( '369.70' );
		$o->set_status( 'on-hold' );
		return (int) $o->save();
	};
	$pg   = mpo_id();
	$ng   = mpo_id();
	$ph   = mpo_id();
	$nh   = mpo_id();
	$migr = $legacy( $pg, $ng, true );
	$adop = $legacy( $ph, $nh, false );
	mpo_check( '(N1) set-up: the migrator moves the first legacy order', 'migrated' === Eru_MPO_Migrator::migrate_order( $migr, 'trendyol', $pg ) );
	$om  = wc_get_order( $migr );
	$ids = array_map( fn( $i ) => (string) $i->get_meta( '_eru_mpo_remote_line_id' ), array_values( $om->get_items() ) );
	mpo_check( '(N1) migrated items get the remote line ids from the legacy raw package; payment shows "Trendyol"', array( $pg . '1', $pg . '2' ) === $ids && 'Trendyol' === $om->get_payment_method_title() && 'Trendyol' === $om->get_payment_method() );
	$book['status']['Created'] = array( 0 => $page( array( $pkg( $pg, $ng ), $pkg( $ph, $nh ) ) ) );
	$r                         = Eru_MPO_Importer::pull( 'trendyol' );
	Eru_MPO_Importer::pull( 'trendyol', array( 'lifecycle' => true ) );
	$om = $order_of( $pg );
	$oa2 = $order_of( $ph );
	mpo_check( '(N1) the migrated order updated inside the window: no new-lines note, no review flag', $om && $om->get_id() === $migr && ! $note( $om, 'sonradan satır' ) && 'yes' !== $om->get_meta( '_eru_mpo_review' ) );
	mpo_check( '(N1) the legacy order adopted on import (no line ids at all): no new-lines note, no review flag, no second order', 0 === $r['created'] && $oa2 && $oa2->get_id() === $adop && ! $note( $oa2, 'sonradan satır' ) && 'yes' !== $oa2->get_meta( '_eru_mpo_review' ) );
	$book['status'] = array();

	// (f) Missing credentials → no HTTP; the request log never holds credentials, identity or tax numbers.
	$calls = count( $router->calls );
	Eru_MPO_Credentials::update( $adapter(), array( 'clear_api_key' => '1', 'enabled' => 'yes' ) + $creds );
	$ty = $adapter();
	$f  = $ty->fetch_orders( time() - DAY_IN_SECONDS, time() );
	$r  = Eru_MPO_Importer::pull( 'trendyol' );
	mpo_check( '(f) API key missing: not configured, fetch returns an error, pull is not_ready, no request sent', ! $ty->is_configured() && is_wp_error( $f ) && 'eru_mpo_not_configured' === $f->get_error_code() && 'not_ready' === $r['result'] && count( $router->calls ) === $calls );
	$f = Eru_MPO_Adapter_Trendyol::settings_fields();
	$configure();
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
	ob_start();
	Eru_MPO_Credentials::render( $adapter() );
	$html = (string) ob_get_clean();
	wp_set_current_user( 0 );
	mpo_check( '(f) the rendered form never contains a stored secret; it says "Kayıtlı" instead', 'secret' === $f['api_key']['type'] && '' !== $html && false === strpos( $html, $creds['api_key'] ) && false === strpos( $html, $creds['api_secret'] ) && false !== strpos( $html, 'Kayıtlı' ) );
	$typed = '  a%41' . "b<x>\\c  "; // A percent octet, a tag and a backslash.
	mpo_check( '(f) a secret is stored as typed (trimmed only: %xx, < > and backslashes kept)', "a%41b<x>\\c" === Eru_MPO_Credentials::sanitize( $f, array( 'api_secret' => $typed ), array() )['values']['api_secret'] );
	$stored = get_option( 'eru_mpo_mp_trendyol' );
	$auto   = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'eru_mpo_mp_trendyol' ) ); // phpcs:ignore
	mpo_check( '(f) a blank secret keeps the stored one; the option is not autoloaded', $creds['api_secret'] === Eru_MPO_Credentials::sanitize( Eru_MPO_Adapter_Trendyol::settings_fields(), array( 'api_secret' => '' ), $stored )['values']['api_secret'] && in_array( $auto, array( 'no', 'off' ), true ) );
	$book['fail']['status:Created'] = array(
		array(
			500,
			mpo_fixture(
				'ty-error-echo.json',
				array(
					'{{AUTH}}'   => $basic,
					'{{SECRET}}' => $creds['api_secret'],
					'{{KEY}}'    => $creds['api_key'],
					'{{TCKN}}'   => $tckn,
					'{{TAXNO}}'  => $taxno,
				)
			),
		),
		array( 502, str_repeat( 'Demo uzun yanıt ', 2000 ) ),
	);
	$log_max = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}eru_mpo_log" ); // phpcs:ignore
	$r1      = Eru_MPO_Importer::pull( 'trendyol' );
	$r2      = Eru_MPO_Importer::pull( 'trendyol' );
	$rows    = array_values( array_filter( mpo_log_rows( 'trendyol', $start ), fn( $x ) => (int) $x->id > $log_max ) );
	$text     = implode( "\n", wp_list_pluck( $rows, 'message' ) );
	$http     = array_values( array_filter( $rows, fn( $x ) => false !== strpos( $x->message, '/orders?' ) ) );
	mpo_check( '(f) failing requests are logged with method, path, status and the redacted response', 'error' === $r1['result'] && 'error' === $r2['result'] && 2 === count( $http ) && false !== strpos( $http[0]->message, 'GET /integration/order/sellers/1000/orders?' ) && false !== strpos( $http[0]->message, '500' ) && false !== strpos( $http[0]->message, 'Yanıt:' ) );
	$all = mpo_all_stored_text( 'trendyol' );
	mpo_check( '(f) Basic auth header, API key and secret never logged (log and raw tables)', false === strpos( $all, substr( $basic, 6 ) ) && false === strpos( $all, $creds['api_secret'] ) && false === strpos( $all, $creds['api_key'] ) );
	mpo_check( '(f) identity and tax numbers never in the request log (identity numbers not in raw either)', false === strpos( $all, $tckn ) && false === strpos( $text, $taxno ) && false !== strpos( $http[0]->message, '[gizli]' ) );
	$body2 = (string) substr( $http[1]->message, (int) strpos( $http[1]->message, 'Yanıt:' ) );
	mpo_check( '(f) response bodies are capped at 8 KB', strlen( $body2 ) <= 8192 + 64 && false !== strpos( $body2, 'kesildi' ) );
	$calls = count( $router->calls );
	$leak  = Eru_MPO_Http::request( 'trendyol', 'GET', $host . '/x?key=' . rawurlencode( $creds['api_key'] ), array(), null, array( 'secrets' => array( $creds['api_key'] ) ) );
	mpo_check( '(f) a URL carrying a credential is refused before sending', ! $leak['ok'] && count( $router->calls ) === $calls );

	// (g) 429 → retryable error, the next run is scheduled with a backoff, nothing sleeps.
	Eru_MPO_Cron::unschedule_all();
	$book['fail']['status:Created'] = array( 429, 429 );
	$t0                             = microtime( true );
	$res                            = Eru_MPO_Sync::run( 'orders', 'trendyol' );
	$elapsed                        = microtime( true ) - $t0;
	$pend                           = mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) );
	mpo_check( '(g) 429: pull error, next run scheduled one minute later, run took under a second', 'error' === $res['result'] && 60 === $res['delay'] && 1 === count( $pend ) && abs( mpo_action_time( $pend[0] ) - ( time() + 60 ) ) <= 5 && $elapsed < 1.0 );
	$st = Eru_MPO_Sync::state( 'orders', 'trendyol' );
	mpo_check( '(g) run state: retry, 1 failure, HTTP 429', 'retry' === $st['result'] && 1 === $st['fails'] && 429 === $st['code'] );
	as_unschedule_all_actions( Eru_MPO_Sync::ORDERS, array( 'trendyol' ), Eru_MPO_Cron::GROUP );
	$res = Eru_MPO_Sync::run( 'orders', 'trendyol' );
	mpo_check( '(g) a second 429 doubles the delay (2 minutes)', 120 === $res['delay'] && 2 === Eru_MPO_Sync::state( 'orders', 'trendyol' )['fails'] );
	as_unschedule_all_actions( Eru_MPO_Sync::ORDERS, array( 'trendyol' ), Eru_MPO_Cron::GROUP );
	$book['fail']['status:Created'] = array( array( 503, '', array( 'Retry-After' => '7200' ) ) );
	$res                            = Eru_MPO_Sync::run( 'orders', 'trendyol' );
	mpo_check( '(g) a long Retry-After is capped at 30 minutes', 1800 === $res['delay'] );
	as_unschedule_all_actions( Eru_MPO_Sync::ORDERS, array( 'trendyol' ), Eru_MPO_Cron::GROUP );
	$res = Eru_MPO_Sync::run( 'orders', 'trendyol' );
	mpo_check( '(g) a clean run resets the count and uses the normal 5-minute interval', 'ok' === $res['result'] && 300 === $res['delay'] && 0 === Eru_MPO_Sync::state( 'orders', 'trendyol' )['fails'] );

	// I2: the fetch throws. The next run was armed before the pull, so exactly one pending action remains.
	Eru_MPO_Cron::unschedule_all();
	$during = -1;
	$book['throw']['status:Created'] = function () use ( &$during ) {
		$during = count( mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) ) );
		throw new RuntimeException( 'Demo: unexpected failure inside the fetch' );
	};
	$res  = Eru_MPO_Sync::run( 'orders', 'trendyol' );
	$pend = mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) );
	mpo_check( '(I2) a throw inside the fetch: the run ends with an error, the next run was already pending during the fetch, exactly one pending action remains', 'error' === $res['result'] && 1 === $during && 1 === count( $pend ) );
	as_unschedule_all_actions( Eru_MPO_Sync::ORDERS, array( 'trendyol' ), Eru_MPO_Cron::GROUP );
	delete_option( Eru_MPO_Sync::state_option( 'orders', 'trendyol' ) );

	// N4: a slow pull. The successor armed before the pull is kept on a normal outcome; nothing is cancelled and
	// re-created. (The pull "takes" 6 s: the spy moves the target interval by 6 s during the fetch, which is what a
	// 6-second pull does to "interval from the end of the run".)
	Eru_MPO_Cron::unschedule_all();
	$shift       = 0;
	$slow        = function ( $sec ) use ( &$shift ) {
		return $sec + $shift;
	};
	add_filter( 'eru_mpo_sync_interval', $slow );
	$book['spy'] = function () use ( &$shift ) {
		$shift = 6;
	};
	$canceled = fn() => as_get_scheduled_actions(
		array(
			'hook'     => Eru_MPO_Sync::ORDERS,
			'args'     => array( 'trendyol' ),
			'status'   => ActionScheduler_Store::STATUS_CANCELED,
			'per_page' => 500,
		),
		'ids'
	);
	$before = $canceled(); // Earlier test clean-ups cancelled some; only new rows count.
	$res    = Eru_MPO_Sync::run( 'orders', 'trendyol' );
	unset( $book['spy'] );
	remove_filter( 'eru_mpo_sync_interval', $slow );
	mpo_check( '(N4) slow normal run: one pending successor, no cancelled action rows', 'ok' === $res['result'] && 6 === $shift && 1 === count( mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) ) ) && array() === array_diff( $canceled(), $before ) );
	as_unschedule_all_actions( Eru_MPO_Sync::ORDERS, array( 'trendyol' ), Eru_MPO_Cron::GROUP );
	delete_option( Eru_MPO_Sync::state_option( 'orders', 'trendyol' ) );

	// (h) Product Push bridge.
	add_filter( 'eru_mpo_ppt_active', '__return_true' );
	$empty = array(
		'seller_id'       => '',
		'api_key'         => '',
		'api_secret'      => '',
		'integration_ref' => 'SelfIntegration',
	);
	mpo_check( '(h) setting off: Product Push credentials untouched', $empty === apply_filters( 'eru_ppt_credentials', $empty ) );
	$configure( array( 'share_ppt' => 'yes' ) );
	mpo_check( '(h) Product Push keys empty → filled from this plugin (same account, so its integration reference too)', array_merge( $creds ) === apply_filters( 'eru_ppt_credentials', $empty ) );
	$same = array( 'api_key' => $creds['api_key'] ) + $empty;
	$got  = apply_filters( 'eru_ppt_credentials', $same );
	mpo_check( '(h) Product Push holds a key of the same account: the empty keys are filled', $creds['api_key'] === $got['api_key'] && '1000' === $got['seller_id'] && $creds['api_secret'] === $got['api_secret'] );
	$own = array( 'api_key' => 'ppt-' . 'own-key' ) + $empty;
	mpo_check( '(h) Product Push holds its own, different key: nothing is filled (no mixed accounts)', $own === apply_filters( 'eru_ppt_credentials', $own ) );
	$ref = array( 'integration_ref' => 'DigerEntegrasyon' ) + $empty;
	mpo_check( '(h) Product Push holds another integration reference (key owner): nothing is filled', $ref === apply_filters( 'eru_ppt_credentials', $ref ) );
	$other = array( 'seller_id' => '2000' ) + $empty;
	mpo_check( '(h) Product Push has another seller ID: nothing is filled', $other === apply_filters( 'eru_ppt_credentials', $other ) );
	remove_filter( 'eru_mpo_ppt_active', '__return_true' );
	add_filter( 'eru_mpo_ppt_active', '__return_false' );
	mpo_check( '(h) Product Push not active: untouched', $empty === apply_filters( 'eru_ppt_credentials', $empty ) );
	remove_filter( 'eru_mpo_ppt_active', '__return_false' );
	$configure();

	// (i) Scheduler: master off → nothing; armed once per marketplace and kind; re-arm counts pending only.
	Eru_MPO_Cron::unschedule_all();
	Eru_MPO_Settings::update( array( 'enabled' => 'no' ) );
	Eru_MPO_Sync::ensure();
	mpo_check( '(i) master off: ensure schedules nothing', array() === mpo_pending( Eru_MPO_Sync::ORDERS ) && array() === mpo_pending( Eru_MPO_Sync::LIFECYCLE ) );
	$book['status']['Created'] = array( 0 => $page( array() ) );
	mpo_check( '(i) master off: a scheduled run does nothing and does not re-arm', 'not_wanted' === Eru_MPO_Sync::run( 'orders', 'trendyol' )['result'] && array() === mpo_pending( Eru_MPO_Sync::ORDERS ) );
	Eru_MPO_Settings::update( array( 'enabled' => 'yes' ) );
	Eru_MPO_Sync::ensure();
	Eru_MPO_Sync::ensure();
	mpo_check( '(i) master on: one orders chain and one lifecycle chain for Trendyol, none for the unconfigured Hepsiburada', 1 === count( mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) ) ) && 1 === count( mpo_pending_args( Eru_MPO_Sync::LIFECYCLE, array( 'trendyol' ) ) ) && array() === mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'hepsiburada' ) ) );
	$aid = mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) )[0];
	ActionScheduler::store()->log_execution( $aid ); // The action is now running (in progress), not pending.
	try {
		$during      = -1;
		$book['spy'] = function () use ( &$during ) {
			if ( -1 === $during ) {
				$during = count( mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) ) );
			}
		};
		Eru_MPO_Sync::run( 'orders', 'trendyol' );
		unset( $book['spy'] );
		$now = mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) );
		mpo_check( '(i) the running action arms its successor before pulling (pending-only: the running one does not count)', 1 === $during );
		mpo_check( '(i) the running action re-arms itself: exactly one new pending action', 1 === count( $now ) && (int) $now[0] !== (int) $aid && abs( mpo_action_time( $now[0] ) - ( time() + 300 ) ) <= 5 );
	} finally {
		ActionScheduler::store()->mark_complete( $aid );
	}
	Eru_MPO_Sync::run( 'orders', 'trendyol' );
	mpo_check( '(i) a run while one is pending adds none', 1 === count( mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) ) ) );
	$tok = Eru_MPO_Lock::acquire( 'sync_orders_trendyol', 900 );
	$lk  = Eru_MPO_Sync::run( 'orders', 'trendyol' );
	Eru_MPO_Lock::release( 'sync_orders_trendyol', $tok );
	mpo_check( '(i) lock held by another runner: locked, no pull, chain kept', 'locked' === $lk['result'] && 1 === count( mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) ) ) );
	$configure( array( 'enabled' => 'no' ) );
	Eru_MPO_Sync::ensure();
	mpo_check( '(i) marketplace switched off: ensure removes its chains', array() === mpo_pending_args( Eru_MPO_Sync::ORDERS, array( 'trendyol' ) ) && array() === mpo_pending_args( Eru_MPO_Sync::LIFECYCLE, array( 'trendyol' ) ) );
	$configure();
	Eru_MPO_Sync::ensure();
	Eru_MPO_Plugin::deactivate();
	mpo_check( '(i) deactivation removes every job of the group', array() === mpo_pending_group() );
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
echo "OK: mpo-trendyol\n";
