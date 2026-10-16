<?php
/**
 * Reads compiled campaign rows and turns them into engine Campaign objects.
 *
 * A row that the engine refuses (ArgumentError: a malformed target id, cap or argument written by an
 * older build or by hand) is skipped on its own: the other campaigns keep working, the problem is
 * logged once and shown as an admin notice (ConfigErrors). Active rows are read once per request and
 * kept in the object cache under the campaign version, which CampaignWriter bumps on every write.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Database;

use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Infrastructure\ConfigErrors;

final class CampaignRepository {

	public const VERSION_OPTION = 'eru_ce_campaign_version';
	/** Number of active rows, autoloaded: with none, the cart hooks return without a query. */
	public const ACTIVE_OPTION = 'eru_ce_active_count';
	private const CACHE_GROUP   = 'eru_ce';

	/** @var Campaign[]|null */
	private static ?array $active = null;
	/** @var array<int,array<string,mixed>>|null active rows by id */
	private static ?array $rows = null;

	public static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	public static function bump(): void {
		global $wpdb;

		update_option( self::VERSION_OPTION, self::version() + 1, true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( self::ACTIVE_OPTION, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'campaigns' ) . " WHERE status = 'active'" ), true );
		self::flush();
	}

	public static function flush(): void {
		wp_cache_delete( 'active_' . self::version(), self::CACHE_GROUP );
		self::$active = null;
		self::$rows   = null;
	}

	/** True when at least one campaign row is active; the inert check of every hook. */
	public static function hasActive(): bool {
		$count = get_option( self::ACTIVE_OPTION, null );

		if ( null !== $count && (int) $count <= 0 ) {
			return false;
		}

		return array() !== self::activeRows();
	}

	/** @return Campaign[] runnable active campaigns, bad rows skipped */
	public static function active(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}

		self::$active = array();

		foreach ( self::activeRows() as $id => $row ) {
			$def = json_decode( (string) $row['definition'], true );

			try {
				if ( ! is_array( $def ) ) {
					throw new ArgumentError( 'definition', 'invalid' );
				}

				self::$active[] = Campaign::fromArray(
					array_merge(
						$def,
						array(
							'id'       => $id,
							'version'  => (int) $row['version'],
							'priority' => (int) $row['priority'],
						)
					)
				);
			} catch ( ArgumentError $e ) {
				ConfigErrors::record( 'campaign', $id, $e->field, $e->reason, (int) $row['version'] );
			}
		}

		return self::$active;
	}

	/**
	 * Usage limits of the active campaigns that have one.
	 *
	 * @return array<int,array{total:?int,per_customer:?int}>
	 */
	public static function limits(): array {
		$out = array();

		foreach ( self::activeRows() as $id => $row ) {
			$limits = self::limitsOf( $row );

			if ( null !== $limits['total'] || null !== $limits['per_customer'] ) {
				$out[ $id ] = $limits;
			}
		}

		return $out;
	}

	/**
	 * Limits of the given campaigns, whatever their status (a campaign paused after the cart was built
	 * still counts its uses).
	 *
	 * @param int[] $ids
	 * @return array<int,array{total:?int,per_customer:?int}>
	 */
	public static function limitsFor( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$out = array();

		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'total'        => null,
				'per_customer' => null,
			);
		}

		if ( array() === $ids ) {
			return $out;
		}

		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		foreach ( (array) $wpdb->get_results( 'SELECT campaign_id, usage_limit, usage_limit_per_customer FROM ' . Schema::table( 'campaigns' ) . " WHERE campaign_id IN ({$in})", ARRAY_A ) as $row ) {
			$out[ (int) $row['campaign_id'] ] = self::limitsOf( $row );
		}

		return $out;
	}

	/** One compiled row (any status), uncached. */
	public static function row( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'campaigns' ) . ' WHERE campaign_id = %d', $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	private static function activeRows(): array {
		if ( null !== self::$rows ) {
			return self::$rows;
		}

		global $wpdb;

		$key  = 'active_' . self::version();
		$rows = wp_cache_get( $key, self::CACHE_GROUP );

		if ( ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( 'SELECT campaign_id, status, priority, exclusive, starts_ts, ends_ts, usage_limit, usage_limit_per_customer, version, definition FROM ' . Schema::table( 'campaigns' ) . " WHERE status = 'active' ORDER BY priority DESC, campaign_id ASC", ARRAY_A );
			wp_cache_set( $key, $rows, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		$now        = time();
		self::$rows = array();

		foreach ( $rows as $row ) {
			// Campaigns whose season is over are left out without asking the engine.
			if ( null !== $row['ends_ts'] && '' !== $row['ends_ts'] && (int) $row['ends_ts'] <= $now ) {
				continue;
			}

			self::$rows[ (int) $row['campaign_id'] ] = $row;
		}

		return self::$rows;
	}

	/** @param array<string,mixed> $row */
	private static function limitsOf( array $row ): array {
		$total = $row['usage_limit'] ?? null;
		$per   = $row['usage_limit_per_customer'] ?? null;

		return array(
			'total'        => null === $total || '' === $total ? null : (int) $total,
			'per_customer' => null === $per || '' === $per ? null : (int) $per,
		);
	}
}
