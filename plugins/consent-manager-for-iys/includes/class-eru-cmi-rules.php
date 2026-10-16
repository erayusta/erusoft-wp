<?php
/**
 * İYS business rules as pure functions (no database, no clock unless passed in).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * - H175: İYS refuses a RET as the first record of a recipient. A RET is sent only when İYS is known to
 *   hold a record for that recipient and channel (accepted earlier, or an earlier record still in flight).
 *   TACIR (trader) recipients may be messaged without prior consent and may only opt out, so a first
 *   TACIR RET is a valid record and is sent.
 * - H467: İYS refuses a consent dated more than 3 business days ago. Business days are Monday–Friday in
 *   Turkish time minus the dates returned by the `eru_cmi_holidays` filter. The deadline is the same time of
 *   day 3 business days after the consent; a record counts as aged 6 hours before it (safety margin, since
 *   whether İYS counts by day or by instant is not documented). An aged opt-out (RET) is always sent with the
 *   current time: a later withdrawal date can never harm the recipient, and İYS must not keep an ONAY the
 *   person withdrew. An aged ONAY is skipped, or, when the "re-stamp" setting is on and the change was made
 *   on this site, sent with the current time after a look-up at İYS (see Eru_CMI_Queue). The stored consent
 *   date is never changed: it stays as the evidence.
 * - İYS answers with codes per record: H194 (already registered) counts as accepted; H175 and H178 (a newer
 *   record exists at İYS) mean "nothing to do" and are marked skipped; anything else is a rejection.
 */
class Eru_CMI_Rules {

	const MAX_BUSINESS_DAYS = 3;
	const TZ                = 'Europe/Istanbul';

	/** A record counts as aged this long before the H467 deadline. */
	const SAFETY_MARGIN = 6 * HOUR_IN_SECONDS;

	/** Sources İYS accepts. */
	const IYS_SOURCES = array( 'HS_FIZIKSEL_ORTAM', 'HS_ISLAK_IMZA', 'HS_WEB', 'HS_CAGRI_MERKEZI', 'HS_SOSYAL_MEDYA', 'HS_EPOSTA', 'HS_MESAJ', 'HS_MOBIL', 'HS_EORTAM', 'HS_ETKINLIK', 'HS_2015', 'HS_ATM', 'HS_KARAR' );

	/** Actors whose changes were made on this site (eligible for re-stamping). */
	const LOCAL_ACTORS = array( 'user', 'admin', 'api' );

	/** @return DateTimeZone İYS time zone. */
	public static function tz() {
		return new DateTimeZone( self::TZ );
	}

	/** @return string[] Public holidays as Y-m-d (filter `eru_cmi_holidays`). */
	public static function holidays() {
		$h = apply_filters( 'eru_cmi_holidays', array() );
		return array_values( array_filter( array_map( 'strval', is_array( $h ) ? $h : array() ), fn( $d ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) );
	}

	/**
	 * Whole business days from the consent's calendar date to now's calendar date (Turkish time); the
	 * consent day itself is not counted.
	 *
	 * @param int      $consent_ts Consent time (unix).
	 * @param int      $now        Now (unix).
	 * @param string[] $holidays   Y-m-d dates that are not business days.
	 * @return int
	 */
	public static function business_days_since( $consent_ts, $now, array $holidays ) {
		if ( $consent_ts >= $now ) {
			return 0;
		}
		$tz   = self::tz();
		$day  = ( new DateTimeImmutable( '@' . (int) $consent_ts ) )->setTimezone( $tz )->setTime( 0, 0 );
		$end  = ( new DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz )->setTime( 0, 0 );
		$hol  = array_flip( $holidays );
		$days = 0;
		for ( $i = 0; $day < $end && $i < 4000; $i++ ) {
			$day = $day->modify( '+1 day' );
			if ( (int) $day->format( 'N' ) <= 5 && ! isset( $hol[ $day->format( 'Y-m-d' ) ] ) ) {
				++$days;
			}
		}
		return $days;
	}

	/**
	 * H467 deadline: the same time of day (Turkish time) 3 business days after the consent.
	 *
	 * @param int      $consent_ts Consent time (unix).
	 * @param string[] $holidays   Y-m-d dates that are not business days.
	 * @return int Unix time.
	 */
	public static function deadline( $consent_ts, array $holidays ) {
		$t   = ( new DateTimeImmutable( '@' . (int) $consent_ts ) )->setTimezone( self::tz() );
		$hol = array_flip( $holidays );
		for ( $n = 0, $i = 0; $n < self::MAX_BUSINESS_DAYS && $i < 4000; $i++ ) {
			$t = $t->modify( '+1 day' );
			if ( (int) $t->format( 'N' ) <= 5 && ! isset( $hol[ $t->format( 'Y-m-d' ) ] ) ) {
				++$n;
			}
		}
		return $t->getTimestamp();
	}

	/**
	 * @param int      $consent_ts Consent time.
	 * @param int      $now        Now.
	 * @param string[] $holidays   Holidays.
	 * @return bool Aged for H467: now is within the safety margin of the deadline, or past it.
	 */
	public static function is_too_old( $consent_ts, $now, array $holidays ) {
		return (int) $now > self::deadline( $consent_ts, $holidays ) - self::SAFETY_MARGIN;
	}

	/**
	 * Decides whether a record goes to İYS.
	 *
	 * @param array $record status, recipient_type, consent_ts.
	 * @param array $opts   known (bool: İYS holds or will hold a record), now (int), restamp (bool: re-stamp
	 *                      aged ONAYs), local (bool: changed on this site), holidays (string[]).
	 * @return array{send:bool,code:string,reason:string,consent_ts:int,restamped:bool}
	 */
	public static function evaluate( array $record, array $opts ) {
		$ts  = (int) ( $record['consent_ts'] ?? 0 );
		$now = (int) ( $opts['now'] ?? time() );
		$out = array(
			'send'       => true,
			'code'       => '',
			'reason'     => '',
			'consent_ts' => $ts,
			'restamped'  => false,
		);
		if ( 'RET' === ( $record['status'] ?? '' ) && 'TACIR' !== ( $record['recipient_type'] ?? '' ) && empty( $opts['known'] ) ) {
			$out['send']   = false;
			$out['code']   = 'H175';
			$out['reason'] = 'H175: ' . __( 'İYS\'de bu alıcı için önceki bir kayıt yok; ilk kayıt RET olamaz. Yerel olarak saklandı, gönderilmedi.', 'consent-manager-for-iys' );
			return $out;
		}
		if ( self::is_too_old( $ts, $now, (array) ( $opts['holidays'] ?? array() ) ) ) {
			// An opt-out always goes (dated now); an ONAY only with the setting, for a change made here.
			if ( 'RET' === ( $record['status'] ?? '' ) || ( ! empty( $opts['restamp'] ) && ! empty( $opts['local'] ) ) ) {
				$out['consent_ts'] = $now;
				$out['restamped']  = true;
				return $out;
			}
			$out['send']   = false;
			$out['code']   = 'H467';
			$out['reason'] = 'H467: ' . __( 'İzin tarihi 3 iş gününden eski; İYS kabul etmez.', 'consent-manager-for-iys' );
		}
		return $out;
	}

	/**
	 * Recipient type: an explicit valid value wins; otherwise TACIR when the setting is on and a company
	 * name is given.
	 *
	 * @param string $explicit    BIREYSEL | TACIR | ''.
	 * @param string $company     Billing company.
	 * @param bool   $use_company Setting "TACIR when the company field is filled".
	 * @return string
	 */
	public static function recipient_type( $explicit, $company, $use_company ) {
		$explicit = strtoupper( trim( (string) $explicit ) );
		if ( in_array( $explicit, Eru_CMI_Recipient::RECIPIENT_TYPES, true ) ) {
			return $explicit;
		}
		return $use_company && '' !== trim( (string) $company ) ? 'TACIR' : 'BIREYSEL';
	}

	/** @param string $source Requested İYS source. @return string A value İYS accepts (default HS_WEB). */
	public static function iys_source( $source ) {
		$source = strtoupper( trim( (string) $source ) );
		return in_array( $source, self::IYS_SOURCES, true ) ? $source : 'HS_WEB';
	}

	/** @param string $actor Actor. @return bool Changed on this site. */
	public static function is_local( $actor ) {
		return in_array( $actor, self::LOCAL_ACTORS, true );
	}

	/**
	 * Maps an İYS per-record error code to a queue status.
	 *
	 * @param string $code İYS code (e.g. H120).
	 * @return string accepted | skipped | rejected
	 */
	public static function classify_code( $code ) {
		$code = strtoupper( (string) $code );
		if ( 'H194' === $code ) {
			return 'accepted';
		}
		if ( in_array( $code, array( 'H175', 'H178' ), true ) ) {
			return 'skipped';
		}
		return 'rejected';
	}

	/** @param int $ts Unix time. @return string İYS date format (Turkish time, no zone). */
	public static function iys_date( $ts ) {
		return ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( self::tz() )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Parses a consent date: int = unix time; string without a zone = Turkish time.
	 *
	 * @param mixed $date Date.
	 * @return int|null Unix time, or null when unparsable.
	 */
	public static function parse_date( $date ) {
		if ( is_int( $date ) || ( is_string( $date ) && ctype_digit( $date ) ) ) {
			return (int) $date;
		}
		if ( ! is_string( $date ) || '' === trim( $date ) ) {
			return null;
		}
		try {
			return ( new DateTimeImmutable( trim( $date ), self::tz() ) )->getTimestamp();
		} catch ( Exception $e ) {
			return null;
		}
	}
}
