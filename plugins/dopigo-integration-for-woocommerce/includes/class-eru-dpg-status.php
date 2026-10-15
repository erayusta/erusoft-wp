<?php
/**
 * Order status sync in both directions through the mapping table.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Woo→Dopigo is queued per order; Dopigo→Woo runs in the 5-minute pull and only touches the order that
 * stores that Dopigo order id. An unmapped (or blank) status never changes anything.
 */
class Eru_DPG_Status {

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_changed' ), 20, 4 );
	}

	/** @param string $wc WC status (with or without wc-). @return string|null Dopigo status. */
	public static function wc_to_dopigo( $wc ) {
		$wc  = preg_replace( '/^wc-/', '', (string) $wc );
		$map = Eru_DPG_Settings::status_map()['wc_to_dp'];
		$v   = (string) ( $map[ $wc ] ?? '' );
		return '' !== $v && array_key_exists( $v, Eru_DPG_Settings::dopigo_statuses() ) ? $v : null;
	}

	/** @param string $dp Dopigo status. @return string|null WC status (without wc-). */
	public static function dopigo_to_wc( $dp ) {
		$map = Eru_DPG_Settings::status_map()['dp_to_wc'];
		$v   = (string) ( $map[ (string) $dp ] ?? '' );
		return '' !== $v && array_key_exists( 'wc-' . $v, wc_get_order_statuses() ) ? $v : null;
	}

	/** woocommerce_order_status_changed. */
	public static function on_changed( $id, $from, $to, $order = null ) {
		if ( Eru_DPG_Products::suppressed() || ! Eru_DPG_Settings::is_enabled() || ! Eru_DPG_Settings::allows( 'status_direction', 'wc_to_dp' ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $id );
		if ( $order && '' !== (string) $order->get_meta( Eru_DPG_Orders::M_ID ) && null !== self::wc_to_dopigo( $to ) ) {
			Eru_DPG_Cron::enqueue( 'eru_dpg_push_status', array( (int) $id ) );
		}
	}

	/** Action Scheduler callback: send the order's current status. @param int $id Order id. */
	public static function run_push_job( $id ) {
		$order  = wc_get_order( (int) $id );
		$remote = $order ? (string) $order->get_meta( Eru_DPG_Orders::M_ID ) : '';
		if ( ! $order || '' === $remote || ! Eru_DPG_Settings::is_enabled() ) {
			return;
		}
		$status = self::wc_to_dopigo( $order->get_status() );
		if ( null === $status ) {
			return;
		}
		$r = ( new Eru_DPG_API() )->update_order( $remote, array( 'status' => $status ) );
		if ( Eru_DPG_API::is_retryable( $r ) ) {
			Eru_DPG_Cron::retry( 'eru_dpg_push_status', array( (int) $id ), $r, (string) $id );
			return;
		}
		Eru_DPG_Cron::reset_attempts( 'eru_dpg_push_status', array( (int) $id ) );
		if ( is_wp_error( $r ) ) {
			Eru_DPG_Logger::log( 'status', 'failed', __( 'Durum gönderilemedi: ', 'dopigo-integration-for-woocommerce' ) . $r->get_error_message(), null, 'wc_to_dp', (string) $id );
			return;
		}
		/* translators: 1: WC status, 2: Dopigo status */
		Eru_DPG_Logger::log( 'status', ! empty( $r['dry_run'] ) ? 'info' : 'success', sprintf( __( 'Woo → Dopigo durum: %1$s → %2$s', 'dopigo-integration-for-woocommerce' ), $order->get_status(), $status ), null, 'wc_to_dp', (string) $id );
	}

	/**
	 * Reads recent Dopigo orders and applies mapped statuses to the linked WC orders.
	 *
	 * @return array Counters.
	 */
	public static function pull() {
		$res = array( 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'missing' => 0 );
		if ( ! Eru_DPG_Settings::is_enabled() || ! Eru_DPG_Settings::allows( 'status_direction', 'dp_to_wc' ) ) {
			return $res;
		}
		$orders = ( new Eru_DPG_API() )->get_orders(
			array(
				'limit'    => (int) Eru_DPG_Settings::get( 'page_size' ),
				'ordering' => '-id',
			)
		);
		if ( is_wp_error( $orders ) ) {
			Eru_DPG_Logger::log( 'status', 'warning', __( 'Dopigo siparişleri alınamadı: ', 'dopigo-integration-for-woocommerce' ) . $orders->get_error_message(), null, 'dp_to_wc' );
			return $res + array( 'error' => $orders->get_error_message() );
		}
		foreach ( $orders as $do ) {
			if ( ! is_array( $do ) || empty( $do['id'] ) ) {
				continue;
			}
			++$res['processed'];
			$order = ! empty( $do['service_order_id'] ) ? wc_get_order( (int) $do['service_order_id'] ) : null;
			if ( ! $order instanceof WC_Order ) {
				++$res['missing'];
				continue;
			}
			if ( (string) $order->get_meta( Eru_DPG_Orders::M_ID ) !== (string) $do['id'] ) {
				++$res['skipped']; // Same service id but not the order this site created.
				continue;
			}
			if ( Eru_DPG_Cron::pending( 'eru_dpg_push_status', array( $order->get_id() ) ) ) {
				++$res['skipped']; // A Woo→Dopigo status push is queued: the Woo status is newer.
				continue;
			}
			$target = self::dopigo_to_wc( (string) ( $do['status'] ?? '' ) );
			if ( null === $target || $order->has_status( $target ) ) {
				++$res['skipped'];
				continue;
			}
			if ( Eru_DPG_Settings::dry_run() ) {
				++$res['updated'];
				continue;
			}
			Eru_DPG_Products::without_echo(
				function () use ( $order, $target, $do ) {
					/* translators: %s: Dopigo status */
					$order->update_status( $target, sprintf( __( 'Dopigo durum senkronu: %s', 'dopigo-integration-for-woocommerce' ), sanitize_text_field( (string) $do['status'] ) ) );
				}
			);
			++$res['updated'];
		}
		if ( $res['updated'] ) {
			Eru_DPG_Logger::log( 'status', Eru_DPG_Settings::dry_run() ? 'info' : 'success', __( 'Dopigo → Woo sipariş durumu turu', 'dopigo-integration-for-woocommerce' ), $res, 'dp_to_wc' );
		}
		update_option( 'eru_dpg_last_status_pull', time(), false );
		return $res;
	}
}
