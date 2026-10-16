<?php
/**
 * Chosen shipping rates. Each rate matches both by its full id ("flat_rate:4") and by its method id ("flat_rate"), so a rule can name a method without knowing the zone instance.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Cart;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class ShippingMethodCondition extends BaseCondition {

	public function id(): string {
		return 'cart.shipping_method';
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
		$ids = array();

		foreach ( $scope->context->cart->shipping_methods as $rate ) {
			$ids[] = $rate;
			$colon = strpos( $rate, ':' );

			if ( false !== $colon ) {
				$ids[] = substr( $rate, 0, $colon );
			}
		}

		return array() === $ids ? null : array_values( array_unique( $ids ) );
	}
}
