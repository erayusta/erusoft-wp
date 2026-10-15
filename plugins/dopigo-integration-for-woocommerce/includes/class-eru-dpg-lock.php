<?php
/**
 * Named locks stored as option rows, taken with INSERT IGNORE so two requests cannot both win.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * add_option() is not atomic (it uses ON DUPLICATE KEY UPDATE), so the lock row is inserted directly.
 */
class Eru_DPG_Lock {

	/** @param string $name Lock name (suffix). @return string Option name. */
	public static function option( $name ) {
		return 'eru_dpg_lock_' . $name;
	}

	/**
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds after which a lock is considered stale and taken over.
	 * @return bool Acquired.
	 */
	public static function acquire( $name, $ttl = 600 ) {
		global $wpdb;
		$opt = self::option( $name );
		if ( self::insert( $opt ) ) {
			return true;
		}
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) ); // phpcs:ignore
		if ( $held && $held > time() - $ttl ) {
			return false;
		}
		// Stale: remove only the row we saw, then race for a fresh insert.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $opt, (string) $held ) ); // phpcs:ignore
		self::flush( $opt );
		return self::insert( $opt );
	}

	/** @param string $name Lock name. */
	public static function refresh( $name ) {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) time() ), array( 'option_name' => self::option( $name ) ) ); // phpcs:ignore
		self::flush( self::option( $name ) );
	}

	/** @param string $name Lock name. */
	public static function release( $name ) {
		delete_option( self::option( $name ) );
	}

	/** @param string $opt Option. @return bool Inserted. */
	private static function insert( $opt ) {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, (string) time() ) ); // phpcs:ignore
		self::flush( $opt );
		return 1 === $n;
	}

	/** @param string $opt Option. */
	private static function flush( $opt ) {
		wp_cache_delete( $opt, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
