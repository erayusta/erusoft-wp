<?php
/**
 * Named locks stored as option rows, taken with INSERT IGNORE so two requests cannot both win.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * add_option() is not atomic, so the lock row is inserted directly. A lock older than its TTL is stale
 * (a crashed worker) and may be taken over.
 */
class Eru_PPT_Lock {

	/** @param string $name Lock name. @return string Option name. */
	public static function option( $name ) {
		return 'eru_ppt_lock_' . $name;
	}

	/**
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which the lock is stale.
	 * @return bool Acquired.
	 */
	public static function acquire( $name, $ttl = 300 ) {
		global $wpdb;
		$opt = self::option( $name );
		if ( self::insert( $opt ) ) {
			return true;
		}
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $held && $held > time() - $ttl ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $opt, (string) $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush( $opt );
		return self::insert( $opt );
	}

	/** @param string $name Lock name. Extends a held lock (long runs). */
	public static function refresh( $name ) {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) time() ), array( 'option_name' => self::option( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush( self::option( $name ) );
	}

	/** @param string $name Lock name. */
	public static function release( $name ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::option( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush( self::option( $name ) );
	}

	/** @param string $opt Option. @return bool Inserted. */
	private static function insert( $opt ) {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush( $opt );
		return 1 === $n;
	}

	/** @param string $opt Option. */
	private static function flush( $opt ) {
		wp_cache_delete( $opt, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
