<?php
/**
 * The lines a campaign may act on (inside its targets and passing its item conditions).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Context;

final class MatchResult {

	/**
	 * @param Item[] $items
	 */
	public function __construct( public readonly array $items ) {}

	public function isEmpty(): bool {
		return array() === $this->items;
	}

	/** Sum of the eligible lines. */
	public function eligibleSubtotalMinor(): int {
		$total = 0;

		foreach ( $this->items as $item ) {
			$total += $item->lineSubtotalMinor();
		}

		return $total;
	}

	/** Units in the eligible lines. */
	public function eligibleQuantity(): int {
		$total = 0;

		foreach ( $this->items as $item ) {
			$total += $item->qty;
		}

		return $total;
	}
}
