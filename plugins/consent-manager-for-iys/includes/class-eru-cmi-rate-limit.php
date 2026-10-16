<?php
/**
 * Rate limit for the public consent routes (REST and the link pages).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 20 requests per 10 minutes per fingerprint. The fingerprint is a salted hash of the client IP (filter
 * `eru_cmi_fingerprint`, e.g. to use a trusted proxy header). Counters live in the persistent object cache
 * when there is one (atomic add/incr), otherwise in one non-autoloaded option capped at MAX_KEYS entries:
 * expired windows are dropped first, then the oldest, so arbitrary input can never grow it. The option is
 * read and written under a MySQL named lock, so parallel requests cannot lose increments; when the lock is
 * not obtained within 3 seconds the request is refused.
 *
 * Buckets keep separate counters: "public" (REST and link-page POSTs, 20 per window) and "mail" (confirmation
 * mails a client can trigger at checkout, registration or in Hesabım, MAIL_LIMIT per window).
 */
class Eru_CMI_Rate_Limit {

	const LIMIT    = 20;
	const WINDOW   = 600;
	const MAX_KEYS = 500;
	const OPTION   = 'eru_cmi_rl';
	const GROUP    = 'eru_cmi_rl';
	const MAIL_LIMIT = 10;

	/** @return string Fingerprint of the current client. */
	public static function fingerprint() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$fp = Eru_CMI_Recipient::hash_value( 'ip:' . $ip );
		/**
		 * Filters the rate-limit fingerprint of the current request.
		 *
		 * @param string $fp Salted hash of REMOTE_ADDR.
		 * @param string $ip REMOTE_ADDR.
		 */
		return substr( hash( 'sha256', (string) apply_filters( 'eru_cmi_fingerprint', $fp, $ip ) ), 0, 32 );
	}

	/**
	 * Counts one request.
	 *
	 * @param string   $bucket public | mail.
	 * @param int|null $limit  Requests per window (default LIMIT, MAIL_LIMIT for "mail").
	 * @return bool True while the client is within the limit.
	 */
	public static function hit( $bucket = 'public', $limit = null ) {
		global $wpdb;
		$limit = null === $limit ? ( 'mail' === $bucket ? self::MAIL_LIMIT : self::LIMIT ) : (int) $limit;
		$key   = ( 'public' === $bucket ? '' : sanitize_key( $bucket ) . ':' ) . self::fingerprint();
		if ( wp_using_ext_object_cache() ) {
			$slot = 'c_' . $key . '_' . (int) floor( time() / self::WINDOW );
			if ( false === wp_cache_add( $slot, 1, self::GROUP, self::WINDOW ) ) {
				$n = (int) wp_cache_incr( $slot, 1, self::GROUP );
			} else {
				$n = 1;
			}
			return $n <= $limit;
		}
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $wpdb->prefix . 'eru_cmi_rl' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		try {
			return self::count_in_option( $key ) <= $limit;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'eru_cmi_rl' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** @param string $key Counter key. @return int Count in the current window (called under the lock). */
	private static function count_in_option( $key ) {
		wp_cache_delete( self::OPTION, 'options' ); // Read the stored value, not this request's cached copy.
		$now = time();
		$map = get_option( self::OPTION, array() );
		$map = is_array( $map ) ? $map : array();
		$map = array_filter( $map, fn( $v ) => is_array( $v ) && (int) ( $v[1] ?? 0 ) > $now - self::WINDOW );
		$cur = $map[ $key ] ?? array( 0, $now );
		++$cur[0];
		$map[ $key ] = $cur;
		if ( count( $map ) > self::MAX_KEYS ) {
			uasort( $map, fn( $a, $b ) => (int) $a[1] <=> (int) $b[1] );
			$map = array_slice( $map, -self::MAX_KEYS, null, true );
		}
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $map, '', false );
		} else {
			update_option( self::OPTION, $map, false );
		}
		return (int) $cur[0];
	}

	/** Clears the counters (tests, uninstall). */
	public static function reset() {
		delete_option( self::OPTION );
		if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}
}
