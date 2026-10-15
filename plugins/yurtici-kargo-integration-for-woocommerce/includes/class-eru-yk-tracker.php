<?php
/**
 * Scheduled delivery tracking (queryShipment) with per-phase intervals and back-off.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every 5 minutes, under a lock, the tracker takes up to 50 orders whose shipment is "dispatched" or
 * "in_transit" and whose next poll time has come, oldest last poll first. The due time and the ordering
 * are both in the query, so orders whose WooCommerce status changes between runs are never skipped.
 */
class Eru_YK_Tracker {

	const HOOK          = 'eru_yk_track';
	const BATCH         = 50;
	const BACKOFF_AFTER = 20;
	const TIME_BUDGET   = 120;

	/** Codes (operationStatus or cargoEventId) documented as delivered. Filter: eru_yk_delivered_codes. */
	const DELIVERED_CODES = array( 'DLV', 'OK' );

	/** Not yet accepted by a branch (no change). Filter: eru_yk_ignored_codes. */
	const IGNORED_CODES = array( 'NOP', 'ISR' );

	/** Cancelled on the provider's side (stop tracking). Filter: eru_yk_cancelled_codes. */
	const CANCELLED_CODES = array( 'CNL' );

	/**
	 * @param string $name delivered|ignored|cancelled.
	 * @return string[] Upper-case codes.
	 */
	public static function codes( $name ) {
		$map = array(
			'delivered' => self::DELIVERED_CODES,
			'ignored'   => self::IGNORED_CODES,
			'cancelled' => self::CANCELLED_CODES,
		);
		return array_map( fn( $c ) => strtoupper( (string) $c ), (array) apply_filters( 'eru_yk_' . $name . '_codes', $map[ $name ] ?? array() ) );
	}

	/**
	 * Seconds until the next poll.
	 *
	 * @param string $phase     dispatched | in_transit.
	 * @param int    $no_detail Consecutive polls without a record.
	 * @return int
	 */
	public static function interval( $phase, $no_detail = 0 ) {
		$base = 60 * max( 5, (int) Eru_YK_Settings::get( 'in_transit' === $phase ? 'poll_transit' : 'poll_dispatched' ) );
		if ( $no_detail > self::BACKOFF_AFTER ) {
			$exp  = min( 20, $no_detail - self::BACKOFF_AFTER );
			$base = (int) min( DAY_IN_SECONDS, $base * ( 2 ** $exp ) );
		}
		return $base;
	}

	/**
	 * @param int $limit Limit.
	 * @return WC_Order[] Due orders, oldest last poll first.
	 */
	public static function due_orders( $limit ) {
		return wc_get_orders(
			array(
				'type'       => 'shop_order',
				'limit'      => max( 1, (int) $limit ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					'yk_state' => array(
						'key'     => Eru_YK_Dispatcher::M_STATE,
						'value'   => array( 'dispatched', 'in_transit' ),
						'compare' => 'IN',
					),
					'yk_next'  => array(
						'key'     => Eru_YK_Dispatcher::M_NEXT,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					'yk_last'  => array(
						'key'     => Eru_YK_Dispatcher::M_POLLED,
						'compare' => 'EXISTS',
						'type'    => 'NUMERIC',
					),
				),
				'orderby'    => array( 'yk_last' => 'ASC' ),
			)
		);
	}

	/**
	 * Scheduled run.
	 *
	 * @return array Counters.
	 */
	public static function run() {
		$stats = array(
			'checked'   => 0,
			'delivered' => 0,
			'errors'    => 0,
		);
		if ( ! Eru_YK_Settings::is_enabled() || ! Eru_YK_Lock::acquire( 'track', 15 * MINUTE_IN_SECONDS ) ) {
			return $stats;
		}
		try {
			$start = time();
			foreach ( self::due_orders( (int) apply_filters( 'eru_yk_track_batch_size', self::BATCH ) ) as $order ) {
				if ( time() - $start > self::TIME_BUDGET ) {
					break;
				}
				$r = self::track( $order );
				++$stats['checked'];
				if ( 'delivered' === $r ) {
					++$stats['delivered'];
				} elseif ( 'error' === $r ) {
					++$stats['errors'];
				}
			}
		} finally {
			Eru_YK_Lock::release( 'track' );
		}
		return $stats;
	}

	/**
	 * Queries one order: its recorded account first, then each other account once.
	 *
	 * @param WC_Order $order Order.
	 * @return string delivered | in_transit | ignored | cancelled | no_detail | gave_up | error | skipped
	 */
	public static function track( WC_Order $order ) {
		$key   = (string) $order->get_meta( Eru_YK_Dispatcher::M_KEY );
		$state = (string) $order->get_meta( Eru_YK_Dispatcher::M_STATE );
		if ( '' === $key || ! in_array( $state, array( 'dispatched', 'in_transit' ), true ) ) {
			return 'skipped';
		}
		$usable  = Eru_YK_Accounts::usable();
		$primary = (string) $order->get_meta( Eru_YK_Dispatcher::M_ACCOUNT );
		$try     = isset( $usable[ $primary ] ) ? array( $primary => $usable[ $primary ] ) + $usable : $usable;
		$now     = time();
		$found  = null;
		$used   = null;
		$errors = 0;
		$last   = '';
		foreach ( $try as $acc_id => $account ) {
			$q       = ( new Eru_YK_Client( $account ) )->query( $key, $order->get_id() );
			$outcome = Eru_YK_Client::outcome( $q );
			if ( 'found' === $outcome ) {
				$found = Eru_YK_Client::delivery( $q['data'] );
				$used  = $account;
				break;
			}
			if ( 'error' === $outcome ) {
				// Auth or service errors are not "no record": they never count toward giving up.
				++$errors;
				$last = Eru_YK_Dispatcher::describe( $q );
			}
		}
		$order->update_meta_data( Eru_YK_Dispatcher::M_POLLED, $now );

		if ( ! $found ) {
			if ( $errors > 0 ) {
				$order->update_meta_data( Eru_YK_Dispatcher::M_NEXT, $now + self::interval( $state ) );
				$order->save();
				Eru_YK_Logger::event( $order->get_id(), 'track_error', __( 'Takip sorgusu başarısız; sonraki turda yeniden denenecek: ', 'yurtici-kargo-integration-for-woocommerce' ) . $last, 'warn' );
				return 'error';
			}
			$n = (int) $order->get_meta( Eru_YK_Dispatcher::M_NODETAIL ) + 1;
			$order->update_meta_data( Eru_YK_Dispatcher::M_NODETAIL, $n );
			if ( $n >= (int) Eru_YK_Settings::get( 'giveup' ) ) {
				$order->update_meta_data( Eru_YK_Dispatcher::M_STATE, 'gave_up' );
				$order->delete_meta_data( Eru_YK_Dispatcher::M_NEXT );
				$order->save();
				/* translators: %d: polls */
				$note = sprintf( __( 'Yurtiçi Kargo: %d sorguda kayıt bulunamadı, otomatik takip bırakıldı.', 'yurtici-kargo-integration-for-woocommerce' ), $n );
				$order->add_order_note( $note );
				Eru_YK_Logger::event( $order->get_id(), 'track_gave_up', $note, 'error' );
				return 'gave_up';
			}
			$order->update_meta_data( Eru_YK_Dispatcher::M_NEXT, $now + self::interval( $state, $n ) );
			$order->save();
			if ( 1 === $n || 0 === $n % 10 ) {
				/* translators: %d: polls */
				Eru_YK_Logger::event( $order->get_id(), 'track_no_detail', sprintf( __( 'Hiçbir hesapta kayıt bulunamadı (%d. sorgu).', 'yurtici-kargo-integration-for-woocommerce' ), $n ), 'warn' );
			}
			return 'no_detail';
		}

		$order->update_meta_data( Eru_YK_Dispatcher::M_NODETAIL, 0 );
		if ( $used && $used['id'] !== $primary ) {
			$order->update_meta_data( Eru_YK_Dispatcher::M_ACCOUNT, $used['id'] );
			$order->update_meta_data( Eru_YK_Dispatcher::M_LABEL, $used['label'] );
			/* translators: %s: account label */
			Eru_YK_Logger::event( $order->get_id(), 'account_healed', sprintf( __( 'Kayıt "%s" hesabında bulundu; siparişin hesabı düzeltildi.', 'yurtici-kargo-integration-for-woocommerce' ), $used['label'] ), 'warn' );
		}
		Eru_YK_Dispatcher::set_tracking( $order, $found['doc_id'], $found['tracking_url'] );
		$codes = array_filter( array( $found['status'], $found['event_id'] ) );
		$text  = '' !== $found['event'] ? $found['event'] : $found['message'];
		$label = trim( implode( '/', $codes ) . ( '' !== $text ? ' — ' . $text : '' ) );
		$prev  = (string) $order->get_meta( Eru_YK_Dispatcher::M_LASTEVENT );
		$order->update_meta_data( Eru_YK_Dispatcher::M_LASTEVENT, $label );

		if ( array_intersect( $codes, self::codes( 'delivered' ) ) ) {
			$order->update_meta_data( Eru_YK_Dispatcher::M_STATE, 'delivered' );
			$order->delete_meta_data( Eru_YK_Dispatcher::M_NEXT );
			$order->save();
			/* translators: %s: provider status */
			$note = sprintf( __( 'Yurtiçi Kargo: teslim edildi (%s).', 'yurtici-kargo-integration-for-woocommerce' ), $label );
			Eru_YK_Dispatcher::advance( $order, 'delivered_status', $note );
			Eru_YK_Logger::event( $order->get_id(), 'track_delivered', $note, 'success' );
			return 'delivered';
		}
		if ( array_intersect( $codes, self::codes( 'cancelled' ) ) ) {
			$order->update_meta_data( Eru_YK_Dispatcher::M_STATE, 'cancelled' );
			$order->delete_meta_data( Eru_YK_Dispatcher::M_NEXT );
			$order->save();
			/* translators: %s: provider status */
			$note = sprintf( __( 'Yurtiçi Kargo: gönderi Yurtiçi tarafında iptal görünüyor (%s); takip durduruldu.', 'yurtici-kargo-integration-for-woocommerce' ), $label );
			$order->add_order_note( $note );
			Eru_YK_Logger::event( $order->get_id(), 'track_cancelled', $note, 'warn' );
			return 'cancelled';
		}
		if ( ! $codes || array_intersect( $codes, self::codes( 'ignored' ) ) ) {
			$order->update_meta_data( Eru_YK_Dispatcher::M_NEXT, $now + self::interval( $state ) );
			$order->save();
			return 'ignored';
		}
		$order->update_meta_data( Eru_YK_Dispatcher::M_NEXT, $now + self::interval( 'in_transit' ) );
		if ( 'dispatched' === $state ) {
			$order->update_meta_data( Eru_YK_Dispatcher::M_STATE, 'in_transit' );
			$order->save();
			/* translators: %s: provider status */
			$note = sprintf( __( 'Yurtiçi Kargo: kargo yola çıktı (%s).', 'yurtici-kargo-integration-for-woocommerce' ), $label );
			Eru_YK_Dispatcher::advance( $order, 'transit_status', $note );
			Eru_YK_Logger::event( $order->get_id(), 'track_in_transit', $note, 'info' );
			return 'in_transit';
		}
		$order->save();
		if ( $label !== $prev ) {
			Eru_YK_Logger::event( $order->get_id(), 'track_update', $label, 'info' );
		}
		return 'in_transit';
	}
}
