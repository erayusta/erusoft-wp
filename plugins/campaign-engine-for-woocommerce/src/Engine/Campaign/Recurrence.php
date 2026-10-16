<?php
/**
 * Repeating time windows inside a campaign's season.
 *
 * Shapes (all times are local "HH:MM", day codes MO…SU):
 *   none         no repetition: the season alone decides
 *   daily        ['freq' => 'daily', 'start' => '22:00', 'end' => '01:59']
 *   weekly       ['freq' => 'weekly', 'days' => ['TU', 'TH'], 'start' => '10:00', 'end' => '13:59']
 *   weekly_span  ['freq' => 'weekly_span', 'from_day' => 'FR', 'from' => '18:00', 'to_day' => 'SU', 'to' => '23:59']
 *   monthly      ['freq' => 'monthly', 'days' => [1, 15], 'start' => '00:00', 'end' => '23:59']
 *
 * The end minute is included: a window "to 23:59" lasts until midnight. When the end time is earlier
 * than the start time, in a daily, weekly or monthly rule, the window runs past midnight and belongs to the day it
 * started on. A weekly span runs from its start day and time to its end day and time, wrapping round
 * the week when needed. A monthly day that a month lacks (the 31st in November) is skipped, not moved.
 *
 * The class never reads the clock and never picks a time zone: each method judges the moment it is
 * given, in that moment's own zone. `Schedule` converts to the store's zone first.
 *
 * Anything malformed makes the recurrence invalid, and an invalid recurrence is never active. Falling
 * back to "always" would run a campaign around the clock that the shop manager meant to limit.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Campaign;

use DateTimeImmutable;
use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;

final class Recurrence {

	public const FREQ_NONE        = 'none';
	public const FREQ_DAILY       = 'daily';
	public const FREQ_WEEKLY      = 'weekly';
	public const FREQ_WEEKLY_SPAN = 'weekly_span';
	public const FREQ_MONTHLY     = 'monthly';
	public const FREQ_INVALID     = 'invalid';

	/** ISO-8601 day number => day code. */
	public const DAYS = array(
		1 => 'MO',
		2 => 'TU',
		3 => 'WE',
		4 => 'TH',
		5 => 'FR',
		6 => 'SA',
		7 => 'SU',
	);

	/** How far ahead `nextStart()` looks, in days (covers a monthly rule on the 31st). */
	public const HORIZON_DAYS = 400;

	/**
	 * @param string[] $days      Day codes (weekly).
	 * @param int[]    $monthdays Days of the month (monthly).
	 */
	private function __construct(
		private string $freq,
		private array $days = array(),
		private array $monthdays = array(),
		private string $start = '',
		private string $end = '',
		private int $from_day = 0,
		private int $to_day = 0
	) {}

	public static function none(): self {
		return new self( self::FREQ_NONE );
	}

	public static function invalid(): self {
		return new self( self::FREQ_INVALID );
	}

	/**
	 * Builds a recurrence from its stored array. null, '' and [] mean "none"; anything else that is not
	 * a complete, valid rule is invalid.
	 *
	 * @param mixed $raw
	 */
	public static function fromArray( $raw ): self {
		if ( null === $raw || '' === $raw || array() === $raw ) {
			return self::none();
		}

		if ( ! is_array( $raw ) ) {
			return self::invalid();
		}

		$freq = is_string( $raw['freq'] ?? null ) ? strtolower( trim( $raw['freq'] ) ) : '';

		if ( self::FREQ_NONE === $freq ) {
			return self::none();
		}

		if ( self::FREQ_WEEKLY_SPAN === $freq ) {
			$from_day = self::dayNumber( $raw['from_day'] ?? null );
			$to_day   = self::dayNumber( $raw['to_day'] ?? null );
			$from     = Args::clock( $raw['from'] ?? null );
			$to       = Args::clock( $raw['to'] ?? null );

			if ( 0 === $from_day || 0 === $to_day || '' === $from || '' === $to ) {
				return self::invalid();
			}

			return new self( $freq, array(), array(), $from, $to, $from_day, $to_day );
		}

		$start = Args::clock( $raw['start'] ?? null );
		$end   = Args::clock( $raw['end'] ?? null );

		if ( '' === $start || '' === $end ) {
			return self::invalid();
		}

		if ( self::FREQ_DAILY === $freq ) {
			return new self( $freq, array(), array(), $start, $end );
		}

		if ( self::FREQ_WEEKLY === $freq ) {
			$days = self::dayCodes( $raw['days'] ?? null );

			return array() === $days ? self::invalid() : new self( $freq, $days, array(), $start, $end );
		}

		if ( self::FREQ_MONTHLY === $freq ) {
			$monthdays = self::monthdays( $raw['days'] ?? null );

			return array() === $monthdays ? self::invalid() : new self( $freq, array(), $monthdays, $start, $end );
		}

		return self::invalid();
	}

	/**
	 * Day codes from a list of codes ("fr") or ISO numbers (5), upper-cased, unique, in week order.
	 * One unreadable entry empties the whole list: running on fewer days than configured would be a
	 * different campaign.
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	public static function dayCodes( $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		$found = array();

		foreach ( $raw as $value ) {
			$number = self::dayNumber( $value );

			if ( 0 === $number ) {
				return array();
			}

			$found[ $number ] = self::DAYS[ $number ];
		}

		ksort( $found );

		return array_values( $found );
	}

	public function freq(): string {
		return $this->freq;
	}

	public function isValid(): bool {
		return self::FREQ_INVALID !== $this->freq;
	}

	public function isNone(): bool {
		return self::FREQ_NONE === $this->freq;
	}

	/** @return array<string,mixed> The stored shape; feeding it to fromArray() gives an equal rule. */
	public function toArray(): array {
		switch ( $this->freq ) {
			case self::FREQ_DAILY:
				return array( 'freq' => $this->freq, 'start' => $this->start, 'end' => $this->end );
			case self::FREQ_WEEKLY:
				return array( 'freq' => $this->freq, 'days' => $this->days, 'start' => $this->start, 'end' => $this->end );
			case self::FREQ_MONTHLY:
				return array( 'freq' => $this->freq, 'days' => $this->monthdays, 'start' => $this->start, 'end' => $this->end );
			case self::FREQ_WEEKLY_SPAN:
				return array(
					'freq'     => $this->freq,
					'from_day' => self::DAYS[ $this->from_day ],
					'from'     => $this->start,
					'to_day'   => self::DAYS[ $this->to_day ],
					'to'       => $this->end,
				);
			default:
				return array( 'freq' => $this->freq );
		}
	}

	/** Whether the moment falls inside one of the windows. */
	public function matches( DateTimeImmutable $moment ): bool {
		if ( self::FREQ_NONE === $this->freq ) {
			return true;
		}

		return null !== $this->windowAt( $moment );
	}

	/**
	 * The window holding the moment as [start, end) — end exclusive — or null.
	 *
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|null
	 */
	public function windowAt( DateTimeImmutable $moment ): ?array {
		if ( ! $this->isValid() || self::FREQ_NONE === $this->freq ) {
			return null;
		}

		$midnight = $moment->setTime( 0, 0 );
		$lookback = self::FREQ_WEEKLY_SPAN === $this->freq ? 7 : 1;

		for ( $i = $lookback; $i >= 0; $i-- ) {
			$window = $this->windowStartingOn( $midnight->modify( '-' . $i . ' day' ) );

			if ( null !== $window && $window[0] <= $moment && $moment < $window[1] ) {
				return $window;
			}
		}

		return null;
	}

	/** Start of the first window that opens strictly after the moment, or null. */
	public function nextStart( DateTimeImmutable $moment ): ?DateTimeImmutable {
		if ( ! $this->isValid() || self::FREQ_NONE === $this->freq ) {
			return null;
		}

		$midnight = $moment->setTime( 0, 0 );

		for ( $i = 0; $i <= self::HORIZON_DAYS; $i++ ) {
			$window = $this->windowStartingOn( $midnight->modify( '+' . $i . ' day' ) );

			if ( null !== $window && $window[0] > $moment ) {
				return $window[0];
			}
		}

		return null;
	}

	/**
	 * The window that opens on the given local day, if the rule has one there.
	 *
	 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}|null
	 */
	private function windowStartingOn( DateTimeImmutable $day ): ?array {
		$number = (int) $day->format( 'N' );

		switch ( $this->freq ) {
			case self::FREQ_DAILY:
				break;
			case self::FREQ_WEEKLY:
				if ( ! in_array( self::DAYS[ $number ], $this->days, true ) ) {
					return null;
				}
				break;
			case self::FREQ_MONTHLY:
				if ( ! in_array( (int) $day->format( 'j' ), $this->monthdays, true ) ) {
					return null;
				}
				break;
			case self::FREQ_WEEKLY_SPAN:
				if ( $number !== $this->from_day ) {
					return null;
				}
				$span = ( $this->to_day - $this->from_day + 7 ) % 7;
				if ( 0 === $span && $this->end < $this->start ) {
					$span = 7;
				}
				return array( self::at( $day, $this->start ), self::at( $day->modify( '+' . $span . ' day' ), $this->end )->modify( '+1 minute' ) );
			default:
				return null;
		}

		$end_day = $this->end < $this->start ? $day->modify( '+1 day' ) : $day;

		return array( self::at( $day, $this->start ), self::at( $end_day, $this->end )->modify( '+1 minute' ) );
	}

	/** The local day at "HH:MM". */
	private static function at( DateTimeImmutable $day, string $clock ): DateTimeImmutable {
		return $day->setTime( (int) substr( $clock, 0, 2 ), (int) substr( $clock, 3, 2 ) );
	}

	/**
	 * ISO day number from a code ("FR") or a number (5); 0 when unreadable.
	 *
	 * @param mixed $raw
	 */
	private static function dayNumber( $raw ): int {
		if ( is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '/^[1-7]$/', trim( $raw ) ) ) ) {
			$number = (int) $raw;

			return $number >= 1 && $number <= 7 ? $number : 0;
		}

		if ( ! is_string( $raw ) ) {
			return 0;
		}

		$found = array_search( strtoupper( trim( $raw ) ), self::DAYS, true );

		return false === $found ? 0 : (int) $found;
	}

	/**
	 * Days of the month 1–31, unique and sorted; empty when any entry is unreadable.
	 *
	 * @param mixed $raw
	 * @return int[]
	 */
	private static function monthdays( $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $value ) {
			try {
				$day = Args::positiveInt( $value, 'days' );
			} catch ( ArgumentError $e ) {
				return array();
			}

			if ( $day > 31 ) {
				return array();
			}

			$out[ $day ] = $day;
		}

		ksort( $out );

		return array_values( $out );
	}
}
