<?php
/**
 * Cart and checkout output. Every block is guarded by its module switch.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classic (shortcode) cart and checkout hooks. Block cart/checkout is not hooked; see README.
 */
class Eru_CB_Front {

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'cart_top' ), 5 );
		add_action( 'woocommerce_after_cart', array( __CLASS__, 'cart_bottom' ), 20 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'checkout_top' ), 5 );
	}

	/** Bar with suggestions and the timed coupon box above the cart. */
	public static function cart_top() {
		$out = '';
		if ( Eru_CB_Settings::is_on( 'ship_enabled' ) && Eru_CB_Settings::is_on( 'ship_on_cart' ) ) {
			$out .= Eru_CB_Shipping::bar_html( true );
		}
		$out .= self::timed();
		self::emit( $out );
	}

	/** Recommendations below the cart, only when the cart has no cross-sells. */
	public static function cart_bottom() {
		if ( ! Eru_CB_Settings::is_on( 'recs_enabled' ) ) {
			return;
		}
		$items = Eru_CB_Shipping::recommendations();
		if ( $items ) {
			self::emit( '<section class="eru-cb-recs"><h2 class="eru-cb-recs__title">' . esc_html__( 'Bunlar da ilginizi çekebilir', 'conversion-boosters-for-woocommerce' ) . '</h2>' . Eru_CB_Shipping::cards( $items ) . '</section>' );
		}
	}

	/** Bar (without suggestions) and optional timed coupon box at checkout. */
	public static function checkout_top() {
		$out = '';
		if ( Eru_CB_Settings::is_on( 'ship_enabled' ) && Eru_CB_Settings::is_on( 'ship_on_checkout' ) ) {
			$out .= Eru_CB_Shipping::bar_html( false );
		}
		if ( Eru_CB_Settings::is_on( 'tc_on_checkout' ) ) {
			$out .= self::timed();
		}
		self::emit( $out );
	}

	/** @return string Timed coupon box. */
	private static function timed() {
		return Eru_CB_Settings::is_on( 'tc_enabled' ) ? Eru_CB_Timed::box_html() : '';
	}

	/** @param string $html Escaped markup built by this plugin. */
	private static function emit( $html ) {
		if ( '' === trim( $html ) ) {
			return;
		}
		Eru_CB_Shipping::enqueue();
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- every part is escaped where it is built.
	}
}
