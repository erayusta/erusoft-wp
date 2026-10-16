<?php
/**
 * Current unit price of the line, in minor units.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class PriceCondition extends BaseCondition {

	public function id(): string {
		return 'item.price';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $item?->unit_minor;
	}
}
