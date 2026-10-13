<?php
/**
 * Brand Product Sorting — a manual order stored on a term is applied on that term's archive.
 *   wp eval-file tests/php/bps-order.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_BPS' ) ) {
	echo "FAIL: class Eru_BPS missing (plugin not active?)\n";
	exit( 1 );
}

$ids   = array();
$terms = array();
$term  = function ( $name, $tax = 'product_cat' ) use ( &$terms ) {
	$t = wp_insert_term( $name, $tax );
	$terms[] = array( (int) $t['term_id'], $tax );
	return (int) $t['term_id'];
};
$mk    = function ( $title, $cat, $price = '10' ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$p->set_category_ids( array( $cat ) );
	$p->save();
	return $ids[] = $p->get_id();
};
$main  = function ( $slug, array $get = array(), $tax = 'product_cat' ) {
	$old_get = $_GET;
	$old_q   = array( $GLOBALS['wp_the_query'] ?? null, $GLOBALS['wp_query'] ?? null );
	$_GET    = $get;
	$q       = new WP_Query();
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = $q;
	$res     = $q->query( array( $tax => $slug, 'post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => 20 ) );
	WC()->query->remove_ordering_args(); // WooCommerce keeps its price/popularity clause filters registered.
	$_GET    = $old_get;
	list( $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] ) = $old_q;
	return array_map( 'intval', $res );
};

try {
	$t  = $term( 'BPS Demo T' );
	$u  = $term( 'BPS Demo U' );
	$ts = get_term( $t )->slug;
	// Titles in alphabetical order A, B, C, which is also the default menu_order/title order.
	$a  = $mk( 'BPS Demo A', $t, '30' );
	$b  = $mk( 'BPS Demo B', $t, '20' );
	$c  = $mk( 'BPS Demo C', $t, '10' );
	$o  = $mk( 'BPS Demo D', $u );

	$check( 'no stored order: default order (A,B,C)', $main( $ts ) === array( $a, $b, $c ) );

	$check( 'save order [C,A,B]', Eru_BPS::save_order( $t, array( $c, $a, $b ) ) );
	$check( 'archive for T returns C,A,B', $main( $ts ) === array( $c, $a, $b ) );
	$check( 'an explicit orderby=price is not overridden', $main( $ts, array( 'orderby' => 'price' ) ) === array( $c, $b, $a ) );
	$check( 'stored order is per term', Eru_BPS::get_order( $u ) === array() );

	$check( 'ids from other terms are accepted only as products (junk ignored)', Eru_BPS::save_order( $t, array( $b, 999999, $c, $a, $b ) ) && Eru_BPS::get_order( $t ) === array( $b, $c, $a ) );
	$check( 'archive for T returns B,C,A', $main( $ts ) === array( $b, $c, $a ) );

	// A product added later (not in the stored list) goes after the ordered ones.
	$d = $mk( 'BPS Demo AA', $t );
	$check( 'new product not in the list goes last', $main( $ts ) === array( $b, $c, $a, $d ) );

	Eru_BPS::clear_order( $t );
	$check( 'clear restores default order', Eru_BPS::get_order( $t ) === array() && $main( $ts ) === array( $a, $d, $b, $c ) );

	$check( 'other term archive untouched', $main( get_term( $u )->slug ) === array( $o ) );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $terms as $tt ) {
		wp_delete_term( $tt[0], $tt[1] );
	}
}

exit( $fail ? 1 : 0 );
