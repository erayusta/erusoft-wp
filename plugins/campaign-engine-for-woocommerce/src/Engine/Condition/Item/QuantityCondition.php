<?php
/**
 * Units on the line.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class QuantityCondition extends BaseCondition {

	public function id(): string {
		return 'item.quantity';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $item?->qty;
	}
}
