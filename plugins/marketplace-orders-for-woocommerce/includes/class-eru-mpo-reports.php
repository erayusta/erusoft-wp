<?php
/**
 * Reports: the plugin's own order statuses in WooCommerce's paid-status list and analytics.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A marketplace collects the money before the order reaches the shop, so in "own" status mode
 * eru-mpo-new / accepted / ready / shipped / delivered count as paid (woocommerce_order_is_paid_statuses, used by
 * customer totals, and the legacy reports through woocommerce_reports_order_statuses when the report's list holds
 * paid core statuses). eru-mpo-cancelled and
 * eru-mpo-returned are added to the statuses WooCommerce Analytics leaves out
 * (woocommerce_analytics_excluded_order_statuses); other statuses are counted by Analytics anyway. The list is
 * filterable (eru_mpo_paid_statuses, logical keys). In "core" mode the orders already use core statuses.
 */
class Eru_MPO_Reports {

	/** Hooks. */
	public static function init() {
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'reports' ) );
		add_filter( 'woocommerce_analytics_excluded_order_statuses', array( __CLASS__, 'excluded' ) );
	}

	/** @return string[] Own slugs (without "wc-") that count as paid. */
	public static function paid_slugs() {
		$keys = (array) apply_filters( 'eru_mpo_paid_statuses', array( 'new', 'accepted', 'ready', 'shipped', 'delivered' ) );
		return array_values( array_map( array( 'Eru_MPO_Statuses', 'slug' ), array_filter( $keys, array( 'Eru_MPO_Statuses', 'is_key' ) ) ) );
	}

	/** @param mixed $list Statuses. @return mixed The list plus the paid marketplace statuses. */
	public static function paid( $list ) {
		return is_array( $list ) ? array_values( array_unique( array_merge( $list, self::paid_slugs() ) ) ) : $list;
	}

	/**
	 * Legacy reports: the marketplace statuses join a status list only when it is a list of paid statuses (it holds
	 * processing or completed); a report built on other statuses (e.g. refunded only) is left as it is.
	 *
	 * @param mixed $list Statuses.
	 * @return mixed
	 */
	public static function reports( $list ) {
		if ( ! is_array( $list ) || ! array_intersect( array( 'processing', 'completed', 'wc-processing', 'wc-completed' ), $list ) ) {
			return $list;
		}
		return self::paid( $list );
	}

	/** @param mixed $list Excluded statuses. @return mixed Plus cancelled and returned marketplace orders. */
	public static function excluded( $list ) {
		if ( ! is_array( $list ) ) {
			return $list;
		}
		return array_values( array_unique( array_merge( $list, array( Eru_MPO_Statuses::slug( 'cancelled' ), Eru_MPO_Statuses::slug( 'returned' ) ) ) ) );
	}
}
