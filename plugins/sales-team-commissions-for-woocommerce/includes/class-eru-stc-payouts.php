<?php
/**
 * Payouts. A request claims every approved, unclaimed row of the agent (adjustments included) under a
 * per-agent MySQL lock: one open request per agent, nothing below the minimum, nothing at or below zero.
 * Paying it marks the claimed rows paid; cancelling it releases them and folds the adjustments written
 * against them in the meantime back into the rows.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Payouts {

	const STATUSES = array( 'requested', 'paid', 'cancelled' );

	/** @return string Table name. */
	private static function t() {
		return Eru_STC_Installer::table( 'payouts' );
	}

	/**
	 * Opens a payout for an agent's available balance.
	 *
	 * @param int   $agent_id Agent.
	 * @param array $args     method, note, details (ciphertext snapshot; default: the agent's stored payout
	 *                        details), created_by, ignore_minimum (bool; an admin paying out a smaller balance).
	 * @return int|WP_Error Payout id.
	 */
	public static function create_request( $agent_id, array $args = array() ) {
		global $wpdb;
		$agent = Eru_STC_Agents::get( $agent_id );
		if ( ! $agent ) {
			return new WP_Error( 'eru_stc_payout_agent', __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$lock = $wpdb->prefix . 'eru_stc_payout_' . (int) $agent->id;
		/**
		 * Seconds a payout request waits for the agent's lock before giving up.
		 *
		 * @param int $seconds Default 10.
		 */
		$wait = max( 0, min( 30, (int) apply_filters( 'eru_stc_lock_wait', 10 ) ) );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $wait ) ) ) {
			return new WP_Error( 'eru_stc_payout_busy', __( 'Başka bir ödeme talebi işleniyor; tekrar deneyin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		try {
			$payouts_table = self::t();
			$ct            = Eru_STC_Commissions::t();
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$payouts_table} WHERE agent_id = %d AND status = 'requested' LIMIT 1", $agent->id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return new WP_Error( 'eru_stc_payout_open', __( 'Bekleyen bir ödeme talebi zaten var.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, amount, currency FROM {$ct} WHERE agent_id = %d AND status = 'approved' AND payout_id IS NULL ORDER BY id", $agent->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = 0;
			foreach ( $rows as $r ) {
				$total += Eru_STC_Money::to_minor( $r->amount );
			}
			if ( ! $rows || $total <= 0 ) {
				return new WP_Error( 'eru_stc_payout_empty', __( 'Ödenebilir bakiye yok.', 'sales-team-commissions-for-woocommerce' ) );
			}
			if ( empty( $args['ignore_minimum'] ) && $total < Eru_STC_Money::to_minor( Eru_STC_Settings::get( 'min_payout' ) ) ) {
				return new WP_Error( 'eru_stc_payout_minimum', __( 'Bakiye en düşük ödeme tutarının altında.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				$payouts_table,
				array(
					'agent_id'     => (int) $agent->id,
					'amount'       => Eru_STC_Money::from_minor( $total ),
					'currency'     => (string) ( $rows[0]->currency ? $rows[0]->currency : get_woocommerce_currency() ),
					'status'       => 'requested',
					'method'       => sanitize_key( (string) ( $args['method'] ?? 'bank_transfer' ) ),
					'details'      => array_key_exists( 'details', $args ) ? ( null === $args['details'] ? null : (string) $args['details'] ) : Eru_STC_Payout_Details::snapshot( $agent ),
					'note'         => substr( sanitize_text_field( (string) ( $args['note'] ?? '' ) ), 0, 255 ),
					'created_by'   => (int) ( $args['created_by'] ?? get_current_user_id() ),
					'requested_at' => $now,
					'updated_at'   => $now,
				)
			);
			$payout_id = (int) $wpdb->insert_id;
			if ( $payout_id <= 0 ) {
				return new WP_Error( 'eru_stc_payout_db', __( 'Ödeme talebi kaydedilemedi.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$ids     = array_map( fn( $r ) => (int) $r->id, $rows );
			$in      = implode( ',', $ids );
			$claimed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = %d, updated_at = %s WHERE id IN ({$in}) AND agent_id = %d AND status = 'approved' AND payout_id IS NULL", $payout_id, $now, $agent->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$undo = function () use ( $wpdb, $ct, $payouts_table, $payout_id ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = NULL WHERE payout_id = %d", $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->delete( $payouts_table, array( 'id' => $payout_id ) );
			};
			if ( $claimed !== count( $ids ) ) {
				// A row changed under us (refund, admin): undo and let the caller retry.
				$undo();
				return new WP_Error( 'eru_stc_payout_changed', __( 'Bakiye bu arada değişti; tekrar deneyin.', 'sales-team-commissions-for-woocommerce' ) );
			}
			// A refund may have changed a row's amount between the selection and the claim: the payout is
			// the sum of the rows it holds now.
			$held = Eru_STC_Money::to_minor( self::rows_total( $payout_id ) );
			if ( $held <= 0 || ( empty( $args['ignore_minimum'] ) && $held < Eru_STC_Money::to_minor( Eru_STC_Settings::get( 'min_payout' ) ) ) ) {
				$undo();
				return new WP_Error( 'eru_stc_payout_changed', __( 'Bakiye bu arada değişti; tekrar deneyin.', 'sales-team-commissions-for-woocommerce' ) );
			}
			if ( $held !== $total ) {
				$wpdb->update( $payouts_table, array( 'amount' => Eru_STC_Money::from_minor( $held ) ), array( 'id' => $payout_id ) );
			}
			/**
			 * Fires after a payout request claimed the agent's balance.
			 *
			 * @param int   $payout_id Payout.
			 * @param int   $agent_id  Agent.
			 * @param int[] $ids       Claimed commission rows.
			 */
			do_action( 'eru_stc_payout_requested', $payout_id, (int) $agent->id, $ids );
			return $payout_id;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Marks a requested payout paid; its rows (adjustments included) become paid.
	 *
	 * @param int    $payout_id Payout.
	 * @param string $reference Bank reference or note.
	 * @param string $method    Payment method key (empty: keep the requested one).
	 * @return true|WP_Error
	 */
	public static function mark_paid( $payout_id, $reference = '', $method = '' ) {
		global $wpdb;
		$payouts_table = self::t();
		$ct            = Eru_STC_Commissions::t();
		$now           = current_time( 'mysql', true );
		$method        = sanitize_key( (string) $method );
		$n             = $wpdb->query( $wpdb->prepare( "UPDATE {$payouts_table} SET status = 'paid', paid_at = %s, reference = %s, method = IF(%s = '', method, %s), updated_at = %s WHERE id = %d AND status = 'requested'", $now, substr( sanitize_text_field( (string) $reference ), 0, 190 ), $method, $method, $now, $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $n ) {
			return new WP_Error( 'eru_stc_payout_state', __( 'Yalnızca bekleyen talep ödendi olarak işaretlenebilir.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET status = 'paid', updated_at = %s WHERE payout_id = %d AND status = 'approved'", $now, $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		do_action( 'eru_stc_payout_paid', (int) $payout_id );
		return true;
	}

	/**
	 * Cancels a requested payout and releases its rows.
	 *
	 * @param int $payout_id Payout.
	 * @return true|WP_Error
	 */
	public static function cancel( $payout_id ) {
		global $wpdb;
		$payouts_table = self::t();
		$ct            = Eru_STC_Commissions::t();
		$now           = current_time( 'mysql', true );
		$n             = $wpdb->query( $wpdb->prepare( "UPDATE {$payouts_table} SET status = 'cancelled', updated_at = %s WHERE id = %d AND status = 'requested'", $now, $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== (int) $n ) {
			return new WP_Error( 'eru_stc_payout_state', __( 'Yalnızca bekleyen talep iptal edilebilir.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$released = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$ct} WHERE payout_id = %d AND status = 'approved' AND entry_key = ''", $payout_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET payout_id = NULL, updated_at = %s WHERE payout_id = %d AND status = 'approved'", $now, $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Adjustments written while the payout held a row now fold back into it (no double clawback).
		foreach ( $released as $row_id ) {
			Eru_STC_Refunds::net_adjustments( $row_id );
		}
		do_action( 'eru_stc_payout_cancelled', (int) $payout_id );
		return true;
	}

	/**
	 * Sum of the commission rows a payout holds (it equals the payout amount; shown for audit).
	 *
	 * @param int $payout_id Payout.
	 * @return string Decimal.
	 */
	public static function rows_total( $payout_id ) {
		global $wpdb;
		$ct  = Eru_STC_Commissions::t();
		$sum = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT amount FROM {$ct} WHERE payout_id = %d", $payout_id ) ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sum += Eru_STC_Money::to_minor( $a );
		}
		return Eru_STC_Money::from_minor( $sum );
	}

	/** @param int $payout_id Payout. @return object|null */
	public static function get( $payout_id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $payout_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Payouts, newest first.
	 *
	 * @param array $args agent_id, status, ids (int[]), before_id (keyset: only ids below it), limit (≤ 500),
	 *                    offset.
	 * @return object[]
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$params[] = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$params[] = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$t        = self::t();
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
		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
			$ids     = array_values( array_filter( array_map( 'absint', $args['ids'] ) ) );
			$where[] = $ids ? 'id IN (' . implode( ',', $ids ) . ')' : '0=1';
		}
		if ( ! empty( $args['agent_id'] ) ) {
			$where[]  = 'agent_id = %d';
			$params[] = (int) $args['agent_id'];
		}
		if ( ! empty( $args['before_id'] ) ) {
			$where[]  = 'id < %d';
			$params[] = (int) $args['before_id'];
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		return array( implode( ' AND ', $where ), $params );
	}
}
