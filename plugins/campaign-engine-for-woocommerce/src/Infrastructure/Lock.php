<?php
/**
 * Named MySQL locks (GET_LOCK) around read-check-write sequences: usage counts, an order's paid/refund
 * processing, a reward's maturation. The lock belongs to the database connection, so it is atomic across
 * PHP processes and is freed by MySQL when a crashed request's connection closes.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure;

final class Lock {

	/** Lock name: unique per database and table prefix, at most 64 characters. */
	public static function name( string $key ): string {
		global $wpdb;

		return 'eru_ce_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $key );
	}

	/** Waits up to $timeout seconds; true when this connection now holds the lock. */
	public static function acquire( string $key, int $timeout = 5 ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name( $key ), max( 0, $timeout ) ) );
	}

	public static function release( string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $key ) ) );
	}

	/**
	 * Runs $fn while holding the lock. Returns [true, result] or [false, null] when the lock was not
	 * obtained in time (the caller decides whether to retry later).
	 *
	 * @return array{0:bool,1:mixed}
	 */
	public static function with( string $key, callable $fn, int $timeout = 10 ): array {
		if ( ! self::acquire( $key, $timeout ) ) {
			return array( false, null );
		}

		try {
			return array( true, $fn() );
		} finally {
			self::release( $key );
		}
	}
}
