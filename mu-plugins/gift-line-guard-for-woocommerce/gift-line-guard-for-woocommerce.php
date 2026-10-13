<?php
/**
 * Plugin Name:       Gift Line Guard for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/gift-line-guard-for-woocommerce/
 * Description:       Must-use guard for zero-priced gift cart lines: locks the quantity and keeps the price at 0 so gifts cannot be edited or abused. Works with Gift Product Rules for WooCommerce and, through a filter, with any other gift plugin.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gift-line-guard-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Eru_GLG_Guard', false ) ) {

	/**
	 * A cart line is a gift line when it carries the `_eru_gpr_gift` cart item key
	 * (Gift Product Rules) or when the filter `eru_glg_is_gift_line` says so.
	 * The first quantity the guard sees on a gift line is the allowed quantity.
	 */
	class Eru_GLG_Guard {

		const GPR_KEY = '_eru_gpr_gift';
		const QTY_KEY = '_eru_glg_qty';

		public static function init(): void {
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'enforce' ), 99 );
			add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'enforce' ), 99 );
			add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'locked_quantity' ), 99, 3 );
			add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'api_editable' ), 99, 3 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'free_order_item' ), 99, 4 );
		}

		public static function is_gift_line( array $item, string $key = '' ): bool {
			$is = ! empty( $item[ self::GPR_KEY ] );
			return (bool) apply_filters( 'eru_glg_is_gift_line', $is, $item, $key );
		}

		/** @param WC_Cart $cart Cart. */
		public static function enforce( $cart ): void {
			if ( ! $cart instanceof WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
				return;
			}
			foreach ( $cart->cart_contents as $key => $item ) {
				if ( ! self::is_gift_line( $item, (string) $key ) ) {
					continue;
				}
				if ( ! isset( $item[ self::QTY_KEY ] ) ) {
					$cart->cart_contents[ $key ][ self::QTY_KEY ] = max( 1, (int) $item['quantity'] );
				}
				$allowed = (int) apply_filters( 'eru_glg_allowed_quantity', (int) $cart->cart_contents[ $key ][ self::QTY_KEY ], $item, (string) $key );
				if ( $allowed > 0 && (int) $item['quantity'] !== $allowed ) {
					$cart->cart_contents[ $key ]['quantity'] = $allowed;
				}
				if ( isset( $item['data'] ) && is_object( $item['data'] ) && (float) $item['data']->get_price() > 0 ) {
					$item['data']->set_price( 0 );
				}
			}
		}

		public static function locked_quantity( $html, $key, $item ) {
			if ( ! is_array( $item ) || ! self::is_gift_line( $item, (string) $key ) ) {
				return $html;
			}
			return sprintf( '<span class="eru-glg-qty">%d</span><input type="hidden" name="cart[%s][qty]" value="%d" />', (int) $item['quantity'], esc_attr( (string) $key ), (int) $item['quantity'] );
		}

		public static function api_editable( $editable, $product, $item ) {
			return is_array( $item ) && self::is_gift_line( $item ) ? false : $editable;
		}

		/** Last line of defence: the order line of a gift is always free. */
		public static function free_order_item( $order_item, $key, $values, $order ): void {
			if ( is_array( $values ) && self::is_gift_line( $values, (string) $key ) ) {
				$order_item->set_subtotal( 0 );
				$order_item->set_total( 0 );
			}
		}
	}

	add_action(
		'plugins_loaded',
		function () {
			if ( class_exists( 'WooCommerce' ) ) {
				Eru_GLG_Guard::init();
			}
		},
		30
	);
}
