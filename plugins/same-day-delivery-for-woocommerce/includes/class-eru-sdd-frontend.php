<?php
/** "Ships today / tomorrow" message on product and cart pages. Output only when enabled. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SDD_Frontend {

	public static function init(): void {
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_message' ), 36 );
		add_action( 'woocommerce_before_cart_totals', array( __CLASS__, 'cart_message' ) );
		add_shortcode( 'eru_sdd_message', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Shipping rates are cached per package; make the hash depend on today's open/closed state.
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'package_slot' ) );
	}

	public static function enabled(): bool {
		return (bool) Eru_SDD_Schedule::settings()['show_messages'];
	}

	/** Is the same-day shipping method added (and enabled) in any shipping zone? Cached per request. */
	public static function method_configured(): bool {
		static $on = null;
		if ( null === $on ) {
			global $wpdb;
			$on = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_shipping_zone_methods WHERE method_id = %s AND is_enabled = 1", 'eru_sdd' ) ); // phpcs:ignore WordPress.DB
			$on = (bool) apply_filters( 'eru_sdd_method_configured', $on );
		}
		return $on;
	}

	/** Rate cache key follows the open/closed state, only when the shipping method is in use. */
	public static function package_slot( $packages ) {
		if ( ! self::method_configured() ) {
			return $packages;
		}
		$slot = Eru_SDD_Schedule::ships_today() ? Eru_SDD_Schedule::now()->format( 'Y-m-d' ) : 'closed';
		foreach ( $packages as $i => $p ) {
			$packages[ $i ]['eru_sdd_slot'] = $slot;
		}
		return $packages;
	}

	public static function enqueue(): void {
		if ( self::enabled() && ( is_product() || is_cart() ) ) {
			wp_enqueue_style( 'eru-sdd', ERU_SDD_URL . 'assets/sdd.css', array(), ERU_SDD_VERSION );
		}
	}

	public static function message_html(): string {
		$st = Eru_SDD_Schedule::status();
		if ( ! $st ) {
			return '';
		}
		return '<p class="eru-sdd-message eru-sdd-' . esc_attr( $st['type'] ) . '">' . esc_html( $st['text'] ) . '</p>';
	}

	public static function product_message(): void {
		global $product;
		if ( ! self::enabled() || ! $product instanceof WC_Product || $product->is_virtual() || $product->is_downloadable() ) {
			return;
		}
		if ( ! apply_filters( 'eru_sdd_show_for_product', true, $product ) ) {
			return;
		}
		echo self::message_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in message_html().
	}

	public static function cart_message(): void {
		if ( self::enabled() ) {
			echo self::message_html(); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function shortcode(): string {
		return self::enabled() ? self::message_html() : '';
	}
}
