<?php
/**
 * Order JSON Export — export contains order number + SKU, allowlisted meta only.
 *
 *   wp eval-file tests/php/oje-export.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_OJE_Exporter' ) ) {
	echo "FAIL: Eru_OJE_Exporter missing (plugin not active?)\n";
	exit( 1 );
}

$old   = get_option( 'eru_oje_meta_allowlist', null );
$pid   = 0;
$oid   = 0;
try {
	$p = new WC_Product_Simple();
	$p->set_name( 'OJE Demo Ürün' );
	$p->set_regular_price( '100' );
	$p->set_sku( 'OJE-DEMO-1' );
	$p->set_status( 'publish' );
	$pid = $p->save();

	$o = wc_create_order();
	$o->add_product( wc_get_product( $pid ), 2 );
	$o->set_billing_first_name( 'Ayşe' );
	$o->set_billing_last_name( 'Örnek' );
	$o->set_billing_email( 'ornek@example.com' );
	$o->update_meta_data( '_oje_allowed', 'evet' );
	$o->update_meta_data( '_oje_secret', 'gizli' );
	$o->calculate_totals();
	$o->save();
	$oid = $o->get_id();

	update_option( 'eru_oje_meta_allowlist', "_oje_allowed\n" );
	$json = Eru_OJE_Exporter::to_json( array( wc_get_order( $oid ) ) );
	$doc  = json_decode( $json, true );

	$check( 'JSON decodes', is_array( $doc ) && JSON_ERROR_NONE === json_last_error() );
	$check( 'schema marker', ( $doc['schema'] ?? '' ) === 'eru-oje/1' );
	$row = $doc['orders'][0] ?? array();
	$check( 'order number present', (string) ( $row['number'] ?? '' ) === (string) $oid );
	$check( 'line item SKU present', ( $row['items'][0]['sku'] ?? '' ) === 'OJE-DEMO-1' );
	$check( 'quantity 2', ( $row['items'][0]['quantity'] ?? 0 ) === 2 );
	$check( 'allowlisted meta exported', ( $row['meta']['_oje_allowed'] ?? '' ) === 'evet' );
	$check( 'non-allowlisted meta excluded', ! str_contains( $json, 'gizli' ) && ! isset( $row['meta']['_oje_secret'] ) );
	$check( 'Turkish characters unescaped', str_contains( $json, 'Ayşe' ) );

	$q = Eru_OJE_Exporter::query( array( 'ids' => array( $oid ) ) );
	$check( 'query by id', 1 === count( $q['orders'] ) );
	$q = Eru_OJE_Exporter::query( array( 'limit' => 1000, 'after' => '2000-01-01' ) );
	$check( 'limit bounded to 100', count( $q['orders'] ) <= 100 );

	// REST: permission callback requires manage_woocommerce.
	wp_set_current_user( 0 );
	$server = rest_get_server();
	$req    = new WP_REST_Request( 'GET', '/eru-oje/v1/orders' );
	$res    = $server->dispatch( $req );
	$check( 'REST denies anonymous', in_array( $res->get_status(), array( 401, 403 ), true ) );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admin[0]->ID );
	$req = new WP_REST_Request( 'GET', '/eru-oje/v1/orders' );
	$req->set_param( 'per_page', 5 );
	$res = $server->dispatch( $req );
	$check( 'REST allows manager', 200 === $res->get_status() && isset( $res->get_data()['orders'] ) );
	$req = new WP_REST_Request( 'GET', '/eru-oje/v1/orders' );
	$req->set_param( 'per_page', 500 );
	$check( 'REST rejects per_page > 100', 400 === $server->dispatch( $req )->get_status() );
} finally {
	if ( $oid ) {
		wc_get_order( $oid )->delete( true );
	}
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
	if ( null === $old ) {
		delete_option( 'eru_oje_meta_allowlist' );
	} else {
		update_option( 'eru_oje_meta_allowlist', $old );
	}
	wp_set_current_user( 0 );
}
exit( $fail ? 1 : 0 );
