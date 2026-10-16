<?php
/**
 * Integer money helpers.
 *
 * The engine keeps every amount as an int in the currency's minor unit (kuruş for TRY). Floats never
 * enter a calculation: decimal input is parsed as text and percentages are turned into basis points.
 * The number of decimals is passed in by the caller (the store's price decimals), never guessed.
 *
 * Rounding rules, in one place:
 *  - parsing (`of`) rounds half up past the currency's decimals;
 *  - a percentage of an amount (`percent`) rounds UP to the next minor unit, i.e. in the customer's
 *    favour, so "15 %" is never shown as 15 % and charged as 14.99 %;
 *  - splitting an amount (`distribute`) never rounds at all: the parts always add up to the whole.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

final class Money {

	/**
	 * Converts a decimal amount to minor units.
	 *
	 * Accepts "1234.5", "1234,5", ints and floats. A string with more than one separator, or anything
	 * that is not a number, gives 0 — the caller validates input; the engine never guesses whether
	 * "1.234" meant one thousand or one point two.
	 *
	 * @param string|int|float $amount   Decimal amount.
	 * @param int              $decimals Currency decimals (2 for TRY).
	 */
	public static function of( $amount, int $decimals = 2 ): int {
		$decimals = max( 0, $decimals );

		if ( is_int( $amount ) ) {
			return $amount * ( 10 ** $decimals );
		}

		if ( is_float( $amount ) ) {
			// Format with enough digits first so binary noise (19.99 → 19.98999…) cannot leak in.
			$amount = number_format( $amount, $decimals + 2, '.', '' );
		}

		if ( ! is_string( $amount ) ) {
			return 0;
		}

		$text = str_replace( ',', '.', trim( $amount ) );

		if ( 1 !== preg_match( '/^(-)?(\d*)(?:\.(\d*))?$/', $text, $m ) || ( '' === $m[2] && '' === ( $m[3] ?? '' ) ) ) {
			return 0;
		}

		$negative = '-' === $m[1];
		$whole    = '' === $m[2] ? '0' : $m[2];
		$fraction = $m[3] ?? '';
		$kept     = substr( str_pad( $fraction, $decimals, '0' ), 0, $decimals );
		$next     = strlen( $fraction ) > $decimals ? (int) $fraction[ $decimals ] : 0;
		$minor    = (int) $whole * ( 10 ** $decimals ) + ( '' === $kept ? 0 : (int) $kept );

		if ( $next >= 5 ) {
			++$minor;
		}

		return $negative ? -$minor : $minor;
	}

	/** Formats minor units as a plain decimal string ("1234.50"), for storage and for WooCommerce. */
	public static function toDecimal( int $minor, int $decimals = 2 ): string {
		$decimals = max( 0, $decimals );
		$sign     = $minor < 0 ? '-' : '';
		$abs      = abs( $minor );

		if ( 0 === $decimals ) {
			return $sign . $abs;
		}

		$base = 10 ** $decimals;

		return $sign . intdiv( $abs, $base ) . '.' . str_pad( (string) ( $abs % $base ), $decimals, '0', STR_PAD_LEFT );
	}

	/**
	 * Percentage of an amount, rounded up to the next minor unit.
	 *
	 * The percentage may carry at most two decimals ("12.5", "12,25"); further digits are ignored.
	 * Zero, negative or unreadable percentages give 0.
	 *
	 * @param string|int|float $percent
	 */
	public static function percent( int $minor, $percent ): int {
		if ( $minor <= 0 ) {
			return 0;
		}

		$basis_points = self::basisPoints( $percent );

		if ( $basis_points <= 0 ) {
			return 0;
		}

		return intdiv( $minor * $basis_points + 9999, 10000 );
	}

	/**
	 * Percentage of an amount, rounded DOWN. Used for caps, where rounding up would let the cap be
	 * exceeded by a minor unit.
	 *
	 * @param string|int|float $percent
	 */
	public static function percentFloor( int $minor, $percent ): int {
		if ( $minor <= 0 ) {
			return 0;
		}

		$basis_points = self::basisPoints( $percent );

		return $basis_points <= 0 ? 0 : intdiv( $minor * $basis_points, 10000 );
	}

	/**
	 * A percentage as basis points (hundredths of a percent): "12.5" → 1250. Unreadable → 0.
	 *
	 * @param string|int|float $percent
	 */
	public static function basisPoints( $percent ): int {
		if ( is_int( $percent ) ) {
			return $percent * 100;
		}

		if ( is_float( $percent ) ) {
			$percent = number_format( $percent, 2, '.', '' );
		}

		if ( ! is_string( $percent ) ) {
			return 0;
		}

		$text = str_replace( ',', '.', trim( $percent ) );

		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d*))?$/', $text, $m ) ) {
			return 0;
		}

		$fraction = substr( str_pad( $m[2] ?? '', 2, '0' ), 0, 2 );

		return (int) $m[1] * 100 + (int) $fraction;
	}

	/**
	 * Splits a total over weighted keys with the largest-remainder method.
	 *
	 * The parts always add up to the total exactly. Every key gets the floor of its exact share; the
	 * minor units left over go one by one to the keys with the largest remainders. Ties go to the larger
	 * weight, then to the smaller key (compared as strings), so the result never depends on the order
	 * of the input. Negative weights count as zero. When every weight is zero, every part is zero and
	 * nothing is handed out (no key can claim it).
	 *
	 * @param array<string|int,int> $weights
	 * @return array<string|int,int> Same keys, same order as $weights.
	 * @throws \InvalidArgumentException When the total is negative.
	 */
	public static function distribute( int $total, array $weights ): array {
		if ( $total < 0 ) {
			throw new \InvalidArgumentException( 'Money::distribute needs a total of zero or more.' );
		}

		$clean = array();
		$sum   = 0;

		foreach ( $weights as $key => $weight ) {
			$weight        = max( 0, (int) $weight );
			$clean[ $key ] = $weight;
			$sum          += $weight;
		}

		if ( $sum <= 0 ) {
			return array_map( static fn(): int => 0, $clean );
		}

		// Keep total × weight inside a 64-bit int. Only reachable with absurd figures; halving every
		// weight changes the proportions by far less than a minor unit and the total stays exact.
		while ( $total > 0 && $sum > intdiv( PHP_INT_MAX, $total ) ) {
			$sum = 0;

			foreach ( $clean as $key => $weight ) {
				$clean[ $key ] = intdiv( $weight, 2 );
				$sum          += $clean[ $key ];
			}
		}

		if ( $sum <= 0 ) {
			return array_map( static fn(): int => 0, $clean );
		}

		$parts     = array();
		$remainders = array();
		$assigned   = 0;

		foreach ( $clean as $key => $weight ) {
			$product            = $total * $weight;
			$parts[ $key ]      = intdiv( $product, $sum );
			$remainders[ $key ] = $product % $sum;
			$assigned          += $parts[ $key ];
		}

		$left  = $total - $assigned;
		$order = array_keys( $clean );

		usort(
			$order,
			static function ( $a, $b ) use ( $remainders, $clean ): int {
				if ( $remainders[ $a ] !== $remainders[ $b ] ) {
					return $remainders[ $b ] <=> $remainders[ $a ];
				}

				if ( $clean[ $a ] !== $clean[ $b ] ) {
					return $clean[ $b ] <=> $clean[ $a ];
				}

				return strcmp( (string) $a, (string) $b );
			}
		);

		foreach ( $order as $key ) {
			if ( $left <= 0 ) {
				break;
			}

			++$parts[ $key ];
			--$left;
		}

		return $parts;
	}
}
