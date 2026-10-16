<?php
/**
 * Commission rows. A commission row is unique per (order, line, agent, level) with entry_key ''; an
 * adjustment row (entry_key 'adj:<n>', parent_id = the commission row) records a change to a row that
 * a payout already holds. Status: pending → approved (after the hold) → paid (with its payout), or void.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Commissions {

	const STATUSES = array( 'pending', 'approved', 'void', 'paid' );

	/** @return string Table name. */
	public static function t() {
		return Eru_STC_Installer::table( 'commissions' );
	}

	/**
	 * Inserts a row; a duplicate (same order, line, agent, level, entry) is ignored.
	 *
	 * @param array $row Columns; money in minor units under basis_minor / amount_minor.
	 * @return int New row id, 0 when it already existed.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$amount = Eru_STC_Money::from_minor( (int) $row['amount_minor'] );
		$cols   = array(
			'order_id'        => (int) $row['order_id'],
			'item_id'         => (int) $row['item_id'],
			'agent_id'        => (int) $row['agent_id'],
			'level'           => (int) $row['level'],
			'entry_key'       => (string) ( $row['entry_key'] ?? '' ),
			'parent_id'       => isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
			'customer_id'     => (int) ( $row['customer_id'] ?? 0 ),
			'product_id'      => (int) ( $row['product_id'] ?? 0 ),
			'rule_id'         => (int) ( $row['rule_id'] ?? 0 ),
			'rule_type'       => (string) ( $row['rule_type'] ?? '' ),
			'rule_rate'       => (string) ( $row['rule_rate'] ?? '0' ),
			'basis'           => Eru_STC_Money::from_minor( (int) ( $row['basis_minor'] ?? 0 ) ),
			'original_amount' => $amount,
			'amount'          => $amount,
			'currency'        => substr( (string) ( $row['currency'] ?? '' ), 0, 3 ),
			'status'          => in_array( $row['status'] ?? 'pending', self::STATUSES, true ) ? $row['status'] : 'pending',
			'earned_at'       => $row['earned_at'] ?? $now,
			'approved_at'     => $row['approved_at'] ?? null,
			'note'            => substr( (string) ( $row['note'] ?? '' ), 0, 255 ),
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		$names  = array();
		$marks  = array();
		$vals   = array();
		foreach ( $cols as $k => $v ) {
			$names[] = $k;
			if ( null === $v ) {
				$marks[] = 'NULL';
				continue;
			}
			$marks[] = is_int( $v ) ? '%d' : '%s';
			$vals[]  = $v;
		}
		$t = self::t();
		$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$t} (" . implode( ',', $names ) . ') VALUES (' . implode( ',', $marks ) . ')', $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return 1 === (int) $n ? (int) $wpdb->insert_id : 0;
	}

	/** @param int $id Row id. @return object|null */
	public static function get( $id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rows of an order.
	 *
	 * @param int  $order_id    Order id.
	 * @param bool $adjustments Include adjustment rows.
	 * @return object[]
	 */
	public static function for_order( $order_id, $adjustments = true ) {
		global $wpdb;
		$t   = self::t();
		$sql = "SELECT * FROM {$t} WHERE order_id = %d" . ( $adjustments ? '' : " AND entry_key = ''" ) . ' ORDER BY item_id, level, id';
		return $wpdb->get_results( $wpdb->prepare( $sql, $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Rows for list screens and exports.
	 *
	 * @param array $args agent_id, order_id, payout_id, status, entry ('commission' | 'adjustment'), from / to
	 *                    (earned_at, UTC), limit (≤ 500), offset.
	 * @return object[]
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$params[]               = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$params[]               = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$t                      = self::t();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @param array $args Same filters as list(). @return int */
	public static function count( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$t                      = self::t();
		$sql                    = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array [ where SQL with placeholders, params ]. */
	private static function where( array $args ) {
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'agent_id', 'order_id', 'payout_id' ) as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$where[]  = "{$k} = %d";
				$params[] = (int) $args[ $k ];
			}
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( isset( $args['entry'] ) && 'commission' === $args['entry'] ) {
			$where[] = "entry_key = ''";
		} elseif ( isset( $args['entry'] ) && 'adjustment' === $args['entry'] ) {
			$where[] = "entry_key <> ''";
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'earned_at >= %s';
			$params[] = (string) $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'earned_at <= %s';
			$params[] = (string) $args['to'];
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Voids an unclaimed pending or approved row by an admin decision (void_reason "manual"). Such a row
	 * stays void: neither refunds nor a new completion bring it back.
	 *
	 * @param int $id Row id.
	 * @return bool Whether this call voided it.
	 */
	public static function void_manual( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row || ! in_array( $row->status, array( 'pending', 'approved' ), true ) || null !== $row->payout_id ) {
			return false;
		}
		$lock = Eru_STC_Refunds::lock_order( (int) $row->order_id );
		try {
			$t = self::t();
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'void', void_reason = 'manual', note = 'manual', updated_at = %s WHERE id = %d AND status IN ('pending','approved') AND payout_id IS NULL", current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} finally {
			Eru_STC_Refunds::unlock_order( $lock );
		}
		if ( 1 !== (int) $n ) {
			return false;
		}
		/** This action is documented in includes/class-eru-stc-refunds.php */
		do_action( 'eru_stc_commissions_voided', (int) $row->order_id, array( (int) $id ), 'manual' );
		return true;
	}

	/**
	 * An agent's totals (decimal strings):
	 * pending (in hold), available (approved, not claimed; may be negative after adjustments),
	 * requested (claimed by an open payout), paid.
	 *
	 * @param int $agent_id Agent.
	 * @return array<string,string>
	 */
	public static function balance( $agent_id ) {
		global $wpdb;
		$t    = self::t();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, payout_id IS NULL AS free, SUM(amount) AS total FROM {$t} WHERE agent_id = %d AND status IN ('pending','approved','paid') GROUP BY status, payout_id IS NULL", $agent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array(
			'pending'   => 0,
			'available' => 0,
			'requested' => 0,
			'paid'      => 0,
		);
		foreach ( $rows as $r ) {
			$m = Eru_STC_Money::to_minor( $r->total );
			if ( 'pending' === $r->status ) {
				$out['pending'] += $m;
			} elseif ( 'paid' === $r->status ) {
				$out['paid'] += $m;
			} elseif ( (int) $r->free ) {
				$out['available'] += $m;
			} else {
				$out['requested'] += $m;
			}
		}
		return array_map( array( 'Eru_STC_Money', 'from_minor' ), $out );
	}

	/**
	 * Approves one pending row (the eligibility job, or an admin skipping the hold).
	 *
	 * @param int $id Row id.
	 * @return bool Whether this call changed it.
	 */
	public static function approve( $id ) {
		global $wpdb;
		$t   = self::t();
		$now = current_time( 'mysql', true );
		$n   = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'approved', approved_at = %s, updated_at = %s WHERE id = %d AND status = 'pending'", $now, $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $n ) {
			return false;
		}
		/**
		 * Fires when a commission row becomes approved (payable).
		 *
		 * @param int    $id  Row id.
		 * @param object $row Row after the change.
		 */
		do_action( 'eru_stc_commission_approved', (int) $id, self::get( $id ) );
		return true;
	}

	/**
	 * Ids of pending commission rows whose hold has passed.
	 *
	 * @param string $cutoff UTC datetime; rows earned at or before it are due.
	 * @param int    $limit  Batch size.
	 * @return int[]
	 */
	public static function due_ids( $cutoff, $limit ) {
		global $wpdb;
		$t = self::t();
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND earned_at <= %s ORDER BY earned_at, id LIMIT %d", $cutoff, max( 1, (int) $limit ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Updates a row's amount (and status) in place; for unpaid rows only.
	 *
	 * @param int    $id           Row id.
	 * @param int    $amount_minor New amount.
	 * @param string $status       New status.
	 * @param string $note         Note.
	 * @return bool Whether it changed.
	 */
	public static function set_amount( $id, $amount_minor, $status, $note = '' ) {
		global $wpdb;
		$t = self::t();
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET amount = %s, status = %s, note = %s, updated_at = %s WHERE id = %d AND status IN ('pending','approved') AND payout_id IS NULL", Eru_STC_Money::from_minor( $amount_minor ), $status, substr( $note, 0, 255 ), current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === (int) $n;
	}

	/**
	 * The adjustment rows recorded against a commission row.
	 *
	 * @param object $row Commission row.
	 * @return array [ int sum of the live (non-void) ones in minor units, int count of all ones (for the
	 *               next entry key) ].
	 */
	public static function adjustments_of( $row ) {
		global $wpdb;
		$t = self::t();
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN status <> 'void' THEN amount ELSE 0 END),0) AS total, COUNT(*) AS n FROM {$t} WHERE parent_id = %d AND entry_key <> ''", $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( Eru_STC_Money::to_minor( $r->total ), (int) $r->n );
	}
}
