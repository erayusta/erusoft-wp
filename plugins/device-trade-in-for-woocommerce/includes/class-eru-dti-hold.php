<?php
/**
 * Optional order hold until the trade-in device is approved.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works on woocommerce_order_status_changed only, so gateways are untouched.
 */
class Eru_DTI_Hold {

	const STATUS = 'wc-eru-dti-hold';

	/** Hooks. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
	}

	/** @return bool Whether the custom status should exist. */
	private static function status_needed() {
		return Eru_DTI_Settings::is_on( 'hold_enabled' ) || get_option( 'eru_dti_hold_used' );
	}

	/** Registers the order status. */
	public static function register_status() {
		if ( ! self::status_needed() ) {
			return;
		}
		if ( get_post_status_object( self::STATUS ) ) {
			return;
		}
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Takas onayı bekleniyor', 'Order status', 'device-trade-in-for-woocommerce' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders. */
				'label_count'               => _n_noop( 'Takas onayı bekleniyor <span class="count">(%s)</span>', 'Takas onayı bekleniyor <span class="count">(%s)</span>', 'device-trade-in-for-woocommerce' ),
			)
		);
	}

	/** @param array $statuses Order statuses. @return array */
	public static function add_status( $statuses ) {
		if ( self::status_needed() ) {
			self::register_status();
			$statuses[ self::STATUS ] = _x( 'Takas onayı bekleniyor', 'Order status', 'device-trade-in-for-woocommerce' );
		}
		return $statuses;
	}

	/** @return bool True when a user with the capability changes the status in the admin. */
	public static function is_admin_change() {
		$is = is_admin() && is_user_logged_in() && current_user_can( 'manage_woocommerce' );
		return (bool) apply_filters( 'eru_dti_is_admin_change', $is );
	}

	/**
	 * @param int      $order_id Order ID.
	 * @param string   $from     Old status (no prefix).
	 * @param string   $to       New status (no prefix).
	 * @param WC_Order $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! Eru_DTI_Settings::is_on( 'hold_enabled' ) || ! $order instanceof WC_Order ) {
			return;
		}
		$hold = preg_replace( '/^wc-/', '', (string) Eru_DTI_Settings::get( 'hold_status' ) );
		if ( $to === $hold || ! in_array( $to, (array) Eru_DTI_Settings::get( 'hold_triggers' ), true ) ) {
			return;
		}
		if ( ! $order->get_meta( '_eru_dti_tradein_id' ) || self::is_admin_change() ) {
			return;
		}
		$row = Eru_DTI_DB::tradein_for_order( $order->get_id() );
		if ( ! $row || 'onaylandi' === $row['status'] ) {
			return;
		}
		update_option( 'eru_dti_hold_used', 1, false );
		$order->update_meta_data( '_eru_dti_held_from', $to );
		$order->update_status( $hold, __( 'Takas cihazı onaylanana kadar sipariş beklemeye alındı.', 'device-trade-in-for-woocommerce' ) . ' ' );
	}

	/**
	 * A rejected device must not let the order go on with the credit applied: cancel (setting) or hold it, and leave a note.
	 *
	 * @param WC_Order $order Order.
	 * @return string cancelled|held|noted
	 */
	public static function on_rejected( $order ) {
		$st   = $order->get_status();
		$hold = preg_replace( '/^wc-/', '', (string) Eru_DTI_Settings::get( 'hold_status' ) );
		if ( in_array( $st, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			return 'noted';
		}
		if ( Eru_DTI_Settings::is_on( 'reject_cancel' ) ) {
			$order->update_status( 'cancelled', __( 'Takas cihazı reddedildi, sipariş iptal edildi (takas kredisi uygulanmıştı).', 'device-trade-in-for-woocommerce' ) . ' ' );
			return 'cancelled';
		}
		if ( Eru_DTI_Settings::is_on( 'hold_enabled' ) && $st !== $hold && 'eru-dti-hold' !== $st ) {
			update_option( 'eru_dti_hold_used', 1, false );
			$order->update_meta_data( '_eru_dti_held_from', $st );
			$order->update_status( $hold, __( 'Takas cihazı reddedildi; sipariş beklemeye alındı. Takas kredisi uygulanmıştı, siparişi gözden geçirin.', 'device-trade-in-for-woocommerce' ) . ' ' );
			return 'held';
		}
		$order->add_order_note( __( 'Takas cihazı reddedildi; sipariş takas kredisiyle ilerliyor. Siparişi gözden geçirin.', 'device-trade-in-for-woocommerce' ) );
		return 'noted';
	}

	/** Releases a held order after approval. @param WC_Order $order Order. */
	public static function release( $order ) {
		$hold = preg_replace( '/^wc-/', '', (string) Eru_DTI_Settings::get( 'hold_status' ) );
		if ( $order->get_status() !== $hold && 'eru-dti-hold' !== $order->get_status() ) {
			return;
		}
		$to = (string) $order->get_meta( '_eru_dti_held_from' );
		if ( '' === $to || ! isset( wc_get_order_statuses()[ 'wc-' . $to ] ) ) {
			$to = 'processing';
		}
		$order->update_status( $to, __( 'Takas cihazı onaylandı, sipariş serbest bırakıldı.', 'device-trade-in-for-woocommerce' ) . ' ' );
	}
}
