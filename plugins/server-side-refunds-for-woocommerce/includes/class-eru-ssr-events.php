<?php
/**
 * Checkout capture and refund / cancel event creation.
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SSR_Events {

	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'capture' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'capture' ) );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
	}

	/** Extracts "X.Y" from a GA cookie such as GA1.1.X.Y. */
	public static function parse_ga_cookie( $cookie ) {
		return preg_match( '/^GA\d+\.\d+\.(\d+\.\d+)$/', trim( (string) $cookie ), $m ) ? $m[1] : '';
	}

	/** Stores the client id (and, if enabled, the user id) on the order via the CRUD API. */
	public static function capture( $order ) {
		if ( ! $order instanceof WC_Order || ! self::destinations() ) {
			return; // Inert by default: no data is collected until a destination is on.
		}
		$changed = false;
		if ( isset( $_COOKIE['_ga'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only cookie parse.
			$cid = self::parse_ga_cookie( sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ) ) ); // phpcs:ignore
			if ( '' !== $cid ) {
				$order->update_meta_data( '_eru_ssr_client_id', $cid );
				$changed = true;
			}
		}
		if ( Eru_SSR_Settings::is_on( 'send_user_id' ) && $order->get_customer_id() > 0 ) {
			$order->update_meta_data( '_eru_ssr_user_id', (string) $order->get_customer_id() );
			$changed = true;
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/** Captured client id, or the <id>.<created ts> fallback when allowed, else empty. */
	public static function client_id( WC_Order $order ) {
		$cid = (string) $order->get_meta( '_eru_ssr_client_id' );
		if ( '' !== $cid ) {
			return $cid;
		}
		if ( Eru_SSR_Settings::is_on( 'fallback_client_id' ) && $order->get_date_created() ) {
			return $order->get_id() . '.' . $order->get_date_created()->getTimestamp();
		}
		return '';
	}

	/** Destinations that are on and configured. */
	public static function destinations() {
		$d = array();
		if ( Eru_SSR_GA4::ready() ) {
			$d[] = 'ga4';
		}
		if ( Eru_SSR_Matomo::ready() ) {
			$d[] = 'matomo';
		}
		return $d;
	}

	/** Builds the stored payload. */
	private static function payload( WC_Order $order, $type, $value, $refund_id = 0 ) {
		return array(
			'eru_ssr_event_type' => $type,
			'transaction_id'     => (string) $order->get_order_number(),
			'order_number'       => (string) $order->get_order_number(),
			'currency'           => $order->get_currency(),
			'value'              => (float) wc_format_decimal( $value, 2 ),
			'client_id'          => self::client_id( $order ),
			'user_id'            => (string) $order->get_meta( '_eru_ssr_user_id' ),
			'ts'                 => time(),
			'refund_id'          => (int) $refund_id,
		);
	}

	private static function enqueue( WC_Order $order, $type, $value, $dedupe, $refund_id = 0 ) {
		$payload = self::payload( $order, $type, $value, $refund_id );
		foreach ( self::destinations() as $dest ) {
			if ( 'ga4' === $dest && '' === $payload['client_id'] ) {
				continue; // GA4 requires a client id.
			}
			// A cancellation already reversed the remaining amount; a later refund must not reverse it again.
			if ( 0 !== strpos( $dedupe, 'cancel:' ) && Eru_SSR_Queue::exists( 'cancel:' . $order->get_id() . ':' . $dest ) ) {
				continue;
			}
			if ( ! apply_filters( 'eru_ssr_should_send', true, $dest, $order, $type, $payload ) ) {
				continue;
			}
			Eru_SSR_Queue::insert( $dest, $dedupe . ':' . $dest, $order->get_id(), $payload );
		}
	}

	public static function on_refunded( $order_id, $refund_id ) {
		if ( ! self::destinations() ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}
		$amount = abs( (float) $refund->get_amount() );
		if ( $amount <= 0 ) {
			return;
		}
		$full = (float) wc_format_decimal( $order->get_total() - $order->get_total_refunded(), 2 ) <= 0;
		if ( ! Eru_SSR_Settings::is_on( $full ? 'ev_full' : 'ev_partial' ) ) {
			return;
		}
		self::enqueue( $order, $full ? 'full' : 'partial', $amount, 'refund:' . (int) $refund_id, $refund_id );
	}

	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! in_array( $to, array( 'cancelled', 'failed' ), true ) || ! self::destinations() ) {
			return;
		}
		if ( ! Eru_SSR_Settings::is_on( 'cancelled' === $to ? 'ev_cancelled' : 'ev_failed' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// The order is no longer in a paid status here, so look at where it came from (or at its paid date).
		$was_paid = in_array( $from, wc_get_is_paid_statuses(), true ) || null !== $order->get_date_paid();
		if ( ! $was_paid ) {
			return;
		}
		$remaining = (float) wc_format_decimal( $order->get_total() - $order->get_total_refunded(), 2 );
		if ( $remaining <= 0 ) {
			return;
		}
		self::enqueue( $order, $to, $remaining, 'cancel:' . (int) $order_id );
	}
}
