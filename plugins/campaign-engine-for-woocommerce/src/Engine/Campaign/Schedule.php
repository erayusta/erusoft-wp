<?php
/**
 * When a campaign runs: a season (calendar start and end) and, inside it, an optional recurrence.
 *
 * The season works in absolute time: `starts_ts` is included, `ends_ts` is excluded. The recurrence
 * works in wall-clock time of the store's zone, which the caller passes in (WordPress's site zone);
 * every moment given to this class is converted to that zone first, so a server running in UTC and a
 * shop in Istanbul agree. A "flash" schedule is an ordinary season marked as such, for storefront
 * countdowns; the engine treats it like any other.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Campaign;

use DateTimeImmutable;
use DateTimeZone;

final class Schedule {

	public readonly ?int $starts_ts;
	public readonly ?int $ends_ts;
	public readonly Recurrence $recurrence;
	public readonly DateTimeZone $timezone;
	public readonly bool $flash;

	public function __construct( ?int $starts_ts = null, ?int $ends_ts = null, ?Recurrence $recurrence = null, ?DateTimeZone $timezone = null, bool $flash = false ) {
		$this->starts_ts  = $starts_ts;
		$this->ends_ts    = $ends_ts;
		$this->recurrence = $recurrence ?? Recurrence::none();
		$this->timezone   = $timezone ?? new DateTimeZone( 'UTC' );
		$this->flash      = $flash;
	}

	/** No season, no recurrence: always on. */
	public static function always(): self {
		return new self();
	}

	/** A flash window of the given length from the start. */
	public static function flash( int $starts_ts, int $minutes, ?DateTimeZone $timezone = null ): self {
		return new self( $starts_ts, $starts_ts + max( 1, $minutes ) * 60, null, $timezone, true );
	}

	/**
	 * Builds a schedule from its stored array:
	 * ['starts_ts' => int|null, 'ends_ts' => int|null, 'recurrence' => array|null, 'timezone' => 'Europe/Istanbul', 'flash' => bool].
	 * An unknown time zone makes the schedule never active (its windows could not be placed).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function fromArray( array $row ): self {
		$zone       = null;
		$recurrence = Recurrence::fromArray( $row['recurrence'] ?? null );
		$name       = $row['timezone'] ?? '';

		if ( is_string( $name ) && '' !== $name ) {
			try {
				$zone = new DateTimeZone( $name );
			} catch ( \Exception $e ) {
				$recurrence = Recurrence::invalid();
			}
		}

		return new self(
			self::timestamp( $row['starts_ts'] ?? null ),
			self::timestamp( $row['ends_ts'] ?? null ),
			$recurrence,
			$zone,
			(bool) ( $row['flash'] ?? false )
		);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'starts_ts'  => $this->starts_ts,
			'ends_ts'    => $this->ends_ts,
			'recurrence' => $this->recurrence->isNone() ? null : $this->recurrence->toArray(),
			'timezone'   => $this->timezone->getName(),
			'flash'      => $this->flash,
		);
	}

	public function isFlash(): bool {
		return $this->flash;
	}

	public function isActiveAt( DateTimeImmutable $now ): bool {
		$ts = $now->getTimestamp();

		if ( null !== $this->starts_ts && $ts < $this->starts_ts ) {
			return false;
		}

		if ( null !== $this->ends_ts && $ts >= $this->ends_ts ) {
			return false;
		}

		return $this->recurrence->matches( $this->local( $now ) );
	}

	/** True once the season is over (or the rule can never run again). */
	public function hasEnded( DateTimeImmutable $now ): bool {
		if ( ! $this->recurrence->isValid() ) {
			return true;
		}

		return null !== $this->ends_ts && $now->getTimestamp() >= $this->ends_ts;
	}

	/**
	 * When the campaign next becomes active after this moment (in the store's zone), or null when it
	 * never will. While a window is open this is the start of the following window.
	 */
	public function nextStart( DateTimeImmutable $now ): ?DateTimeImmutable {
		if ( $this->hasEnded( $now ) ) {
			return null;
		}

		$ts   = $now->getTimestamp();
		$from = $this->local( $now );

		if ( null !== $this->starts_ts && $ts < $this->starts_ts ) {
			$season_start = $this->local( ( new DateTimeImmutable( '@' . $this->starts_ts ) ) );

			if ( $this->recurrence->matches( $season_start ) ) {
				return $season_start;
			}

			$from = $season_start;
		}

		if ( $this->recurrence->isNone() ) {
			return null;
		}

		$next = $this->recurrence->nextStart( $from );

		if ( null === $next || ( null !== $this->ends_ts && $next->getTimestamp() >= $this->ends_ts ) ) {
			return null;
		}

		return $next;
	}

	/**
	 * When the currently open window closes (the earlier of the recurrence window's end and the season
	 * end), or null when the campaign is not active now or has no end. Used for honest countdowns.
	 */
	public function currentEnd( DateTimeImmutable $now ): ?DateTimeImmutable {
		if ( ! $this->isActiveAt( $now ) ) {
			return null;
		}

		$end = null;

		if ( ! $this->recurrence->isNone() ) {
			$window = $this->recurrence->windowAt( $this->local( $now ) );
			$end    = null === $window ? null : $window[1];
		}

		if ( null !== $this->ends_ts && ( null === $end || $this->ends_ts < $end->getTimestamp() ) ) {
			$end = $this->local( new DateTimeImmutable( '@' . $this->ends_ts ) );
		}

		return $end;
	}

	/** The moment in the store's zone. */
	private function local( DateTimeImmutable $moment ): DateTimeImmutable {
		return $moment->setTimezone( $this->timezone );
	}

	/**
	 * @param mixed $raw
	 */
	private static function timestamp( $raw ): ?int {
		if ( is_int( $raw ) ) {
			return $raw;
		}

		return is_string( $raw ) && 1 === preg_match( '/^-?\d+$/', trim( $raw ) ) ? (int) trim( $raw ) : null;
	}
}
