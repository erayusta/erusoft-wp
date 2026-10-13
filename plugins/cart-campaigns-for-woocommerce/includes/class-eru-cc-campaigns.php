<?php
/** Cart-threshold campaigns: settings, discount fee and progress message. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CC_Campaigns {

	const OPTION = 'eru_cc_settings';
	const TD     = 'cart-campaigns-for-woocommerce';

	public static function init(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply' ), 10 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'print_cart' ) );
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( __CLASS__, 'print_mini' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function defaults(): array {
		return array(
			'tiers'         => array(),
			'progress_text' => __( '{amount} daha ekleyin, {reward} kazanın.', 'cart-campaigns-for-woocommerce' ),
			'success_text'  => __( 'Tebrikler! {reward} kazandınız.', 'cart-campaigns-for-woocommerce' ),
			'show_cart'     => 1,
			'show_mini'     => 1,
		);
	}

	public static function get(): array {
		$s = get_option( self::OPTION, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
		$s['tiers'] = is_array( $s['tiers'] ) ? array_values( $s['tiers'] ) : array();
		return $s;
	}

	/** @param mixed $raw Posted (unslashed) settings. */
	public static function sanitize( $raw ): array {
		$d   = self::defaults();
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'tiers'         => array(),
			'progress_text' => isset( $raw['progress_text'] ) && '' !== trim( (string) $raw['progress_text'] ) ? sanitize_text_field( (string) $raw['progress_text'] ) : $d['progress_text'],
			'success_text'  => isset( $raw['success_text'] ) && '' !== trim( (string) $raw['success_text'] ) ? sanitize_text_field( (string) $raw['success_text'] ) : $d['success_text'],
			'show_cart'     => ! empty( $raw['show_cart'] ) ? 1 : 0,
			'show_mini'     => ! empty( $raw['show_mini'] ) ? 1 : 0,
		);
		foreach ( is_array( $raw['tiers'] ?? null ) ? $raw['tiers'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$threshold = isset( $row['threshold'] ) ? (float) wc_format_decimal( (string) $row['threshold'] ) : 0.0;
			$value     = isset( $row['value'] ) ? (float) wc_format_decimal( (string) $row['value'] ) : 0.0;
			if ( $threshold <= 0 || $value <= 0 ) {
				continue;
			}
			$mode = isset( $row['mode'] ) && 'fixed' === $row['mode'] ? 'fixed' : 'percent';
			$out['tiers'][] = array(
				'threshold' => $threshold,
				'mode'      => $mode,
				'value'     => 'percent' === $mode ? min( 100.0, $value ) : $value,
				'label'     => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
			);
		}
		usort( $out['tiers'], fn( $a, $b ) => $a['threshold'] <=> $b['threshold'] );
		return $out;
	}

	/** Tiers sorted by threshold. */
	private static function tiers(): array {
		$tiers = self::get()['tiers'];
		usort( $tiers, fn( $a, $b ) => (float) $a['threshold'] <=> (float) $b['threshold'] );
		return $tiers;
	}

	/** @return array{0:?array,1:?array} Highest tier reached and next tier (either may be null). */
	public static function status( WC_Cart $cart ): array {
		$subtotal = (float) $cart->get_subtotal();
		$reached  = null;
		$next     = null;
		foreach ( self::tiers() as $tier ) {
			if ( $subtotal >= (float) $tier['threshold'] ) {
				$reached = $tier;
			} elseif ( null === $next ) {
				$next = $tier;
			}
		}
		return array( $reached, $next );
	}

	public static function reward_text( array $tier ): string {
		return 'percent' === $tier['mode']
			/* translators: %s: percentage */
			? sprintf( __( '%%%s indirim', 'cart-campaigns-for-woocommerce' ), wc_format_localized_decimal( (string) $tier['value'] ) )
			/* translators: %s: amount */
			: sprintf( __( '%s indirim', 'cart-campaigns-for-woocommerce' ), wp_strip_all_tags( wc_price( (float) $tier['value'] ) ) );
	}

	/**
	 * What is left to pay: cart contents minus discounts (negative fees) other plugins already added,
	 * so stacked discounts from several plugins never push the total below zero.
	 */
	private static function budget( WC_Cart $cart ): float {
		$left = (float) $cart->get_cart_contents_total();
		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( isset( $fee->amount ) && (float) $fee->amount < 0 ) {
				$left += (float) $fee->amount;
			}
		}
		return max( 0.0, $left );
	}

	public static function apply( $cart ): void {
		if ( ! $cart instanceof WC_Cart || $cart->is_empty() || ! self::tiers() ) {
			return;
		}
		/** Return false to skip cart campaigns (e.g. when another discount source should win). */
		if ( ! apply_filters( 'eru_cc_apply', true, $cart ) ) {
			return;
		}
		list( $tier ) = self::status( $cart );
		if ( ! $tier ) {
			return;
		}
		$base   = (float) $cart->get_cart_contents_total();
		$amount = 'percent' === $tier['mode'] ? $base * (float) $tier['value'] / 100 : min( (float) $tier['value'], $base );
		$amount = round( min( $amount, self::budget( $cart ) ), wc_get_price_decimals() );
		/** Filters the campaign discount (positive). Return 0 to skip. */
		$amount = (float) apply_filters( 'eru_cc_discount_amount', $amount, $tier, $cart );
		if ( $amount > 0 ) {
			$label = '' !== $tier['label'] ? $tier['label'] : __( 'Sepet kampanyası', 'cart-campaigns-for-woocommerce' );
			$cart->add_fee( $label, -$amount, false );
		}
	}

	/** Progress / success message (HTML) for the cart, or '' when nothing applies. */
	public static function message( WC_Cart $cart ): string {
		if ( ! self::tiers() ) {
			return '';
		}
		$s = self::get();
		list( $reached, $next ) = self::status( $cart );
		if ( $next ) {
			$missing  = max( 0.0, (float) $next['threshold'] - (float) $cart->get_subtotal() );
			$text     = str_replace( array( '{amount}', '{reward}' ), array( wc_price( $missing ), esc_html( self::reward_text( $next ) ) ), esc_html( $s['progress_text'] ) );
			$progress = min( 100, (int) floor( (float) $cart->get_subtotal() / (float) $next['threshold'] * 100 ) );
		} elseif ( $reached ) {
			$text     = str_replace( '{reward}', esc_html( self::reward_text( $reached ) ), esc_html( $s['success_text'] ) );
			$progress = 100;
		} else {
			return '';
		}
		return '<div class="eru-cc-progress" role="status"><p class="eru-cc-text">' . wp_kses_post( $text ) . '</p><div class="eru-cc-bar"><span style="width:' . (int) $progress . '%"></span></div></div>';
	}

	public static function print_cart(): void {
		if ( self::get()['show_cart'] && WC()->cart && ! WC()->cart->is_empty() ) {
			echo self::message( WC()->cart ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in message().
		}
	}

	public static function print_mini(): void {
		if ( self::get()['show_mini'] && WC()->cart && ! WC()->cart->is_empty() ) {
			echo self::message( WC()->cart ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in message().
		}
	}

	public static function enqueue(): void {
		if ( self::tiers() ) {
			wp_enqueue_style( 'eru-cc', ERU_CC_URL . 'assets/style.css', array(), ERU_CC_VERSION );
		}
	}
}
