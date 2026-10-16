<?php
/**
 * Installment counts, the commission fee line, the installment plan and money in minor units.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowed counts are 1 (single payment) plus every enabled rate up to the configured maximum; anything else
 * normalises to 0 (invalid). The selected count lives in the WooCommerce session ("eru_vnv_installments"),
 * written from the checkout's order-review refresh, the place-order request and the verify call. When Vinov is
 * the chosen method and the count has a positive rate, a non-taxable fee "Vinov taksit farkı (N taksit, %x)"
 * is added in woocommerce_cart_calculate_fees, so the order total — and therefore the instruction amount —
 * includes the commission. The fee base is the discounted items plus shipping, taxes included.
 */
class Eru_VNV_Installments {

	const SESSION = 'eru_vnv_installments';

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_fee' ), 20 );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_from_review' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'remember_from_request' ) );
	}

	/** @return int[] Allowed counts (1 first). */
	public static function allowed() {
		return array_merge( array( 1 ), array_keys( Eru_VNV_Settings::rates() ) );
	}

	/** @param mixed $n Requested count. @return int The count when allowed, 0 otherwise. */
	public static function normalize( $n ) {
		if ( ! is_scalar( $n ) || ! preg_match( '/^\d{1,2}$/', trim( (string) $n ) ) ) {
			return 0;
		}
		$n = (int) $n;
		return in_array( $n, self::allowed(), true ) ? $n : 0;
	}

	/** @param int $n Count. @return float Commission rate in percent (0 for a single payment). */
	public static function rate( $n ) {
		$rates = Eru_VNV_Settings::rates();
		return $n > 1 && isset( $rates[ $n ] ) ? (float) $rates[ $n ] : 0.0;
	}

	/** @return int Decimals used for minor units. */
	private static function decimals() {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/** @param mixed $amount Decimal amount. @return int Minor units. */
	public static function to_minor( $amount ) {
		return (int) round( (float) $amount * ( 10 ** self::decimals() ) );
	}

	/** @param int $minor Minor units. @return float Decimal amount. */
	public static function from_minor( $minor ) {
		$d = self::decimals();
		return round( (int) $minor / ( 10 ** $d ), $d );
	}

	/** @param int $base_minor Base. @param int $n Count. @return int Fee in minor units (rounded once). */
	public static function fee_minor( $base_minor, $n ) {
		$rate = self::rate( $n );
		return $rate > 0 ? (int) round( $base_minor * $rate / 100 ) : 0;
	}

	/** @param int $n Count. @return string "Vinov taksit farkı (3 taksit, %4)". */
	public static function label( $n ) {
		$r = rtrim( rtrim( number_format( self::rate( $n ), 2, ',', '' ), '0' ), ',' );
		/* translators: 1: installment count, 2: commission rate */
		return sprintf( __( 'Vinov taksit farkı (%1$d taksit, %%%2$s)', 'vakifbank-vinov-integration-for-woocommerce' ), $n, $r );
	}

	/**
	 * Equal parts in minor units whose sum is exactly the total (the remainder goes to the last parts), with
	 * monthly maturities starting one month from now.
	 *
	 * @param int                     $total_minor Total.
	 * @param int                     $n           Count (≥ 2).
	 * @param DateTimeImmutable|null $from        Start (default: now, Europe/Istanbul).
	 * @return array<int, array{amount_minor:int, maturity:string}>
	 */
	public static function plan( $total_minor, $n, $from = null ) {
		$n    = max( 1, (int) $n );
		$base = intdiv( (int) $total_minor, $n );
		$rem  = (int) $total_minor - $base * $n;
		$out  = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$out[] = array(
				'amount_minor' => $base + ( $i > $n - $rem ? 1 : 0 ),
				'maturity'     => self::maturity( $i, $from ),
			);
		}
		return $out;
	}

	/**
	 * Midnight on the same day $i months later, clamped to the last day of a shorter month (31 Jan + 1 month →
	 * 28/29 Feb, not 3 Mar).
	 *
	 * @param int                     $i    Months ahead.
	 * @param DateTimeImmutable|null $from Start (default: now).
	 * @return string Y-m-d\TH:i:sP in Europe/Istanbul.
	 */
	public static function maturity( $i, $from = null ) {
		$tz    = new DateTimeZone( Eru_VNV_API_Client::TIMEZONE );
		$from  = $from instanceof DateTimeImmutable ? $from->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
		$m     = (int) $from->format( 'n' ) - 1 + (int) $i;
		$y     = (int) $from->format( 'Y' ) + intdiv( $m, 12 );
		$m     = $m % 12 + 1;
		$first = $from->setDate( $y, $m, 1 )->setTime( 0, 0, 0 );
		$day   = min( (int) $from->format( 'j' ), (int) $first->format( 't' ) );
		return $first->setDate( $y, $m, $day )->format( 'Y-m-d\TH:i:sP' );
	}

	/** @return int Count selected in the session (normalised; 1 when unset or invalid). */
	public static function selected() {
		$n = function_exists( 'WC' ) && WC()->session ? self::normalize( WC()->session->get( self::SESSION ) ) : 0;
		return $n > 0 ? $n : 1;
	}

	/** @param mixed $n Requested count; stored only when allowed (else 1). */
	public static function remember( $n ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$n = self::normalize( $n );
			WC()->session->set( self::SESSION, $n > 0 ? $n : 1 );
		}
	}

	/** @param string $post_data Serialised checkout form (order-review refresh). */
	public static function remember_from_review( $post_data ) {
		if ( ! Eru_VNV_Settings::active_flag() ) {
			return;
		}
		$data = array();
		parse_str( (string) $post_data, $data );
		if ( isset( $data['eru_vnv_installments'] ) ) {
			self::remember( wp_unslash( $data['eru_vnv_installments'] ) );
		}
	}

	/** Place-order request: the posted count drives the fee of the order being created. */
	public static function remember_from_request() {
		if ( ! Eru_VNV_Settings::active_flag() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		if ( isset( $_POST['payment_method'], $_POST['eru_vnv_installments'] ) && Eru_VNV_Settings::GATEWAY_ID === $_POST['payment_method'] ) {
			self::remember( sanitize_text_field( wp_unslash( $_POST['eru_vnv_installments'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
	}

	/** @param WC_Cart $cart Cart. @return int Fee base in minor units (discounted items + shipping, with taxes). */
	public static function base_minor( $cart ) {
		return self::to_minor( (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax() + (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax() );
	}

	/**
	 * Adds the commission fee when Vinov is chosen with a count that has a positive rate.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function add_fee( $cart ) {
		if ( ! Eru_VNV_Settings::active_flag() || ! $cart instanceof WC_Cart || ! WC()->session ) {
			return;
		}
		if ( Eru_VNV_Settings::GATEWAY_ID !== WC()->session->get( 'chosen_payment_method' ) ) {
			return;
		}
		$n   = self::selected();
		$fee = self::fee_minor( self::base_minor( $cart ), $n );
		if ( $fee > 0 ) {
			$cart->fees_api()->add_fee(
				array(
					'id'       => 'eru-vnv-installment-fee',
					'name'     => self::label( $n ),
					'amount'   => self::from_minor( $fee ),
					'taxable'  => false,
					'tax_class' => '',
				)
			);
		}
	}
}
