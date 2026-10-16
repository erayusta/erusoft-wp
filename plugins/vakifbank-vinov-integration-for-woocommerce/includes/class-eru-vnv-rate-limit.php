<?php
/**
 * Rate limit for the public OTP endpoints.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ten-minute windows (counted from the first request) keyed by bucket + id. The id is the client fingerprint
 * (a keyed hash of the IP; filter `eru_vnv_fingerprint` for a trusted proxy header) or the identity number's
 * HMAC, never the number itself. Counters live in the persistent object cache when there is one (atomic
 * add/incr), otherwise in one non-autoloaded option capped at MAX_KEYS entries (expired windows dropped first,
 * then the oldest), read and written under a MySQL named lock so parallel requests cannot lose increments.
 * No lock within 3 seconds → the request is refused.
 *
 * Per-identity counters (hit_isolated) do not share that capped map: each lives in its own expiring transient,
 * so floods from many fingerprints cannot evict a victim's counter. Expired ones go with WordPress's daily
 * expired-transient clean-up.
 */
class Eru_VNV_Rate_Limit {

	const WINDOW   = 600;
	const MAX_KEYS = 500;
	const OPTION   = 'eru_vnv_rl';
	const GROUP    = 'eru_vnv_rl';

	/** @return string Fingerprint of the current client. */
	public static function fingerprint() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$fp = hash_hmac( 'sha256', 'ip|' . $ip, wp_salt( 'nonce' ) );
		/**
		 * Filters the rate-limit fingerprint of the current request.
		 *
		 * @param string $fp Keyed hash of REMOTE_ADDR.
		 * @param string $ip REMOTE_ADDR.
		 */
		return substr( hash( 'sha256', (string) apply_filters( 'eru_vnv_fingerprint', $fp, $ip ) ), 0, 32 );
	}

	/**
	 * Counts one request.
	 *
	 * @param string $bucket Bucket (send, verify, send_id, verify_id).
	 * @param string $id     Fingerprint or identity HMAC.
	 * @param int    $limit  Requests per window.
	 * @return bool True while within the limit.
	 */
	public static function hit( $bucket, $id, $limit ) {
		global $wpdb;
		$key = sanitize_key( $bucket ) . ':' . substr( hash( 'sha256', (string) $id ), 0, 32 );
		if ( wp_using_ext_object_cache() ) {
			$slot = 'c_' . $key . '_' . (int) floor( time() / self::WINDOW );
			if ( false === wp_cache_add( $slot, 1, self::GROUP, self::WINDOW ) ) {
				$n = (int) wp_cache_incr( $slot, 1, self::GROUP );
			} else {
				$n = 1;
			}
			return $n <= (int) $limit;
		}
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $wpdb->prefix . self::OPTION ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		try {
			return self::count_in_option( $key ) <= (int) $limit;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Counts one request in a counter of its own (not in the capped map).
	 *
	 * @param string $bucket Bucket.
	 * @param string $id     Identity HMAC.
	 * @param int    $limit  Requests per window.
	 * @return bool True while within the limit.
	 */
	public static function hit_isolated( $bucket, $id, $limit ) {
		global $wpdb;
		if ( wp_using_ext_object_cache() ) {
			return self::hit( $bucket, $id, $limit );
		}
		$name = 'eru_vnv_rlt_' . substr( hash( 'sha256', sanitize_key( $bucket ) . '|' . (string) $id ), 0, 32 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $wpdb->prefix . self::OPTION ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return false;
		}
		try {
			wp_cache_delete( '_transient_' . $name, 'options' );
			wp_cache_delete( '_transient_timeout_' . $name, 'options' );
			$now = time();
			$cur = get_transient( $name );
			$cur = is_array( $cur ) && (int) ( $cur[1] ?? 0 ) > $now - self::WINDOW ? $cur : array( 0, $now );
			++$cur[0];
			set_transient( $name, array( (int) $cur[0], (int) $cur[1] ), max( 1, (int) $cur[1] + self::WINDOW - $now ) );
			return (int) $cur[0] <= (int) $limit;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/** @param string $key Counter key. @return int Count in the current window (called under the lock). */
	private static function count_in_option( $key ) {
		wp_cache_delete( self::OPTION, 'options' );
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
		global $wpdb;
		delete_option( self::OPTION );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eru\\_vnv\\_rlt\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eru\\_vnv\\_rlt\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}
	}
}
