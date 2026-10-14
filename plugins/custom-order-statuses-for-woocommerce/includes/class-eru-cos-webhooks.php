<?php
/**
 * Optional module (off by default): WooCommerce webhook topics per custom status plus
 * order.processing / order.completed / order.cancelled / order.refunded.
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_COS_Webhooks {

	public static function init(): void {
		add_filter( 'woocommerce_webhook_topic_hooks', array( __CLASS__, 'topic_hooks' ) );
		add_filter( 'woocommerce_valid_webhook_events', array( __CLASS__, 'events' ) );
		add_filter( 'woocommerce_webhook_topics', array( __CLASS__, 'topics' ) );
	}

	private static function on(): bool {
		return (bool) Eru_COS_Statuses::settings()['webhooks'];
	}

	/** event => [ label, hook ] */
	private static function map(): array {
		if ( ! self::on() ) {
			return array();
		}
		$map = array(
			'processing' => array( __( 'Sipariş işleniyor', 'custom-order-statuses-for-woocommerce' ), 'woocommerce_order_status_processing' ),
			'completed'  => array( __( 'Sipariş tamamlandı', 'custom-order-statuses-for-woocommerce' ), 'woocommerce_order_status_completed' ),
			'cancelled'  => array( __( 'Sipariş iptal edildi', 'custom-order-statuses-for-woocommerce' ), 'woocommerce_order_status_cancelled' ),
			'refunded'   => array( __( 'Sipariş iade edildi', 'custom-order-statuses-for-woocommerce' ), 'woocommerce_order_status_refunded' ),
		);
		foreach ( Eru_COS_Statuses::all() as $slug => $s ) {
			/* translators: %s: status name */
			$map[ 'status_' . $slug ] = array( sprintf( __( 'Sipariş durumu: %s', 'custom-order-statuses-for-woocommerce' ), $s['label'] ), 'woocommerce_order_status_' . $slug );
		}
		return $map;
	}

	public static function topic_hooks( $hooks ) {
		foreach ( self::map() as $event => $m ) {
			$hooks[ 'order.' . $event ] = array( $m[1] );
		}
		return $hooks;
	}

	public static function events( $events ) {
		return array_values( array_unique( array_merge( (array) $events, array_keys( self::map() ) ) ) );
	}

	public static function topics( $topics ) {
		foreach ( self::map() as $event => $m ) {
			$topics[ 'order.' . $event ] = $m[0];
		}
		return $topics;
	}
}
