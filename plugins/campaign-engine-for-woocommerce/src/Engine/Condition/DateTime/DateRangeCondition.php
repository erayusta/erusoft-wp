<?php
/**
 * Calendar range: ['condition' => 'date.range', 'from' => '2026-11-20', 'to' => '2026-11-30'].
 *
 * `from`/`to` are local dates ("Y-m-d") or local minutes ("Y-m-d H:i") read in the zone of the
 * context's "now". A date-only `to` includes that whole day; a minute `to` includes that minute.
 * `from_ts`/`to_ts` (Unix seconds, `to_ts` exclusive) may be used instead. Either end may be left
 * out; a malformed end never matches.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Condition\DateTime;

use DateTimeImmutable;
use DateTimeZone;
use Erusoft\CampaignEngine\Engine\Condition\ConditionInterface;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class DateRangeCondition implements ConditionInterface {

	public function id(): string {
		return 'date.range';
	}

	public function group(): string {
		return 'datetime';
	}

	public function scope(): string {
		return 'cart';
	}

	public function matches( Scope $scope, ?Item $item, array $args ): bool {
		$now  = $scope->context->now;
		$ts   = $now->getTimestamp();
		$zone = $now->getTimezone();

		$from = self::bound( $args, 'from', $zone, false );
		$to   = self::bound( $args, 'to', $zone, true );

		if ( false === $from || false === $to ) {
			return false;
		}

		if ( null !== $from && $ts < $from ) {
			return false;
		}

		return null === $to || $ts < $to;
	}

	/**
	 * Start (inclusive) or end (exclusive) of the range as a timestamp; null when absent, false when
	 * malformed.
	 *
	 * @param array<string,mixed> $args
	 * @return int|false|null
	 */
	private static function bound( array $args, string $name, DateTimeZone $zone, bool $is_end ) {
		$ts_key = $name . '_ts';

		if ( isset( $args[ $ts_key ] ) && '' !== $args[ $ts_key ] ) {
			return is_numeric( $args[ $ts_key ] ) ? (int) $args[ $ts_key ] : false;
		}

		if ( ! isset( $args[ $name ] ) || '' === $args[ $name ] ) {
			return null;
		}

		$text = is_string( $args[ $name ] ) ? trim( $args[ $name ] ) : '';

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
			$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $text, $zone );

			if ( false === $day || $day->format( 'Y-m-d' ) !== $text ) {
				return false;
			}

			return $is_end ? $day->modify( '+1 day' )->getTimestamp() : $day->getTimestamp();
		}

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $text ) ) {
			$minute = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $text, $zone );

			if ( false === $minute || $minute->format( 'Y-m-d H:i' ) !== $text ) {
				return false;
			}

			return $minute->getTimestamp() + ( $is_end ? 60 : 0 );
		}

		return false;
	}
}
