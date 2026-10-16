<?php
/**
 * Retention of the personal data the plugin keeps for bookkeeping, run in batches by the daily sweep.
 * After `retention_days` (setting, default 365):
 *  - rewards that are closed (used, expired or reversed) lose their e-mail and account link; the amount
 *    and status stay as the money record;
 *  - usage rows (released or consumed) lose their e-mail hash and account link, except rows of active
 *    campaigns with a per-customer limit, which still need them to count that customer's uses;
 *  - audit-log rows are deleted.
 * Available or pending rewards keep their e-mail: their coupon is bound to it.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure;

use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\Schema;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;

final class Retention {

	/**
	 * One batch of each kind. Returns true when a batch came back full (more work remains).
	 */
	public static function run( int $batch ): bool {
		global $wpdb;

		$cutoff  = Clock::mysql( time() - Options::retentionDays() * DAY_IN_SECONDS );
		$now     = Clock::mysql();
		$rewards = Schema::table( 'rewards' );
		$usage   = Schema::table( 'usage' );
		$audit   = Schema::table( 'audit_log' );
		$keep    = self::perCustomerCampaigns();
		$skip    = array() === $keep ? '' : ' AND campaign_id NOT IN (' . implode( ',', $keep ) . ')';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names, integer list.
		$r = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$rewards} SET email = '', customer_id = 0, updated_at = %s WHERE status IN ('used','expired','reversed') AND updated_at < %s AND ( email <> '' OR customer_id <> 0 ) ORDER BY id ASC LIMIT %d", $now, $cutoff, $batch ) );
		$u = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$usage} SET email_hash = '', customer_id = 0 WHERE status IN ('released','consumed') AND created_at < %s AND ( email_hash <> '' OR customer_id <> 0 ){$skip} ORDER BY id ASC LIMIT %d", $cutoff, $batch ) );
		$a = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE created_at < %s ORDER BY id ASC LIMIT %d", $cutoff, $batch ) );
		// phpcs:enable

		return $r >= $batch || $u >= $batch || $a >= $batch;
	}

	/** Whether any row will need the purge one day (keeps the daily sweep armed while data exists). */
	public static function pending(): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table names.
		return null !== $wpdb->get_var( 'SELECT 1 FROM ' . Schema::table( 'rewards' ) . " WHERE status IN ('used','expired','reversed') AND ( email <> '' OR customer_id <> 0 ) LIMIT 1" )
			|| null !== $wpdb->get_var( 'SELECT 1 FROM ' . Schema::table( 'usage' ) . " WHERE status IN ('released','consumed') AND ( email_hash <> '' OR customer_id <> 0 ) LIMIT 1" )
			|| null !== $wpdb->get_var( 'SELECT 1 FROM ' . Schema::table( 'audit_log' ) . ' LIMIT 1' );
		// phpcs:enable
	}

	/** @return int[] active campaigns with a per-customer usage limit */
	private static function perCustomerCampaigns(): array {
		$out = array();

		foreach ( CampaignRepository::limits() as $id => $limit ) {
			if ( null !== $limit['per_customer'] ) {
				$out[] = (int) $id;
			}
		}

		return $out;
	}
}
