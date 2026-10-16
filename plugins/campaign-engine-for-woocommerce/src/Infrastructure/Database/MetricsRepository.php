<?php
/**
 * Per-customer order count and spend for the customer conditions (first order, order count, total
 * spent, days since the last order).
 *
 * A customer is "u:<user id>" when the order has an account, otherwise "e:<sha256 of the e-mail>".
 * Rows change only by deltas computed from an order's recorded contribution (CustomerMetrics), so
 * replays never double count. The first time a customer is seen, the row is seeded from the paid
 * orders placed before the first campaign was saved (from then on every paid order is counted as it
 * happens). Nothing is written before a campaign exists, so the plugin stays inert.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Database;

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use WC_Order;

final class MetricsRepository {

	/** When the first campaign was saved; metrics are kept from then on (0 = not yet, inert). */
	public const SINCE_OPTION = 'eru_ce_metrics_since';
	private const SEED_LIMIT      = 200;

	/** @var array<string,array<string,mixed>|null> */
	private static array $memo = array();

	public static function table(): string {
		return Schema::table( 'customer_metrics' );
	}

	public static function key( int $customer_id, string $email ): string {
		if ( $customer_id > 0 ) {
			return 'u:' . $customer_id;
		}

		$hash = UsageRepository::emailHash( $email );

		return '' === $hash ? '' : 'e:' . $hash;
	}

	public static function get( string $key ): ?array {
		global $wpdb;

		if ( '' === $key ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE customer_key = %s', $key ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	public static function forEmail( string $email ): ?array {
		global $wpdb;

		$hash = UsageRepository::emailHash( $email );

		if ( '' === $hash ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE customer_key = %s OR ( email_hash = %s AND customer_key LIKE 'u:%%' ) ORDER BY id ASC LIMIT 1", 'e:' . $hash, $hash ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The customer's figures for the engine, seeding from history when unknown (not stored).
	 *
	 * @return array{order_count:int,total_spent_minor:int,first_order_ts:?int,last_order_ts:?int}
	 */
	public static function figures( int $customer_id, string $email ): array {
		$key = self::key( $customer_id, $email );

		if ( '' === $key ) {
			return self::empty();
		}

		if ( ! array_key_exists( $key, self::$memo ) ) {
			self::$memo[ $key ] = self::get( $key ) ?? self::seed( $customer_id, $email, 0 );
		}

		$row = self::$memo[ $key ];

		return array(
			'order_count'       => max( 0, (int) $row['order_count'] ),
			'total_spent_minor' => max( 0, (int) $row['total_spent_minor'] ),
			'first_order_ts'    => Clock::ts( $row['first_order_at'] ?? null ),
			'last_order_ts'     => Clock::ts( $row['last_order_at'] ?? null ),
		);
	}

	/**
	 * Adds a delta to the customer's row, creating (and seeding) it first.
	 */
	public static function apply( int $customer_id, string $email, int $d_count, int $d_spent, ?int $order_ts, int $exclude_order ): void {
		global $wpdb;

		$key = self::key( $customer_id, $email );

		if ( '' === $key ) {
			return;
		}

		$table = self::table();
		$hash  = UsageRepository::emailHash( $email );
		$date  = static fn( $v ): string => null === $v ? 'NULL' : $wpdb->prepare( '%s', $v );

		if ( null === self::get( $key ) ) {
			$seed = self::seed( $customer_id, $email, $exclude_order );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (customer_key, customer_id, email_hash, order_count, total_spent_minor, first_order_at, last_order_at, updated_at) VALUES (%s, %d, %s, %d, %d, " . $date( $seed['first_order_at'] ) . ', ' . $date( $seed['last_order_at'] ) . ', %s)',
					$key,
					$customer_id,
					$hash,
					(int) $seed['order_count'],
					(int) $seed['total_spent_minor'],
					Clock::mysql()
				)
			);
		}

		$sets = array(
			$wpdb->prepare( 'order_count = GREATEST(0, order_count + %d)', $d_count ),
			$wpdb->prepare( 'total_spent_minor = total_spent_minor + %d', $d_spent ),
			$wpdb->prepare( "email_hash = IF(email_hash = '', %s, email_hash)", $hash ),
			$wpdb->prepare( 'updated_at = %s', Clock::mysql() ),
		);

		if ( $d_count > 0 && null !== $order_ts ) {
			$at     = Clock::mysql( $order_ts );
			$sets[] = $wpdb->prepare( 'first_order_at = IF(first_order_at IS NULL OR first_order_at > %s, %s, first_order_at)', $at, $at );
			$sets[] = $wpdb->prepare( 'last_order_at = IF(last_order_at IS NULL OR last_order_at < %s, %s, last_order_at)', $at, $at );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE customer_key = %s', $key ) );

		unset( self::$memo[ $key ] );
	}

	/** Adds a delta to an existing row by key (moving an order's contribution away). */
	public static function applyToKey( string $key, int $d_count, int $d_spent ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET order_count = GREATEST(0, order_count + %d), total_spent_minor = total_spent_minor + %d, updated_at = %s WHERE customer_key = %s', $d_count, $d_spent, Clock::mysql(), $key ) );
		self::$memo = array();
	}

	/** Privacy eraser: deletes the rows of the e-mail and of the account that has it. */
	public static function delete( string $email, int $customer_id ): int {
		global $wpdb;

		$keys = array();
		$hash = UsageRepository::emailHash( $email );

		if ( '' !== $hash ) {
			$keys[] = 'e:' . $hash;
		}

		if ( $customer_id > 0 ) {
			$keys[] = 'u:' . $customer_id;
		}

		$n = 0;

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n += (int) $wpdb->delete( self::table(), array( 'customer_key' => $key ), array( '%s' ) );
		}

		if ( '' !== $hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n += (int) $wpdb->delete( self::table(), array( 'email_hash' => $hash ), array( '%s' ) );
		}

		self::$memo = array();

		return $n;
	}

	public static function since(): int {
		return max( 0, (int) get_option( self::SINCE_OPTION, 0 ) );
	}

	/** Starts the bookkeeping (first campaign saved); later calls keep the first date. */
	public static function start(): void {
		add_option( self::SINCE_OPTION, time(), '', true );
	}

	/**
	 * Paid orders of the customer created before the bookkeeping started (later orders are counted as
	 * they are paid).
	 *
	 * @return array<string,mixed>
	 */
	private static function seed( int $customer_id, string $email, int $exclude_order ): array {
		$out = self::emptyRow();

		$customer = $customer_id > 0 ? $customer_id : strtolower( trim( $email ) );

		if ( '' === $customer || 0 === $customer ) {
			return $out;
		}

		$orders = wc_get_orders(
			array(
				'customer'     => $customer,
				'status'       => wc_get_is_paid_statuses(),
				'type'         => 'shop_order',
				'date_created' => '<' . self::since(),
				'limit'        => self::SEED_LIMIT,
				'exclude'      => $exclude_order > 0 ? array( $exclude_order ) : array(),
			)
		);
		$dec    = wc_get_price_decimals();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$net = Money::of( (string) $order->get_total(), $dec ) - Money::of( (string) $order->get_total_refunded(), $dec );

			if ( $net <= 0 ) {
				continue;
			}

			++$out['order_count'];
			$out['total_spent_minor'] += $net;
			$created                   = $order->get_date_created();
			$at                        = $created ? Clock::mysql( $created->getTimestamp() ) : null;

			if ( null !== $at ) {
				$out['first_order_at'] = null === $out['first_order_at'] || $at < $out['first_order_at'] ? $at : $out['first_order_at'];
				$out['last_order_at']  = null === $out['last_order_at'] || $at > $out['last_order_at'] ? $at : $out['last_order_at'];
			}
		}

		return $out;
	}

	private static function emptyRow(): array {
		return array(
			'order_count'       => 0,
			'total_spent_minor' => 0,
			'first_order_at'    => null,
			'last_order_at'     => null,
		);
	}

	private static function empty(): array {
		return array(
			'order_count'       => 0,
			'total_spent_minor' => 0,
			'first_order_ts'    => null,
			'last_order_ts'     => null,
		);
	}
}
