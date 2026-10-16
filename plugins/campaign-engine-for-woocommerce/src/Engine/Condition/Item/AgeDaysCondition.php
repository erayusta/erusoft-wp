<?php
/**
 * Whole days since the product was published; unknown without a publish date.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class AgeDaysCondition extends BaseCondition {

	public function id(): string {
		return 'item.age_days';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		if ( null === $item || null === $item->published_ts ) {
			return null;
		}

		return max( 0, intdiv( $scope->context->now->getTimestamp() - $item->published_ts, 86400 ) );
	}
}
