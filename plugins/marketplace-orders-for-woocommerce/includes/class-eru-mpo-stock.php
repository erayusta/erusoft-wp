<?php
/**
 * WooCommerce stock on import (setting "stock_mode"): leave (default) or reduce on import and restore on cancel.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uses WooCommerce's own functions, guarded by the order's "stock reduced" flag (the same flag WooCommerce sets
 * when a web order reduces stock) and each line's _reduced_stock meta, so an order reduces stock at most once
 * whatever runs it again. Unmatched lines have no product and are skipped by WooCommerce.
 */
class Eru_MPO_Stock {

	/**
	 * "Leave" must hold outside the import too: WooCommerce reduces stock when an order reaches processing,
	 * completed or on-hold, or on payment_complete, unless the order is flagged as reduced. For marketplace orders
	 * (created by this plugin or migrated to it) those reductions are refused while the mode is "leave". Restoring
	 * stock is not filtered: an order reduced earlier (mode was "reduce") is still restored on cancel.
	 */
	public static function init() {
		add_filter( 'woocommerce_can_reduce_order_stock', array( __CLASS__, 'filter_can_reduce' ), 20, 2 );
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', array( __CLASS__, 'filter_payment_complete' ), 20, 2 );
	}

	/** @param WC_Order|mixed $order Order. @return bool A marketplace order. */
	public static function is_marketplace_order( $order ) {
		return $order instanceof WC_Order && ( 0 === strpos( (string) $order->get_created_via(), 'eru-mpo-' ) || '' !== (string) $order->get_meta( '_eru_mpo_marketplace' ) );
	}

	/** @param bool $can Can reduce. @param WC_Order $order Order. @return bool */
	public static function filter_can_reduce( $can, $order ) {
		return ( 'leave' === Eru_MPO_Settings::stock_mode() && self::is_marketplace_order( $order ) ) ? false : $can;
	}

	/** @param bool $trigger Reduce on payment. @param int $order_id Order id. @return bool */
	public static function filter_payment_complete( $trigger, $order_id ) {
		if ( ! $trigger || 'leave' !== Eru_MPO_Settings::stock_mode() ) {
			return $trigger;
		}
		return self::is_marketplace_order( wc_get_order( $order_id ) ) ? false : $trigger;
	}

	/** @param WC_Order $order Order. @return bool Stock was reduced for this order already. */
	public static function is_reduced( $order ) {
		return (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );
	}

	/**
	 * Reduces stock once, when the mode is "reduce".
	 *
	 * @param WC_Order $order Saved order.
	 * @return bool Reduced now.
	 */
	public static function reduce( $order ) {
		if ( 'reduce' !== Eru_MPO_Settings::stock_mode() || ! $order->get_id() || self::is_reduced( $order ) ) {
			return false;
		}
		wc_reduce_stock_levels( $order );
		$order->get_data_store()->set_stock_reduced( $order->get_id(), true );
		return true;
	}

	/**
	 * Restores what this order reduced (any mode: an order reduced earlier is restored even if the mode changed).
	 *
	 * @param WC_Order $order Order.
	 * @return bool Restored now.
	 */
	public static function restore( $order ) {
		if ( ! $order->get_id() || ! self::is_reduced( $order ) ) {
			return false;
		}
		wc_increase_stock_levels( $order );
		$order->get_data_store()->set_stock_reduced( $order->get_id(), false );
		return true;
	}

	/**
	 * Restores the units of cancelled lines (partial cancel), never more than the line reduced.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $lines List of {remote_line_id, sku, barcode, quantity}.
	 * @return int Units restored.
	 */
	public static function restore_lines( $order, array $lines ) {
		if ( ! self::is_reduced( $order ) || 'yes' !== get_option( 'woocommerce_manage_stock' ) ) {
			return 0;
		}
		$restored = 0;
		foreach ( $lines as $l ) {
			$item = Eru_MPO_Lifecycle::find_item( $order, $l );
			if ( ! $item ) {
				continue;
			}
			$product = $item->get_product();
			$reduced = (int) $item->get_meta( '_reduced_stock', true );
			$qty     = min( max( 1, (int) ( $l['quantity'] ?? 1 ) ), $reduced );
			if ( ! $product || ! $product->managing_stock() || $qty < 1 ) {
				continue;
			}
			$new = wc_update_product_stock( $product, $qty, 'increase' );
			if ( is_wp_error( $new ) ) {
				continue;
			}
			$left = $reduced - $qty;
			if ( $left > 0 ) {
				$item->update_meta_data( '_reduced_stock', $left );
			} else {
				$item->delete_meta_data( '_reduced_stock' );
			}
			$item->save();
			$restored += $qty;
			/* translators: 1: product name, 2: units, 3: new stock */
			$order->add_order_note( sprintf( __( 'Kısmi iptal: %1$s stoğu %2$d adet artırıldı (yeni stok %3$s).', 'marketplace-orders-for-woocommerce' ), $product->get_name(), $qty, (string) $new ) );
		}
		return $restored;
	}
}
