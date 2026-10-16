<?php
/**
 * Whether the shopper is logged in (value true or false).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Customer;

use Erusoft\CampaignEngine\Engine\Condition\FlagCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class LoggedInCondition extends FlagCondition {

	public function id(): string {
		return 'customer.logged_in';
	}

	public function group(): string {
		return 'customer';
	}

	public function scope(): string {
		return 'cart';
	}

	protected function flag( Scope $scope, ?Item $item ): bool {
		return $scope->context->customer->logged_in;
	}
}
