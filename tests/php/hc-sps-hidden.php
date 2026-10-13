<?php
/**
 * Hide Categories × Smart Product Search — the live-search REST dropdown must not list hidden-category
 * products or hidden categories.
 *   wp eval-file tests/php/hc-sps-hidden.php   (both plugins active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_HC' ) || ! class_exists( 'Eru_SPS' ) ) {
	echo "FAIL: Eru_HC / Eru_SPS missing (activate both plugins)\n";
	exit( 1 );
}

// The dropdown is served over REST, where the shop-loop hiding does not run.
if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true );
}

$saved_hc  = get_option( 'eru_hc_settings', null );
$saved_sps = get_option( Eru_SPS::OPTION, null );
$ids       = array();
$terms     = array();
$term      = function ( $name, $parent = 0 ) use ( &$terms ) {
	$t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	return $terms[] = (int) $t['term_id'];
};
$mk        = function ( $title, $cats ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	$p->set_category_ids( $cats );
	$p->save();
	return $ids[] = $p->get_id();
};
$names = fn( $res ) => array_map( fn( $c ) => $c['name'], $res['categories'] );
$pids  = fn( $res ) => array_map( fn( $r ) => (int) $r['id'], $res['products'] );

try {
	update_option( Eru_SPS::OPTION, Eru_SPS::sanitize_settings( array( 'rate_limit' => 0, 'show_cats' => 1 ) ) );
	$h   = $term( 'Zxqdemo Gizli' );
	$hc  = $term( 'Zxqdemo Gizli Alt', $h );
	$k   = $term( 'Zxqdemo Açık' );
	$p_h = $mk( 'Zxqdemo Ürün Gizli', array( $h ) );
	$p_c = $mk( 'Zxqdemo Ürün Alt', array( $hc ) );
	$p_k = $mk( 'Zxqdemo Ürün Açık', array( $k ) );

	update_option( 'eru_hc_settings', array() );
	$res = Eru_SPS::results( 'zxqdemo' );
	$check( 'nothing hidden: all three products found', 3 === count( array_intersect( $pids( $res ), array( $p_h, $p_c, $p_k ) ) ) );
	$check( 'nothing hidden: hidden-to-be category listed', in_array( 'Zxqdemo Gizli', $names( $res ), true ) );

	update_option( 'eru_hc_settings', array( 'ids' => array( $h ), 'block' => 'no' ) );
	$res = Eru_SPS::results( 'zxqdemo' );
	$check( 'REST dropdown omits product in hidden category', ! in_array( $p_h, $pids( $res ), true ) );
	$check( 'REST dropdown omits product in hidden child category', ! in_array( $p_c, $pids( $res ), true ) );
	$check( 'REST dropdown keeps visible product', in_array( $p_k, $pids( $res ), true ) );
	$check( 'REST dropdown omits hidden category and its child (cache keyed by hidden set)', ! array_intersect( array( 'Zxqdemo Gizli', 'Zxqdemo Gizli Alt' ), $names( $res ) ) );
	$check( 'REST dropdown keeps visible category', in_array( 'Zxqdemo Açık', $names( $res ), true ) );

	update_option( 'eru_hc_settings', array() );
	$res = Eru_SPS::results( 'zxqdemo' );
	$check( 'un-hiding brings the category back', in_array( 'Zxqdemo Gizli', $names( $res ), true ) );
} finally {
	null === $saved_hc ? delete_option( 'eru_hc_settings' ) : update_option( 'eru_hc_settings', $saved_hc );
	null === $saved_sps ? delete_option( Eru_SPS::OPTION ) : update_option( Eru_SPS::OPTION, $saved_sps );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( array_reverse( $terms ) as $t ) {
		wp_delete_term( $t, 'product_cat' );
	}
	delete_transient( 'eru_sps_cats' );
}

exit( $fail ? 1 : 0 );
