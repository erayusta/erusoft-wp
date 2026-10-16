<?php
/**
 * Order map (table eru_mpo_orders): which package became which WooCommerce order.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Row states: pending (claimed, order being built), done (wc_order_id set), error (build/save failed; the next
 * run may retake it). claim() is an INSERT IGNORE on UNIQUE(marketplace, remote_ref): only one importer can own
 * a package at a time, even without the lock. retake() is a compare-and-set on the observed state.
 */
class Eru_MPO_Map {

	/** @return string */
	private static function t() {
		return Eru_MPO_Installer::table( 'orders' );
	}

	/** @return string UTC now. */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** @param string $mp Marketplace. @param string $ref Reference. @return object|null */
	public static function get( $mp, $ref ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE marketplace = %s AND remote_ref = %s', $mp, $ref ) ); // phpcs:ignore
	}

	/** @param int $order_id Order id. @return object|null */
	public static function get_by_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE wc_order_id = %d ORDER BY id ASC LIMIT 1', (int) $order_id ) ); // phpcs:ignore
	}

	/** @return bool This request inserted the pending row. */
	public static function claim( $mp, $ref ) {
		global $wpdb;
		$now = self::now();
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t() . " (marketplace, remote_ref, wc_order_id, status, review, error, created_at, updated_at) VALUES (%s, %s, NULL, 'pending', 0, NULL, %s, %s)", $mp, $ref, $now, $now ) ); // phpcs:ignore
	}

	/**
	 * Takes over a row that is still in the observed state (pending/error), back to a fresh pending.
	 *
	 * @param int         $id         Row id.
	 * @param string      $status     Observed status.
	 * @param string|null $updated_at Observed updated_at (pending rows: proves nobody touched it since).
	 * @return bool
	 */
	public static function retake( $id, $status, $updated_at = null ) {
		global $wpdb;
		$sql  = 'UPDATE ' . self::t() . " SET status = 'pending', error = NULL, updated_at = %s WHERE id = %d AND status = %s";
		$args = array( self::now(), (int) $id, $status );
		if ( null !== $updated_at ) {
			$sql   .= ' AND updated_at = %s';
			$args[] = $updated_at;
		}
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore
	}

	/** Marks the row done with its order (clears any error and backoff). */
	public static function done( $mp, $ref, $order_id, $review ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . " SET status = 'done', wc_order_id = %d, review = %d, error = NULL, attempts = 0, next_try_at = NULL, updated_at = %s WHERE marketplace = %s AND remote_ref = %s", (int) $order_id, $review ? 1 : 0, self::now(), $mp, $ref ) ); // phpcs:ignore
	}

	/**
	 * Records a failure with a backoff of 1, 2, 4 … minutes (capped at 60). A done row stays done (it keeps its
	 * order); any other row becomes error.
	 *
	 * @param string $mp      Marketplace.
	 * @param string $ref     Reference.
	 * @param string $message Error (redacted).
	 */
	public static function note_failure( $mp, $ref, $message ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . " SET error = %s, next_try_at = DATE_ADD( %s, INTERVAL LEAST( 60, POW( 2, LEAST( attempts, 6 ) ) ) MINUTE ), attempts = attempts + 1, status = IF( status = 'done', 'done', 'error' ), wc_order_id = IF( status = 'done', wc_order_id, NULL ), updated_at = %s WHERE marketplace = %s AND remote_ref = %s", substr( Eru_MPO_Logger::redact( (string) $message ), 0, 1000 ), self::now(), self::now(), $mp, $ref ) ); // phpcs:ignore
	}

	/** Clears the error and backoff of a row after a successful pass. */
	public static function clear_error( $mp, $ref ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET error = NULL, attempts = 0, next_try_at = NULL WHERE marketplace = %s AND remote_ref = %s AND ( error IS NOT NULL OR attempts > 0 )', $mp, $ref ) ); // phpcs:ignore
	}

	/** Sets an informational error text without backoff (e.g. "trashed"). */
	public static function set_error( $mp, $ref, $message ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET error = %s WHERE marketplace = %s AND remote_ref = %s', (string) $message, $mp, $ref ) ); // phpcs:ignore
	}

	/** @param object $row Map row. @return bool Its backoff has not passed yet. */
	public static function backing_off( $row ) {
		return $row && ! empty( $row->next_try_at ) && strtotime( $row->next_try_at . ' UTC' ) > time();
	}

	/**
	 * Inserts a done row (migrator, adoption). An existing row for the same package is only replaced when it does
	 * not point to a live order.
	 *
	 * @return bool The row now points to $order_id.
	 */
	public static function insert_done( $mp, $ref, $order_id, $review ) {
		global $wpdb;
		$now = self::now();
		$ins = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t() . " (marketplace, remote_ref, wc_order_id, status, review, error, created_at, updated_at) VALUES (%s, %s, %d, 'done', %d, NULL, %s, %s)", $mp, $ref, (int) $order_id, $review ? 1 : 0, $now, $now ) ); // phpcs:ignore
		if ( 1 === $ins ) {
			return true;
		}
		$row = self::get( $mp, $ref );
		if ( ! $row ) {
			return false;
		}
		if ( (int) $row->wc_order_id === (int) $order_id ) {
			return true;
		}
		if ( $row->wc_order_id && Eru_MPO_Importer::order_exists( (int) $row->wc_order_id ) ) {
			return false;
		}
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . " SET status = 'done', wc_order_id = %d, review = %d, error = NULL, updated_at = %s WHERE id = %d AND status = %s", (int) $order_id, $review ? 1 : 0, $now, (int) $row->id, $row->status ) ); // phpcs:ignore
	}

	/** Marks a not-done row failed, with backoff. */
	public static function fail( $mp, $ref, $message ) {
		$row = self::get( $mp, $ref );
		if ( $row && 'done' !== $row->status ) {
			self::note_failure( $mp, $ref, $message );
		}
	}

	/** @param int $id Row id. */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::t(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore
	}

	/** Removes the rows of a deleted order so the package can be imported again. @param int $order_id Order id. */
	public static function delete_by_order( $order_id ) {
		global $wpdb;
		if ( (int) $order_id > 0 ) {
			$wpdb->delete( self::t(), array( 'wc_order_id' => (int) $order_id ), array( '%d' ) ); // phpcs:ignore
		}
	}

	/** @param string $mp Marketplace. @param string $ref Reference. @param bool $review Flag. */
	public static function set_review( $mp, $ref, $review ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET review = %d, updated_at = %s WHERE marketplace = %s AND remote_ref = %s', $review ? 1 : 0, self::now(), $mp, $ref ) ); // phpcs:ignore
	}
}
