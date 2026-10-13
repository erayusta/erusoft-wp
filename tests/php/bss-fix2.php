<?php
/**
 * Bundle Stock Sync — fix round 2: token locks and deferred retry.
 *   wp eval-file tests/php/bss-fix2.php
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
global $wpdb;
$ids = array();
$mk  = function ( $name, $sku, $stock = null ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	if ( null !== $stock ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
	}
	$p->save();
	return $ids[] = $p->get_id();
};
$a = $mk( 'Demo Bileşen A', 'DEMO-BSS-A', 5 );
$k = $mk( 'Demo Paket', 'DEMO-BSS-KIT' );
Eru_BSS_Bundles::set_components( $k, array( array( 'sku' => 'DEMO-BSS-A', 'qty' => 1 ) ) );
$name = 'eru_bss_lock_' . $k;
$pending = function () use ( $k ) {
	return as_get_scheduled_actions( array( 'hook' => 'eru_bss_retry', 'args' => array( $k ), 'group' => 'eru-bss', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' );
};
as_unschedule_all_actions( 'eru_bss_retry', array( $k ), 'eru-bss' );

// Lock held by someone else: recalc is deferred once, not silently dropped.
$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => 'other|' . time(), 'autoload' => 'no' ) );
$check( 'locked: recalc returns false', false === Eru_BSS_Sync::recalc( $k ) );
Eru_BSS_Sync::recalc( $k );
$check( 'exactly one deferred retry is scheduled', 1 === count( $pending() ) );
$check( 'lock owned by someone else is not removed by the failed attempt', null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );

// Retry hook recalculates once the lock is gone.
$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 2 ), array( 'post_id' => $a, 'meta_key' => '_stock' ) );
wp_cache_delete( $a, 'post_meta' );
do_action( 'eru_bss_retry', $k );
wp_cache_delete( $k, 'post_meta' );
$check( 'retry hook brings the bundle up to date (2)', 2 === wc_get_product( $k )->get_stock_quantity() );

// Token release.
$check( 'after a successful recalc the lock is released', null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
as_unschedule_all_actions( 'eru_bss_retry', array( $k ), 'eru-bss' );
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
