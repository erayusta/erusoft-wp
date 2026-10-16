<?php
/**
 * User roles of the shopper (case-insensitive); a guest has none.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Customer;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class RoleCondition extends BaseCondition {

	public function id(): string {
		return 'customer.role';
	}

	public function group(): string {
		return 'customer';
	}

	public function scope(): string {
		return 'cart';
	}

	protected function defaultOperator(): string {
		return 'in';
	}

	protected function caseInsensitive(): bool {
		return true;
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $scope->context->customer->roles;
	}
}
