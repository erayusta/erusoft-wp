<?php
/**
 * Bundle Stock Sync — fix round 1: backorder component, stale-value protection.
 *   wp eval-file tests/php/bss-fix1.php
 */
$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
if ( ! class_exists( 'Eru_BSS_Bundles' ) ) {
	echo "FAIL: plugin not active\n";
	exit( 1 );
}
$ids = array();
$mk  = function ( $name, $sku, $stock = null, $backorders = 'no' ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	if ( null !== $stock ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
		$p->set_backorders( $backorders );
		$p->set_stock_status( $stock > 0 ? 'instock' : ( 'no' === $backorders ? 'outofstock' : 'onbackorder' ) );
	}
	$p->save();
	return $ids[] = $p->get_id();
};
$qty = fn( $id ) => wc_get_product( $id )->get_stock_quantity();

// Backorder component with no stock does not make the bundle unsellable.
$a = $mk( 'Demo Bileşen A', 'DEMO-BSS-A', 0, 'yes' );
$b = $mk( 'Demo Bileşen B', 'DEMO-BSS-B', 6 );
$k = $mk( 'Demo Paket', 'DEMO-BSS-KIT' );
Eru_BSS_Bundles::set_components( $k, array( array( 'sku' => 'DEMO-BSS-A', 'qty' => 1 ), array( 'sku' => 'DEMO-BSS-B', 'qty' => 2 ) ) );
$check( 'backorder component does not limit: bundle = B/2 = 3, in stock', 3 === $qty( $k ) && 'instock' === wc_get_product( $k )->get_stock_status() );

// Stale value: a component changes between calculation and save -> final value reflects the fresh read.
$c = $mk( 'Demo Bileşen C', 'DEMO-BSS-C', 10 );
$k2 = $mk( 'Demo Paket 2', 'DEMO-BSS-KIT2' );
Eru_BSS_Bundles::set_components( $k2, array( array( 'sku' => 'DEMO-BSS-C', 'qty' => 1 ) ) );
add_action( 'eru_bss_before_save', function () use ( $c ) {
	static $once = true;
	if ( $once ) {
		$once = false;
		global $wpdb;
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 4 ), array( 'post_id' => $c, 'meta_key' => '_stock' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $c, 'post_meta' );
		wp_cache_delete( 'product-' . $c, 'products' );
	}
} );
Eru_BSS_Sync::recalc( $k2 );
$check( 'component changed mid-recalc: bundle saved with the fresh value (4)', 4 === $qty( $k2 ) );
remove_all_actions( 'eru_bss_before_save' );

// Per-bundle lock.
update_option( 'eru_bss_lock_' . $k2, time(), false );
$r = Eru_BSS_Sync::recalc( $k2 );
$check( 'locked bundle: recalc returns false and does not save', false === $r );
delete_option( 'eru_bss_lock_' . $k2 );
update_option( 'eru_bss_lock_' . $k2, time() - 9999, false );
$check( 'expired lock is taken over', false !== Eru_BSS_Sync::recalc( $k2 ) );
delete_option( 'eru_bss_lock_' . $k2 );

foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
