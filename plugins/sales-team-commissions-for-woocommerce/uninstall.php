<?php
/**
 * Uninstall: removes the settings, the round-robin pointer, the watchdog marker, the job lock, the
 * scheduled jobs, invitation counters and ids (user meta) and one-shot admin notices. The tables (agents, customer links, rules, commissions, payouts) are business records
 * and are kept unless ERU_STC_REMOVE_DATA is defined as true (wp-config.php). Order meta and order notes
 * are always kept.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'eru_stc_eligibility' );
	as_unschedule_all_actions( 'eru_stc_send_invite' );
}

foreach ( array( 'eru_stc_settings', 'eru_stc_rr_pointer', 'eru_stc_watchdog_at', 'eru_stc_lock_eligibility' ) as $eru_stc_opt ) {
	delete_option( $eru_stc_opt );
}

// Without the schema marker the next activation runs dbDelta again over the kept tables.
delete_option( 'eru_stc_db_version' );

// Invitation rate-limit counters and markers (user meta), and one-shot admin notices.
foreach ( array( '_eru_stc_invites', '_eru_stc_invited_at', '_eru_stc_invite_id' ) as $eru_stc_meta ) {
	delete_metadata( 'user', 0, $eru_stc_meta, '', true );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eru\\_stc\\_notice\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eru\\_stc\\_notice\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
flush_rewrite_rules( false );

if ( defined( 'ERU_STC_REMOVE_DATA' ) && true === ERU_STC_REMOVE_DATA ) {
	delete_option( 'eru_stc_export_audit' ); // Full-IBAN export audit: an audit record, kept like the tables.
	foreach ( array( 'commissions', 'payouts', 'links', 'rules', 'agents' ) as $eru_stc_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'eru_stc_' . $eru_stc_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	}
}
