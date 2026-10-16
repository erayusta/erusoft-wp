<?php
/**
 * The usage ledger: one row per campaign and checkout token.
 *
 * Status:
 *  - reserved  held by an order that is not paid yet; counts against the limits;
 *  - consumed  the order was paid; counts against the limits;
 *  - released  the order failed, was cancelled or deleted, or the hold went stale; does not count.
 *
 * Race safety: reserve() takes a MySQL GET_LOCK per limited campaign (in id order, so two checkouts can
 * never deadlock), counts the active rows and inserts inside the lock. Two concurrent checkouts for the
 * last use therefore see each other: one gets it, the other is refused. The (campaign, token) UNIQUE key
 * makes every write idempotent: a retried checkout or a repeated status hook updates the same row.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Database;

use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Lock;

final class UsageRepository {

	public const RESERVED = 'reserved';
	public const CONSUMED = 'consumed';
	public const RELEASED = 'released';

	/** Rows never attached to an order (the request died between reserve and save) go after this. */
	public const ORPHAN_AFTER = HOUR_IN_SECONDS;

	/** Bumped on every write in this request; part of the cart's evaluation fingerprint. */
	private static int $writes = 0;

	/** Whether the last reserve() was refused because a campaign's lock could not be taken in time. */
	private static bool $busy = false;

	public static function lastRefusalWasBusy(): bool {
		return self::$busy;
	}

	public static function table(): string {
		return Schema::table( 'usage' );
	}

	public static function writes(): int {
		return self::$writes;
	}

	public static function emailHash( string $email ): string {
		$email = strtolower( trim( $email ) );

		return '' === $email ? '' : hash( 'sha256', $email );
	}

	/**
	 * Uses left per limited campaign for this customer, the smaller of the overall and the
	 * per-customer remainder. Rows of $exclude (the customer's own unpaid or draft order) do not count.
	 *
	 * @param array<int,array{total:?int,per_customer:?int}> $limits
	 * @param string|string[]                                $exclude tokens
	 * @return array<int,int>
	 */
	public static function remaining( array $limits, int $customer_id, string $email_hash, $exclude = '' ): array {
		global $wpdb;

		if ( array() === $limits ) {
			return array();
		}

		$table   = self::table();
		$ids     = implode( ',', array_map( 'intval', array_keys( $limits ) ) );
		$tokens  = array_values( array_filter( array_map( 'strval', (array) $exclude ) ) );
		$tokens  = array() === $tokens ? array( '' ) : $tokens;
		$not_in  = 'token NOT IN (' . implode( ',', array_map( static fn( string $t ): string => $wpdb->prepare( '%s', $t ), $tokens ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list, prepared tokens.
		$total = $wpdb->get_results( "SELECT campaign_id, COUNT(*) AS n FROM {$table} WHERE campaign_id IN ({$ids}) AND status IN ('reserved','consumed') AND {$not_in} GROUP BY campaign_id", ARRAY_A );
		$used  = array();

		foreach ( (array) $total as $row ) {
			$used[ (int) $row['campaign_id'] ] = (int) $row['n'];
		}

		$mine = array();
		$who  = self::customerWhere( $customer_id, $email_hash );

		if ( '' !== $who ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT campaign_id, COUNT(*) AS n FROM {$table} WHERE campaign_id IN ({$ids}) AND status IN ('reserved','consumed') AND {$not_in} AND {$who} GROUP BY campaign_id", ARRAY_A ) as $row ) {
				$mine[ (int) $row['campaign_id'] ] = (int) $row['n'];
			}
		}

		$out = array();

		foreach ( $limits as $id => $limit ) {
			$left = PHP_INT_MAX;

			if ( null !== $limit['total'] ) {
				$left = min( $left, $limit['total'] - ( $used[ $id ] ?? 0 ) );
			}

			if ( null !== $limit['per_customer'] ) {
				$left = min( $left, $limit['per_customer'] - ( $mine[ $id ] ?? 0 ) );
			}

			$out[ (int) $id ] = max( 0, $left );
		}

		return $out;
	}

	/**
	 * Reserves one use of each campaign for $token, all or nothing.
	 *
	 * @param array<int,array{total:?int,per_customer:?int}> $campaigns campaign id => limits
	 * @param array<int,int>                                 $discounts campaign id => discount given (minor)
	 * @return int[] refused campaign ids (empty = every use reserved). On refusal nothing stays reserved.
	 */
	public static function reserve( array $campaigns, string $token, int $customer_id, string $email_hash, int $order_id, array $discounts = array() ): array {
		ksort( $campaigns );

		self::$busy = false;

		$locked  = array();
		$refused = array();
		$timeout = max( 1, (int) apply_filters( 'eru_ce_lock_timeout', 5 ) );

		try {
			foreach ( $campaigns as $id => $limit ) {
				if ( null === $limit['total'] && null === $limit['per_customer'] ) {
					continue;
				}

				if ( ! Lock::acquire( 'usage_' . $id, $timeout ) ) {
					// Not a used-up limit: the store is busy with another checkout of this campaign.
					self::$busy = true;
					$refused[]  = (int) $id;
					continue;
				}

				$locked[] = (int) $id;
			}

			foreach ( $campaigns as $id => $limit ) {
				$id = (int) $id;

				if ( in_array( $id, $refused, true ) ) {
					continue;
				}

				if ( null !== $limit['total'] || null !== $limit['per_customer'] ) {
					$left = self::remaining( array( $id => $limit ), $customer_id, $email_hash, $token )[ $id ] ?? 0;

					/**
					 * Fires between the count and the insert of a limited reservation (inside the lock).
					 *
					 * @param int    $id    Campaign id.
					 * @param string $token Checkout token.
					 */
					do_action( 'eru_ce_reservation_checked', $id, $token );

					if ( $left <= 0 ) {
						$refused[] = $id;
						continue;
					}
				}

				self::upsert( $id, $token, $order_id, $customer_id, $email_hash, self::RESERVED, (int) ( $discounts[ $id ] ?? 0 ) );
			}

			if ( array() !== $refused ) {
				self::release( $token );
			}
		} finally {
			foreach ( $locked as $id ) {
				Lock::release( 'usage_' . $id );
			}
		}

		return $refused;
	}

	/**
	 * Marks the token's rows of these campaigns consumed, creating them when missing (paid-only mode,
	 * or a reservation released earlier). Returns the campaigns that are now over their limit.
	 *
	 * @param array<int,array{total:?int,per_customer:?int}> $campaigns
	 * @param array<int,int>                                 $discounts
	 * @return int[]
	 */
	public static function consume( array $campaigns, string $token, int $order_id, int $customer_id, string $email_hash, array $discounts = array() ): array {
		$over = array();

		foreach ( $campaigns as $id => $limit ) {
			self::upsert( (int) $id, $token, $order_id, $customer_id, $email_hash, self::CONSUMED, (int) ( $discounts[ $id ] ?? 0 ) );

			if ( null !== $limit['total'] && self::activeCount( (int) $id ) > $limit['total'] ) {
				$over[] = (int) $id;
			}
		}

		return $over;
	}

	/** Releases a token's rows (reserved only, or consumed too). Returns the number of rows changed. */
	public static function release( string $token, bool $with_consumed = false, array $only = array() ): int {
		global $wpdb;

		if ( '' === $token ) {
			return 0;
		}

		$table    = self::table();
		$statuses = $with_consumed ? "'reserved','consumed'" : "'reserved'";
		$extra    = array() === $only ? '' : ' AND campaign_id IN (' . implode( ',', array_map( 'intval', $only ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'released', updated_at = %s WHERE token = %s AND status IN ({$statuses}){$extra}", Clock::mysql(), $token ) );

		if ( $n > 0 ) {
			++self::$writes;
		}

		return $n;
	}

	/** Links a token's rows to the order once it has an id. */
	public static function attach( string $token, int $order_id ): void {
		global $wpdb;

		if ( '' === $token || $order_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET order_id = %d WHERE token = %s AND order_id = 0', $order_id, $token ) );
	}

	/** @return int[] campaign ids the token holds (reserved or consumed) */
	public static function heldBy( string $token ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT campaign_id FROM ' . self::table() . " WHERE token = %s AND status IN ('reserved','consumed')", $token ) ) );
	}

	public static function activeCount( int $campaign_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE campaign_id = %d AND status IN ('reserved','consumed')", $campaign_id ) );
	}

	/** Reservations still open (the daily sweep keeps running while there are any). */
	public static function openCount(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'reserved'" );
	}

	/**
	 * Releases holds nobody will pay for, in one batch:
	 *  - rows never attached to an order, older than ORPHAN_AFTER;
	 *  - rows older than $ttl whose order is gone, failed, cancelled, a draft or still unpaid "pending".
	 * Rows of on-hold orders (waiting for a bank transfer) stay; each row is looked at once a day.
	 *
	 * @param callable $order_check fn( int $order_id ): string 'release' | 'consume' | 'keep'
	 * @return array{more:bool,released:int[]} order ids released
	 */
	public static function sweep( int $batch, int $ttl, callable $order_check ): array {
		global $wpdb;

		$table = self::table();
		$now   = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphans = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'released', updated_at = %s WHERE status = 'reserved' AND order_id = 0 AND created_at < %s LIMIT %d", Clock::mysql( $now ), Clock::mysql( $now - self::ORPHAN_AFTER ), $batch ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, order_id FROM {$table} WHERE status = 'reserved' AND order_id > 0 AND created_at < %s AND ( checked_at IS NULL OR checked_at < %s ) ORDER BY id ASC LIMIT %d", Clock::mysql( $now - $ttl ), Clock::mysql( $now - 20 * HOUR_IN_SECONDS ), $batch ), ARRAY_A );
		$released = array();

		foreach ( $rows as $row ) {
			$order_id = (int) $row['order_id'];
			$verdict  = $order_check( $order_id );

			if ( 'release' === $verdict ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'released', updated_at = %s WHERE id = %d AND status = 'reserved'", Clock::mysql( $now ), (int) $row['id'] ) );
				$released[ $order_id ] = $order_id;
			} elseif ( 'consume' === $verdict ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'consumed', updated_at = %s WHERE id = %d AND status = 'reserved'", Clock::mysql( $now ), (int) $row['id'] ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET checked_at = %s WHERE id = %d", Clock::mysql( $now ), (int) $row['id'] ) );
			}
		}

		if ( $orphans > 0 || array() !== $rows ) {
			++self::$writes;
		}

		return array(
			'more'     => $orphans >= $batch || count( $rows ) >= $batch,
			'released' => array_values( $released ),
		);
	}

	/** Releases the reserved rows of a deleted or trashed order. */
	public static function releaseOrder( int $order_id ): void {
		global $wpdb;

		if ( $order_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'released', updated_at = %s WHERE order_id = %d AND status = 'reserved'", Clock::mysql(), $order_id ) );

		if ( $n > 0 ) {
			++self::$writes;
		}
	}

	/** Removes the link to a person (privacy eraser); counts stay. */
	public static function anonymise( int $customer_id, string $email_hash ): int {
		global $wpdb;

		$who = self::customerWhere( $customer_id, $email_hash );

		if ( '' === $who ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( 'UPDATE ' . self::table() . " SET customer_id = 0, email_hash = '' WHERE {$who}" );
	}

	private static function upsert( int $id, string $token, int $order_id, int $customer_id, string $email_hash, string $status, int $discount ): void {
		global $wpdb;

		$now   = Clock::mysql();
		$table = self::table();

		// created_at restarts when a released row is taken again; a consumed row is never downgraded.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (campaign_id, token, order_id, customer_id, email_hash, status, discount_minor, created_at, updated_at)
				VALUES (%d, %s, %d, %d, %s, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE
					created_at = IF(status = 'released', VALUES(created_at), created_at),
					status = IF(status = 'consumed', 'consumed', VALUES(status)),
					order_id = IF(VALUES(order_id) > 0, VALUES(order_id), order_id),
					customer_id = VALUES(customer_id),
					email_hash = VALUES(email_hash),
					discount_minor = VALUES(discount_minor),
					checked_at = NULL,
					updated_at = VALUES(updated_at)",
				$id,
				$token,
				$order_id,
				$customer_id,
				$email_hash,
				$status,
				$discount,
				$now,
				$now
			)
		);

		++self::$writes;
	}

	/** SQL condition matching the customer's rows by account or e-mail; '' when unknown. */
	private static function customerWhere( int $customer_id, string $email_hash ): string {
		global $wpdb;

		$parts = array();

		if ( $customer_id > 0 ) {
			$parts[] = $wpdb->prepare( 'customer_id = %d', $customer_id );
		}

		if ( '' !== $email_hash ) {
			$parts[] = $wpdb->prepare( 'email_hash = %s', $email_hash );
		}

		return array() === $parts ? '' : '(' . implode( ' OR ', $parts ) . ')';
	}
}
