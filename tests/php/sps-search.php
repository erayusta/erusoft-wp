<?php
/**
 * Smart Product Search — Turkish-insensitive matching in SQL, SKU, synonyms, rate limit.
 *   wp eval-file tests/php/sps-search.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_SPS' ) ) {
	echo "FAIL: class Eru_SPS missing (plugin not active?)\n";
	exit( 1 );
}

$saved = get_option( Eru_SPS::OPTION, null );
$ip    = implode( '.', array( '198', '51', '100', '77' ) ); // Documentation address, assembled to avoid a literal.
$ids   = array();
$mk    = function ( $title, $sku ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_sku( $sku );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	$p->save();
	return $ids[] = $p->get_id();
};

try {
	update_option( Eru_SPS::OPTION, Eru_SPS::sanitize_settings( array( 'rate_limit' => 0, 'synonyms' => "abajur, lamba\nkot, jean" ) ) );
	$lamp  = $mk( 'Işık Lamba', 'SPS-DEMO-001' );
	$other = $mk( 'Demo Çorap', 'SPS-DEMO-002' );

	$has = fn( $q, $id ) => in_array( $id, Eru_SPS::search_ids( $q ), true );
	$check( '"isik" finds "Işık Lamba"', $has( 'isik', $lamp ) );
	$check( '"lamba" finds it', $has( 'lamba', $lamp ) );
	$check( '"ışık" and "IŞIK" find it', $has( 'ışık', $lamp ) && $has( 'IŞIK', $lamp ) );
	$check( '"ISIK LAMBA" (two words, upper case) finds it', $has( 'ISIK LAMBA', $lamp ) );
	$check( 'SKU fragment finds it', $has( 'sps-demo-001', $lamp ) );
	$check( 'folded "corap" finds "Çorap"', $has( 'corap', $other ) && ! $has( 'corap', $lamp ) );
	$check( 'unrelated query returns empty', array() === Eru_SPS::search_ids( 'zzqqxx' ) );
	$check( 'synonym "abajur" finds the lamp', $has( 'abajur', $lamp ) );

	delete_post_meta( $lamp, Eru_SPS::META_INDEX );
	delete_post_meta( $lamp, Eru_SPS::META_TITLE );
	$check( 'un-indexed product is still found in SQL (title fallback)', $has( 'isik', $lamp ) );
	Eru_SPS::index_product( $lamp );
	$check( 'index meta rebuilt', str_starts_with( (string) get_post_meta( $lamp, Eru_SPS::META_INDEX, true ), 'isik lamba sps-demo-001' ) );

	// Products that must never be found.
	$hidden = array(
		'draft'    => $mk( 'Gizli Taslak Demo', 'SPS-HID-1' ),
		'private'  => $mk( 'Gizli Özel Demo', 'SPS-HID-2' ),
		'search'   => $mk( 'Gizli Arama Demo', 'SPS-HID-3' ),
		'password' => $mk( 'Gizli Şifreli Demo', 'SPS-HID-4' ),
	);
	wp_update_post( array( 'ID' => $hidden['draft'], 'post_status' => 'draft' ) );
	wp_update_post( array( 'ID' => $hidden['private'], 'post_status' => 'private' ) );
	wp_update_post( array( 'ID' => $hidden['password'], 'post_password' => 'x' ) );
	$hp = wc_get_product( $hidden['search'] );
	$hp->set_catalog_visibility( 'catalog' );
	$hp->save();
	$found = Eru_SPS::search_ids( 'gizli', 50 );
	$check( 'draft, private, password-protected and search-hidden products never appear', array() === array_intersect( array_values( $hidden ), $found ) );
	$rest = rest_do_request( ( function () {
		$r = new WP_REST_Request( 'GET', '/eru-sps/v1/search' );
		$r->set_param( 'q', 'gizli' );
		return $r;
	} )() )->get_data();
	$check( 'REST results exclude them too', array() === array_intersect( array_values( $hidden ), wp_list_pluck( $rest['products'], 'id' ) ) );

	// A variation SKU is searchable through its parent.
	$vp = new WC_Product_Variable();
	$vp->set_name( 'Demo Varyasyonlu' );
	$vp->set_status( 'publish' );
	$vp->save();
	$ids[] = $vp->get_id();
	$va = new WC_Product_Variation();
	$va->set_parent_id( $vp->get_id() );
	$va->set_sku( 'SPS-VAR-77' );
	$va->set_regular_price( '5' );
	$va->save();
	$check( 'variation SKU finds the parent after the variation is saved', $has( 'sps-var-77', $vp->get_id() ) );

	$ids_bounded = Eru_SPS::search_ids( 'demo', 1 );
	$check( 'limit is applied', count( $ids_bounded ) <= 1 );
	$check( 'quote/SQL characters do not break the query', is_array( Eru_SPS::search_ids( "x' OR 1=1 -- %" ) ) );

	$req = new WP_REST_Request( 'GET', '/eru-sps/v1/search' );
	$req->set_param( 'q', 'isik' );
	$res = rest_do_request( $req );
	$d   = $res->get_data();
	$check( 'REST endpoint returns the product', 200 === $res->get_status() && in_array( $lamp, wp_list_pluck( $d['products'], 'id' ), true ) );

	update_option( Eru_SPS::OPTION, Eru_SPS::sanitize_settings( array( 'rate_limit' => 2 ) ) );
	add_filter( 'eru_sps_client_ip', fn() => $ip );
	delete_transient( 'eru_sps_rl_' . md5( $ip ) );
	$codes = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$codes[] = rest_do_request( $req )->get_status();
	}
	$check( 'rate limit: third request in a minute gets 429', array( 200, 200, 429 ) === $codes );
	delete_transient( 'eru_sps_rl_' . md5( $ip ) );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	null === $saved ? delete_option( Eru_SPS::OPTION ) : update_option( Eru_SPS::OPTION, $saved );
}
exit( $fail ? 1 : 0 );
