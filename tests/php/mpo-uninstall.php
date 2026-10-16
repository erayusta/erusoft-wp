<?php
/**
 * Marketplace Orders for WooCommerce — uninstall: options, locks, jobs and the working tables (raw, listings,
 * sync queue) go; the order map and the log stay unless ERU_MPO_REMOVE_DATA is true; order meta and notes always
 * stay. Run twice:
 *
 *   wp eval-file tests/php/mpo-uninstall.php
 *   wp --exec="define('ERU_MPO_REMOVE_DATA', true);" eval-file tests/php/mpo-uninstall.php
 */

require __DIR__ . '/mpo-helpers.php';
mpo_require_plugin();
mpo_no_http();

global $wpdb;
$remove = defined( 'ERU_MPO_REMOVE_DATA' ) && true === ERU_MPO_REMOVE_DATA;
$keep   = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_mpo\\_%'", ARRAY_A ); // phpcs:ignore
$failed = false;
$oid    = 0;
$ref    = 'DEMO-PKG-U-' . mpo_run_tag();
$exists = fn( $t ) => $wpdb->prefix . 'eru_mpo_' . $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'eru_mpo_' . $t ) );

// Clean-up runs once: in finally, and from a shutdown function after an exit() or a fatal error.
$final_cleanup = function () use ( &$oid, $ref, $keep ) {
	global $wpdb;
	static $ran = false;
	if ( $ran ) {
		return;
	}
	$ran = true;
	if ( $oid && wc_get_order( $oid ) ) {
		wc_get_order( $oid )->delete( true );
	}
	delete_option( 'eru_mpo_db_version' );
	Eru_MPO_Installer::maybe_install();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_mpo_orders WHERE marketplace = 'demo' AND remote_ref = %s", $ref ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = 'demo' AND remote_ref = %s", $ref ) ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_mpo\\_%'" ); // phpcs:ignore
	foreach ( $keep as $row ) {
		$wpdb->insert( $wpdb->options, $row ); // phpcs:ignore
	}
	wp_cache_flush();
	Eru_MPO_Cron::unschedule_all();
};
register_shutdown_function( $final_cleanup );

try {
	mpo_reset();
	mpo_settings();
	$o = new WC_Order();
	$o->update_meta_data( '_eru_mpo_marketplace', 'demo' );
	$o->update_meta_data( '_eru_mpo_remote_ref', $ref );
	$o->set_status( 'eru-mpo-new' );
	$oid = (int) $o->save();
	$o->add_order_note( 'Demo pazaryeri notu' );
	Eru_MPO_Map::insert_done( 'demo', $ref, $oid, false );
	Eru_MPO_Logger::info( 'Demo günlük satırı', 'demo', $ref );
	Eru_MPO_Raw::store( 'demo', $ref, array( 'marker' => 'demo' ) );
	$tok = Eru_MPO_Lock::acquire( 'uninstall_demo', 60 );
	mpo_check( 'set-up: job armed, lock held, rows present', 1 === count( mpo_pending( 'eru_mpo_daily' ) ) && is_string( $tok ) && null !== mpo_map_row( $ref ) && is_array( Eru_MPO_Raw::get( 'demo', $ref ) ) );

	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', plugin_basename( ERU_MPO_FILE ) );
	}
	include ERU_MPO_PATH . 'uninstall.php';
	wp_cache_flush();

	mpo_check( 'options gone (settings, marketplace option, schema version, lock rows)', false === get_option( Eru_MPO_Settings::OPTION ) && false === get_option( 'eru_mpo_mp_demo' ) && false === get_option( 'eru_mpo_db_version' ) && ! Eru_MPO_Lock::held( 'uninstall_demo' ) );
	mpo_check( 'no job of the group left', array() === mpo_pending_group() );
	mpo_check( 'working tables dropped (raw, listings, sync_queue)', ! $exists( 'raw' ) && ! $exists( 'listings' ) && ! $exists( 'sync_queue' ) );
	if ( $remove ) {
		mpo_check( 'ERU_MPO_REMOVE_DATA: order map and log dropped too', ! $exists( 'orders' ) && ! $exists( 'log' ) );
	} else {
		mpo_check( 'order map and log kept (business/audit records)', $exists( 'orders' ) && $exists( 'log' ) && null !== mpo_map_row( $ref ) );
	}
	$o = wc_get_order( $oid );
	mpo_check( 'order meta and order notes always kept', $o && $ref === $o->get_meta( '_eru_mpo_remote_ref' ) && 1 <= count( wc_get_order_notes( array( 'order_id' => $oid ) ) ) );
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	}
} finally {
	$final_cleanup();
}
if ( $failed ) {
	exit( 1 );
}
echo "ALL PASS\n";
