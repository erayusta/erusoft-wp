<?php
/**
 * Catalog mode and WhatsApp order button.
 *
 * @package catalog-mode-whatsapp-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CMW {

	const OPTION = 'eru_cmw_settings';
	const TD     = 'catalog-mode-whatsapp-orders-for-woocommerce';

	public static function defaults() {
		return array(
			'number'       => '',
			'hide_price'   => 0,
			'hide_cart'    => 0,
			'show_single'  => 1,
			'show_loop'    => 0,
			'button_text'  => __( 'WhatsApp ile sipariş ver', 'catalog-mode-whatsapp-orders-for-woocommerce' ),
			'message'      => __( 'Merhaba, {product} ürünü hakkında sipariş vermek istiyorum. {url}', 'catalog-mode-whatsapp-orders-for-woocommerce' ),
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Digits only, international format without "+" (8-15 digits). "00" prefix is dropped; a
	 * Turkish national number 0XXXXXXXXXX (11 digits) or a bare mobile 5XXXXXXXXX (10 digits) becomes 90…. Anything else: ''.
	 */
	public static function normalize_number( $raw ) {
		$d = preg_replace( '/\D+/', '', (string) $raw );
		$d = is_string( $d ) ? $d : '';
		if ( str_starts_with( $d, '00' ) ) {
			$d = substr( $d, 2 );
		} elseif ( 11 === strlen( $d ) && str_starts_with( $d, '0' ) ) {
			$d = '90' . substr( $d, 1 );
		} elseif ( 10 === strlen( $d ) && str_starts_with( $d, '5' ) ) {
			$d = '90' . $d;
		}
		return strlen( $d ) >= 8 && strlen( $d ) <= 15 && '0' !== $d[0] ? $d : '';
	}

	public static function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$d   = self::defaults();
		$raw = isset( $in['number'] ) ? trim( (string) $in['number'] ) : '';
		$num = self::normalize_number( $raw );
		if ( '' !== $raw && '' === $num && function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'eru_cmw', 'eru_cmw_number', __( 'WhatsApp numarası geçersiz: ülke koduyla birlikte yazın (örn. 905550000000). Numara boş bırakıldı, düğme gösterilmez.', 'catalog-mode-whatsapp-orders-for-woocommerce' ), 'error' );
		} elseif ( '' !== $num && preg_replace( '/\D+/', '', $raw ) !== $num && function_exists( 'add_settings_error' ) ) {
			/* translators: %s: normalised number */
			add_settings_error( 'eru_cmw', 'eru_cmw_number_conv', sprintf( __( 'Numara uluslararası biçime çevrildi: %s', 'catalog-mode-whatsapp-orders-for-woocommerce' ), $num ), 'info' );
		}
		$text = isset( $in['button_text'] ) ? sanitize_text_field( (string) $in['button_text'] ) : '';
		$msg  = isset( $in['message'] ) ? sanitize_textarea_field( (string) $in['message'] ) : '';
		return array(
			'number'      => $num,
			'hide_price'  => empty( $in['hide_price'] ) ? 0 : 1,
			'hide_cart'   => empty( $in['hide_cart'] ) ? 0 : 1,
			'show_single' => empty( $in['show_single'] ) ? 0 : 1,
			'show_loop'   => empty( $in['show_loop'] ) ? 0 : 1,
			'button_text' => '' === $text ? $d['button_text'] : $text,
			'message'     => '' === $msg ? $d['message'] : substr( $msg, 0, 1000 ),
		);
	}

	public static function init() {
		$s = self::get_settings();
		if ( $s['hide_price'] ) {
			add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'hide_price' ), 99 );
			add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'hide_price' ), 99 );
		}
		if ( $s['hide_cart'] ) {
			add_filter( 'woocommerce_is_purchasable', '__return_false', 99 );
			if ( did_action( 'init' ) ) {
				self::remove_cart_actions();
			} else {
				add_action( 'init', array( __CLASS__, 'remove_cart_actions' ), 20 );
			}
		}
		if ( '' !== $s['number'] ) {
			if ( $s['show_single'] ) {
				add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'button' ), 35 );
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
			}
			if ( $s['show_loop'] ) {
				add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'button' ), 11 );
				add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
			}
		}
	}

	/** Empty price HTML on the storefront; the admin screens (not AJAX) keep their prices. */
	public static function hide_price( $html ) {
		return is_admin() && ! wp_doing_ajax() ? $html : '';
	}

	public static function remove_cart_actions() {
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}

	public static function assets() {
		if ( ! function_exists( 'is_woocommerce' ) || ! is_woocommerce() ) {
			return;
		}
		wp_enqueue_style( 'eru-cmw', ERU_CMW_URL . 'assets/css/button.css', array(), ERU_CMW_VERSION );
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_script( 'eru-cmw', ERU_CMW_URL . 'assets/js/button.js', array(), ERU_CMW_VERSION, true );
		}
	}

	/** Message text with {product}, {url}, {sku}, {price} replaced (price only when prices are visible). */
	public static function message( $product, $s = null ) {
		$s     = $s ? $s : self::get_settings();
		$price = $s['hide_price'] ? '' : wp_strip_all_tags( html_entity_decode( wc_price( (float) $product->get_price() ), ENT_QUOTES, 'UTF-8' ) );
		$msg   = strtr(
			$s['message'],
			array(
				'{product}' => wp_strip_all_tags( $product->get_name() ),
				'{url}'     => $product->get_permalink(),
				'{sku}'     => (string) $product->get_sku(),
				'{price}'   => $price,
			)
		);
		return apply_filters( 'eru_cmw_message', trim( $msg ), $product );
	}

	public static function link( $product, $s = null ) {
		$s = $s ? $s : self::get_settings();
		return 'https://wa.me/' . $s['number'] . '?text=' . rawurlencode( self::message( $product, $s ) );
	}

	public static function button() {
		global $product;
		$s = self::get_settings();
		if ( ! $product instanceof WC_Product || '' === $s['number'] ) {
			return;
		}
		printf(
			'<a class="eru-cmw-button" href="%1$s" target="_blank" rel="noopener noreferrer" data-eru-cmw-base="%2$s">%3$s</a>',
			esc_url( self::link( $product, $s ) ),
			esc_attr( 'https://wa.me/' . $s['number'] . '?text=' ),
			esc_html( $s['button_text'] )
		);
	}
}
