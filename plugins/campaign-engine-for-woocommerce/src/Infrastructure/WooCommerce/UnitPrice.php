<?php
/**
 * Unit price for a discounted line, as a decimal string WooCommerce multiplies back by the quantity.
 *
 * The engine gives an exact line discount in minor units; it is not always divisible by the quantity
 * (3 units, 10.00 off → 3.333… each). Rounding the unit would move the line total, so the unit keeps
 * EXTRA decimals beyond the currency's: WooCommerce computes price × qty with four extra decimals of
 * precision and rounds there, which lands exactly on the engine's line total. Built by long division on
 * integers (no float, no overflow), truncated, so the line never ends above the engine's total.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Engine\Money;

final class UnitPrice {

	public const EXTRA = 10;

	public static function forLine( int $line_minor, int $qty, int $decimals ): string {
		$line_minor = max( 0, $line_minor );
		$qty        = max( 1, $qty );
		$whole      = intdiv( $line_minor, $qty );
		$rest       = $line_minor % $qty;
		$text       = Money::toDecimal( $whole, $decimals );

		if ( 0 === $rest ) {
			return $text;
		}

		$digits = '';

		for ( $i = 0; $i < self::EXTRA; $i++ ) {
			$rest   *= 10;
			$digits .= (string) intdiv( $rest, $qty );
			$rest    = $rest % $qty;
		}

		return $text . ( 0 === $decimals ? '.' : '' ) . rtrim( $digits, '0' );
	}
}
