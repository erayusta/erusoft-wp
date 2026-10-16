<?php
/**
 * Store-wide engine behaviour.
 *
 *  - `stacking` how campaigns share a line:
 *       'best'  the campaign giving the most on that line wins (default);
 *       'first' the highest-priority campaign on that line wins, whatever it gives;
 *       'stack' every campaign applies and the discounts add up, capped at the line.
 *    Exclusive campaigns ("tek başına") are settled before stacking: when one applies, nothing else does.
 *  - `max_cart_discount_percent` cap on the whole cart's discount as a share of its subtotal, null = none;
 *  - `min_unit_price_minor` no unit is pushed below this price by campaigns;
 *  - `with_coupons` whether campaigns still apply when a coupon is in the cart.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

final class Settings {

	public const STACKING = array( 'best', 'first', 'stack' );

	public function __construct(
		public readonly string $stacking = 'best',
		public readonly ?string $max_cart_discount_percent = null,
		public readonly int $min_unit_price_minor = 0,
		public readonly bool $with_coupons = true
	) {}

	/**
	 * Reads stored settings. Missing keys take the defaults above (an empty cart cap means "no cap").
	 * A value that is present but malformed throws ArgumentError: a money guard such as the cart cap
	 * or the price floor must never fail open by turning into "none". The settings screen validates
	 * with the same readers before saving.
	 *
	 * @param array<string,mixed> $row
	 * @throws ArgumentError
	 */
	public static function fromArray( array $row ): self {
		$cap   = $row['max_cart_discount_percent'] ?? null;
		$floor = $row['min_unit_price_minor'] ?? null;

		return new self(
			Args::choice( $row, 'stacking', self::STACKING, 'best' ),
			null === $cap || '' === $cap ? null : Args::percent( $cap, 'max_cart_discount_percent' ),
			null === $floor || '' === $floor ? 0 : Args::minor( $floor, 'min_unit_price_minor' ),
			Args::flag( $row['with_coupons'] ?? null, true )
		);
	}
}
