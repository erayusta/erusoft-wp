<?php
/**
 * A rule-tree leaf.
 *
 * A condition reads one value from the context and compares it with the node's arguments. The node
 * itself is passed as $args, e.g. ['condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 60000].
 * Money values are minor units; dates and times are read in the zone of the context's "now".
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition;

use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

interface ConditionInterface {

	/** Unique id such as 'cart.subtotal'. */
	public function id(): string;

	/** Admin grouping: 'cart', 'item', 'customer' or 'datetime'. */
	public function group(): string;

	/**
	 * 'item' when the condition is judged per line (and filters lines), 'cart' when it is judged once
	 * for the whole cart.
	 */
	public function scope(): string;

	/**
	 * @param array<string,mixed> $args The rule node.
	 */
	public function matches( Scope $scope, ?Item $item, array $args ): bool;
}
