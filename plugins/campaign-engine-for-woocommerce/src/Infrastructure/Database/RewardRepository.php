<?php
/**
 * Spend-and-earn reward rows.
 *
 * Status: pending (earned, maturing) → available (coupon issued) → used | expired | reversed.
 * One reward per campaign and source order (UNIQUE), so paid hooks that fire twice create one row.
 * Kept on uninstall unless ERU_CE_REMOVE_DATA is true; the privacy eraser anonymises, never deletes.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Database;

use Erusoft\CampaignEngine\Infrastructure\Clock;

final class RewardRepository {

	public const PENDING   = 'pending';
	public const AVAILABLE = 'available';
	public const USED      = 'used';
	public const EXPIRED   = 'expired';
	public const REVERSED  = 'reversed';

	public static function table(): string {
		return Schema::table( 'rewards' );
	}

	/**
	 * Inserts a reward unless one exists for the campaign and order.
	 *
	 * @param array<string,mixed> $row
	 * @return int new id, or 0 when it already existed
	 */
	public static function insert( array $row ): int {
		global $wpdb;

		$now  = Clock::mysql();
		$data = array(
			'campaign_id'      => (int) $row['campaign_id'],
			'campaign_version' => (int) ( $row['campaign_version'] ?? 1 ),
			'source_order_id'  => (int) $row['source_order_id'],
			'customer_id'      => (int) ( $row['customer_id'] ?? 0 ),
			'email'            => substr( (string) ( $row['email'] ?? '' ), 0, 190 ),
			'currency'         => substr( (string) ( $row['currency'] ?? '' ), 0, 3 ),
			'original_minor'   => (int) $row['amount_minor'],
			'amount_minor'     => (int) $row['amount_minor'],
			'min_cart_minor'   => (int) ( $row['min_cart_minor'] ?? 0 ),
			'valid_days'       => (int) ( $row['valid_days'] ?? 0 ),
			'category_ids'     => (string) wp_json_encode( array_values( array_map( 'intval', (array) ( $row['category_ids'] ?? array() ) ) ) ),
			'status'           => self::PENDING,
			'available_at'     => (string) $row['available_at'],
			'created_at'       => $now,
			'updated_at'       => $now,
		);
		$cols = implode( ', ', array_keys( $data ) );
		$fmt  = implode( ', ', array_map( static fn( $v ): string => is_int( $v ) ? '%d' : '%s', $data ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$n = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . " ({$cols}) VALUES ({$fmt})", array_values( $data ) ) );

		return 1 === $n ? (int) $wpdb->insert_id : 0;
	}

	public static function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function forOrder( int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE source_order_id = %d ORDER BY id ASC', $order_id ), ARRAY_A );
	}

	/** @return array<int,array<string,mixed>> */
	public static function byEmail( string $email, int $limit = 500, int $offset = 0 ): array {
		global $wpdb;

		$email = strtolower( trim( $email ) );

		if ( '' === $email ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d', $email, $limit, $offset ), ARRAY_A );
	}

	/**
	 * Rewards of one account: rows earned by the account, plus guest rows whose source order now belongs
	 * to the account (the merchant linked the order). An e-mail match alone never counts: anyone can
	 * register or switch an account to another person's address, and the rows carry live coupon codes.
	 *
	 * @return array<int,array<string,mixed>> newest first
	 */
	public static function forCustomer( int $customer_id, int $limit = 100 ): array {
		global $wpdb;

		if ( $customer_id <= 0 ) {
			return array();
		}

		$orders = function_exists( 'wc_get_orders' )
			? array_values( array_filter( array_map( 'intval', (array) wc_get_orders(
				array(
					'customer_id' => $customer_id,
					'limit'       => 500,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'ids',
					'type'        => 'shop_order',
				)
			) ) ) )
			: array();
		$linked = array() === $orders ? '' : ' OR ( customer_id = 0 AND source_order_id IN (' . implode( ',', $orders ) . ') )';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE customer_id = %d{$linked} ORDER BY id DESC LIMIT %d", $customer_id, $limit ), ARRAY_A );

		// The source order must still belong to the account at the time of reading.
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $customer_id ): bool {
					if ( (int) $row['customer_id'] === $customer_id ) {
						return true;
					}

					$order = wc_get_order( (int) $row['source_order_id'] );

					return $order && (int) $order->get_customer_id() === $customer_id;
				}
			)
		);
	}

	/**
	 * One page of rewards for the admin list, optionally of one status.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function page( string $status, int $per_page, int $page ): array {
		global $wpdb;

		$where = '' === $status ? '1=1' : $wpdb->prepare( 'status = %s', $status );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, $page - 1 ) * $per_page ), ARRAY_A );

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/** @return array<int,array<string,mixed>> rewards whose coupon was used by this order */
	public static function usedBy( int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'used' AND used_order_id = %d", $order_id ), ARRAY_A );
	}

	/** @return array<int,array<string,mixed>> available rewards carrying one of these coupon codes */
	public static function availableByCodes( array $codes ): array {
		global $wpdb;

		$codes = array_values( array_filter( array_map( static fn( $c ): string => strtolower( (string) $c ), $codes ) ) );

		if ( array() === $codes ) {
			return array();
		}

		$in = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'available' AND LOWER(code) IN ({$in})", $codes ), ARRAY_A );
	}

	/**
	 * Changes fields of a reward, optionally only while it is still in $from status (compare-and-set).
	 *
	 * @param array<string,int|string|null> $data
	 * @return bool whether a row changed
	 */
	public static function update( int $id, array $data, ?string $from = null ): bool {
		global $wpdb;

		$data['updated_at'] = Clock::mysql();
		$where              = array( 'id' => $id );

		if ( null !== $from ) {
			$where['status'] = $from;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->update( self::table(), $data, $where ) > 0;
	}

	/** @return int[] pending rewards that are due, oldest first */
	public static function dueIds( int $batch ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE status = 'pending' AND available_at <= %s ORDER BY available_at ASC, id ASC LIMIT %d", Clock::mysql(), $batch ) ) );
	}

	/** Marks available rewards whose coupon date passed as expired; returns how many. */
	public static function expire( int $batch ): int {
		global $wpdb;

		$now = Clock::mysql();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'expired', updated_at = %s WHERE status = 'available' AND expires_at IS NOT NULL AND expires_at < %s ORDER BY id ASC LIMIT %d", $now, $now, $batch ) );
	}

	/** Pending or available rewards (the daily sweep keeps running while there are any). */
	public static function openCount(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('pending','available')" );
	}

	/** @return int[] pending reward ids (to re-arm their maturation after a reactivation) */
	public static function pendingIds( int $limit = 1000 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE status = 'pending' ORDER BY id ASC LIMIT %d", $limit ) ) );
	}

	/** Privacy eraser for given rows. */
	public static function anonymiseIds( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integer list.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET email = '', customer_id = 0, updated_at = %s WHERE id IN (" . implode( ',', $ids ) . ')', Clock::mysql() ) );
	}

	/** Privacy eraser: removes the e-mail and the account link; the money record stays. */
	public static function anonymise( string $email, int $customer_id ): int {
		global $wpdb;

		$email = strtolower( trim( $email ) );
		$parts = array();

		if ( '' !== $email ) {
			$parts[] = $wpdb->prepare( 'email = %s', $email );
		}

		if ( $customer_id > 0 ) {
			$parts[] = $wpdb->prepare( 'customer_id = %d', $customer_id );
		}

		if ( array() === $parts ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET email = '', customer_id = 0, updated_at = %s WHERE " . implode( ' OR ', $parts ), Clock::mysql() ) );
	}
}
