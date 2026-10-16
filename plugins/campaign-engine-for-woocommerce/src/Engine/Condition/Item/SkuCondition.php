<?php
/**
 * The line's SKU (case-insensitive); unknown when empty.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class SkuCondition extends BaseCondition {

	public function id(): string {
		return 'item.sku';
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

	protected function caseInsensitive(): bool {
		return true;
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return null === $item || '' === $item->sku ? null : $item->sku;
	}
}
