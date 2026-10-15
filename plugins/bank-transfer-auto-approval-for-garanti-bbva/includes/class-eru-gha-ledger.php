<?php
/**
 * Used-transaction ledger: a bank transaction approves at most one order.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table {prefix}eru_gha_used with UNIQUE (txn_id, active): an active row has active = 1, so the database
 * refuses a second active row for the same transaction. Rows are never removed by order status changes.
 * A manual release (capability, nonce and note) sets active = NULL and keeps the row for the audit trail.
 * Claims are written before the order status changes, inside the run lock.
 */
class Eru_GHA_Ledger {

	/** @return string Table. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_gha_used';
	}

	/**
	 * @param string[] $ids Transaction ids.
	 * @return string[] The ids among them that have an active row.
	 */
	public static function used_ids( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $ids ), 'strlen' ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$ph  = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$out = array_merge( $out, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT txn_id FROM ' . self::table() . " WHERE active = 1 AND txn_id IN ({$ph})", $chunk ) ) ); // phpcs:ignore
		}
		return array_values( array_intersect( $ids, $out ) );
	}

	/** @param string $txn_id Transaction. @return int Order id of the active row (0 = unused). */
	public static function order_for( $txn_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM ' . self::table() . ' WHERE active = 1 AND txn_id = %s', (string) $txn_id ) ); // phpcs:ignore
	}

	/** @param int $order_id Order. @return string MySQL user-lock name. */
	private static function order_lock_name( $order_id ) {
		return 'eru_gha_order_' . get_current_blog_id() . '_' . absint( $order_id );
	}

	/**
	 * Per-order MySQL user lock around claim + status change, so the one-claim-per-order rule also holds
	 * under READ COMMITTED (the UNIQUE key only covers the transaction id).
	 *
	 * @param int $order_id Order.
	 * @return bool Acquired (false: held elsewhere for 5 s or error; the caller must not approve).
	 */
	public static function lock_order( $order_id ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', self::order_lock_name( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @param int $order_id Order. */
	public static function unlock_order( $order_id ) {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::order_lock_name( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** @param int $order_id Order. @return bool The order already has an active ledger row. */
	public static function order_has_active( $order_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE order_id = %d AND active = 1 LIMIT 1', absint( $order_id ) ) ); // phpcs:ignore
	}

	/**
	 * Claims all transactions for one order in one INSERT … SELECT statement: either every row is written or
	 * none (a duplicate active txn_id fails the whole statement), and nothing is written when the order
	 * already has an active row (one order, one transaction set; release first to re-approve). The order-level
	 * check has no unique key behind it; the caller serialises it with the per-order lock (lock_order()).
	 *
	 * @param array<string,float> $txns     txn_id => amount.
	 * @param int                 $order_id Order.
	 * @param string              $source   auto|manual.
	 * @return bool Claimed.
	 */
	public static function claim( array $txns, $order_id, $source = 'auto' ) {
		global $wpdb;
		if ( ! $txns || self::used_ids( array_keys( $txns ) ) || self::order_has_active( $order_id ) ) {
			return false;
		}
		$now  = current_time( 'mysql', true );
		$rows = array();
		foreach ( $txns as $id => $amount ) {
			$rows[] = $wpdb->prepare( 'SELECT %s AS txn_id, %d AS order_id, %f AS amount, %s AS used_at, %s AS source, 1 AS active', (string) $id, absint( $order_id ), round( (float) $amount, 2 ), $now, 'manual' === $source ? 'manual' : 'auto' );
		}
		$t = self::table();
		// One statement: every row or none, and none when the order already has an active claim.
		$sql  = "INSERT INTO {$t} (txn_id, order_id, amount, used_at, source, active) SELECT x.txn_id, x.order_id, x.amount, x.used_at, x.source, x.active FROM (" . implode( ' UNION ALL ', $rows ) . ') AS x'
			. $wpdb->prepare( " WHERE NOT EXISTS (SELECT 1 FROM {$t} AS u WHERE u.order_id = %d AND u.active = 1)", absint( $order_id ) );
		$prev = $wpdb->suppress_errors( true );
		$n    = $wpdb->query( $sql ); // phpcs:ignore
		$wpdb->suppress_errors( $prev );
		return count( $txns ) === (int) $n;
	}

	/**
	 * Manual release: the transaction may be matched again. Requires a note.
	 *
	 * @param string $txn_id  Transaction.
	 * @param string $note    Reason (required).
	 * @param int    $user_id Acting user.
	 * @return true|WP_Error
	 */
	public static function release( $txn_id, $note, $user_id ) {
		global $wpdb;
		$note = trim( sanitize_textarea_field( (string) $note ) );
		if ( mb_strlen( $note ) < 3 ) {
			return new WP_Error( 'eru_gha_note', __( 'Serbest bırakmak için bir not yazın.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$order_id = self::order_for( $txn_id );
		if ( ! $order_id ) {
			return new WP_Error( 'eru_gha_unused', __( 'Bu işlem kayıtlı değil ya da zaten serbest.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET active = NULL, released_at = %s, released_by = %d, release_note = %s WHERE txn_id = %s AND active = 1', current_time( 'mysql', true ), absint( $user_id ), $note, (string) $txn_id ) ); // phpcs:ignore
		if ( 1 !== (int) $n ) {
			return new WP_Error( 'eru_gha_release', __( 'Kayıt güncellenemedi.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$order = wc_get_order( $order_id );
		if ( $order ) {
			/* translators: 1: transaction id, 2: note */
			$order->add_order_note( sprintf( __( 'Garanti BBVA havale işlemi %1$s serbest bırakıldı (başka bir siparişle eşleşebilir). Not: %2$s', 'bank-transfer-auto-approval-for-garanti-bbva' ), $txn_id, $note ) );
		}
		Eru_GHA_Log::add( 'warn', 'release', $order_id, array( (string) $txn_id ), sprintf( /* translators: %s: note */ __( 'İşlem serbest bırakıldı. Not: %s', 'bank-transfer-auto-approval-for-garanti-bbva' ), $note ), array( 'user' => absint( $user_id ) ) );
		return true;
	}

	/**
	 * @param int $page Page.
	 * @param int $per  Per page.
	 * @return array{rows:array,total:int}
	 */
	public static function page( $page, $per = 50 ) {
		global $wpdb;
		$t     = self::table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d OFFSET %d", absint( $per ), max( 0, ( absint( $page ) - 1 ) * absint( $per ) ) ), ARRAY_A ); // phpcs:ignore
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/** Test helper: removes every ledger row of a (fictional) test order. @param int $order_id Order. */
	public static function delete_for_test( $order_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'order_id' => absint( $order_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
