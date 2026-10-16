<?php
/**
 * Whole days since the shopper's last paid order; unknown when there is none. A guest's history is unknown (the condition never matches): a typed e-mail proves nothing, and
 * using it would let anyone claim another person's history or probe whether an address has orders.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Customer;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class DaysSinceLastOrderCondition extends BaseCondition {

	public function id(): string {
		return 'customer.days_since_last_order';
	}

	public function group(): string {
		return 'customer';
	}

	public function scope(): string {
		return 'cart';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		$last = $scope->context->customer->last_order_ts;

		if ( null === $last || ! $scope->context->customer->logged_in ) {
			return null;
		}

		return max( 0, intdiv( $scope->context->now->getTimestamp() - $last, 86400 ) );
	}
}
