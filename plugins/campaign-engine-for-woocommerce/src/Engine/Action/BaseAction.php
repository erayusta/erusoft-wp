<?php
/**
 * Shared helper for actions: builds adjustments stamped with the campaign's id, label, version and
 * priority.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;

abstract class BaseAction implements ActionInterface {

	/**
	 * @param array<string,mixed> $meta
	 */
	protected function adjustment( Campaign $campaign, string $item_key, int $discount_minor, array $meta = array(), string $level = Adjustment::LEVEL_ITEM ): Adjustment {
		return new Adjustment(
			$campaign->id,
			$item_key,
			max( 0, $discount_minor ),
			$level,
			$meta + array( 'action' => $this->id() ),
			$campaign->label,
			$campaign->version,
			$campaign->priority
		);
	}
}
