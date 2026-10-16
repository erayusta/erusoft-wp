<?php
/**
 * Stock quantity of the line; unknown when stock is not managed.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class StockQuantityCondition extends BaseCondition {

	public function id(): string {
		return 'item.stock_quantity';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $item?->stock_qty;
	}
}
