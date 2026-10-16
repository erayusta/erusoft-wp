<?php
/**
 * Commission calculator. When an order reaches the trigger status it writes one row per line, per paying
 * agent level:
 *   agent    the order's attributed agent (_eru_stc_agent_id), else the customer's linked agent;
 *   chain    that agent and its upline up to the level cap (cycle-guarded, inactive agents skipped);
 *   basis    the line total after discounts, excluding tax, plus (optionally) the order's ex-tax shipping
 *            split over the lines by line total (largest remainder, so the shares sum to the shipping);
 *   amount   percent: basis × rate, rounded half-up once per row; fixed: rate × quantity, capped at
 *            the basis.
 * All math is in minor units. Idempotent twice over: the _eru_stc_calculated flag (re-read under the
 * order lock), and the UNIQUE key. Reaching the trigger again (cancelled, then completed) revives the
 * rows the cancellation voided.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Calculator {

	/** Hooks both trigger statuses; the handler checks the setting (and the master switch). */
	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_status' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_status' ), 20, 1 );
	}

	/** @param int $order_id Order that reached processing or completed. */
	public static function on_status( $order_id ) {
		if ( ! Eru_STC_Settings::enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return;
		}
		// "processing" also counts orders that go straight to completed; "completed" waits for completion.
		if ( 'completed' === Eru_STC_Settings::get( 'trigger_status' ) && ! $order->has_status( 'completed' ) ) {
			return;
		}
		$lock = Eru_STC_Refunds::lock_order( $order->get_id() );
		try {
			// Re-read inside the lock: another request may have calculated the order meanwhile.
			$fresh = wc_get_order( $order->get_id() );
			if ( '' === (string) $fresh->get_meta( '_eru_stc_calculated' ) ) {
				self::write_rows( $fresh );
			} else {
				// The trigger is reached again (e.g. cancelled, then completed): revive what the cancellation
				// voided, then re-apply the order's refunds.
				self::revive_locked( $fresh );
			}
		} finally {
			Eru_STC_Refunds::unlock_order( $lock );
		}
	}

	/**
	 * Brings back the rows a cancellation or failure voided (unclaimed ones): pending, the original
	 * amount, a new earned_at (the hold restarts) and no approval; then re-applies the current refunds,
	 * which also writes the reverse adjustment for rows a payout held while the order was cancelled.
	 * Rows voided by refunds stay void unless the refund itself is gone. The caller holds the order lock.
	 *
	 * @param WC_Order $order Order whose trigger status was reached again.
	 * @return int[] Revived row ids.
	 */
	private static function revive_locked( $order ) {
		global $wpdb;
		$t   = Eru_STC_Commissions::t();
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE order_id = %d AND entry_key = '' AND status = 'void' AND void_reason = 'cancel' AND payout_id IS NULL", $order->get_id() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $ids ) {
			$now = current_time( 'mysql', true );
			$in  = implode( ',', $ids );
			// The hold restarts now: date_paid / date_completed may be older than the cancellation.
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'pending', amount = original_amount, void_reason = '', note = '', earned_at = %s, approved_at = NULL, updated_at = %s WHERE id IN ({$in}) AND status = 'void' AND void_reason = 'cancel'", $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order->add_order_note(
				sprintf(
					/* translators: %d: number of commission rows */
					_n( 'İptal edilen %d komisyon satırı yeniden açıldı.', 'İptal edilen %d komisyon satırı yeniden açıldı.', count( $ids ), 'sales-team-commissions-for-woocommerce' ),
					count( $ids )
				)
			);
			/**
			 * Fires after rows voided by a cancellation were revived because the order reached its trigger
			 * status again.
			 *
			 * @param int   $order_id Order.
			 * @param int[] $ids      Revived row ids.
			 */
			do_action( 'eru_stc_commissions_revived', $order->get_id(), $ids );
		}
		Eru_STC_Refunds::reapply_locked( $order );
		return $ids;
	}

	/**
	 * Whether an order is the agent's own purchase: the customer is the agent's user, or (guest checkout)
	 * the billing e-mail is the agent's account e-mail.
	 *
	 * @param object $agent    Agent row.
	 * @param int    $customer Order customer id.
	 * @param string $billing  Billing e-mail, lower case.
	 * @return bool
	 */
	public static function is_own_order( $agent, $customer, $billing ) {
		if ( (int) $customer > 0 && (int) $agent->user_id === (int) $customer ) {
			return true;
		}
		if ( '' === $billing ) {
			return false;
		}
		$user = get_userdata( (int) $agent->user_id );
		return $user && strtolower( trim( $user->user_email ) ) === $billing;
	}

	/**
	 * Whether the order has live (not void) commission rows of a level-1 agent other than $agent_id. A
	 * recalculation for another agent is refused then: void those rows first, then recalculate.
	 *
	 * @param int $order_id Order.
	 * @param int $agent_id Intended level-1 agent.
	 * @return bool
	 */
	public static function level1_conflict( $order_id, $agent_id ) {
		global $wpdb;
		$t = Eru_STC_Commissions::t();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE order_id = %d AND entry_key = '' AND level = 1 AND status <> 'void' AND agent_id <> %d LIMIT 1", $order_id, $agent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The agent who earns level 1 for an order (0 = none).
	 *
	 * @param WC_Order $order Order.
	 * @return int Agent id.
	 */
	public static function agent_for_order( $order ) {
		$agent = (int) $order->get_meta( '_eru_stc_agent_id' );
		if ( $agent <= 0 && $order->get_customer_id() > 0 ) {
			$agent = Eru_STC_Links::agent_for( $order->get_customer_id() );
		}
		/**
		 * The level-1 agent of an order.
		 *
		 * @param int      $agent Agent id (0 = none).
		 * @param WC_Order $order Order.
		 */
		return (int) apply_filters( 'eru_stc_order_agent', $agent, $order );
	}

	/**
	 * The rows the order would get, without writing anything (also used by previews).
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Rows: item_id, product_id, agent_id, level, rule_id, rule_type, rule_rate,
	 *                 basis_minor, amount_minor, basis, amount (decimal strings).
	 */
	public static function plan( $order ) {
		Eru_STC_Rules::flush();
		$agent_id = self::agent_for_order( $order );
		$level1   = $agent_id > 0 ? Eru_STC_Agents::get( $agent_id ) : null;
		if ( ! Eru_STC_Agents::is_active( $level1 ) ) {
			return array();
		}
		$chain    = Eru_STC_Agents::chain( $agent_id, (int) Eru_STC_Settings::get( 'max_levels' ) );
		$customer = (int) $order->get_customer_id();
		$roles    = array();
		if ( $customer > 0 ) {
			$user  = get_userdata( $customer );
			$roles = $user ? (array) $user->roles : array();
		}
		$created = $order->get_date_created();
		$at      = $created ? $created->getTimestamp() : time();
		$billing = strtolower( trim( (string) $order->get_billing_email() ) );
		$rows    = array();
		foreach ( self::line_bases( $order ) as $item_id => $line ) {
			foreach ( $chain as $link ) {
				$agent = $link['agent'];
				if ( self::is_own_order( $agent, $customer, $billing ) ) {
					continue; // No commission on an agent's own purchases (account or billing e-mail).
				}
				$rule = Eru_STC_Rules::find( $link['level'], Eru_STC_Rules::context_for_product( $line['product'], (int) $agent->id, $roles, $at ) );
				if ( ! $rule ) {
					continue;
				}
				$amount = self::amount_minor( $rule, $line['basis_minor'], $line['qty'] );
				if ( $amount <= 0 ) {
					continue;
				}
				$rows[] = array(
					'item_id'      => (int) $item_id,
					'product_id'   => $line['product'] ? (int) $line['product']->get_id() : 0,
					'agent_id'     => (int) $agent->id,
					'level'        => (int) $link['level'],
					'rule_id'      => (int) $rule->id,
					'rule_type'    => $rule->type,
					'rule_rate'    => $rule->rate,
					'basis_minor'  => $line['basis_minor'],
					'amount_minor' => $amount,
					'basis'        => Eru_STC_Money::from_minor( $line['basis_minor'] ),
					'amount'       => Eru_STC_Money::from_minor( $amount ),
				);
			}
		}
		/**
		 * The planned rows of an order, before they are written.
		 *
		 * @param array[]  $rows  Rows (see plan()).
		 * @param WC_Order $order Order.
		 */
		return (array) apply_filters( 'eru_stc_order_plan', $rows, $order );
	}

	/**
	 * Writes the order's rows (duplicates ignored) and sets the calculated flag, under the order lock.
	 * The flag is re-read inside the lock; with $force the rows are (re)written even when it is set
	 * (recalculation). A recalculation only fills empty slots: a planned row is skipped when the order
	 * already has a live (not void) commission row for the same line and level, whatever its agent, so a
	 * changed attribution or hierarchy never pays a line twice.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $force Ignore the calculated flag.
	 * @return int[] Ids of the rows this call created.
	 */
	public static function calculate( $order, $force = false ) {
		$lock = Eru_STC_Refunds::lock_order( $order->get_id() );
		try {
			$fresh = wc_get_order( $order->get_id() );
			if ( ! $fresh || ( ! $force && '' !== (string) $fresh->get_meta( '_eru_stc_calculated' ) ) ) {
				return array();
			}
			return self::write_rows( $fresh, (bool) $force );
		} finally {
			Eru_STC_Refunds::unlock_order( $lock );
		}
	}

	/**
	 * Writes the rows and the flag. The caller holds the order lock.
	 *
	 * @param WC_Order $order Freshly read order.
	 * @param bool     $force Recalculation: skip line/level slots that already hold a live row.
	 * @return int[] Ids of the rows created.
	 */
	private static function write_rows( $order, $force = false ) {
		global $wpdb;
		$rows    = self::plan( $order );
		$created = array();
		$earned  = self::earned_at( $order );
		$taken   = array();
		if ( $force ) {
			$t = Eru_STC_Commissions::t();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT item_id, level FROM {$t} WHERE order_id = %d AND entry_key = '' AND status <> 'void'", $order->get_id() ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$taken[ (int) $r->item_id . '|' . (int) $r->level ] = true;
			}
		}
		foreach ( $rows as $row ) {
			if ( isset( $taken[ (int) $row['item_id'] . '|' . (int) $row['level'] ] ) ) {
				continue;
			}
			$id = Eru_STC_Commissions::insert(
				array_merge(
					$row,
					array(
						'order_id'    => $order->get_id(),
						'customer_id' => (int) $order->get_customer_id(),
						'currency'    => $order->get_currency(),
						'status'      => 'pending',
						'earned_at'   => $earned,
					)
				)
			);
			if ( $id > 0 ) {
				$created[] = $id;
				/**
				 * Fires for each commission row written.
				 *
				 * @param int      $id    Row id.
				 * @param array    $row   Planned row (see Eru_STC_Calculator::plan()).
				 * @param WC_Order $order Order.
				 */
				do_action( 'eru_stc_commission_created', $id, $row, $order );
			}
		}
		$order->update_meta_data( '_eru_stc_calculated', (string) time() );
		if ( $created ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: number of commission rows */
					_n( '%d komisyon satırı yazıldı.', '%d komisyon satırı yazıldı.', count( $created ), 'sales-team-commissions-for-woocommerce' ),
					count( $created )
				)
			);
		}
		$order->save();
		return $created;
	}

	/** @return string UTC datetime the commission counts as earned (start of the hold). */
	private static function earned_at( $order ) {
		$date = $order->has_status( 'completed' ) ? $order->get_date_completed() : $order->get_date_paid();
		$ts   = $date ? $date->getTimestamp() : time();
		return gmdate( 'Y-m-d H:i:s', min( $ts, time() ) );
	}

	/**
	 * Per-line basis in minor units.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array> item_id => [ product, qty, line_minor, shipping_minor, basis_minor ].
	 */
	public static function line_bases( $order ) {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$lines[ (int) $item_id ] = array(
				'product'        => $item->get_product(),
				'qty'            => max( 0, (int) $item->get_quantity() ),
				'line_minor'     => max( 0, Eru_STC_Money::to_minor( $item->get_total() ) ),
				'shipping_minor' => 0,
			);
		}
		if ( 'yes' === Eru_STC_Settings::get( 'include_shipping' ) && $lines ) {
			$shares = Eru_STC_Money::allocate( max( 0, Eru_STC_Money::to_minor( $order->get_shipping_total() ) ), array_column( $lines, 'line_minor', null ) );
			$i      = 0;
			foreach ( $lines as $item_id => $line ) {
				$lines[ $item_id ]['shipping_minor'] = $shares[ $i++ ];
			}
		}
		foreach ( $lines as $item_id => $line ) {
			$lines[ $item_id ]['basis_minor'] = $line['line_minor'] + $line['shipping_minor'];
		}
		return $lines;
	}

	/**
	 * Commission of one row in minor units.
	 *
	 * @param object $rule        Rule (type, rate).
	 * @param int    $basis_minor Line basis.
	 * @param int    $qty         Quantity (fixed rules pay per unit).
	 * @return int
	 */
	public static function amount_minor( $rule, $basis_minor, $qty ) {
		if ( 'fixed' === $rule->type ) {
			// Never more than the line itself.
			return min( Eru_STC_Money::to_minor( $rule->rate ) * max( 0, (int) $qty ), max( 0, (int) $basis_minor ) );
		}
		// rate has 4 decimals: basis × rate_e4 / (100 × 10 000).
		return Eru_STC_Money::div_round( (int) $basis_minor * Eru_STC_Money::to_minor( $rule->rate, 4 ), 1000000 );
	}
}
