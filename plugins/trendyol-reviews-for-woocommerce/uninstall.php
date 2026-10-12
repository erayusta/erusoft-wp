<?php
/**
 * Uninstall: options, scheduled work and (optionally) imported reviews.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$eru_tyr_settings = (array) get_option( 'eru_tyr_settings', array() );

if ( ! empty( $eru_tyr_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$eru_tyr_ids = $wpdb->get_col( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_eru_tyr_source' AND meta_value = 'trendyol'" );
	$eru_tyr_posts = array();
	foreach ( $eru_tyr_ids as $eru_tyr_id ) {
		$eru_tyr_comment = get_comment( (int) $eru_tyr_id );
		if ( $eru_tyr_comment ) {
			$eru_tyr_posts[ (int) $eru_tyr_comment->comment_post_ID ] = true;
			wp_delete_comment( (int) $eru_tyr_id, true );
		}
	}
	if ( class_exists( 'WC_Comments' ) ) {
		foreach ( array_keys( $eru_tyr_posts ) as $eru_tyr_post ) {
			WC_Comments::clear_transients( $eru_tyr_post );
		}
	}

	$eru_tyr_upload = wp_upload_dir();
	$eru_tyr_dir    = trailingslashit( $eru_tyr_upload['basedir'] ) . 'trendyol-reviews';
	if ( is_dir( $eru_tyr_dir ) ) {
		foreach ( (array) scandir( $eru_tyr_dir ) as $eru_tyr_file ) {
			if ( is_file( $eru_tyr_dir . '/' . $eru_tyr_file ) ) {
				wp_delete_file( $eru_tyr_dir . '/' . $eru_tyr_file );
			}
		}
		rmdir( $eru_tyr_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

foreach ( array( 'eru_tyr_settings', 'eru_tyr_api_secret', 'eru_tyr_map', 'eru_tyr_unmatched', 'eru_tyr_run', 'eru_tyr_log' ) as $eru_tyr_option ) {
	delete_option( $eru_tyr_option );
}
wp_clear_scheduled_hook( 'eru_tyr_daily' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'eru-tyr' );
}
