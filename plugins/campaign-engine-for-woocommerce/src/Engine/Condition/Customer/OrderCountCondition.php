<?php
/**
 * Number of the shopper's previous paid orders. A guest's history is unknown (the condition never matches): a typed e-mail proves nothing, and
 * using it would let anyone claim another person's history or probe whether an address has orders.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Customer;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class OrderCountCondition extends BaseCondition {

	public function id(): string {
		return 'customer.order_count';
	}

	public function group(): string {
		return 'customer';
	}

	public function scope(): string {
		return 'cart';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $scope->context->customer->logged_in ? $scope->context->customer->order_count : null;
	}
}
