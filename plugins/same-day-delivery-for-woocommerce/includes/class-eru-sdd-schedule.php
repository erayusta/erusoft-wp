<?php
/** Cut-off / working-day / holiday logic. All times are in the site timezone (wp_timezone()). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SDD_Schedule {

	const OPTION = 'eru_sdd_settings';

	public static function defaults(): array {
		return array(
			'show_messages' => 0,
			'cutoff'        => '14:00',
			'working_days'  => array( 1, 2, 3, 4, 5 ), // ISO: 1 = Monday.
			'holidays'      => '',
			'locations'     => '',
			'msg_today'     => '',
			'msg_tomorrow'  => '',
			'msg_later'     => '',
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	/** Current time in the site timezone; tests override with the `eru_sdd_now` filter (DateTimeInterface or timestamp). */
	public static function now(): DateTimeImmutable {
		$now = apply_filters( 'eru_sdd_now', new DateTimeImmutable( 'now', wp_timezone() ) );
		if ( is_int( $now ) ) {
			$now = ( new DateTimeImmutable( '@' . $now ) );
		}
		if ( ! $now instanceof DateTimeInterface ) {
			$now = new DateTimeImmutable( 'now', wp_timezone() );
		}
		return DateTimeImmutable::createFromInterface( $now )->setTimezone( wp_timezone() );
	}

	/** @return array{0:int,1:int} hour, minute */
	public static function cutoff_parts(): array {
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', (string) self::settings()['cutoff'], $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		return array( 14, 0 );
	}

	/** @return string[] Y-m-d (one-off) or m-d (every year) */
	public static function holidays(): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::settings()['holidays'] ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^(\d{4}-)?\d{2}-\d{2}$/', $line ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function is_working_day( DateTimeInterface $d ): bool {
		$days = array_map( 'intval', (array) self::settings()['working_days'] );
		if ( ! in_array( (int) $d->format( 'N' ), $days, true ) ) {
			return false;
		}
		$h = self::holidays();
		return ! ( in_array( $d->format( 'Y-m-d' ), $h, true ) || in_array( $d->format( 'm-d' ), $h, true ) );
	}

	/** The day an order placed right now is handed to the carrier, or null when no working day is configured. */
	public static function ship_date( ?DateTimeImmutable $now = null ): ?DateTimeImmutable {
		$now = $now ? $now : self::now();
		if ( ! array_filter( (array) self::settings()['working_days'] ) ) {
			return null;
		}
		list( $h, $m ) = self::cutoff_parts();
		$cut           = $now->setTime( $h, $m, 0 );
		if ( self::is_working_day( $now ) && $now < $cut ) {
			return $now->setTime( 0, 0, 0 );
		}
		$d = $now->setTime( 0, 0, 0 );
		for ( $i = 0; $i < 90; $i++ ) {
			$d = $d->modify( '+1 day' );
			if ( self::is_working_day( $d ) ) {
				return $d;
			}
		}
		return null;
	}

	/** True when orders placed now still ship today (the same-day method is offered). */
	public static function ships_today( ?DateTimeImmutable $now = null ): bool {
		$now  = $now ? $now : self::now();
		$date = self::ship_date( $now );
		return $date && $date->format( 'Y-m-d' ) === $now->format( 'Y-m-d' );
	}

	/** @return array{type:string,date:DateTimeImmutable,text:string}|null */
	public static function status( ?DateTimeImmutable $now = null ): ?array {
		$now  = $now ? $now : self::now();
		$date = self::ship_date( $now );
		if ( ! $date ) {
			return null;
		}
		$s    = self::settings();
		$diff = (int) $now->setTime( 0, 0, 0 )->diff( $date )->format( '%a' );
		list( $h, $m ) = self::cutoff_parts();
		$cutoff = sprintf( '%02d:%02d', $h, $m );
		if ( 0 === $diff ) {
			$type = 'today';
			$text = '' !== trim( $s['msg_today'] ) ? $s['msg_today'] : __( 'Bugün kargoda', 'same-day-delivery-for-woocommerce' );
		} elseif ( 1 === $diff ) {
			$type = 'tomorrow';
			$text = '' !== trim( $s['msg_tomorrow'] ) ? $s['msg_tomorrow'] : __( 'Yarın kargoda', 'same-day-delivery-for-woocommerce' );
		} else {
			$type = 'later';
			$tpl  = '' !== trim( $s['msg_later'] ) ? $s['msg_later'] : __( '%s kargoda', 'same-day-delivery-for-woocommerce' );
			$text = sprintf( $tpl, wp_date( 'l', $date->getTimestamp() ) );
		}
		return array( 'type' => $type, 'date' => $date, 'text' => str_replace( '{cutoff}', $cutoff, $text ) );
	}

	/* ---- destination eligibility ---- */

	public static function normalize( string $v ): string {
		$v = strtr( $v, array( 'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c' ) );
		return trim( mb_strtolower( $v ) );
	}

	/**
	 * Locations (settings): one per line, "Şehir" or "Şehir/İlçe". Empty = nowhere eligible.
	 *
	 * @param array $destination WooCommerce package destination (country, state, city, address_2 …).
	 */
	public static function destination_eligible( array $destination ): bool {
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) self::settings()['locations'] ) ) );
		if ( ! $lines ) {
			return (bool) apply_filters( 'eru_sdd_destination_eligible', false, $destination );
		}
		$country = (string) ( $destination['country'] ?? '' );
		$state   = (string) ( $destination['state'] ?? '' );
		$names   = array( self::normalize( (string) ( $destination['city'] ?? '' ) ) );
		if ( $country && $state ) {
			$states = WC()->countries->get_states( $country );
			if ( is_array( $states ) && isset( $states[ $state ] ) ) {
				$names[] = self::normalize( (string) $states[ $state ] );
			}
			$names[] = self::normalize( $state );
		}
		$districts = array( self::normalize( (string) ( $destination['city'] ?? '' ) ), self::normalize( (string) ( $destination['address_2'] ?? '' ) ) );
		$ok = false;
		foreach ( $lines as $line ) {
			$parts = array_map( 'trim', explode( '/', $line, 2 ) );
			$city  = self::normalize( $parts[0] );
			if ( isset( $parts[1] ) && '' !== $parts[1] ) {
				$dist = self::normalize( $parts[1] );
				if ( in_array( $city, $names, true ) && in_array( $dist, $districts, true ) ) {
					$ok = true;
					break;
				}
			} elseif ( in_array( $city, $names, true ) || in_array( $city, $districts, true ) ) {
				$ok = true;
				break;
			}
		}
		return (bool) apply_filters( 'eru_sdd_destination_eligible', $ok, $destination );
	}
}
