<?php
/**
 * Whether the line is on sale (value true or false).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\FlagCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class OnSaleCondition extends FlagCondition {

	public function id(): string {
		return 'item.on_sale';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	protected function flag( Scope $scope, ?Item $item ): bool {
		return null !== $item && $item->isOnSale();
	}
}
