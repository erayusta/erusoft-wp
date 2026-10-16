<?php
/**
 * Brand term ids of the line.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class BrandCondition extends BaseCondition {

	public function id(): string {
		return 'item.brand';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	protected function defaultOperator(): string {
		return 'in';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $item?->brand_ids;
	}
}
