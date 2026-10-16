<?php
/**
 * The final outcome of one evaluation: every adjustment that survived resolution.
 *
 * Line discounts are exact minor-unit amounts per line. When the WooCommerce layer needs a unit price,
 * it splits a line's discount with `UnitAllocation::perUnit()`; the shares add up to the line discount.
 *
 * Free shipping and rewards stay in the set as their own levels and belong to the campaign that made
 * them, so usage limits and order snapshots count those campaigns too.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Adjustment;

final class AdjustmentSet {

	/** @var Adjustment[] */
	private array $adjustments;

	/**
	 * @param Adjustment[] $adjustments
	 */
	public function __construct( array $adjustments = array() ) {
		$this->adjustments = array_values( $adjustments );
	}

	/** @return Adjustment[] */
	public function all(): array {
		return $this->adjustments;
	}

	public function isEmpty(): bool {
		return array() === $this->adjustments;
	}

	/** @return Adjustment[] */
	public function forLevel( string $level ): array {
		return array_values( array_filter( $this->adjustments, static fn( Adjustment $a ): bool => $a->level === $level ) );
	}

	/** @return Adjustment[] Line adjustments of one line. */
	public function forItem( string $item_key ): array {
		return array_values( array_filter( $this->adjustments, static fn( Adjustment $a ): bool => Adjustment::LEVEL_ITEM === $a->level && $a->item_key === $item_key ) );
	}

	/** Total discount on one line (all units). */
	public function lineDiscount( string $item_key ): int {
		$total = 0;

		foreach ( $this->forItem( $item_key ) as $adjustment ) {
			$total += $adjustment->discount_minor;
		}

		return $total;
	}

	/** @return array<string,int> item_key => line discount, for every discounted line. */
	public function lineDiscounts(): array {
		$out = array();

		foreach ( $this->forLevel( Adjustment::LEVEL_ITEM ) as $adjustment ) {
			$out[ $adjustment->item_key ] = ( $out[ $adjustment->item_key ] ?? 0 ) + $adjustment->discount_minor;
		}

		return $out;
	}

	/** Total discount of the cart. */
	public function totalDiscount(): int {
		return array_sum( $this->lineDiscounts() );
	}

	/** @return array<int,int> campaign id => discount it gives (0 for shipping/reward-only campaigns). */
	public function campaignTotals(): array {
		$out = array();

		foreach ( $this->adjustments as $adjustment ) {
			$out[ $adjustment->campaign_id ] = ( $out[ $adjustment->campaign_id ] ?? 0 ) + ( Adjustment::LEVEL_ITEM === $adjustment->level ? $adjustment->discount_minor : 0 );
		}

		return $out;
	}

	/** @return int[] Ids of every campaign with at least one adjustment. */
	public function campaignIds(): array {
		return array_keys( $this->campaignTotals() );
	}

	public function freeShipping(): bool {
		return array() !== $this->freeShippingCampaignIds();
	}

	/** @return int[] */
	public function freeShippingCampaignIds(): array {
		$ids = array();

		foreach ( $this->forLevel( Adjustment::LEVEL_SHIPPING ) as $adjustment ) {
			$ids[ $adjustment->campaign_id ] = true;
		}

		return array_keys( $ids );
	}

	/** @return Adjustment[] Rewards earned by the cart. */
	public function rewards(): array {
		return $this->forLevel( Adjustment::LEVEL_REWARD );
	}

	/** @return array<int,array<string,mixed>> Plain rows for order snapshots. */
	public function toArray(): array {
		return array_map( static fn( Adjustment $a ): array => $a->toArray(), $this->adjustments );
	}
}
