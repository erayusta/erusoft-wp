<?php
/**
 * The line's product id and, for a variation, its variation id.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\Item;

use Erusoft\CampaignEngine\Engine\Condition\BaseCondition;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class ProductCondition extends BaseCondition {

	public function id(): string {
		return 'item.product';
	}

	public function group(): string {
		return 'item';
	}

	public function scope(): string {
		return 'item';
	}

	protected function defaultOperator(): string {
		return 'in';
	}

	/** @return mixed */
	protected function left( Scope $scope, ?Item $item ) {
		return null === $item ? null : array_values( array_filter( array( $item->product_id, $item->variation_id ) ) );
	}
}
