<?php
/**
 * An action turns a matched campaign into adjustments.
 *
 * Contract: `build()` returns an empty list when its arguments are missing or malformed, never a
 * guessed discount. Line adjustments carry the discount for the whole line; the resolver later caps,
 * stacks and finalises them.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Action;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Scope;

interface ActionInterface {

	/** The `type` value of the action row, e.g. 'product_percent'. */
	public function id(): string;

	/** 'item', 'shipping' or 'reward': what the adjustments it builds act on. */
	public function level(): string;

	/**
	 * @param array<string,mixed> $args The action row.
	 * @return Adjustment[]
	 */
	public function build( Scope $scope, Campaign $campaign, array $args ): array;
}
