<?php
/**
 * Shipping country code (ISO 3166-1 alpha-2); unknown until an address is given.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Cart;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class ShippingCountryCondition extends BaseCondition {

	public function id(): string {
		return 'cart.shipping_country';
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
		$country = $scope->context->cart->shipping_country;

		return '' === $country ? null : $country;
	}
}
