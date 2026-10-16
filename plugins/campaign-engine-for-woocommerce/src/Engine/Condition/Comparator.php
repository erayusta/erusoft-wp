<?php
/**
 * Compares a condition's value (left) with the configured value (right).
 *
 * Operators:
 *  - `==`, `!=`                 loose equality (numbers and numeric strings compare as numbers);
 *  - `>`, `>=`, `<`, `<=`       numeric; a non-numeric side never matches;
 *  - `between`                  numeric, right is [min, max], both ends included;
 *  - `in`, `not_in`             right is a list; when left is a list too they mean "shares any" /
 *                               "shares none";
 *  - `all`                      left is a list that holds every right value.
 * Unknown operators and malformed right sides fail closed (no match).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition;

final class Comparator {

	public const OPERATORS = array( '==', '!=', '>', '>=', '<', '<=', 'between', 'in', 'not_in', 'all' );

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	public static function compare( $left, string $operator, $right ): bool {
		switch ( $operator ) {
			case '==':
				return self::equals( $left, $right );
			case '!=':
				return ! self::equals( $left, $right );
			case '>':
			case '>=':
			case '<':
			case '<=':
				return self::numeric( $left, $operator, $right );
			case 'between':
				if ( ! is_array( $right ) || 2 !== count( $right ) ) {
					return false;
				}
				$right = array_values( $right );
				return self::numeric( $left, '>=', $right[0] ) && self::numeric( $left, '<=', $right[1] );
			case 'in':
				return is_array( $right ) && self::shares( $left, $right );
			case 'not_in':
				return is_array( $right ) && ! self::shares( $left, $right );
			case 'all':
				if ( ! is_array( $left ) || ! is_array( $right ) || array() === $right ) {
					return false;
				}
				foreach ( $right as $value ) {
					if ( ! self::shares( $value, $left ) ) {
						return false;
					}
				}
				return true;
			default:
				return false;
		}
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	private static function equals( $left, $right ): bool {
		if ( is_array( $left ) || is_array( $right ) ) {
			return $left === $right;
		}

		if ( is_numeric( $left ) && is_numeric( $right ) ) {
			return 0 === ( ( $left + 0 ) <=> ( $right + 0 ) );
		}

		return (string) $left === (string) $right;
	}

	/**
	 * @param mixed $left
	 * @param mixed $right
	 */
	private static function numeric( $left, string $operator, $right ): bool {
		if ( ! is_numeric( $left ) || ! is_numeric( $right ) ) {
			return false;
		}

		$cmp = ( $left + 0 ) <=> ( $right + 0 );

		switch ( $operator ) {
			case '>':
				return $cmp > 0;
			case '>=':
				return $cmp >= 0;
			case '<':
				return $cmp < 0;
			default:
				return $cmp <= 0;
		}
	}

	/**
	 * True when a scalar is in the list, or when two lists share a value.
	 *
	 * @param mixed   $left
	 * @param mixed[] $list
	 */
	private static function shares( $left, array $list ): bool {
		foreach ( is_array( $left ) ? $left : array( $left ) as $value ) {
			foreach ( $list as $candidate ) {
				if ( ! is_array( $candidate ) && ! is_array( $value ) && self::equals( $value, $candidate ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
