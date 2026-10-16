<?php
/**
 * Whether this would be the shopper's first paid order (value true or false). Guests without history count as first orders; the WooCommerce layer may look guests up by e-mail at checkout.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Customer;

use Erusoft\CampaignEngine\Engine\Condition\FlagCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class FirstOrderCondition extends FlagCondition {

	public function id(): string {
		return 'customer.first_order';
	}

	public function group(): string {
		return 'customer';
	}

	public function scope(): string {
		return 'cart';
	}

	protected function flag( Scope $scope, ?Item $item ): bool {
		return $scope->context->customer->isFirstOrder();
	}
}
