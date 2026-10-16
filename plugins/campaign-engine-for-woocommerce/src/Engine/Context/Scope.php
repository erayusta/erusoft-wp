<?php
/**
 * The pair passed to conditions and actions: the whole context plus the lines in play.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Context;

final class Scope {

	public function __construct(
		public readonly Context $context,
		public readonly MatchResult $match
	) {}
}
