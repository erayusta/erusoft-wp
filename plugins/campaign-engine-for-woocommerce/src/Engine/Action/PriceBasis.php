<?php
/**
 * Which price a discount is computed from, and how much of it the customer really gets.
 *
 * Bases:
 *  - 'current'      the line's current unit price;
 *  - 'regular'      the catalogue price. "20 % off the regular price" describes a TARGET price
 *                   (regular − 20 %). A line already on sale below that target gets nothing, a line
 *                   on sale above it gets only the difference — never a discount on top of the sale;
 *  - 'exclude_sale' the current price, but lines on sale are skipped altogether.
 *
 * In every basis a campaign gives at most what it states: when the current price is above the
 * regular price (another plugin raised it), the effective discount is clamped to the nominal one.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Context\Item;

final class PriceBasis {

	/** The unit price discounts are computed from, or null when the line is skipped. */
	public static function base( Item $item, string $basis ): ?int {
		if ( 'regular' === $basis ) {
			return $item->regularMinor();
		}

		if ( 'exclude_sale' === $basis && $item->isOnSale() ) {
			return null;
		}

		return $item->unit_minor;
	}

	/**
	 * The per-unit discount the customer really gets, given the nominal one computed on $base:
	 * max( 0, current − ( base − nominal ) ), never above the nominal discount.
	 */
	public static function effectivePerUnit( Item $item, int $base, int $nominal ): int {
		$effective = $item->unit_minor - ( $base - $nominal );

		return max( 0, min( $nominal, $effective ) );
	}

	/**
	 * The same rule for a share of a cart-level discount that is not divisible by the quantity:
	 * share − ( base − current ) × qty, never above the share, never above the line, never below 0.
	 */
	public static function effectiveLineShare( Item $item, int $base, int $share ): int {
		$effective = $share - ( $base - $item->unit_minor ) * $item->qty;

		return max( 0, min( $share, $item->lineSubtotalMinor(), $effective ) );
	}
}
