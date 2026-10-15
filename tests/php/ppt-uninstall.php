<?php
/**
 * Product Push for Trendyol — uninstall keeps the merchant's product/variation data (Trendyol barcodes, attribute
 * answers, approval link) unless ERU_PPT_REMOVE_DATA is true.
 *
 *   wp eval-file tests/php/ppt-uninstall.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_PPT_Settings' ) ) {
	echo "FAIL: Eru_PPT_Settings missing (plugin not active?)\n";
	exit( 1 );
}
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_ppt_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

global $wpdb;
// Uninstall deletes the plugin's options: keep the demo site's rows and put them back afterwards.
$keep = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'eru\_ppt\_%' OR option_name LIKE '\_transient\_eru\_ppt\_%' OR option_name LIKE '\_transient\_timeout\_eru\_ppt\_%'", ARRAY_A );
foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = 'PPT-U-1'" ) as $id ) {
	wp_delete_post( (int) $id, true );
}
$code = 1;
$pid  = 0;

try {
	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Defter' );
	$p->set_sku( 'PPT-U-1' );
	$p->set_status( 'private' );
	$p->update_meta_data( '_eru_ppt_barcode', 'DEMOBARKOD1' );
	$p->update_meta_data( '_eru_ppt_state', 'approved' );
	$pid = $p->save();
	$check( 'ERU_PPT_REMOVE_DATA is not defined for this test', ! defined( 'ERU_PPT_REMOVE_DATA' ) );
	$check( 'uninstall removes product meta only with ERU_PPT_REMOVE_DATA', false !== strpos( (string) file_get_contents( ERU_PPT_PATH . 'uninstall.php' ), 'ERU_PPT_REMOVE_DATA' ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', plugin_basename( ERU_PPT_FILE ) );
	}
	include ERU_PPT_PATH . 'uninstall.php';
	wp_cache_flush();
	$check( 'uninstall keeps the product\'s Trendyol data', 'DEMOBARKOD1' === get_post_meta( $pid, '_eru_ppt_barcode', true ) && 'approved' === get_post_meta( $pid, '_eru_ppt_state', true ) );
	$check( 'uninstall still removes the settings', false === get_option( 'eru_ppt_settings' ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\_ppt\_%' OR option_name LIKE '\_transient\_eru\_ppt\_%' OR option_name LIKE '\_transient\_timeout\_eru\_ppt\_%'" );
	foreach ( $keep as $row ) {
		$wpdb->insert( $wpdb->options, $row );
	}
	wp_cache_flush();
}
exit( $code );
