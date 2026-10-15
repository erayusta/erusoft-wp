<?php
/**
 * Dopigo Integration — index, SKU, VAT, order transform, status map, push lock, queued product updates,
 * importer and pull. Every HTTP call is answered by the router below; anything else exits 1.
 *
 *   wp eval-file tests/php/dpg-logic.php
 */

// Demo phone numbers are assembled so the privacy scrubber does not treat them as real data.
$ph_intl  = '+90 (' . implode( ') ', array( '555', '000 00 00' ) );
$ph_local = '0' . implode( ' ', array( '555', '000', '00', '00' ) );
$ph_bare  = implode( '', array( '555', '000', '0000' ) );

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

foreach ( array( 'Eru_DPG_API', 'Eru_DPG_Settings', 'Eru_DPG_Products', 'Eru_DPG_Importer', 'Eru_DPG_Orders', 'Eru_DPG_Status', 'Eru_DPG_Cron', 'Eru_DPG_Logger' ) as $eru_dpg_class ) {
	if ( ! class_exists( $eru_dpg_class ) ) {
		echo "FAIL: {$eru_dpg_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

$fixtures = __DIR__ . '/dpg-fixtures/';
$products_page = json_decode( file_get_contents( $fixtures . 'products-page.json' ), true );

// ---------------------------------------------------------------------------------------------
// HTTP router.
// ---------------------------------------------------------------------------------------------
$reqs       = array();
$on_order   = null;   // Callback run inside the POST /orders/ answer (re-entrancy test).
$orders_out = '';
$post_mode  = 'ok';   // ok | timeout | 502 | 429 — answer of POST /orders/.
$list_mode  = 'ok';   // ok | error — answer of GET /orders/ (lookup by service_order_id).
$router = function ( $pre, $args, $url ) use ( &$reqs, &$on_order, &$orders_out, &$post_mode, &$list_mode, $products_page ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$m    = strtoupper( $args['method'] ?? 'GET' );
	$ok   = function ( $body, $code = 200 ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	};
	if ( 'panel.dopigo.com' !== $host ) {
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	}
	$reqs[] = array( 'method' => $m, 'path' => $path, 'url' => $url, 'args' => $args );
	if ( 'POST' === $m && '/users/get_auth_token/' === $path ) {
		return $ok( array( 'tok' . 'en' => 'demo-logic-' . 'value' ) );
	}
	if ( 'GET' === $m && '/api/v1/products/all/' === $path ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$off = (int) ( $q['offset'] ?? 0 );
		$res = $off > 0 ? array() : $products_page['results'];
		return $ok( array( 'count' => count( $products_page['results'] ), 'next' => null, 'results' => array_slice( $res, 0, (int) ( $q['limit'] ?? 100 ) ) ) );
	}
	if ( 'GET' === $m && preg_match( '#^/api/v1/products/product_meta/(\d+)/$#', $path, $mm ) ) {
		return $ok( array( 'meta_id' => (int) $mm[1], 'category' => array( 'id' => 502, 'name' => 'Demo Kategori 2' ) ) );
	}
	if ( 'POST' === $m && '/api/v1/orders/' === $path ) {
		if ( $on_order ) {
			$cb       = $on_order;
			$on_order = null;
			$cb();
		}
		if ( 'timeout' === $post_mode ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}
		if ( in_array( $post_mode, array( '502', '429' ), true ) ) {
			return $ok( array( 'detail' => 'busy' ), (int) $post_mode );
		}
		return $ok( array( 'id' => 5001 ), 201 );
	}
	if ( 'PUT' === $m && preg_match( '#^/api/v1/orders/\d+/$#', $path ) ) {
		return $ok( array( 'id' => 5001 ) );
	}
	if ( 'GET' === $m && '/api/v1/orders/' === $path ) {
		if ( 'error' === $list_mode ) {
			return $ok( array( 'detail' => 'down' ), 500 );
		}
		return array( 'headers' => array(), 'body' => $orders_out, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( 'PUT' === $m && 0 === strpos( $path, '/api/v1/products/update_product_by_foreign_sku/' ) ) {
		return $ok( array( 'ok' => true ) );
	}
	echo 'FAIL unmocked HTTP ' . $m . ' ' . $url . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $router, 10, 3 );

$count_reqs = function ( $method, $path ) use ( &$reqs ) {
	return count( array_filter( $reqs, fn( $r ) => $r['method'] === $method && $r['path'] === $path ) );
};
$pending = function ( $hook, $args = null ) {
	$q = array( 'hook' => $hook, 'group' => 'eru-dpg', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100 );
	if ( null !== $args ) {
		$q['args'] = $args;
	}
	return count( as_get_scheduled_actions( $q, 'ids' ) );
};

// ---------------------------------------------------------------------------------------------
// Fixture data (all invented) and clean-up of an aborted earlier run.
// ---------------------------------------------------------------------------------------------
$old_settings = get_option( Eru_DPG_Settings::OPTION, null );
$cleanup = function () {
	global $wpdb;
	foreach ( array( 'DEMO-001', 'DEMO-002', 'DEMO-SHARED', 'DPG-77011', 'DPG-77012', 'DEMO-001-77001', 'DP-77021' ) as $sku ) {
		$id = wc_get_product_id_by_sku( $sku );
		if ( $id ) {
			$p = wc_get_product( $id );
			$parent = $p && $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : null;
			if ( $parent ) {
				$parent->delete( true );
			}
			if ( $p ) {
				$p->delete( true );
			}
		}
	}
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ('9101','9102','9103')", '_eru_dpg_product_id' ) ); // phpcs:ignore
	foreach ( $ids as $id ) {
		$p = wc_get_product( (int) $id );
		if ( $p ) {
			$p->delete( true );
		}
	}
	foreach ( wc_get_orders( array( 'billing_email' => 'ornek@example.com', 'limit' => -1, 'created_via' => 'eru-dpg-test' ) ) as $o ) {
		delete_option( 'eru_dpg_lock_order_' . $o->get_id() );
		$o->delete( true );
	}
	foreach ( array( 'Demo Kategori', 'Demo Kategori 2' ) as $name ) {
		$t = get_term_by( 'name', $name, 'product_cat' );
		if ( $t ) {
			wp_delete_term( $t->term_id, 'product_cat' );
		}
	}
	foreach ( array( 'eru_dpg_push_product', 'eru_dpg_push_order', 'eru_dpg_push_status', 'eru_dpg_pull', 'eru_dpg_import', 'eru_dpg_daily' ) as $h ) {
		as_unschedule_all_actions( $h, null, 'eru-dpg' );
	}
	delete_option( 'eru_dpg_token' );
	delete_option( 'eru_dpg_import_state' );
	delete_option( 'eru_dpg_pull_state' );
	delete_option( 'eru_dpg_category_names' );
};
$cleanup();

$base_settings = array(
	'enabled'          => 'yes',
	'auth_mode'        => 'password',
	'username'         => 'demo-user',
	'password'         => 'demo-' . 'logic-pass',
	'dry_run'          => 'no',
	'stock_direction'  => 'off',
	'price_direction'  => 'off',
	'queue_updates'    => 'yes',
	'vat_mode'         => 'gross',
	'default_vat'      => '20',
	'order_push'       => 'no',
	'status_direction' => 'off',
	'import_enabled'   => 'no',
);
update_option( Eru_DPG_Settings::OPTION, array(), false );
Eru_DPG_Settings::save_for_test( $base_settings );

$make_product = function ( $sku, $price, $stock ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Ürün ' . $sku );
	$p->set_sku( $sku );
	$p->set_regular_price( (string) $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->set_status( 'publish' );
	$p->save();
	return $p;
};

$make_order = function ( $p1, $p2 ) use ( $ph_intl ) {
	$o = wc_create_order( array( 'created_via' => 'eru-dpg-test' ) );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( 'Müşteri' );
	$o->set_billing_email( 'ornek@example.com' );
	$o->set_billing_phone( $ph_intl );
	$o->set_billing_address_1( 'Demo Sokak No: 1' );
	$o->set_billing_city( 'Konak' );
	$o->set_billing_state( 'TR35' );
	$o->set_billing_postcode( '35000' );
	$o->set_billing_country( 'TR' );
	$o->set_shipping_first_name( 'Demo' );
	$o->set_shipping_last_name( 'Müşteri' );
	$o->set_shipping_address_1( 'Demo Sokak No: 1' );
	$o->set_shipping_city( 'Konak' );
	$o->set_shipping_state( 'TR35' );
	$o->set_shipping_country( 'TR' );

	$i1 = new WC_Order_Item_Product();
	$i1->set_product( $p1 );
	$i1->set_quantity( 2 );
	$i1->set_subtotal( '1000' );
	$i1->set_total( '1000' );
	$i1->set_taxes( array( 'total' => array( 1 => '200' ), 'subtotal' => array( 1 => '200' ) ) );
	$o->add_item( $i1 );

	$i2 = new WC_Order_Item_Product();
	$i2->set_product( $p2 );
	$i2->set_quantity( 1 );
	$i2->set_subtotal( '300' );
	$i2->set_total( '300' );
	$i2->set_taxes( array( 'total' => array( 2 => '30' ), 'subtotal' => array( 2 => '30' ) ) );
	$o->add_item( $i2 );

	$s = new WC_Order_Item_Shipping();
	$s->set_method_title( 'Demo Kargo' );
	$s->set_total( '50' );
	$s->set_taxes( array( 'total' => array( 1 => '10' ) ) );
	$o->add_item( $s );

	$o->set_total( '1590.00' );
	$o->set_status( 'pending' );
	$o->save();
	return $o;
};

try {
	// -----------------------------------------------------------------------------------------
	// (a) Index builder.
	// -----------------------------------------------------------------------------------------
	$page = $products_page['results'];
	$ix   = Eru_DPG_API::index_from_batch( array_slice( $page, 0, 1 ) );
	$ix   = Eru_DPG_API::index_from_batch( array_slice( $page, 1 ), $ix );
	$check( '(a) variants sharing a foreign_sku merge into one entry with a count of 2', 1 === count( array_keys( $ix['by_foreign_sku'], $ix['by_foreign_sku']['DEMO-SHARED'], true ) ) && 2 === $ix['foreign_sku_counts']['DEMO-SHARED'] );
	$check( '(a) the first variant wins the shared foreign_sku slot', '77011' === (string) $ix['by_foreign_sku']['DEMO-SHARED']['id'] );
	$check( '(a) both variants stay addressable by variant id and own sku', isset( $ix['by_variant_id']['77011'], $ix['by_variant_id']['77012'], $ix['by_sku']['DPG-77011'], $ix['by_sku']['DPG-77012'] ) );
	$check( '(a) chained batches: 4 variants, meta id carried, categories counted', 4 === count( $ix['by_variant_id'] ) && '9102' === (string) $ix['by_variant_id']['77012']['_meta_id'] && 3 === $ix['categories'][501] && 'Demo Kategori' === $ix['category_names'][501] );
	$check( '(a) index stays slim (no images/descriptions)', ! isset( $ix['by_variant_id']['77001']['images'] ) && ! isset( $ix['by_variant_id']['77001']['custom_attributes'] ) );

	// -----------------------------------------------------------------------------------------
	// (b) SKU selection.
	// -----------------------------------------------------------------------------------------
	$counts = $ix['foreign_sku_counts'];
	$check( '(b) unique foreign_sku is used as SKU', 'DEMO-001' === Eru_DPG_Products::pick_sku( $page[0]['products'][0], $counts ) );
	$check( '(b) shared foreign_sku falls back to the Dopigo sku', 'DPG-77011' === Eru_DPG_Products::pick_sku( $page[1]['products'][0], $counts ) && 'DPG-77012' === Eru_DPG_Products::pick_sku( $page[1]['products'][1], $counts ) );
	$check( '(b) no sku at all → DP-<variant id>', 'DP-77021' === Eru_DPG_Products::pick_sku( $page[2]['products'][0], $counts ) );
	$check( '(b) pick_sku is deterministic', Eru_DPG_Products::pick_sku( $page[1]['products'][1], $counts ) === Eru_DPG_Products::pick_sku( $page[1]['products'][1], $counts ) );

	$owner = $make_product( 'DEMO-001', 1000, 10 );
	$c1    = Eru_DPG_Importer::unique_sku_candidate( 0, 'DEMO-001', '77001' );
	$c2    = Eru_DPG_Importer::unique_sku_candidate( 0, 'DEMO-001', '77001' );
	$check( '(b) taken SKU gets a deterministic -<variant id> suffix', 'DEMO-001-77001' === $c1 && $c1 === $c2 );
	$check( '(b) the owner of a SKU keeps it', 'DEMO-001' === Eru_DPG_Importer::unique_sku_candidate( $owner->get_id(), 'DEMO-001', '77001' ) );
	$blocker = $make_product( 'DEMO-001-77001', 1000, 1 );
	$c3      = Eru_DPG_Importer::unique_sku_candidate( 0, 'DEMO-001', '77001' );
	$check( '(b) when the suffixed SKU is also taken the next candidate is collision-free', 'DEMO-001-77001-2' === $c3 && ! wc_get_product_id_by_sku( $c3 ) );
	$blocker->delete( true );

	// -----------------------------------------------------------------------------------------
	// (c) VAT gross ↔ net.
	// -----------------------------------------------------------------------------------------
	$check( '(c) 20 %: 1200 gross ↔ 1000 net', 1000.0 === Eru_DPG_Products::to_net( 1200.0, 20 ) && 1200.0 === Eru_DPG_Products::to_gross( 1000.0, 20 ) );
	$check( '(c) 10 %: 1100 gross ↔ 1000 net', 1000.0 === Eru_DPG_Products::to_net( 1100.0, 10 ) && 1100.0 === Eru_DPG_Products::to_gross( 1000.0, 10 ) );
	$exact = true;
	foreach ( array( 20, 10 ) as $rate ) {
		foreach ( array( 0.01, 0.99, 1.0, 17.35, 83.33, 99.99, 249.9, 999.99, 1234.56, 1000.0 ) as $net ) {
			$back = Eru_DPG_Products::to_net( Eru_DPG_Products::to_gross( $net, $rate ), $rate );
			if ( number_format( $back, 2, '.', '' ) !== number_format( $net, 2, '.', '' ) ) {
				$exact = false;
				echo "  round trip drift at {$rate}%: {$net} → {$back}\n";
			}
		}
	}
	$check( '(c) net → gross → net is exact to 2 decimals at 20 % and 10 %', $exact );
	$check( '(c) gross mode: WC price goes to Dopigo unchanged', 1200.0 === Eru_DPG_Products::wc_to_dopigo( 1200.0, 20 ) && 1200.0 === Eru_DPG_Products::dopigo_to_wc( 1200.0, 20 ) );
	Eru_DPG_Settings::save_for_test( array( 'vat_mode' => 'net' ) );
	$check( '(c) net mode: WC 1000 → Dopigo 1200 and back', 1200.0 === Eru_DPG_Products::wc_to_dopigo( 1000.0, 20 ) && 1000.0 === Eru_DPG_Products::dopigo_to_wc( 1200.0, 20 ) );
	Eru_DPG_Settings::save_for_test( array( 'vat_mode' => 'gross' ) );

	// -----------------------------------------------------------------------------------------
	// (d) Order transform.
	// -----------------------------------------------------------------------------------------
	$p2    = $make_product( 'DEMO-002', 300, 10 );
	$order = $make_order( $owner, $p2 );
	$tx    = Eru_DPG_Orders::transform( $order );
	$it    = $tx['items'];
	$check( '(d) two item lines', 2 === count( $it ) );
	$check( '(d) line 1: 20 % VAT, gross 1200.00, unit 600.00', 20 === $it[0]['vat'] && '1200.00' === $it[0]['price'] && '600.00' === $it[0]['unit_price'] && 2 === $it[0]['amount'] && 'DEMO-001' === $it[0]['sku'] );
	$check( '(d) line 2: 10 % VAT, gross 330.00', 10 === $it[1]['vat'] && '330.00' === $it[1]['price'] && '330.00' === $it[1]['unit_price'] );
	$check( '(d) shipping goes as gross service fee 60.00', '60.00' === $tx['service_fee'] );
	$check( '(d) total formatted with two decimals', '1590.00' === $tx['total'] );
	$check( '(d) phone in +90 (5xx) form → +90 + 10 digits (customer, billing, shipping)', '+90' . $ph_bare === $tx['customer']['phone_number'] && '+90' . $ph_bare === $tx['billing_address']['contact_phone_number'] && '+90' . $ph_bare === $tx['shipping_address']['contact_phone_number'] );
	$check( '(d) phone helper: 0-prefixed and bare numbers → +90 form; empty stays empty', '+90' . $ph_bare === Eru_DPG_Orders::normalize_phone( $ph_local ) && '+90' . $ph_bare === Eru_DPG_Orders::normalize_phone( $ph_bare ) && '' === Eru_DPG_Orders::normalize_phone( '' ) );
	$check( '(d) state TR35 → İzmir as city, WC city as district', 'İzmir' === $tx['billing_address']['city'] && 'Konak' === $tx['billing_address']['district'] && 'İzmir' === $tx['shipping_address']['city'] );
	$check( '(d) sales channel defaults to the site title', get_bloginfo( 'name' ) === $tx['sales_channel'] );
	$check( '(d) service_order_id is the WC order id', (string) $order->get_id() === $tx['service_order_id'] );
	Eru_DPG_Settings::save_for_test( array( 'sales_channel' => 'Demo Kanal' ) );
	$check( '(d) sales channel setting wins', 'Demo Kanal' === Eru_DPG_Orders::transform( $order )['sales_channel'] );
	Eru_DPG_Settings::save_for_test( array( 'sales_channel' => '' ) );

	// -----------------------------------------------------------------------------------------
	// (e) Status map.
	// -----------------------------------------------------------------------------------------
	$check( '(e) WC processing → Dopigo waiting_shipment', 'waiting_shipment' === Eru_DPG_Status::wc_to_dopigo( 'processing' ) && 'waiting_shipment' === Eru_DPG_Status::wc_to_dopigo( 'wc-processing' ) );
	$check( '(e) Dopigo delivered → WC completed', 'completed' === Eru_DPG_Status::dopigo_to_wc( 'delivered' ) );
	$check( '(e) unmapped WC status → no change', null === Eru_DPG_Status::wc_to_dopigo( 'demo-ozel-durum' ) );
	$check( '(e) unmapped / blank Dopigo status → no change', null === Eru_DPG_Status::dopigo_to_wc( 'demo_unknown' ) && null === Eru_DPG_Status::dopigo_to_wc( 'undefined' ) );
	Eru_DPG_Settings::save_for_test( array( 'status_map' => array( 'wc_to_dp' => array( 'on-hold' => 'preparing' ), 'dp_to_wc' => array( 'delivered' => '' ) ) ) );
	$check( '(e) saved mapping overrides the default; blank = no change', 'preparing' === Eru_DPG_Status::wc_to_dopigo( 'on-hold' ) && null === Eru_DPG_Status::dopigo_to_wc( 'delivered' ) );
	Eru_DPG_Settings::save_for_test( array( 'status_map' => array() ) );

	// -----------------------------------------------------------------------------------------
	// (f) Concurrent push → exactly one create.
	// -----------------------------------------------------------------------------------------
	Eru_DPG_Settings::save_for_test( array( 'order_push' => 'yes', 'order_trigger' => 'paid' ) );
	$oid  = $order->get_id();
	$reqs = array();
	Eru_DPG_Orders::on_trigger( $oid );
	Eru_DPG_Orders::on_trigger( $oid );
	$check( '(f) two trigger events queue one push action and send nothing in the request', 1 === $pending( 'eru_dpg_push_order', array( $oid ) ) && 0 === count( $reqs ) );

	$inner = 'unset';
	$on_order = function () use ( $oid, &$inner ) {
		$inner = Eru_DPG_Orders::push( $oid ); // A second event arriving while the first is in flight.
	};
	$first = Eru_DPG_Orders::push( $oid );
	$check( '(f) the in-flight event is skipped', in_array( $inner, array( 'locked', 'pending' ), true ) );
	$check( '(f) exactly one POST to the orders endpoint', 1 === $count_reqs( 'POST', '/api/v1/orders/' ) && 'created' === $first );
	$fresh = wc_get_order( $oid );
	$check( '(f) Dopigo id stored, state sent, lock released', '5001' === (string) $fresh->get_meta( '_eru_dpg_order_id' ) && 'sent' === $fresh->get_meta( '_eru_dpg_order_state' ) && false === get_option( 'eru_dpg_lock_order_' . $oid ) );

	// Second order: state=pending set by another request (no lock row) → skipped; stale pending → retried.
	$order2 = $make_order( $owner, $p2 );
	$oid2   = $order2->get_id();
	$order2->update_meta_data( '_eru_dpg_order_state', 'pending' );
	$order2->update_meta_data( '_eru_dpg_order_pending_at', time() - 30 );
	$order2->save();
	$n = $count_reqs( 'POST', '/api/v1/orders/' );
	$check( '(f) fresh pending state from another request → skipped, no POST', 'pending' === Eru_DPG_Orders::push( $oid2 ) && $n === $count_reqs( 'POST', '/api/v1/orders/' ) );
	$order2 = wc_get_order( $oid2 );
	$order2->update_meta_data( '_eru_dpg_order_pending_at', time() - 11 * MINUTE_IN_SECONDS );
	$order2->save();
	$g = $count_reqs( 'GET', '/api/v1/orders/' );
	$check( '(f) pending older than 10 minutes: looked up in Dopigo first (not found), then retried', 'created' === Eru_DPG_Orders::push( $oid2 ) && $n + 1 === $count_reqs( 'POST', '/api/v1/orders/' ) && $g + 1 === $count_reqs( 'GET', '/api/v1/orders/' ) );
	// A stale lock row (crashed request) does not block forever.
	$order3 = $make_order( $owner, $p2 );
	$oid3   = $order3->get_id();
	add_option( 'eru_dpg_lock_order_' . $oid3, (string) ( time() - 11 * MINUTE_IN_SECONDS ), '', false );
	$check( '(f) stale lock (> 10 min) is taken over', 'created' === Eru_DPG_Orders::push( $oid3 ) );
	add_option( 'eru_dpg_lock_order_' . $oid3, (string) time(), '', false );
	$order3 = wc_get_order( $oid3 );
	$order3->delete_meta_data( '_eru_dpg_order_id' );
	$order3->save();
	$check( '(f) fresh lock row blocks a second push', 'locked' === Eru_DPG_Orders::push( $oid3 ) );
	delete_option( 'eru_dpg_lock_order_' . $oid3 );

	// -----------------------------------------------------------------------------------------
	// (g) Retry after the id was saved → no second create.
	// -----------------------------------------------------------------------------------------
	$n_post = $count_reqs( 'POST', '/api/v1/orders/' );
	$check( '(g) event after the Dopigo id is stored does not create again', 'exists' === Eru_DPG_Orders::push( $oid ) && $n_post === $count_reqs( 'POST', '/api/v1/orders/' ) );
	do_action( 'eru_dpg_push_order', $oid ); // The queued action from (f) runs late.
	$check( '(g) the queued action runs without a second POST', $n_post === $count_reqs( 'POST', '/api/v1/orders/' ) );
	$check( '(g) a forced resend updates (PUT) instead of creating', 'updated' === Eru_DPG_Orders::push( $oid, true ) && $n_post === $count_reqs( 'POST', '/api/v1/orders/' ) && 1 <= $count_reqs( 'PUT', '/api/v1/orders/5001/' ) );

	// Dry run builds the payload but sends nothing.
	$order4 = $make_order( $owner, $p2 );
	Eru_DPG_Settings::save_for_test( array( 'dry_run' => 'yes' ) );
	$n_all = count( $reqs );
	$check( '(g) dry run: no write request, order not marked as sent', 'dry-run' === Eru_DPG_Orders::push( $order4->get_id() ) && 0 === count( array_filter( array_slice( $reqs, $n_all ), fn( $r ) => 'GET' !== $r['method'] && '/users/get_auth_token/' !== $r['path'] ) ) && '' === (string) wc_get_order( $order4->get_id() )->get_meta( '_eru_dpg_order_id' ) );
	Eru_DPG_Settings::save_for_test( array( 'dry_run' => 'no' ) );

	// Status pull touches only the order linked to that Dopigo id.
	Eru_DPG_Settings::save_for_test( array( 'status_direction' => 'dp_to_wc' ) );
	$o1 = wc_get_order( $oid );
	$o1->set_status( 'processing' );
	$o1->save();
	$orders_out = str_replace( '"__ORDER_ID__"', '"' . $oid . '"', file_get_contents( $fixtures . 'orders-page.json' ) );
	$res = Eru_DPG_Status::pull();
	$check( '(e) DP→WC: delivered → completed for the linked order only (a foreign id with the same service id is ignored)', 'completed' === wc_get_order( $oid )->get_status() && 1 === $res['updated'] );

	// Echo guard (both directions): a queued Woo→Dopigo status push wins over the pull.
	$o1 = wc_get_order( $oid );
	$o1->set_status( 'processing' );
	$o1->save();
	as_schedule_single_action( time() + 60, 'eru_dpg_push_status', array( $oid ), 'eru-dpg' );
	$res = Eru_DPG_Status::pull();
	$check( '(echo) pending status push for the order → pull does not overwrite the Woo status', 'processing' === wc_get_order( $oid )->get_status() && 0 === $res['updated'] && $res['skipped'] >= 1 );
	as_unschedule_all_actions( 'eru_dpg_push_status', null, 'eru-dpg' );

	// Ambiguous create failures (timeout / 5xx): look up by service_order_id before any re-POST.
	Eru_DPG_Settings::save_for_test( array( 'order_push' => 'yes' ) );
	$order5 = $make_order( $owner, $p2 );
	$id5    = $order5->get_id();
	$posts  = $count_reqs( 'POST', '/api/v1/orders/' );
	$post_mode = 'timeout';
	Eru_DPG_Orders::run_job( $id5 );
	$o5 = wc_get_order( $id5 );
	$check( '(amb) timeout on create → state unknown, no id, retry scheduled', 'unknown' === $o5->get_meta( '_eru_dpg_order_state' ) && '' === (string) $o5->get_meta( '_eru_dpg_order_id' ) && 1 === $pending( 'eru_dpg_push_order', array( $id5 ) ) && $posts + 1 === $count_reqs( 'POST', '/api/v1/orders/' ) );
	as_unschedule_all_actions( 'eru_dpg_push_order', null, 'eru-dpg' );
	$post_mode  = 'ok';
	$orders_out = wp_json_encode( array( 'results' => array( array( 'id' => 5999, 'service_order_id' => '999999999', 'status' => 'waiting_shipment' ), array( 'id' => 5101, 'service_order_id' => (string) $id5, 'status' => 'waiting_shipment' ) ) ) );
	$gets = $count_reqs( 'GET', '/api/v1/orders/' );
	Eru_DPG_Orders::run_job( $id5 );
	$o5 = wc_get_order( $id5 );
	$check( '(amb) retry looks the order up and adopts the existing Dopigo id without a second POST', '5101' === (string) $o5->get_meta( '_eru_dpg_order_id' ) && 'sent' === $o5->get_meta( '_eru_dpg_order_state' ) && $posts + 1 === $count_reqs( 'POST', '/api/v1/orders/' ) && $gets + 1 === $count_reqs( 'GET', '/api/v1/orders/' ) );

	$order6 = $make_order( $owner, $p2 );
	$id6    = $order6->get_id();
	$post_mode = '502';
	Eru_DPG_Orders::run_job( $id6 );
	as_unschedule_all_actions( 'eru_dpg_push_order', null, 'eru-dpg' );
	$list_mode = 'error';
	$post_mode = 'ok';
	$posts     = $count_reqs( 'POST', '/api/v1/orders/' );
	Eru_DPG_Orders::run_job( $id6 );
	$o6 = wc_get_order( $id6 );
	$check( '(amb) 502 then lookup impossible → needs_review, no POST, no auto-retry', 'needs_review' === $o6->get_meta( '_eru_dpg_order_state' ) && $posts === $count_reqs( 'POST', '/api/v1/orders/' ) && 0 === $pending( 'eru_dpg_push_order' ) );
	$check( '(amb) needs_review is surfaced (count for the dashboard)', Eru_DPG_Orders::needs_review_count() >= 1 );
	$check( '(amb) trigger events do not auto-push a needs_review order', ( Eru_DPG_Orders::on_trigger( $id6 ) || true ) && 0 === $pending( 'eru_dpg_push_order' ) && 'needs_review' === Eru_DPG_Orders::push( $id6 ) );
	$list_mode = 'ok';
	$check( '(amb) a manual (forced) resend after review creates it', 'created' === Eru_DPG_Orders::push( $id6, true ) && $posts + 1 === $count_reqs( 'POST', '/api/v1/orders/' ) );

	// Metabox resend handler (the AJAX button's core): capability, nonce, queued args.
	if ( ! class_exists( 'Eru_DPG_Metabox' ) ) {
		require_once ERU_DPG_PATH . 'includes/class-eru-dpg-metabox.php';
	}
	as_unschedule_all_actions( 'eru_dpg_push_order', null, 'eru-dpg' );
	$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
	$prev_uid = get_current_user_id();
	wp_set_current_user( 0 );
	$check( '(resend) no capability → 403, nothing queued', 403 === Eru_DPG_Metabox::resend_result( $id5, 'x' )['code'] && 0 === $pending( 'eru_dpg_push_order' ) );
	wp_set_current_user( $admin_id );
	$check( '(resend) bad nonce → 400, nothing queued', 400 === Eru_DPG_Metabox::resend_result( $id5, 'bad' )['code'] && 400 === Eru_DPG_Metabox::resend_result( $id5, wp_create_nonce( 'eru_dpg_resend_' . $id6 ) )['code'] && 0 === $pending( 'eru_dpg_push_order' ) );
	$rr = Eru_DPG_Metabox::resend_result( $id5, wp_create_nonce( 'eru_dpg_resend_' . $id5 ) );
	$check( '(resend) order already in Dopigo → queues a forced update [id, 1]', 200 === $rr['code'] && array( $id5, 1 ) === $rr['args'] && 1 === $pending( 'eru_dpg_push_order', array( $id5, 1 ) ) );
	$fresh8 = $make_order( $owner, $p2 );
	$rr     = Eru_DPG_Metabox::resend_result( $fresh8->get_id(), wp_create_nonce( 'eru_dpg_resend_' . $fresh8->get_id() ) );
	$check( '(resend) order not yet sent → queues a create [id]', 200 === $rr['code'] && array( $fresh8->get_id() ) === $rr['args'] && 1 === $pending( 'eru_dpg_push_order', array( $fresh8->get_id() ) ) );
	$o6 = wc_get_order( $id6 );
	$o6->delete_meta_data( '_eru_dpg_order_id' );
	$o6->update_meta_data( '_eru_dpg_order_state', 'needs_review' );
	$o6->save();
	$rr = Eru_DPG_Metabox::resend_result( $id6, wp_create_nonce( 'eru_dpg_resend_' . $id6 ) );
	$check( '(resend) needs_review order → manual decision queues a forced push [id, 1]', 200 === $rr['code'] && array( $id6, 1 ) === $rr['args'] );
	wp_set_current_user( $prev_uid );
	as_unschedule_all_actions( 'eru_dpg_push_order', null, 'eru-dpg' );

	$order7 = $make_order( $owner, $p2 );
	$id7    = $order7->get_id();
	$post_mode = '429';
	Eru_DPG_Orders::run_job( $id7 );
	as_unschedule_all_actions( 'eru_dpg_push_order', null, 'eru-dpg' );
	$post_mode = 'ok';
	$gets      = $count_reqs( 'GET', '/api/v1/orders/' );
	$check( '(amb) 429 is not ambiguous (not processed): retry POSTs without lookup', 'failed' === wc_get_order( $id7 )->get_meta( '_eru_dpg_order_state' ) && 'created' === Eru_DPG_Orders::push( $id7 ) && $gets === $count_reqs( 'GET', '/api/v1/orders/' ) );
	Eru_DPG_Settings::save_for_test( array( 'status_direction' => 'off', 'order_push' => 'no' ) );

	// -----------------------------------------------------------------------------------------
	// (i) Product save with the queue on → no HTTP, one pending action.
	// -----------------------------------------------------------------------------------------
	Eru_DPG_Settings::save_for_test( array( 'stock_direction' => 'wc_to_dp', 'price_direction' => 'wc_to_dp', 'queue_updates' => 'yes' ) );
	as_unschedule_all_actions( 'eru_dpg_push_product', null, 'eru-dpg' );
	$reqs = array();
	$fresh = wc_get_product( $owner->get_id() );
	$fresh->set_stock_quantity( 9 );
	$fresh->save();
	$fresh = wc_get_product( $owner->get_id() );
	$fresh->set_regular_price( '1100' );
	$fresh->save();
	wc_update_product_stock( $fresh, 8 );
	$check( '(i) no HTTP during product save', 0 === count( $reqs ) );
	$check( '(i) exactly one pending push action for the product', 1 === $pending( 'eru_dpg_push_product', array( $owner->get_id() ) ) && 1 === $pending( 'eru_dpg_push_product' ) );
	do_action( 'eru_dpg_push_product', $owner->get_id() );
	$put = array_values( array_filter( $reqs, fn( $r ) => 'PUT' === $r['method'] ) );
	$body = json_decode( $put[0]['args']['body'] ?? '{}', true );
	$check( '(i) the action sends current stock and gross price by foreign sku', 1 === count( $put ) && '/api/v1/products/update_product_by_foreign_sku/DEMO-001/true' === $put[0]['path'] && 8 === $body['stock'] && '1100.00' === $body['price'] );

	// Echo guard: a DP→WC write does not queue a push back.
	as_unschedule_all_actions( 'eru_dpg_push_product', null, 'eru-dpg' );
	Eru_DPG_Products::without_echo(
		function () use ( $owner ) {
			wc_update_product_stock( wc_get_product( $owner->get_id() ), 6 );
		}
	);
	$check( '(i) DP→WC writes do not echo back to Dopigo', 0 === $pending( 'eru_dpg_push_product' ) );

	// Queue off → sent inline (source behaviour), still no action.
	Eru_DPG_Settings::save_for_test( array( 'queue_updates' => 'no' ) );
	$reqs = array();
	$fresh = wc_get_product( $owner->get_id() );
	$fresh->set_stock_quantity( 5 );
	$fresh->save();
	$check( '(i) queue off: sent during save, nothing queued', 1 <= count( array_filter( $reqs, fn( $r ) => 'PUT' === $r['method'] ) ) && 0 === $pending( 'eru_dpg_push_product' ) );
	Eru_DPG_Settings::save_for_test( array( 'queue_updates' => 'yes', 'stock_direction' => 'off', 'price_direction' => 'off' ) );

	// Double-decrement warning + preset.
	Eru_DPG_Settings::save_for_test( array( 'stock_direction' => 'both', 'order_push' => 'yes' ) );
	$check( '(i) stock Woo→Dopigo + order push is flagged as double-decrement risk', Eru_DPG_Settings::double_decrement_risk() );
	Eru_DPG_Settings::apply_order_only_preset();
	$check( '(i) "yalnızca sipariş gönder" keeps order push, drops Woo→Dopigo stock', ! Eru_DPG_Settings::double_decrement_risk() && 'dp_to_wc' === Eru_DPG_Settings::get( 'stock_direction' ) && 'yes' === Eru_DPG_Settings::get( 'order_push' ) );
	Eru_DPG_Settings::save_for_test( array( 'stock_direction' => 'off', 'order_push' => 'no' ) );

	// -----------------------------------------------------------------------------------------
	// Importer and DP→WC pull.
	// -----------------------------------------------------------------------------------------
	Eru_DPG_Settings::save_for_test( array( 'import_status' => 'draft', 'category_mode' => 'append', 'category_parent' => '0' ) );
	$r = Eru_DPG_Importer::instance()->run_batch( 20, 10, 0 );
	$var_id = wc_get_product_id_by_sku( 'DPG-77011' );
	$var    = $var_id ? wc_get_product( $var_id ) : null;
	$parent = $var ? wc_get_product( $var->get_parent_id() ) : null;
	$check( '(import) variable product created from shared-foreign_sku variants', $parent && $parent->is_type( 'variable' ) && 2 === count( $parent->get_children() ) && 'draft' === $parent->get_status() );
	$check( '(import) existing DEMO-001 matched by SKU (no duplicate)', 1 === count( wc_get_products( array( 'sku' => 'DEMO-001', 'return' => 'ids', 'limit' => 5 ) ) ) && '77001' === (string) wc_get_product( $owner->get_id() )->get_meta( '_eru_dpg_variant_id' ) );
	$check( '(import) category resolved by name', $parent && in_array( (int) get_term_by( 'name', 'Demo Kategori', 'product_cat' )->term_id, $parent->get_category_ids(), true ) );
	$bere = wc_get_product( wc_get_product_id_by_sku( 'DP-77021' ) );
	$check( '(import) integer category id resolved through the product detail, simple product with DP-<id> SKU', $bere && $bere->is_type( 'simple' ) && in_array( (int) get_term_by( 'name', 'Demo Kategori 2', 'product_cat' )->term_id, $bere->get_category_ids(), true ) );
	$check( '(import) pass complete, cursor back to 0', ! empty( $r['pass_done'] ) && 0 === (int) Eru_DPG_Importer::state()['offset'] );
	$again = Eru_DPG_Importer::instance()->run_batch( 20, 10, 0 );
	$check( '(import) second pass with unchanged updated_at skips everything', 0 === $again['created'] && 0 === $again['updated'] && $again['skipped'] >= 2 );

	Eru_DPG_Settings::save_for_test( array( 'stock_direction' => 'dp_to_wc' ) );
	$fresh = wc_get_product( $owner->get_id() );
	$fresh->set_stock_quantity( 1 );
	$fresh->save();
	as_unschedule_all_actions( 'eru_dpg_push_product', null, 'eru-dpg' );
	$res = Eru_DPG_Products::instance()->pull_batch( 20, 2 );
	$check( '(pull) DP→WC stock applied (7 from Dopigo)', 7 === wc_get_product( $owner->get_id() )->get_stock_quantity() && $res['updated'] >= 1 );
	$check( '(pull) cursor stored and wraps at the end of the catalogue', ! empty( $res['done'] ) && 0 === (int) get_option( 'eru_dpg_pull_state', array( 'offset' => 0 ) )['offset'] );

	// Echo guard (both directions): a queued Woo→Dopigo push for the product wins over the pull.
	Eru_DPG_Settings::save_for_test( array( 'stock_direction' => 'both' ) );
	Eru_DPG_Products::without_echo(
		function () use ( $owner ) {
			wc_update_product_stock( wc_get_product( $owner->get_id() ), 2 );
		}
	);
	as_schedule_single_action( time() + 30, 'eru_dpg_push_product', array( $owner->get_id() ), 'eru-dpg' );
	$res = Eru_DPG_Products::instance()->pull_batch( 20, 50 );
	$check( '(echo) pending product push → pull keeps the Woo stock (2, not Dopigo 7)', 2 === wc_get_product( $owner->get_id() )->get_stock_quantity() && $res['skipped'] >= 1 );
	as_unschedule_all_actions( 'eru_dpg_push_product', null, 'eru-dpg' );
	$res = Eru_DPG_Products::instance()->pull_batch( 20, 50 );
	$check( '(echo) once the push has run, the pull applies Dopigo stock again', 7 === wc_get_product( $owner->get_id() )->get_stock_quantity() );
	Eru_DPG_Settings::save_for_test( array( 'stock_direction' => 'off' ) );

	echo "ALL PASS\n";
} catch ( Throwable $e ) {
	echo 'ERROR: ' . $e->getMessage() . "\n";
	$cleanup();
	null === $old_settings ? delete_option( Eru_DPG_Settings::OPTION ) : update_option( Eru_DPG_Settings::OPTION, $old_settings, false );
	exit( 1 );
}
$cleanup();
null === $old_settings ? delete_option( Eru_DPG_Settings::OPTION ) : update_option( Eru_DPG_Settings::OPTION, $old_settings, false );
remove_filter( 'pre_http_request', $router, 10 );
