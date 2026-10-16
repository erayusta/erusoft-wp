<?php
/**
 * Uninstall: removes settings (including the external API key hash and signing secret), caches, locks,
 * scheduled jobs, pull, reconcile, table-import and Growth Automations copy state, the queue and the API log.
 * The consent store and its audit history are legal records and are kept unless
 * ERU_CMI_REMOVE_DATA is defined as true (wp-config.php); the hash salt is kept with them, and so is the
 * link-signing secret (opt-out links already sent keep working after a reinstall) and the consent texts the
 * history's text hashes refer to (eru_cmi_texts).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_cmi_flush', 'eru_cmi_tick', 'eru_cmi_log_purge', 'eru_cmi_test_connection', 'eru_cmi_capture_order', 'eru_cmi_pull', 'eru_cmi_reconcile', 'eru_cmi_table_import', 'eru_cmi_gra_backfill' ) as $eru_cmi_hook ) {
		as_unschedule_all_actions( $eru_cmi_hook );
	}
}

foreach ( array( 'eru_cmi_settings', 'eru_cmi_active', 'eru_cmi_front', 'eru_cmi_rl', 'eru_cmi_watchdog_at', 'eru_cmi_test_result', 'eru_cmi_pull_cursor_sandbox', 'eru_cmi_pull_cursor_live', 'eru_cmi_pull_last', 'eru_cmi_reconcile_sandbox', 'eru_cmi_reconcile_live', 'eru_cmi_table_job', 'eru_cmi_gra_backfill' ) as $eru_cmi_opt ) {
	delete_option( $eru_cmi_opt );
}
// Token caches, locks and the per-address daily mail markers, in batches until none is left (bounded loop).
for ( $eru_cmi_i = 0; $eru_cmi_i < 1000; $eru_cmi_i++ ) {
	$eru_cmi_n = (int) $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_cmi\\_lock\\_%' OR option_name LIKE 'eru\\_cmi\\_rt\\_%' OR option_name LIKE '\\_transient\\_eru\\_cmi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eru\\_cmi\\_%' LIMIT 1000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $eru_cmi_n < 1000 ) {
		break;
	}
}

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_cmi_queue' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_cmi_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

// Opt-out links stay valid, but the Hesabım endpoint must leave the rewrite rules.
delete_option( 'rewrite_rules' );

// Without the schema marker the next activation runs dbDelta again: kept tables stay, dropped ones return.
delete_option( 'eru_cmi_db_version' );

if ( defined( 'ERU_CMI_REMOVE_DATA' ) && true === ERU_CMI_REMOVE_DATA ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_cmi_history' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_cmi_consents' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	delete_option( 'eru_cmi_hash_salt' );
	delete_option( 'eru_cmi_token_secret' );
	delete_option( 'eru_cmi_texts' );
}
