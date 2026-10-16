<?php
/**
 * Total the shopper spent on previous paid orders, in minor units. A guest's history is unknown (the condition never matches): a typed e-mail proves nothing, and
 * using it would let anyone claim another person's history or probe whether an address has orders.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Customer;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class TotalSpentCondition extends BaseCondition {

	public function id(): string {
		return 'customer.total_spent';
	}

	public function group(): string {
		return 'customer';
	}

	public function scope(): string {
		return 'cart';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $scope->context->customer->logged_in ? $scope->context->customer->total_spent_minor : null;
	}
}
