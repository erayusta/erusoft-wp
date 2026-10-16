<?php
/**
 * A campaign that matched the cart, with the adjustments its actions proposed, before resolution.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;

final class Candidate {

	/**
	 * @param Adjustment[] $adjustments
	 */
	public function __construct(
		public readonly Campaign $campaign,
		public readonly array $adjustments
	) {}

	/** Proposed line discount in total (shipping and reward marks count as 0). */
	public function total(): int {
		$total = 0;

		foreach ( $this->adjustments as $adjustment ) {
			if ( Adjustment::LEVEL_ITEM === $adjustment->level ) {
				$total += $adjustment->discount_minor;
			}
		}

		return $total;
	}

	/** Orders candidates: higher priority first, then lower campaign id. */
	public static function compare( self $a, self $b ): int {
		if ( $a->campaign->priority !== $b->campaign->priority ) {
			return $b->campaign->priority <=> $a->campaign->priority;
		}

		return $a->campaign->id <=> $b->campaign->id;
	}
}
