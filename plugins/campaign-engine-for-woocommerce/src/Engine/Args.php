<?php
/**
 * Strict readers for campaign, action and settings arguments.
 *
 * Rows come from an admin form, JSON or stored options, so a value may arrive as a string, an int or
 * a float. Money-deciding values go through these readers. A value that is present but unusable — or
 * required and missing — throws ArgumentError with the field name and a reason; it never becomes a
 * cast or a silent blank. PHP casts turn "7,5" into 7 and "25x" into 25, and a blank cap reads as
 * "no cap": both would run a different campaign from the one the shop manager typed in.
 *
 * Accepted forms:
 *  - percent      int, float or decimal string ("12,5" or "12.5"), at most two decimals, 0 < p < 100;
 *                 returned as a canonical string ("12.5", "20");
 *  - minor units  int, integral float (10000.0) or digit string with an optional all-zero fraction
 *                 ("10000.00"); commas are refused because "75,00" is ambiguous for a minor-unit field;
 *  - ids          the same integral forms, each above zero.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

final class Args {

	/**
	 * A discount percentage, canonical ("12.5"). Throws on missing, malformed, more than two
	 * decimals or outside 0 < p < 100 (100 % is the "free" reward, not a percentage).
	 *
	 * @param mixed $raw
	 * @throws ArgumentError
	 */
	public static function percent( $raw, string $field = 'percent' ): string {
		if ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
			throw new ArgumentError( $field, 'missing' );
		}

		if ( is_int( $raw ) ) {
			$basis_points = $raw * 100;
		} elseif ( is_float( $raw ) ) {
			if ( ! is_finite( $raw ) ) {
				throw new ArgumentError( $field, 'invalid' );
			}
			$basis_points = (int) round( $raw * 100 );
			if ( abs( $raw * 100 - $basis_points ) > 1e-6 ) {
				throw new ArgumentError( $field, 'too_precise' );
			}
		} elseif ( is_string( $raw ) ) {
			$text = str_replace( ',', '.', trim( $raw ) );
			if ( 1 !== preg_match( '/^(\d+)(?:\.(\d+))?$/', $text, $m ) ) {
				throw new ArgumentError( $field, 'invalid' );
			}
			$fraction = rtrim( $m[2] ?? '', '0' );
			if ( strlen( $fraction ) > 2 ) {
				throw new ArgumentError( $field, 'too_precise' );
			}
			if ( strlen( $m[1] ) > 3 ) {
				throw new ArgumentError( $field, 'out_of_range' );
			}
			$basis_points = (int) $m[1] * 100 + (int) str_pad( $fraction, 2, '0' );
		} else {
			throw new ArgumentError( $field, 'invalid' );
		}

		if ( $basis_points < 0 ) {
			throw new ArgumentError( $field, 'invalid' );
		}

		if ( $basis_points <= 0 || $basis_points >= 10000 ) {
			throw new ArgumentError( $field, 'out_of_range' );
		}

		$whole = intdiv( $basis_points, 100 );
		$cents = $basis_points % 100;

		return 0 === $cents ? (string) $whole : $whole . '.' . rtrim( str_pad( (string) $cents, 2, '0', STR_PAD_LEFT ), '0' );
	}

	/**
	 * A whole number of minor units, zero or more. Throws on missing, negative, non-integral or
	 * malformed input.
	 *
	 * @param mixed $raw
	 * @throws ArgumentError
	 */
	public static function minor( $raw, string $field = 'amount' ): int {
		if ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
			throw new ArgumentError( $field, 'missing' );
		}

		if ( is_int( $raw ) ) {
			if ( $raw < 0 ) {
				throw new ArgumentError( $field, 'invalid' );
			}
			return $raw;
		}

		if ( is_float( $raw ) ) {
			if ( ! is_finite( $raw ) || $raw < 0 || $raw > 1e15 ) {
				throw new ArgumentError( $field, 'invalid' );
			}
			if ( floor( $raw ) !== $raw ) {
				throw new ArgumentError( $field, 'not_integral' );
			}
			return (int) $raw;
		}

		if ( is_string( $raw ) && 1 === preg_match( '/^(\d{1,15})(?:\.(\d+))?$/', trim( $raw ), $m ) ) {
			if ( '' !== trim( $m[2] ?? '', '0' ) ) {
				throw new ArgumentError( $field, 'not_integral' );
			}
			return (int) $m[1];
		}

		throw new ArgumentError( $field, 'invalid' );
	}

	/**
	 * A whole number above zero (amounts, counts, sizes). Throws like minor(), and on zero.
	 *
	 * @param mixed $raw
	 * @throws ArgumentError
	 */
	public static function positiveInt( $raw, string $field = 'value' ): int {
		$value = self::minor( $raw, $field );

		if ( $value < 1 ) {
			throw new ArgumentError( $field, 'out_of_range' );
		}

		return $value;
	}

	/**
	 * One of the allowed values (case-insensitive). Missing → $default, or an error when $default is
	 * null; present but unknown → an error.
	 *
	 * @param array<string,mixed> $args
	 * @param string[]            $allowed
	 * @throws ArgumentError
	 */
	public static function choice( array $args, string $key, array $allowed, ?string $default ): string {
		$raw = $args[ $key ] ?? null;

		if ( null === $raw || '' === $raw ) {
			if ( null === $default ) {
				throw new ArgumentError( $key, 'missing' );
			}
			return $default;
		}

		$value = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';

		if ( ! in_array( $value, $allowed, true ) ) {
			throw new ArgumentError( $key, 'unknown_choice' );
		}

		return $value;
	}

	/**
	 * A list of ids from action or campaign arguments: unique and sorted. Missing → empty list; any
	 * malformed entry throws (dropping it could widen a campaign, e.g. turn a targeted one storewide).
	 *
	 * @param mixed $raw
	 * @return int[]
	 * @throws ArgumentError
	 */
	public static function idList( $raw, string $field ): array {
		if ( null === $raw || '' === $raw ) {
			return array();
		}

		$out = array();

		foreach ( is_array( $raw ) ? $raw : array( $raw ) as $value ) {
			try {
				$id = self::positiveInt( $value, $field );
			} catch ( ArgumentError $e ) {
				throw new ArgumentError( $field, 'invalid' );
			}

			$out[ $id ] = true;
		}

		$ids = array_keys( $out );
		sort( $ids );

		return $ids;
	}

	/**
	 * Lenient id list for context data built by the store itself (cart lines, customers): malformed
	 * entries are skipped. Never use it for campaign arguments.
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	public static function ids( $raw ): array {
		$out = array();

		foreach ( is_array( $raw ) ? $raw : array( $raw ) as $value ) {
			if ( is_int( $value ) && $value > 0 ) {
				$out[ $value ] = true;
			} elseif ( is_string( $value ) && 1 === preg_match( '/^\d{1,15}$/', trim( $value ) ) && (int) $value > 0 ) {
				$out[ (int) $value ] = true;
			}
		}

		$ids = array_keys( $out );
		sort( $ids );

		return $ids;
	}

	/**
	 * A list of non-empty strings (codes, slugs, method ids).
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	public static function strings( $raw ): array {
		$out = array();

		foreach ( is_array( $raw ) ? $raw : array( $raw ) as $value ) {
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$out[] = trim( $value );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * A wall-clock time "HH:MM" (00:00–23:59); '' otherwise. Callers treat '' as invalid
	 * (recurrences become invalid, time windows never match), so this blank fails closed.
	 *
	 * @param mixed $raw
	 */
	public static function clock( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}

		$value = trim( $raw );

		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	/**
	 * A boolean flag from a form or JSON value; $default when missing.
	 *
	 * @param mixed $raw
	 */
	public static function flag( $raw, bool $default = false ): bool {
		if ( null === $raw || '' === $raw ) {
			return $default;
		}

		if ( is_bool( $raw ) ) {
			return $raw;
		}

		if ( is_int( $raw ) ) {
			return 0 !== $raw;
		}

		if ( ! is_scalar( $raw ) ) {
			return $default;
		}

		return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
