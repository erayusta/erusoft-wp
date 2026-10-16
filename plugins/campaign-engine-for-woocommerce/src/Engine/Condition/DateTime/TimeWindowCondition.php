<?php
/**
 * Time of day: ['condition' => 'time.window', 'from' => '09:00', 'to' => '12:59'].
 *
 * Minute resolution, both ends included (so "to 23:59" covers the last minute of the day). When `from`
 * is later than `to` the window runs across midnight. Malformed clocks never match.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\DateTime;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\Condition\ConditionInterface;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class TimeWindowCondition implements ConditionInterface {

	public function id(): string {
		return 'time.window';
	}

	public function group(): string {
		return 'datetime';
	}

	public function scope(): string {
		return 'cart';
	}

	public function matches( Scope $scope, ?Item $item, array $args ): bool {
		$from = Args::clock( $args['from'] ?? null );
		$to   = Args::clock( $args['to'] ?? null );

		if ( '' === $from || '' === $to ) {
			return false;
		}

		$now = $scope->context->now->format( 'H:i' );

		if ( $from <= $to ) {
			return $now >= $from && $now <= $to;
		}

		return $now >= $from || $now <= $to;
	}
}
