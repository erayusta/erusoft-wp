<?php
/**
 * Uninstall: delete this plugin's options, its WooCommerce e-mail settings and the per-user group toggle.
 * Orders keep the status they have; see README "Silme ve taşıma" before uninstalling.
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'eru_cos_statuses' );
delete_option( 'eru_cos_groups' );
delete_option( 'eru_cos_settings' );
delete_transient( 'eru_cos_group_counts' );
delete_metadata( 'user', 0, 'eru_cos_hide_groups', '', true );

$eru_cos_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'woocommerce_eru_cos_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $eru_cos_names as $eru_cos_name ) {
	delete_option( $eru_cos_name );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'eru_cos_migrate_batch' );
}
delete_transient( 'eru_cos_notice' );

// Per-user error notices (eru_cos_error_<user ID>) left by a failed save.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_eru_cos_error_' ) . '%', $wpdb->esc_like( '_transient_timeout_eru_cos_error_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
