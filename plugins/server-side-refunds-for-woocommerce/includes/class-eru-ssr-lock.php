<?php
/**
 * Named, owner-checked locks stored as option rows, taken with INSERT IGNORE so two requests cannot both win.
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The row value is "<time>|<token>". Only the holder of the token may release the lock; another worker may
 * take it over only after its TTL has passed (a crashed worker), and then the old holder's release is a no-op.
 */
class Eru_SSR_Lock {

	/** @var array<string,string> Tokens held by this process. */
	private static $held = array();

	/** @param string $name Lock name. @return string Option name. */
	public static function option( $name ) {
		return 'eru_ssr_lock_' . $name;
	}

	/**
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which the lock is stale.
	 * @return string|false Owner token, or false when held by someone else.
	 */
	public static function acquire( $name, $ttl = 300 ) {
		global $wpdb;
		$opt   = self::option( $name );
		$token = wp_generate_password( 20, false );
		if ( self::insert( $opt, $token ) ) {
			self::$held[ $name ] = $token;
			return $token;
		}
		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$since = (int) strtok( $value, '|' );
		if ( '' !== $value && $since > time() - $ttl ) {
			return false;
		}
		// Stale: delete exactly the stale row (not one another worker just wrote), then race for the insert.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $opt, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush( $opt );
		if ( self::insert( $opt, $token ) ) {
			self::$held[ $name ] = $token;
			return $token;
		}
		return false;
	}

	/**
	 * Releases the lock only when the token matches.
	 *
	 * @param string      $name  Lock name.
	 * @param string|null $token Owner token (default: the one this process acquired).
	 */
	public static function release( $name, $token = null ) {
		global $wpdb;
		$token = null === $token ? ( self::$held[ $name ] ?? '' ) : (string) $token;
		if ( '' === $token ) {
			return;
		}
		$opt = self::option( $name );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $opt, '%|' . $wpdb->esc_like( $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ( self::$held[ $name ] ?? '' ) === $token ) {
			unset( self::$held[ $name ] );
		}
		self::flush( $opt );
	}

	/**
	 * Moves the lock's timestamp forward while the owner is still working, so a long batch is not taken over as stale.
	 *
	 * @param string $name  Lock name.
	 * @param string $token Owner token.
	 */
	public static function refresh( $name, $token ) {
		global $wpdb;
		if ( '' === (string) $token ) {
			return;
		}
		$opt = self::option( $name );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", time() . '|' . $token, $opt, '%|' . $wpdb->esc_like( (string) $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush( $opt );
	}

	/** @param string $opt Option. @param string $token Token. @return bool Inserted. */
	private static function insert( $opt, $token ) {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, time() . '|' . $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush( $opt );
		return 1 === $n;
	}

	/** @param string $opt Option. */
	private static function flush( $opt ) {
		wp_cache_delete( $opt, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
