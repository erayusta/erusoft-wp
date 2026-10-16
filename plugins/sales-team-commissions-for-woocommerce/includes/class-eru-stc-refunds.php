<?php
/**
 * Refunds, cancellations and failures adjust the order's commission rows.
 *
 * What counts as refunded, per line, in basis terms (ex-tax, minor units):
 *   - the line's own refunds (refund line items);
 *   - its share of refunds not tied to any item (amount-only refunds: the refund amount minus everything
 *     its items account for), spread over the lines by line total + tax, then taken ex-tax;
 *   - its share of shipping refunds, only when shipping was in the basis at calculation time (the rows'
 *     stored basis is larger than the line total). Fee refunds never count: fees are never in the basis.
 *
 * Policy prorata: reduction D = Σ original amounts × refunded basis ÷ line basis (rounded once), split
 * over the line's rows by the largest remainder, so the per-row reductions always sum to D.
 * Policy void: a refund of the line itself voids its rows; refunds not tied to the line (amount-only,
 * shipping) reduce pro rata, so only a full refund voids everything.
 * A fully refunded order counts every line as fully refunded. Cancelled / failed: every row is voided,
 * and while the order stays cancelled / failed any event reconciles as a cancellation (no revival).
 *
 * Unpaid rows change in place (a row at 0 becomes void, void_reason refund). A row held by a payout
 * (paid, or claimed by a request) never changes: an adjustment row with the difference goes to the
 * agent's next payout. Everything is recomputed from the order's current refunds, so firing an event
 * twice changes nothing, and deleting a refund gives back what it took (a revived row re-enters the hold).
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Refunds {

	/** Hooks the order events (always on: rows already written must follow their order). */
	public static function init() {
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ), 20, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_refund' ), 20, 1 );
		add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'on_refund_deleted' ), 20, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancel' ), 20, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_cancel' ), 20, 1 );
		// A trashed or deleted order counts as cancelled; untrash brings it back through the trigger path.
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'on_cancel' ), 20, 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'on_cancel' ), 20, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 20, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'on_untrash' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'on_untrash_post' ), 20, 1 );
	}

	/** @param int $post_id Post being trashed from the posts screen (orders stored as posts). */
	public static function on_trash_post( $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::reconcile( (int) $post_id, 'cancel' );
		}
	}

	/** @param int $post_id Restored post (orders stored as posts). */
	public static function on_untrash_post( $post_id ) {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::on_untrash( (int) $post_id );
		}
	}

	/**
	 * A restored order at its trigger status revives the rows the trash voided (same path as a
	 * cancelled order that is completed again).
	 *
	 * @param int $order_id Order.
	 */
	public static function on_untrash( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $order->has_status( array( 'processing', 'completed' ) ) ) {
			Eru_STC_Calculator::on_status( (int) $order_id );
		}
	}

	/** @param int $order_id Order. */
	public static function on_refund( $order_id ) {
		self::reconcile( $order_id, 'refund' );
	}

	/** @param int $refund_id Deleted refund. @param int $order_id Order. */
	public static function on_refund_deleted( $refund_id, $order_id ) {
		self::reconcile( $order_id, 'refund' );
	}

	/** @param int $order_id Order. */
	public static function on_cancel( $order_id ) {
		self::reconcile( $order_id, 'cancel' );
	}

	/**
	 * Brings the order's rows in line with its refunds (or voids them on cancel).
	 *
	 * @param int    $order_id Order.
	 * @param string $reason   refund | cancel.
	 * @return array{voided:int[],adjusted:int[],changed:int[]} Row ids touched.
	 */
	public static function reconcile( $order_id, $reason ) {
		$out   = array(
			'voided'   => array(),
			'adjusted' => array(),
			'changed'  => array(),
		);
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || '' === (string) $order->get_meta( '_eru_stc_calculated' ) ) {
			return $out;
		}
		// While the order is cancelled or failed every event reconciles as a cancellation: nothing goes back
		// to pending and no amount is restored. Only reaching the trigger status again revives rows.
		if ( $order->has_status( array( 'cancelled', 'failed', 'trash' ) ) ) {
			$reason = 'cancel';
		}
		$lock = self::lock_order( $order->get_id() );
		try {
			$by_item = array();
			foreach ( Eru_STC_Commissions::for_order( $order->get_id(), false ) as $row ) {
				if ( 'void' === $row->status && 'refund' !== $row->void_reason ) {
					continue; // Voided by a cancellation: only a new completion revives it.
				}
				$by_item[ (int) $row->item_id ][] = $row;
			}
			if ( $by_item ) {
				self::reduce( $order, $by_item, 'cancel' === $reason ? 'cancel' : 'refund', $out );
			}
		} finally {
			self::unlock_order( $lock );
		}
		if ( $out['voided'] ) {
			/**
			 * Fires after rows of an order were voided (refund or cancellation).
			 *
			 * @param int    $order_id Order.
			 * @param int[]  $ids      Row ids voided by this call.
			 * @param string $reason   refund | cancel.
			 */
			do_action( 'eru_stc_commissions_voided', $order->get_id(), $out['voided'], $reason );
		}
		return $out;
	}

	/**
	 * Computes each line's reduction and applies it to the line's rows.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $by_item item_id => rows.
	 * @param string   $why     refund | cancel.
	 * @param array    $out     Collected ids.
	 */
	private static function reduce( $order, array $by_item, $why, array &$out ) {
		$full     = 'cancel' === $why || $order->has_status( 'refunded' );
		$policy   = Eru_STC_Settings::get( 'refund_policy' );
		$refunded = self::refunded_basis( $order, $by_item );
		foreach ( $by_item as $item_id => $rows ) {
			$weights = array();
			$basis   = 0;
			foreach ( $rows as $i => $row ) {
				$weights[ $i ] = max( 0, Eru_STC_Money::to_minor( $row->original_amount ) );
				$basis         = max( $basis, Eru_STC_Money::to_minor( $row->basis ) );
			}
			$sum  = array_sum( $weights );
			$r    = $refunded[ $item_id ];
			$part = min( $basis, $r['own'] + $r['other'] );
			if ( $full || ( 'void' === $policy && $r['own'] > 0 ) || ( $part > 0 && $part >= $basis ) ) {
				$cut = $sum;
			} elseif ( $part > 0 && $basis > 0 ) {
				$cut = min( $sum, Eru_STC_Money::div_round( $sum * $part, $basis ) );
			} else {
				$cut = 0;
			}
			$parts = $cut >= $sum ? $weights : Eru_STC_Money::allocate( $cut, $weights );
			foreach ( $rows as $i => $row ) {
				self::apply( $row, $parts[ $i ], $why, $out );
			}
		}
	}

	/**
	 * Refunded basis per calculated line (ex-tax minor units).
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $by_item item_id => rows (the lines that carry commission rows).
	 * @return array<int,array{own:int,other:int}> own = the line's own refunds; other = its share of
	 *         amount-only refunds and (when shipping was in the basis) of shipping refunds.
	 */
	private static function refunded_basis( $order, array $by_item ) {
		$lines  = array();
		$gross  = array();
		$stored = 0;
		$plain  = 0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$line              = max( 0, Eru_STC_Money::to_minor( $item->get_total() ) );
			$lines[ $item_id ] = $line;
			$gross[ $item_id ] = $line + max( 0, Eru_STC_Money::to_minor( $item->get_total_tax() ) );
			if ( isset( $by_item[ $item_id ] ) ) {
				$plain  += $line;
				$stored += max( array_map( fn( $r ) => Eru_STC_Money::to_minor( $r->basis ), $by_item[ $item_id ] ) );
			}
		}
		// Refunds not tied to an item, and shipping refunds (ex-tax).
		$loose    = 0;
		$shipping = 0;
		foreach ( $order->get_refunds() as $refund ) {
			$tied = 0;
			foreach ( $refund->get_items( array( 'line_item', 'shipping', 'fee' ) ) as $ri ) {
				$total = abs( Eru_STC_Money::to_minor( $ri->get_total() ) );
				$tied += $total + abs( Eru_STC_Money::to_minor( $ri->get_total_tax() ) );
				if ( $ri->is_type( 'shipping' ) ) {
					$shipping += $total;
				}
			}
			$loose += max( 0, abs( Eru_STC_Money::to_minor( $refund->get_amount() ) ) - $tied );
		}
		$loose_shares = Eru_STC_Money::allocate( $loose, $gross );
		// Shipping counts only if it was in the basis when the rows were written (same split as then).
		$ship_shares = $stored > $plain ? Eru_STC_Money::allocate( $shipping, $lines ) : array_fill_keys( array_keys( $lines ), 0 );
		$out         = array();
		foreach ( $by_item as $item_id => $rows ) {
			$own   = isset( $lines[ $item_id ] ) ? max( 0, Eru_STC_Money::to_minor( $order->get_total_refunded_for_item( $item_id ) ) ) : 0;
			$other = 0;
			if ( isset( $lines[ $item_id ] ) && $gross[ $item_id ] > 0 ) {
				$other = Eru_STC_Money::div_round( $loose_shares[ $item_id ] * $lines[ $item_id ], $gross[ $item_id ] ) + $ship_shares[ $item_id ];
			}
			$out[ $item_id ] = array(
				'own'   => $own,
				'other' => $other,
			);
		}
		return $out;
	}

	/**
	 * Re-applies the order's current refunds after rows were revived (no lock taken: the caller holds the
	 * order lock).
	 *
	 * @param WC_Order $order Order.
	 * @return array{voided:int[],adjusted:int[],changed:int[]}
	 */
	public static function reapply_locked( $order ) {
		$out     = array(
			'voided'   => array(),
			'adjusted' => array(),
			'changed'  => array(),
		);
		$by_item = array();
		foreach ( Eru_STC_Commissions::for_order( $order->get_id(), false ) as $row ) {
			if ( 'void' !== $row->status || 'refund' === $row->void_reason ) {
				$by_item[ (int) $row->item_id ][] = $row;
			}
		}
		if ( $by_item ) {
			self::reduce( $order, $by_item, 'refund', $out );
		}
		return $out;
	}

	/**
	 * Applies a reduction to one row: in place when unpaid, through an adjustment row when a payout holds
	 * it. Public for recovery tools; reconcile() is the normal entry point.
	 *
	 * @param object $row Commission row as last read.
	 * @param int    $cut Total reduction from the original amount (minor).
	 * @param string $why refund | cancel.
	 * @return array{voided:int[],adjusted:int[],changed:int[]}
	 */
	public static function apply_row( $row, $cut, $why ) {
		$out = array(
			'voided'   => array(),
			'adjusted' => array(),
			'changed'  => array(),
		);
		self::apply( $row, (int) $cut, $why, $out );
		return $out;
	}

	/** See apply_row(). */
	private static function apply( $row, $cut, $why, array &$out ) {
		global $wpdb;
		$t   = Eru_STC_Commissions::t();
		$now = current_time( 'mysql', true );
		if ( 'paid' !== $row->status && null === $row->payout_id ) {
			list( $adj_sum ) = Eru_STC_Commissions::adjustments_of( $row );
			$original        = Eru_STC_Money::to_minor( $row->original_amount );
			$amount          = Eru_STC_Money::to_minor( $row->amount );
			// Live adjustments (left from a time a payout held the row) already took their part.
			$target = $original - $cut - $adj_sum;
			if ( 'cancel' === $why || $target <= 0 ) {
				if ( 'void' === $row->status && ( 'refund' === $row->void_reason || $why === $row->void_reason ) ) {
					if ( 'cancel' === $why || 0 === $amount ) {
						return; // Already void; a refund void stays a refund void through a cancellation.
					}
				}
				$reason = 'cancel' === $why ? 'cancel' : 'refund';
				$new    = 'cancel' === $reason ? $amount : 0;
				$n      = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET amount = %s, status = 'void', void_reason = %s, note = %s, updated_at = %s WHERE id = %d AND payout_id IS NULL AND status <> 'paid'", Eru_STC_Money::from_minor( $new ), $reason, $reason, $now, $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( 1 !== (int) $n ) {
					self::retry_held( $row, $cut, $why, $out );
					return;
				}
				if ( 'void' !== $row->status ) {
					$out['voided'][] = (int) $row->id;
				}
				return;
			}
			if ( $target === $amount && 'void' !== $row->status ) {
				return;
			}
			if ( 'void' === $row->status ) {
				// A refund-voided row whose refund was deleted comes back pending and re-enters the hold.
				$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET amount = %s, status = 'pending', void_reason = '', note = %s, earned_at = %s, approved_at = NULL, updated_at = %s WHERE id = %d AND payout_id IS NULL AND status = 'void'", Eru_STC_Money::from_minor( $target ), $cut > 0 ? 'refund' : '', $now, $now, $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET amount = %s, note = %s, updated_at = %s WHERE id = %d AND payout_id IS NULL AND status IN ('pending','approved')", Eru_STC_Money::from_minor( $target ), $cut > 0 ? 'refund' : '', $now, $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( 1 !== (int) $n ) {
				self::retry_held( $row, $cut, $why, $out );
				return;
			}
			$out['changed'][] = (int) $row->id;
			do_action( 'eru_stc_commission_adjusted', (int) $row->id, 0, (int) $row->order_id );
			return;
		}
		// Held by a payout: what has been taken back so far = in-place reduction before the claim + live adjustments.
		list( $adj_sum, $adj_count ) = Eru_STC_Commissions::adjustments_of( $row );
		$taken                       = ( Eru_STC_Money::to_minor( $row->original_amount ) - Eru_STC_Money::to_minor( $row->amount ) ) - $adj_sum;
		$delta                       = $cut - $taken;
		if ( 0 === $delta ) {
			return;
		}
		$id = Eru_STC_Commissions::insert(
			array(
				'order_id'     => (int) $row->order_id,
				'item_id'      => (int) $row->item_id,
				'agent_id'     => (int) $row->agent_id,
				'level'        => (int) $row->level,
				'entry_key'    => 'adj:' . ( $adj_count + 1 ),
				'parent_id'    => (int) $row->id,
				'customer_id'  => (int) $row->customer_id,
				'product_id'   => (int) $row->product_id,
				'rule_id'      => (int) $row->rule_id,
				'rule_type'    => $row->rule_type,
				'rule_rate'    => $row->rule_rate,
				'basis_minor'  => 0,
				'amount_minor' => -$delta,
				'currency'     => $row->currency,
				'status'       => 'approved',
				'earned_at'    => $now,
				'approved_at'  => $now,
				'note'         => $why,
			)
		);
		if ( $id > 0 ) {
			$out['adjusted'][] = $id;
			/**
			 * Fires when a row changes: in place ($adjustment_id 0) or through an adjustment row.
			 *
			 * @param int $row_id        Commission row.
			 * @param int $adjustment_id Adjustment row id (0 = in place).
			 * @param int $order_id      Order.
			 */
			do_action( 'eru_stc_commission_adjusted', (int) $row->id, $id, (int) $row->order_id );
		}
	}

	/** The in-place write found the row changed (claimed meanwhile): re-read it and apply again once. */
	private static function retry_held( $row, $cut, $why, array &$out ) {
		$fresh = Eru_STC_Commissions::get( (int) $row->id );
		if ( $fresh && ( 'paid' === $fresh->status || null !== $fresh->payout_id ) ) {
			self::apply( $fresh, $cut, $why, $out );
		}
	}

	/**
	 * Folds the live, unclaimed adjustment rows of a commission row back into it (used when the payout
	 * request that held the row is cancelled), so a clawback is never taken twice.
	 *
	 * @param int $row_id Commission row, now unclaimed.
	 * @return bool Whether anything was folded.
	 */
	public static function net_adjustments( $row_id ) {
		global $wpdb;
		$row = Eru_STC_Commissions::get( $row_id );
		if ( ! $row || '' !== $row->entry_key || 'paid' === $row->status || null !== $row->payout_id ) {
			return false;
		}
		$lock = self::lock_order( (int) $row->order_id );
		try {
			$t    = Eru_STC_Commissions::t();
			$adjs = $wpdb->get_results( $wpdb->prepare( "SELECT id, amount FROM {$t} WHERE parent_id = %d AND entry_key <> '' AND status = 'approved' AND payout_id IS NULL", $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $adjs ) {
				return false;
			}
			$now = current_time( 'mysql', true );
			$sum = 0;
			foreach ( $adjs as $a ) {
				if ( 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'void', void_reason = 'netted', updated_at = %s WHERE id = %d AND status = 'approved' AND payout_id IS NULL", $now, $a->id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$sum += Eru_STC_Money::to_minor( $a->amount );
				}
			}
			$amount = max( 0, Eru_STC_Money::to_minor( $row->amount ) + $sum );
			if ( 0 === $amount ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET amount = %s, status = 'void', void_reason = 'refund', note = 'refund', updated_at = %s WHERE id = %d", Eru_STC_Money::from_minor( 0 ), $now, $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET amount = %s, note = 'refund', updated_at = %s WHERE id = %d", Eru_STC_Money::from_minor( $amount ), $now, $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return true;
		} finally {
			self::unlock_order( $lock );
		}
	}

	/**
	 * Per-order MySQL lock around read-check-write of an order's rows.
	 *
	 * @param int $order_id Order.
	 * @return string Lock name when acquired, '' otherwise (the work still runs; the UNIQUE key and the
	 *                recompute-from-totals design keep it safe).
	 */
	public static function lock_order( $order_id ) {
		global $wpdb;
		$name = $wpdb->prefix . 'eru_stc_order_' . (int) $order_id;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ? $name : '';
	}

	/** @param string $name Lock from lock_order(). */
	public static function unlock_order( $name ) {
		global $wpdb;
		if ( '' !== $name ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
}
