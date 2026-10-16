<?php
/**
 * Shared shape of yes/no conditions: the node's `value` (default true) must equal the flag.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

abstract class FlagCondition implements ConditionInterface {

	/** The flag read from the context. */
	abstract protected function flag( Scope $scope, ?Item $item ): bool;

	public function matches( Scope $scope, ?Item $item, array $args ): bool {
		if ( 'item' === $this->scope() && null === $item ) {
			return false;
		}

		return $this->flag( $scope, $item ) === Args::flag( $args['value'] ?? null, true );
	}
}
