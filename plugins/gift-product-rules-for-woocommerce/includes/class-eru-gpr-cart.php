<?php
/** Adds, prices and removes the gift lines. Gift lines carry the cart item key `_eru_gpr_gift` (= rule id). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_GPR_Cart {

	const KEY = '_eru_gpr_gift';

	private static bool $syncing = false;

	public static function init(): void {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'on_calculate' ), 20 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'locked_quantity' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( __CLASS__, 'hide_remove' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'free_label' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'free_label' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'api_editable' ), 20, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item' ), 20, 4 );
	}

	public static function is_gift( array $item ): bool {
		return ! empty( $item[ self::KEY ] );
	}

	public static function on_calculate( $cart ): void {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $cart instanceof WC_Cart ) {
			return;
		}
		if ( ! self::$syncing ) {
			self::$syncing = true;
			try {
				self::sync( $cart );
			} finally {
				self::$syncing = false;
			}
		}
		// Always keep the gift lines free, also in the nested recalculation triggered by add/remove.
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( self::is_gift( $item ) && isset( $item['data'] ) && is_object( $item['data'] ) ) {
				$item['data']->set_price( 0 );
			}
		}
	}

	private static function sync( WC_Cart $cart ): void {
		$rules = Eru_GPR_Rules::get();

		$lines = array();
		$total = 0.0;
		$gifts = array(); // rule id => cart item keys.
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
				continue;
			}
			if ( self::is_gift( $item ) ) {
				$gifts[ (string) $item[ self::KEY ] ][] = $key;
				continue;
			}
			$lines[ $key ] = $item;
			$total        += (float) $item['data']->get_price() * (int) $item['quantity'];
		}

		$wanted = array();
		foreach ( $rules as $rule_id => $rule ) {
			$rule_id = (string) $rule_id;
			if ( Eru_GPR_Rules::matches( $rule, $lines, $total ) ) {
				$wanted[ $rule_id ] = $rule;
			}
		}
		$wanted = (array) apply_filters( 'eru_gpr_active_rules', $wanted, $cart );

		// Remove gifts whose rule no longer matches, plus duplicates.
		foreach ( $gifts as $rule_id => $keys ) {
			$keep = isset( $wanted[ $rule_id ] ) ? array_shift( $keys ) : null;
			foreach ( $keys as $key ) {
				$cart->remove_cart_item( $key );
			}
			if ( null === $keep ) {
				continue;
			}
			$gifts[ $rule_id ] = array( $keep );
			// Quantity is fixed by the rule.
			$qty = max( 1, (int) $wanted[ $rule_id ]['qty'] );
			if ( isset( $cart->cart_contents[ $keep ] ) && (int) $cart->cart_contents[ $keep ]['quantity'] !== $qty ) {
				$cart->cart_contents[ $keep ]['quantity'] = $qty;
				// Keep Gift Line Guard (if installed) in step with the rule's quantity.
				$cart->cart_contents[ $keep ]['_eru_glg_qty'] = $qty;
			}
		}

		// Add missing gifts.
		foreach ( $wanted as $rule_id => $rule ) {
			if ( ! empty( $gifts[ $rule_id ] ) ) {
				continue;
			}
			$product = wc_get_product( (int) $rule['gift_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}
			$variation_id = 0;
			$product_id   = $product->get_id();
			$attributes   = array();
			if ( $product->is_type( 'variation' ) ) {
				$variation_id = $product->get_id();
				$product_id   = $product->get_parent_id();
				$attributes   = $product->get_variation_attributes();
			}
			$cart->add_to_cart( $product_id, max( 1, (int) $rule['qty'] ), $variation_id, $attributes, array( self::KEY => (string) $rule_id ) );
		}
	}

	private static function label( array $item ): string {
		$rules = Eru_GPR_Rules::get();
		$id    = (string) ( $item[ self::KEY ] ?? '' );
		$label = isset( $rules[ $id ]['label'] ) ? (string) $rules[ $id ]['label'] : '';
		return '' !== $label ? $label : __( 'Hediye', 'gift-product-rules-for-woocommerce' );
	}

	public static function locked_quantity( $html, $key, $item ) {
		if ( ! self::is_gift( $item ) ) {
			return $html;
		}
		return sprintf( '<span class="eru-gpr-qty">%d</span><input type="hidden" name="cart[%s][qty]" value="%d" />', (int) $item['quantity'], esc_attr( $key ), (int) $item['quantity'] );
	}

	public static function hide_remove( $link, $key ) {
		$cart = WC()->cart;
		if ( $cart && isset( $cart->cart_contents[ $key ] ) && self::is_gift( $cart->cart_contents[ $key ] ) ) {
			return '';
		}
		return $link;
	}

	public static function free_label( $html, $item, $key ) {
		return self::is_gift( $item ) ? '<span class="eru-gpr-free">' . esc_html( self::label( $item ) ) . '</span>' : $html;
	}

	public static function api_editable( $editable, $product, $item ) {
		return is_array( $item ) && self::is_gift( $item ) ? false : $editable;
	}

	public static function order_item( $order_item, $key, $values, $order ): void {
		if ( empty( $values[ self::KEY ] ) ) {
			return;
		}
		$order_item->add_meta_data( self::KEY, (string) $values[ self::KEY ], true );
		$order_item->add_meta_data( __( 'Kampanya', 'gift-product-rules-for-woocommerce' ), self::label( $values ), true );
	}
}
