<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Always removed: settings and other options (engine and shop-front), Action Scheduler jobs, the
 * customer metrics table (derived from orders; rebuilt on reinstall), the plugin's transients and the
 * per-user "overlap notice hidden" flag.
 * Kept unless ERU_CE_REMOVE_DATA is defined true: campaigns (posts and compiled rows), usage records,
 * rewards and the audit log. Reward coupons already given to customers are never deleted, and order
 * meta and order notes are always kept.
 *
 * @package Erusoft\CampaignEngine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\Erusoft\CampaignEngine\Autoloader::register( __DIR__ . '/src' );

use Erusoft\CampaignEngine\Infrastructure\Database\Schema;

global $wpdb;

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'eru-ce' );
}

foreach ( array( 'eru_ce_settings', 'eru_ce_settings_last_good', 'eru_ce_storefront', 'eru_ce_db_version', 'eru_ce_campaign_version', 'eru_ce_active_count', 'eru_ce_config_errors', 'eru_ce_metrics_since', 'woocommerce_eru_ce_reward_ready_settings' ) as $eru_ce_option ) {
	delete_option( $eru_ce_option );
}

// Short-lived screen state (wizard errors, clock rate limit) and the per-user "hide overlap notice" flag.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_ce\_%' OR option_name LIKE '\_transient\_timeout\_eru\_ce\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_metadata( 'user', 0, 'eru_ce_conflict_dismissed', '', true );

$eru_ce_tables = Schema::tables();
$eru_ce_remove = defined( 'ERU_CE_REMOVE_DATA' ) && true === ERU_CE_REMOVE_DATA;

foreach ( $eru_ce_tables as $eru_ce_name => $eru_ce_table ) {
	if ( $eru_ce_remove || ! in_array( $eru_ce_name, Schema::KEPT_ON_UNINSTALL, true ) ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$eru_ce_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

if ( $eru_ce_remove ) {
	// Campaign posts, in bounded batches. Coupons are not touched.
	for ( $eru_ce_round = 0; $eru_ce_round < 100; $eru_ce_round++ ) {
		$eru_ce_ids = get_posts(
			array(
				'post_type'      => 'eru_ce_campaign',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		if ( array() === $eru_ce_ids ) {
			break;
		}

		foreach ( $eru_ce_ids as $eru_ce_id ) {
			wp_delete_post( (int) $eru_ce_id, true );
		}
	}
}
