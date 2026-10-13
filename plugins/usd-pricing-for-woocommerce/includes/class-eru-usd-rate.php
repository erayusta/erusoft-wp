<?php
/**
 * Exchange-rate source: manual (default) or the TCMB daily XML (opt-in, cached 6 hours).
 * The TCMB download never runs inside a price filter: an expired cache schedules a WP-Cron
 * refresh and the last known rate is used until it lands.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_USD_Rate {

	const TCMB_URL  = 'https://www.tcmb.gov.tr/kurlar/today.xml';
	const CACHE_KEY = 'eru_usd_tcmb_rate';
	const BACKOFF   = 'eru_usd_tcmb_backoff';
	const LAST      = 'eru_usd_tcmb_last';
	const CRON      = 'eru_usd_refresh_rate';

	public static function source(): string {
		return 'tcmb' === get_option( 'eru_usd_source', 'manual' ) ? 'tcmb' : 'manual';
	}

	public static function markup(): float {
		return max( 0.0, (float) get_option( 'eru_usd_markup', 0 ) );
	}

	/** Rate before markup; 0 when unavailable. */
	public static function base(): float {
		if ( 'manual' === self::source() ) {
			return max( 0.0, (float) get_option( 'eru_usd_manual_rate', 0 ) );
		}
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && (float) $cached > 0 ) {
			return (float) $cached;
		}
		if ( ! get_transient( self::BACKOFF ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time(), self::CRON );
		}
		$last = get_option( self::LAST, array() );
		return is_array( $last ) && ! empty( $last['rate'] ) ? (float) $last['rate'] : 0.0;
	}

	/** Rate used for pricing (markup included); 0 means "inert". */
	public static function effective(): float {
		$base = self::base();
		return $base > 0 ? round( $base * ( 1 + self::markup() / 100 ), 4 ) : 0.0;
	}

	/** WP-Cron: refresh the TCMB rate; on failure wait 15 minutes before the next try. */
	public static function cron_refresh(): void {
		if ( 'tcmb' !== self::source() || false !== get_transient( self::CACHE_KEY ) && (float) get_transient( self::CACHE_KEY ) > 0 ) {
			return;
		}
		if ( self::fetch() <= 0 ) {
			set_transient( self::BACKOFF, 1, 15 * MINUTE_IN_SECONDS );
		}
	}

	/** Download, parse and cache the TCMB rate. Returns 0 on failure. */
	public static function fetch(): float {
		$response = wp_remote_get( self::TCMB_URL, array( 'timeout' => 8, 'redirection' => 2, 'limit_response_size' => 1048576 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 0.0;
		}
		$rate = self::parse_tcmb( (string) wp_remote_retrieve_body( $response ) );
		if ( $rate <= 0 ) {
			return 0.0;
		}
		set_transient( self::CACHE_KEY, $rate, 6 * HOUR_IN_SECONDS );
		delete_transient( self::BACKOFF );
		$last = get_option( self::LAST, array() );
		update_option( self::LAST, array( 'rate' => $rate, 'time' => time() ), false );
		if ( ! is_array( $last ) || empty( $last['rate'] ) || abs( (float) $last['rate'] - $rate ) > 0.00005 ) {
			do_action( 'eru_usd_rate_changed', $rate );
		}
		return $rate;
	}

	/** USD ForexSelling from the TCMB today.xml body; 0 when missing or malformed. */
	public static function parse_tcmb( string $body ): float {
		if ( '' === $body ) {
			return 0.0;
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $xml || empty( $xml->Currency ) ) {
			return 0.0;
		}
		foreach ( $xml->Currency as $currency ) {
			if ( 'USD' === (string) $currency['CurrencyCode'] ) {
				return max( 0.0, (float) str_replace( ',', '.', trim( (string) $currency->ForexSelling ) ) );
			}
		}
		return 0.0;
	}
}
