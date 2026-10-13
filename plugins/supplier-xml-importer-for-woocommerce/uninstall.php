<?php
/**
 * Uninstall: removes feeds, run state, locks and temporary files. Imported products are kept.
 *
 * @package supplier-xml-importer-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'eru_sxi_feeds' OR option_name LIKE 'eru\_sxi\_run\_%' OR option_name LIKE 'eru\_sxi\_lock\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'eru_sxi_scheduled', null, 'eru-sxi' );
	as_unschedule_all_actions( 'eru_sxi_batch', null, 'eru-sxi' );
}

$upload = wp_upload_dir( null, false );
$dir    = trailingslashit( $upload['basedir'] ) . 'eru-sxi';
if ( is_dir( $dir ) ) {
	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	foreach ( array( '/.htaccess' ) as $hidden ) {
		if ( is_file( $dir . $hidden ) ) {
			wp_delete_file( $dir . $hidden );
		}
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
