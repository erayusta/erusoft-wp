<?php
/**
 * Day of the week: ['condition' => 'date.weekday', 'value' => ['SA', 'SU']].
 *
 * Days are two-letter codes MO…SU (ISO numbers 1–7 are accepted too) and are read in the zone of the
 * context's "now". An empty or malformed list never matches.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\DateTime;

use Erusoft\CampaignEngine\Engine\Campaign\Recurrence;
use Erusoft\CampaignEngine\Engine\Condition\ConditionInterface;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class WeekdayCondition implements ConditionInterface {

	public function id(): string {
		return 'date.weekday';
	}

	public function group(): string {
		return 'datetime';
	}

	public function scope(): string {
		return 'cart';
	}

	public function matches( Scope $scope, ?Item $item, array $args ): bool {
		$days = Recurrence::dayCodes( $args['value'] ?? null );

		if ( array() === $days ) {
			return false;
		}

		return in_array( Recurrence::DAYS[ (int) $scope->context->now->format( 'N' ) ], $days, true );
	}
}
