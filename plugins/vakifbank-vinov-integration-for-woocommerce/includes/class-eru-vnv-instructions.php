<?php
/**
 * Instruction table: one row per Vinov order, the work list of the status sync.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{prefix}eru_vnv_instructions` holds the order id, the bank's instruction id (UNIQUE; empty while an attempt
 * is unresolved), the environment, amount, installment count, status (0 unresolved attempt, 1 waiting for the
 * customer, 2 approved, 3 cancelled), last_checked_at, created_at and, once tracking ends, closed_at with a
 * reason, and a review flag ("İncelenmeli": paid while cancelled, amount mismatch, bank cancel failed …) with
 * the time it was raised and dismissed. No identity number, phone, name or raw bank response is stored here.
 *
 * The table is created or migrated through a version check on every load (one autoloaded option read). Rows
 * are written by hooks Eru_VNV_Payment fires: an attempt about to call the bank (status 0), a created or
 * adopted instruction (status 1), a definitively refused attempt (row removed). A permanently deleted order
 * takes its row with it.
 */
class Eru_VNV_Instructions {

	const DB_VERSION = '2';
	const DB_OPTION  = 'eru_vnv_db_version';

	/** Hooks. */
	public static function init() {
		self::maybe_install();
		add_action( 'eru_vnv_attempt_started', array( __CLASS__, 'track_attempt' ) );
		add_action( 'eru_vnv_instruction_created', array( __CLASS__, 'record' ), 10, 3 );
		add_action( 'eru_vnv_attempt_failed', array( __CLASS__, 'forget_attempt' ) );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'forget_order' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( __CLASS__, 'keep_unpaid' ), 10, 2 );
		add_action(
			'deleted_post',
			function ( $id, $post = null ) {
				if ( $post instanceof WP_Post && 'shop_order' === $post->post_type ) {
					self::forget_order( $id );
				}
			},
			10,
			2
		);
	}

	/**
	 * WooCommerce cancels unpaid "pending" checkout orders when the stock hold runs out. A Vinov order whose
	 * attempt may have reached the bank (sending/ambiguous) or that has an open instruction row is kept: the
	 * customer may still approve an instruction the shop cannot see yet, and a cancelled order must not be
	 * charged.
	 *
	 * @param bool     $cancel WooCommerce's decision.
	 * @param WC_Order $order  Order.
	 * @return bool
	 */
	public static function keep_unpaid( $cancel, $order ) {
		if ( ! $cancel || ! $order instanceof WC_Order || Eru_VNV_Settings::GATEWAY_ID !== $order->get_payment_method() ) {
			return $cancel;
		}
		if ( in_array( (string) $order->get_meta( '_eru_vnv_attempt_state' ), array( 'sending', 'ambiguous' ), true ) ) {
			return false;
		}
		$row = get_option( self::DB_OPTION ) ? self::by_order( $order->get_id() ) : null;
		return $row && null === $row->closed_at ? false : $cancel;
	}

	/**
	 * A permanently deleted order: its closed, unflagged row goes. An open row stays until the queued bank
	 * cancel (or the sync) has finished with the instruction, and a flagged row stays for review.
	 *
	 * @param int $order_id Order id.
	 */
	public static function forget_order( $order_id ) {
		global $wpdb;
		if ( (int) $order_id > 0 && get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE order_id = %d AND closed_at IS NOT NULL AND review = ''", (int) $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/** @return string Table name. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_vnv_instructions';
	}

	/** Creates or migrates the table when the stored version differs. */
	public static function maybe_install() {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				instruction_id bigint(20) unsigned DEFAULT NULL,
				env varchar(4) NOT NULL DEFAULT 'test',
				amount decimal(18,2) NOT NULL DEFAULT 0,
				installments tinyint(3) unsigned NOT NULL DEFAULT 1,
				status tinyint(3) unsigned NOT NULL DEFAULT 0,
				last_checked_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				closed_at datetime DEFAULT NULL,
				close_reason varchar(20) NOT NULL DEFAULT '',
				review varchar(30) NOT NULL DEFAULT '',
				review_at datetime DEFAULT NULL,
				review_ack datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY instruction_id (instruction_id),
				UNIQUE KEY order_id (order_id),
				KEY open_due (closed_at,last_checked_at),
				KEY review (review)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/** @return string Current GMT time for the table. */
	private static function now() {
		return current_time( 'mysql', true );
	}

	/** @param WC_Order $order Order. @return string test | live */
	private static function env_of( $order ) {
		$e = (string) $order->get_meta( '_eru_vnv_env' );
		return in_array( $e, Eru_VNV_Settings::ENVS, true ) ? $e : Eru_VNV_Settings::env();
	}

	/**
	 * An attempt is about to reach the bank: track it (status 0) so an attempt cut off mid-call is looked up by
	 * the sync. An existing row without an instruction is reopened.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function track_attempt( $order ) {
		global $wpdb;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$t = self::table();
		// On a duplicate (the customer retries an unresolved order) the first created_at is kept on purpose: the
		// give-up clock runs from the first attempt that may have reached the bank, not from the latest retry.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$t} (order_id, env, amount, installments, status, created_at) VALUES (%d, %s, %s, %d, 0, %s)
				ON DUPLICATE KEY UPDATE closed_at = IF(instruction_id IS NULL, NULL, closed_at), close_reason = IF(instruction_id IS NULL, '', close_reason), env = IF(instruction_id IS NULL, VALUES(env), env)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order->get_id(),
				self::env_of( $order ),
				wc_format_decimal( $order->get_total(), 2 ),
				max( 1, (int) $order->get_meta( '_eru_vnv_installments' ) ),
				self::now()
			)
		);
		Eru_VNV_Cron::ensure();
	}

	/**
	 * A definitive bank refusal created nothing: an unresolved row of this order is removed.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function forget_attempt( $order ) {
		global $wpdb;
		if ( $order instanceof WC_Order ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE order_id = %d AND instruction_id IS NULL', $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * A created (or adopted) instruction: status 1, open, polled first.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $id    Instruction id.
	 * @param int      $n     Installments.
	 */
	public static function record( $order, $id, $n = 1 ) {
		global $wpdb;
		if ( ! $order instanceof WC_Order || (int) $id <= 0 ) {
			return;
		}
		$other = self::by_instruction( $id );
		if ( $other && (int) $other->order_id !== $order->get_id() ) {
			// The bank never reuses an id; if it ever looks so, keep the first order's tracking untouched.
			Eru_VNV_Logger::error( sprintf( 'talimat %d zaten sipariş %d için izleniyor; sipariş %d için kayıt açılmadı', (int) $id, (int) $other->order_id, $order->get_id() ) );
			return;
		}
		$t = self::table();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$t} (order_id, instruction_id, env, amount, installments, status, created_at) VALUES (%d, %d, %s, %s, %d, 1, %s)
				ON DUPLICATE KEY UPDATE instruction_id = VALUES(instruction_id), env = VALUES(env), amount = VALUES(amount), installments = VALUES(installments), status = 1, last_checked_at = NULL, closed_at = NULL, close_reason = ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order->get_id(),
				(int) $id,
				self::env_of( $order ),
				wc_format_decimal( $order->get_total(), 2 ),
				max( 1, (int) $n ),
				self::now()
			)
		);
		Eru_VNV_Cron::ensure();
	}

	/** @param int $order_id Order id. @return object|null Row. */
	public static function by_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', (int) $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @param int $id Instruction id. @return object|null Row. */
	public static function by_instruction( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE instruction_id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Open rows, least recently checked first (never checked = NULL sorts first), so a backlog larger than the
	 * limit is worked through over successive runs instead of starving the same rows.
	 *
	 * @param int $limit Rows.
	 * @return object[]
	 */
	public static function due( $limit ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE closed_at IS NULL ORDER BY last_checked_at ASC, id ASC LIMIT %d', max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return int Open rows. */
	public static function open_count() {
		global $wpdb;
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE closed_at IS NULL' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return bool Any row at all (open or kept for retention). */
	public static function has_rows() {
		global $wpdb;
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return false;
		}
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table() . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @param int $row_id Row id. Marks the row as checked now. */
	public static function touch( $row_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'last_checked_at' => self::now() ), array( 'id' => (int) $row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Ends tracking of a row.
	 *
	 * @param int    $row_id Row id.
	 * @param int    $status Final status (0–3).
	 * @param string $reason paid | cancelled | admin_cancel | gave_up | failed | order_missing | review.
	 */
	public static function close( $row_id, $status, $reason ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'status'          => max( 0, min( 3, (int) $status ) ),
				'last_checked_at' => self::now(),
				'closed_at'       => self::now(),
				'close_reason'    => substr( sanitize_key( $reason ), 0, 20 ),
			),
			array( 'id' => (int) $row_id )
		);
	}

	/**
	 * Flags a row for review ("İncelenmeli"). The alarms (paid while cancelled / paid elsewhere) are raised once
	 * and keep a dismissal; any other flag raised again shows again even if it was dismissed.
	 *
	 * @param int    $row_id Row id.
	 * @param string $reason paid_while_cancelled | amount_mismatch | cancel_failed | cancelled_while_paid | id_mismatch | adopted_closed.
	 */
	public static function flag( $row_id, $reason ) {
		global $wpdb;
		$reason = substr( sanitize_key( $reason ), 0, 30 );
		if ( in_array( $reason, array( 'paid_while_cancelled', 'paid_elsewhere' ), true ) && (string) $wpdb->get_var( $wpdb->prepare( 'SELECT review FROM ' . self::table() . ' WHERE id = %d', (int) $row_id ) ) === $reason ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return; // An alarm already raised: keep its time and a dismissal.
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'review'     => $reason,
				'review_at'  => self::now(),
				'review_ack' => null,
			),
			array( 'id' => (int) $row_id )
		);
	}

	/**
	 * Clears a flag that no longer holds (e.g. "cancel_failed" once the bank shows the instruction cancelled).
	 *
	 * @param int    $row_id Row id.
	 * @param string $reason Only this flag is cleared.
	 */
	public static function unflag( $row_id, $reason ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET review = '', review_at = NULL, review_ack = NULL WHERE id = %d AND review = %s", (int) $row_id, sanitize_key( $reason ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @param int $order_id Order id. Dismisses the order's review notice (the flag itself stays listed). */
	public static function ack( $order_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET review_ack = %s WHERE order_id = %d AND review <> ''", self::now(), (int) $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Flagged rows, newest first.
	 *
	 * @param bool     $open_only Only not yet dismissed.
	 * @param string[] $reasons   Only these reasons (empty: all).
	 * @param int      $limit     Rows.
	 * @return object[]
	 */
	public static function reviews( $open_only = false, array $reasons = array(), $limit = 200 ) {
		global $wpdb;
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return array();
		}
		$where = "review <> ''" . ( $open_only ? ' AND review_ack IS NULL' : '' );
		if ( $reasons ) {
			$where .= " AND review IN ('" . implode( "','", array_map( 'sanitize_key', $reasons ) ) . "')";
		}
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY review_at DESC, id DESC LIMIT %d", max( 1, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<string,string> Review reason labels. */
	public static function review_labels() {
		return array(
			'paid_while_cancelled' => __( 'Bankada onaylandı, sipariş iptal/iade/çöp ya da silinmiş', 'vakifbank-vinov-integration-for-woocommerce' ),
			'paid_elsewhere'       => __( 'Bankada onaylandı, sipariş başka yöntemle ödenmiş', 'vakifbank-vinov-integration-for-woocommerce' ),
			'deleted_unresolved'   => __( 'Silinen siparişte sonucu belirsiz deneme', 'vakifbank-vinov-integration-for-woocommerce' ),
			'stock_released'       => __( 'Stok tutma süresi dolduktan sonra talimat bulundu', 'vakifbank-vinov-integration-for-woocommerce' ),
			'cancel_failed'        => __( 'Sipariş iptal edildi, talimat bankada iptal edilemedi', 'vakifbank-vinov-integration-for-woocommerce' ),
			'amount_mismatch'      => __( 'Talimat tutarı sipariş toplamından farklı', 'vakifbank-vinov-integration-for-woocommerce' ),
			'cancelled_while_paid' => __( 'Bankada iptal, sipariş ödenmiş durumda', 'vakifbank-vinov-integration-for-woocommerce' ),
			'id_mismatch'          => __( 'Siparişteki talimat numarası farklı', 'vakifbank-vinov-integration-for-woocommerce' ),
			'adopted_closed'       => __( 'Talimat kapanmış bir siparişte bulundu', 'vakifbank-vinov-integration-for-woocommerce' ),
		);
	}

	/** @param string $reason Reason. @return string Label. */
	public static function review_label( $reason ) {
		$l = self::review_labels();
		return $l[ (string) $reason ] ?? (string) $reason;
	}

	/**
	 * Retention: deletes closed rows created before the cut-off, in batches (open rows are never purged).
	 *
	 * @param int $days Retention in days.
	 * @return int Rows deleted.
	 */
	public static function purge( $days ) {
		global $wpdb;
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return 0;
		}
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		$n   = 0;
		for ( $i = 0; $i < 20; $i++ ) {
			$d  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE closed_at IS NOT NULL AND created_at < %s LIMIT 500', $cut ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$n += $d;
			if ( $d < 500 ) {
				break;
			}
		}
		return $n;
	}

	/** @return array<int,string> Status labels. */
	public static function labels() {
		return array(
			0 => __( 'Belirsiz (banka kaydı aranıyor)', 'vakifbank-vinov-integration-for-woocommerce' ),
			1 => __( 'Müşteri onayı bekleniyor', 'vakifbank-vinov-integration-for-woocommerce' ),
			2 => __( 'Onaylandı', 'vakifbank-vinov-integration-for-woocommerce' ),
			3 => __( 'İptal edildi', 'vakifbank-vinov-integration-for-woocommerce' ),
		);
	}

	/** @param int $code Status. @return string Label. */
	public static function label( $code ) {
		$l = self::labels();
		return $l[ (int) $code ] ?? (string) (int) $code;
	}
}
