<?php
/**
 * Coupon codes applied to the cart (case-insensitive).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Cart;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class CouponCondition extends BaseCondition {

	public function id(): string {
		return 'cart.coupon';
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

	protected function caseInsensitive(): bool {
		return true;
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return $scope->context->cart->coupons;
	}
}
