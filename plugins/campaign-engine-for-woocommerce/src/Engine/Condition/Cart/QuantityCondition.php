<?php
/**
 * Units in the whole cart.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Cart;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class QuantityCondition extends BaseCondition {

	public function id(): string {
		return 'cart.quantity';
	}

	public function group(): string {
		return 'cart';
	}

	public function scope(): string {
		return 'cart';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $scope->context->totalQuantity();
	}
}
