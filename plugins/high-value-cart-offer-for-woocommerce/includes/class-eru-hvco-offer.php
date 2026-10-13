<?php
/** High-value cart offer: settings, WhatsApp link and box output. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HVCO_Offer {

	const OPTION = 'eru_hvco_settings';
	const TD     = 'high-value-cart-offer-for-woocommerce';

	public static function init(): void {
		add_action( 'woocommerce_after_cart_totals', array( __CLASS__, 'print_cart' ) );
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( __CLASS__, 'print_mini' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function defaults(): array {
		return array(
			'number'    => '',
			'threshold' => 0.0,
			'title'     => __( 'Bu sepete özel teklif alın', 'high-value-cart-offer-for-woocommerce' ),
			'message'   => __( 'Sepetiniz için size özel bir teklif hazırlayalım.', 'high-value-cart-offer-for-woocommerce' ),
			'button'    => __( 'WhatsApp ile teklif iste', 'high-value-cart-offer-for-woocommerce' ),
			'intro'     => __( 'Merhaba, sepetim için teklif almak istiyorum:', 'high-value-cart-offer-for-woocommerce' ),
			'show_cart' => 1,
			'show_mini' => 0,
		);
	}

	public static function get(): array {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	/**
	 * International digits for wa.me (same rules as Catalog Mode & WhatsApp Orders): formatting and a
	 * "00" prefix are dropped, Turkish national 0XXXXXXXXXX and bare 5XXXXXXXXX mobiles get "90".
	 * 8-15 digits not starting with 0; anything else becomes ''.
	 */
	public static function clean_number( $number ): string {
		$d = preg_replace( '/\D+/', '', (string) $number );
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

	/** @param mixed $raw Posted (unslashed) settings. */
	public static function sanitize( $raw ): array {
		$d   = self::defaults();
		$raw = is_array( $raw ) ? $raw : array();
		$txt = function ( $key ) use ( $raw, $d ) {
			return isset( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : $d[ $key ];
		};
		return array(
			'number'    => self::clean_number( $raw['number'] ?? '' ),
			'threshold' => isset( $raw['threshold'] ) ? max( 0.0, (float) wc_format_decimal( (string) $raw['threshold'] ) ) : 0.0,
			'title'     => $txt( 'title' ),
			'message'   => $txt( 'message' ),
			'button'    => $txt( 'button' ),
			'intro'     => $txt( 'intro' ),
			'show_cart' => ! empty( $raw['show_cart'] ) ? 1 : 0,
			'show_mini' => ! empty( $raw['show_mini'] ) ? 1 : 0,
		);
	}

	/** Value compared with the threshold: cart contents after discounts, including tax, without shipping. */
	public static function cart_value( WC_Cart $cart ): float {
		$value = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax();
		/** Filters the cart value used for the threshold check. */
		return (float) apply_filters( 'eru_hvco_cart_value', $value, $cart );
	}

	/** Plain-text cart summary sent as the WhatsApp message. */
	public static function summary( WC_Cart $cart, array $s ): string {
		$lines = array( $s['intro'] );
		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( $product instanceof WC_Product ) {
				$lines[] = '- ' . wp_strip_all_tags( $product->get_name() ) . ' x ' . (int) $item['quantity'];
			}
		}
		$lines[] = __( 'Sepet tutarı:', 'high-value-cart-offer-for-woocommerce' ) . ' ' . html_entity_decode( wp_strip_all_tags( wc_price( self::cart_value( $cart ) ) ), ENT_QUOTES, 'UTF-8' );
		return implode( "\n", $lines );
	}

	public static function link( WC_Cart $cart, array $s ): string {
		return 'https://wa.me/' . self::clean_number( $s['number'] ) . '?text=' . rawurlencode( self::summary( $cart, $s ) );
	}

	/** Offer box HTML, or '' when disabled (no number), the cart is empty or below the threshold. */
	public static function box( WC_Cart $cart ): string {
		$s = self::get();
		if ( '' === self::clean_number( $s['number'] ) || $cart->is_empty() ) {
			return '';
		}
		if ( self::cart_value( $cart ) < (float) $s['threshold'] ) {
			return '';
		}
		$href = self::link( $cart, $s );
		return '<div class="eru-hvco-box"><strong class="eru-hvco-title">' . esc_html( $s['title'] ) . '</strong><p class="eru-hvco-message">' . esc_html( $s['message'] ) . '</p>'
			. '<a class="button alt eru-hvco-button" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $s['button'] ) . '</a></div>';
	}

	public static function print_cart(): void {
		if ( self::get()['show_cart'] && WC()->cart ) {
			echo self::box( WC()->cart ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in box().
		}
	}

	public static function print_mini(): void {
		if ( self::get()['show_mini'] && WC()->cart ) {
			echo self::box( WC()->cart ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in box().
		}
	}

	public static function enqueue(): void {
		if ( '' !== self::clean_number( self::get()['number'] ) ) {
			wp_enqueue_style( 'eru-hvco', ERU_HVCO_URL . 'assets/style.css', array(), ERU_HVCO_VERSION );
		}
	}
}
