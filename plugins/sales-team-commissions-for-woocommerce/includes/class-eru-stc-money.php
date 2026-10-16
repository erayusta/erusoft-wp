<?php
/**
 * Money in minor units (kuruş). Every calculation works on integers; decimals are parsed from strings,
 * rounded half away from zero once, and allocations use the largest remainder so the parts always sum to
 * the whole.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Money {

	/** @return int Store decimals (WooCommerce price decimals). */
	public static function decimals() {
		return function_exists( 'wc_get_price_decimals' ) ? max( 0, (int) wc_get_price_decimals() ) : 2;
	}

	/**
	 * Decimal (string, int or float) → minor units, half away from zero.
	 *
	 * @param mixed    $amount   Amount.
	 * @param int|null $decimals Decimals (store default).
	 * @return int
	 */
	public static function to_minor( $amount, $decimals = null ) {
		$d = null === $decimals ? self::decimals() : (int) $decimals;
		if ( is_float( $amount ) ) {
			$amount = sprintf( '%.8F', $amount );
		}
		$s = str_replace( ',', '.', trim( (string) $amount ) );
		if ( 1 !== preg_match( '/^([+-])?(\d*)(?:\.(\d*))?$/', $s, $m ) || ( '' === ( $m[2] ?? '' ) && '' === ( $m[3] ?? '' ) ) ) {
			return 0;
		}
		$neg   = '-' === $m[1];
		$whole = '' === $m[2] ? '0' : $m[2];
		$frac  = str_pad( $m[3] ?? '', $d + 1, '0' );
		$minor = (int) $whole * ( 10 ** $d ) + ( $d > 0 ? (int) substr( $frac, 0, $d ) : 0 );
		if ( (int) $frac[ $d ] >= 5 ) {
			++$minor;
		}
		return $neg ? -$minor : $minor;
	}

	/**
	 * Minor units → decimal string with the store decimals ("-0.07").
	 *
	 * @param int      $minor    Minor units.
	 * @param int|null $decimals Decimals (store default).
	 * @return string
	 */
	public static function from_minor( $minor, $decimals = null ) {
		$d     = null === $decimals ? self::decimals() : (int) $decimals;
		$minor = (int) $minor;
		$abs   = abs( $minor );
		$f     = 10 ** $d;
		$s     = (string) intdiv( $abs, $f );
		if ( $d > 0 ) {
			$s .= '.' . str_pad( (string) ( $abs % $f ), $d, '0', STR_PAD_LEFT );
		}
		return ( $minor < 0 ? '-' : '' ) . $s;
	}

	/**
	 * Integer division rounded half away from zero.
	 *
	 * @param int $num Numerator.
	 * @param int $den Denominator (> 0).
	 * @return int
	 */
	public static function div_round( $num, $den ) {
		$q = intdiv( abs( $num ) * 2 + $den, $den * 2 );
		return $num < 0 ? -$q : $q;
	}

	/**
	 * Splits $total (≥ 0) over non-negative weights by the largest remainder; ties go to the earlier
	 * entry. All weights zero → all parts zero.
	 *
	 * @param int   $total   Minor units to split.
	 * @param int[] $weights Weights.
	 * @return int[] Parts, same keys as $weights, summing to $total (unless every weight is zero).
	 */
	public static function allocate( $total, array $weights ) {
		$sum   = array_sum( array_map( 'intval', $weights ) );
		$parts = array();
		$rems  = array();
		foreach ( $weights as $k => $w ) {
			$parts[ $k ] = 0;
		}
		if ( $sum <= 0 || $total <= 0 ) {
			return $parts;
		}
		$given = 0;
		foreach ( $weights as $k => $w ) {
			$exact       = $total * (int) $w;
			$parts[ $k ] = intdiv( $exact, $sum );
			$rems[ $k ]  = $exact % $sum;
			$given      += $parts[ $k ];
		}
		$order = array_keys( $rems );
		usort(
			$order,
			function ( $a, $b ) use ( $rems, $order ) {
				return $rems[ $b ] <=> $rems[ $a ] ?: array_search( $a, $order, true ) <=> array_search( $b, $order, true );
			}
		);
		for ( $i = 0, $left = $total - $given; $left > 0; $i++, $left-- ) {
			++$parts[ $order[ $i ] ];
		}
		return $parts;
	}
}
