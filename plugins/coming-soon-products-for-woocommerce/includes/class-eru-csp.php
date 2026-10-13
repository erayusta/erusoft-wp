<?php
/**
 * Coming-soon behaviour.
 *
 * @package coming-soon-products-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CSP {

	const OPTION  = 'eru_csp_settings';
	const ENABLED = '_eru_csp_enabled';
	const MESSAGE = '_eru_csp_message';
	const DATE    = '_eru_csp_date';

	public static function init() {
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'purchasable' ), 20, 2 );
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 100, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 10, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'summary_start' ), 1 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'notice' ), 25 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'summary_end' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * @return array{message:string,badge:string,hide_price:string,show_date:string}
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'message'    => isset( $s['message'] ) && '' !== trim( (string) $s['message'] ) ? (string) $s['message'] : __( 'Bu ürün yakında satışa çıkacak.', 'coming-soon-products-for-woocommerce' ),
			'badge'      => isset( $s['badge'] ) && '' !== trim( (string) $s['badge'] ) ? (string) $s['badge'] : __( 'Yakında', 'coming-soon-products-for-woocommerce' ),
			'hide_price' => ( isset( $s['hide_price'] ) && 'no' === $s['hide_price'] ) ? 'no' : 'yes',
			'show_date'  => ( isset( $s['show_date'] ) && 'no' === $s['show_date'] ) ? 'no' : 'yes',
		);
	}

	private static function root_id( $product ) {
		return $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
	}

	/**
	 * Is coming-soon mode on for this product right now? Ends by itself once the release date is reached.
	 *
	 * @param WC_Product|int $product Product or ID.
	 */
	public static function is_active( $product ) {
		$product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		$id = self::root_id( $product );
		if ( ! $id || 'yes' !== get_post_meta( $id, self::ENABLED, true ) ) {
			return false;
		}
		$date = (string) get_post_meta( $id, self::DATE, true );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date <= wp_date( 'Y-m-d' ) ) {
			return false;
		}
		return true;
	}

	public static function message_for( $product ) {
		$id  = self::root_id( $product );
		$msg = trim( (string) get_post_meta( $id, self::MESSAGE, true ) );
		return '' !== $msg ? $msg : self::settings()['message'];
	}

	public static function release_date_for( $product ) {
		$date = (string) get_post_meta( self::root_id( $product ), self::DATE, true );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	public static function purchasable( $ok, $product ) {
		return ( $ok && self::is_active( $product ) ) ? false : $ok;
	}

	public static function price_html( $html, $product ) {
		if ( 'yes' !== self::settings()['hide_price'] || ! self::is_active( $product ) ) {
			return $html;
		}
		return '<span class="eru-csp-badge">' . esc_html( self::settings()['badge'] ) . '</span>';
	}

	public static function loop_button( $link, $product ) {
		return self::is_active( $product ) ? '' : $link;
	}

	/** @var bool */
	private static $removed = false;

	public static function summary_start() {
		global $product;
		if ( $product instanceof WC_Product && self::is_active( $product ) ) {
			// Variable products would otherwise print an "unavailable" stock line.
			self::$removed = (bool) remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		}
	}

	public static function summary_end() {
		if ( self::$removed ) {
			add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
			self::$removed = false;
		}
	}

	public static function notice() {
		global $product;
		if ( ! $product instanceof WC_Product || ! self::is_active( $product ) ) {
			return;
		}
		$text = self::message_for( $product );
		$date = self::release_date_for( $product );
		echo '<div class="eru-csp-notice"><p class="eru-csp-message">' . esc_html( $text ) . '</p>';
		if ( $date && 'yes' === self::settings()['show_date'] ) {
			echo '<p class="eru-csp-date">' . esc_html(
				sprintf(
					/* translators: %s: release date */
					__( 'Tahmini çıkış: %s', 'coming-soon-products-for-woocommerce' ),
					date_i18n( get_option( 'date_format' ), strtotime( $date . ' 12:00:00' ) )
				)
			) . '</p>';
		}
		echo '</div>';
	}

	public static function assets() {
		if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_front_page() || is_search() ) ) {
			wp_enqueue_style( 'eru-csp', ERU_CSP_URL . 'assets/front.css', array(), ERU_CSP_VERSION );
		}
	}
}
