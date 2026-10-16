<?php
/**
 * Stock status of the line (instock, outofstock, onbackorder); unknown when empty.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class StockStatusCondition extends BaseCondition {

	public function id(): string {
		return 'item.stock_status';
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
		return null === $item || '' === $item->stock_status ? null : $item->stock_status;
	}
}
