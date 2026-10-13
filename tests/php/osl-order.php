<?php
/**
 * Out-of-Stock Last — shop/archive main queries list in-stock products first for every orderby.
 *   wp eval-file tests/php/osl-order.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_OSL' ) ) {
	echo "FAIL: class Eru_OSL missing (plugin not active?)\n";
	exit( 1 );
}

$saved_settings = get_option( 'eru_osl_settings', null );
$ids            = array();
$mk             = function ( $title, $price, $stock, $age ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_regular_price( $price );
	$p->set_manage_stock( false );
	$p->set_stock_status( $stock );
	$p->set_status( 'publish' );
	$p->set_date_created( time() - $age );
	$p->save();
	return $ids[] = $p->get_id();
};
$main = function ( array $qv, array $get = array() ) {
	$old_get = $_GET;
	$old_q   = array( $GLOBALS['wp_the_query'] ?? null, $GLOBALS['wp_query'] ?? null );
	$_GET    = $get;
	$q       = new WP_Query();
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = $q;
	$pin     = isset( $qv['post__in'] ) ? $qv['post__in'] : array();
	$cb      = function () use ( $pin ) {
		return $pin; // WooCommerce resets post__in on main queries; this is its own hook.
	};
	add_filter( 'loop_shop_post_in', $cb );
	$res     = $q->query( $qv + array( 'post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => 20 ) );
	remove_filter( 'loop_shop_post_in', $cb );
	WC()->query->remove_ordering_args(); // WooCommerce keeps its price/popularity clause filters registered.
	$_GET    = $old_get;
	list( $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] ) = $old_q;
	return array_map( 'intval', $res );
};

try {
	// Newest and cheapest product is out of stock: without the plugin it would lead for date and price sorting.
	$in_old = $mk( 'OSL Demo Stokta Eski', '50', 'instock', 5000 );
	$in_new = $mk( 'OSL Demo Stokta Yeni', '40', 'instock', 3000 );
	$oos    = $mk( 'OSL Demo Tükendi', '10', 'outofstock', 10 );
	$only   = array( 'post__in' => $ids );

	update_option( 'eru_osl_settings', array( 'enabled' => 'yes', 'search' => 'yes' ) );
	$r = $main( $only, array( 'orderby' => 'date' ) );
	$check( 'orderby=date: out-of-stock last', end( $r ) === $oos && count( $r ) === 3 );
	$check( 'orderby=date: in-stock order kept (newest first)', $r[0] === $in_new && $r[1] === $in_old );

	$r = $main( $only, array( 'orderby' => 'price' ) );
	$check( 'orderby=price: out-of-stock last', end( $r ) === $oos );
	$check( 'orderby=price: in-stock sorted by price', $r[0] === $in_new );

	$r = $main( $only );
	$check( 'default orderby: out-of-stock last', end( $r ) === $oos );

	$r = $main( $only + array( 's' => 'OSL Demo' ), array( 's' => 'OSL Demo' ) );
	$check( 'search: out-of-stock last (apply to search on)', end( $r ) === $oos );

	update_option( 'eru_osl_settings', array( 'enabled' => 'yes', 'search' => 'no' ) );
	$r = $main( $only + array( 's' => 'OSL Demo' ), array( 'orderby' => 'date', 's' => 'OSL Demo' ) );
	$check( 'search: untouched when apply to search is off', $r[0] === $oos );

	update_option( 'eru_osl_settings', array( 'enabled' => 'no', 'search' => 'yes' ) );
	$r = $main( $only, array( 'orderby' => 'date' ) );
	$check( 'disabled: sorting untouched', $r[0] === $oos );

	update_option( 'eru_osl_settings', array( 'enabled' => 'yes', 'search' => 'yes' ) );
	$q   = new WP_Query( $only + array( 'post_type' => 'product', 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) );
	$check( 'secondary (non-main) query untouched', (int) $q->posts[0] === $oos );
	$q   = new WP_Query( $only + array( 'post_type' => 'product', 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'eru_osl' => 1 ) );
	$check( 'secondary query opts in with eru_osl=1', (int) end( $q->posts ) === $oos );
	$c = Eru_OSL::clauses( array( 'join' => '', 'orderby' => '  ' ), $q );
	$check( 'whitespace-only ORDER BY gets a complete default order (no dangling comma)', ! preg_match( '/,\s*$/', $c['orderby'] ) && str_contains( $c['orderby'], 'post_title ASC' ) );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( null === $saved_settings ) {
		delete_option( 'eru_osl_settings' );
	} else {
		update_option( 'eru_osl_settings', $saved_settings );
	}
}

exit( $fail ? 1 : 0 );
