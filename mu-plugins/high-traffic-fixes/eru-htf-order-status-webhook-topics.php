<?php
/**
 * Plugin Name: HTF — Order Status Webhook Topics
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Adds status-transition based WooCommerce webhook topics (order.processing, order.completed, order.cancelled, order.refunded).
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: the stock 'order.updated' webhook topic fires on every save of an order,
 * including meta updates by integrations (ERP syncs, shipping plugins, ...). A single
 * order can trigger the same webhook dozens of times, forcing the receiver to de-duplicate.
 *
 * FIX: registers four extra topics bound to WooCommerce's status hooks
 * (woocommerce_order_status_{processing,completed,cancelled,refunded}). Those hooks fire
 * exactly once per status transition, and meta changes do not trigger them. The topics
 * can be selected when creating a webhook in WooCommerce > Settings > Advanced > Webhooks
 * or through the REST API.
 *
 * Does nothing when WooCommerce is not active (its webhook filters never run).
 *
 * Disable: add_filter( 'eru_htf_order_status_webhook_topics_enabled', '__return_false' );
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status slugs exposed as `order.<status>` topics.
 *
 * @return string[]
 */
function eru_htf_webhook_topic_statuses() {
	return array( 'processing', 'completed', 'cancelled', 'refunded' );
}

add_filter(
	'woocommerce_valid_webhook_events',
	static function ( $events ) {
		if ( ! apply_filters( 'eru_htf_order_status_webhook_topics_enabled', true ) || ! is_array( $events ) ) {
			return $events;
		}

		foreach ( eru_htf_webhook_topic_statuses() as $status ) {
			if ( ! in_array( $status, $events, true ) ) {
				$events[] = $status;
			}
		}

		return $events;
	}
);

add_filter(
	'woocommerce_webhook_topic_hooks',
	static function ( $topic_hooks ) {
		if ( ! apply_filters( 'eru_htf_order_status_webhook_topics_enabled', true ) || ! is_array( $topic_hooks ) ) {
			return $topic_hooks;
		}

		foreach ( eru_htf_webhook_topic_statuses() as $status ) {
			$topic_hooks[ 'order.' . $status ] = array( 'woocommerce_order_status_' . $status );
		}

		return $topic_hooks;
	}
);

add_filter(
	'woocommerce_webhook_topics',
	static function ( $topics ) {
		if ( ! apply_filters( 'eru_htf_order_status_webhook_topics_enabled', true ) || ! is_array( $topics ) ) {
			return $topics;
		}

		$topics['order.processing'] = __( 'Sipariş işleniyor', 'high-traffic-fixes-for-woocommerce' );
		$topics['order.completed']  = __( 'Sipariş tamamlandı', 'high-traffic-fixes-for-woocommerce' );
		$topics['order.cancelled']  = __( 'Sipariş iptal edildi', 'high-traffic-fixes-for-woocommerce' );
		$topics['order.refunded']   = __( 'Sipariş iade edildi', 'high-traffic-fixes-for-woocommerce' );

		return $topics;
	}
);
