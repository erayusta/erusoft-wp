<?php
/**
 * Atomic lock on a unique option row with an owner token.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * INSERT IGNORE on the unique option name, so only one request wins (add_option() would upsert when the
 * request's "notoptions" cache is stale). The value is "time|token"; a lock older than its TTL may be taken
 * over, and only the owner's token releases it.
 */
class Eru_VNV_Lock {

	/** @param string $key Lock key. @return string Option name. */
	public static function option( $key ) {
		return 'eru_vnv_lock_' . sanitize_key( $key );
	}

	/**
	 * @param string $key Lock key.
	 * @param int    $ttl Seconds after which a held lock counts as stale.
	 * @return string|false Owner token, or false when held by someone else.
	 */
	public static function acquire( $key, $ttl = 120 ) {
		global $wpdb;
		$name  = self::option( $key );
		$token = wp_generate_password( 16, false );
		$won   = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, time() . '|' . $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 === $won ) {
			return $token;
		}
		$cur = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( '' !== $cur && (int) strtok( $cur, '|' ) < time() - $ttl ) {
			$took = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", time() . '|' . $token, $name, $cur ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $name, 'options' );
			return 1 === $took ? $token : false;
		}
		return false;
	}

	/** @param string $key Lock key. @param string $token Owner token. */
	public static function release( $key, $token ) {
		global $wpdb;
		$name = self::option( $key );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $name, '%|' . $wpdb->esc_like( (string) $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
