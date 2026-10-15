<?php
/**
 * Bank Transfer Auto-Approval — uninstall keeps the used-transaction ledger (a reinstall must not approve a second
 * order with the same transfer) unless ERU_GHA_REMOVE_DATA is true.
 *
 *   wp eval-file tests/php/gha-uninstall.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_GHA_Ledger' ) || ! class_exists( 'Eru_GHA_Log' ) ) {
	echo "FAIL: Eru_GHA_Ledger missing (plugin not active?)\n";
	exit( 1 );
}
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_gha_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

global $wpdb;
$used     = Eru_GHA_Ledger::table();
$log      = Eru_GHA_Log::table();
$opts     = array();
foreach ( array( 'eru_gha_settings', 'eru_gha_db_version', 'eru_gha_last_run' ) as $o ) {
	$opts[ $o ] = get_option( $o, null );
}
$log_rows = $wpdb->get_results( "SELECT * FROM {$log}", ARRAY_A );
$wpdb->query( "DELETE FROM {$used} WHERE txn_id LIKE 'GHA-U-%'" );
$code     = 1;
$exists   = fn( $t ) => $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );

try {
	$wpdb->insert( $used, array( 'txn_id' => 'GHA-U-1', 'order_id' => 1, 'amount' => 100, 'used_at' => gmdate( 'Y-m-d H:i:s' ), 'source' => 'auto', 'active' => 1 ) );
	$check( 'ERU_GHA_REMOVE_DATA is not defined for this test', ! defined( 'ERU_GHA_REMOVE_DATA' ) );
	$check( 'uninstall drops the ledger only with ERU_GHA_REMOVE_DATA', false !== strpos( (string) file_get_contents( ERU_GHA_PATH . 'uninstall.php' ), 'ERU_GHA_REMOVE_DATA' ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', plugin_basename( ERU_GHA_FILE ) );
	}
	include ERU_GHA_PATH . 'uninstall.php';
	$check( 'uninstall keeps the used-transaction ledger table and its rows', $exists( $used ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$used} WHERE txn_id = 'GHA-U-1'" ) );
	$check( 'uninstall drops the log table (personal data)', ! $exists( $log ) );
	$check( 'uninstall removes the settings', false === get_option( 'eru_gha_settings' ) );
	delete_option( 'eru_gha_db_version' );
	Eru_GHA_Log::maybe_install();
	$check( 'a reinstall keeps the ledger row, so the transfer stays used', $exists( $log ) && array( 'GHA-U-1' ) === Eru_GHA_Ledger::used_ids( array( 'GHA-U-1' ) ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	delete_option( 'eru_gha_db_version' );
	Eru_GHA_Log::maybe_install();
	$wpdb->query( "DELETE FROM {$used} WHERE txn_id LIKE 'GHA-U-%'" );
	$wpdb->query( "DELETE FROM {$log}" );
	foreach ( $log_rows as $row ) {
		$wpdb->insert( $log, $row );
	}
	foreach ( $opts as $o => $v ) {
		if ( null === $v ) {
			delete_option( $o );
		} else {
			update_option( $o, $v, 'eru_gha_db_version' === $o );
		}
	}
}
exit( $code );
