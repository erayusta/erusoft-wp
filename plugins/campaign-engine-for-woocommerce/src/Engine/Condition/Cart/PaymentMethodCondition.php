<?php
/**
 * The chosen payment gateway id; unknown until one is chosen.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Cart;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class PaymentMethodCondition extends BaseCondition {

	public function id(): string {
		return 'cart.payment_method';
	}

	public function group(): string {
		return 'cart';
	}

	public function scope(): string {
		return 'cart';
	}

	protected function defaultOperator(): string {
		return 'in';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		$method = $scope->context->cart->payment_method;

		return '' === $method ? null : $method;
	}
}
