<?php
/** Gateway filter and notices. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PRC_Frontend {

	public static function init(): void {
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_gateways' ), 100 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'cart_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'cart_notice' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_notice' ), 25 );
	}

	public static function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $gateways;
		}
		foreach ( Eru_PRC_Rules::triggered_by_cart() as $rule ) {
			foreach ( $rule['gateways'] as $id ) {
				unset( $gateways[ $id ] );
			}
		}
		return $gateways;
	}

	public static function cart_notice(): void {
		foreach ( Eru_PRC_Rules::triggered_by_cart() as $rule ) {
			if ( '' !== $rule['notice'] ) {
				echo '<div class="woocommerce-info eru-prc-notice">' . wp_kses_post( $rule['notice'] ) . '</div>';
			}
		}
	}

	public static function product_notice(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		foreach ( Eru_PRC_Rules::get() as $rule ) {
			if ( '' !== ( $rule['product_notice'] ?? '' ) && Eru_PRC_Rules::product_matches( $product->get_id(), $rule ) ) {
				echo '<div class="eru-prc-product-notice">' . wp_kses_post( $rule['product_notice'] ) . '</div>';
			}
		}
	}
}
