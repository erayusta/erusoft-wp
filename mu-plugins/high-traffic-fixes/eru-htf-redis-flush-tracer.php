<?php
/**
 * Plugin Name: HTF — Redis Flush Tracer
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Logs the caller and request whenever the Redis object cache is fully flushed. Changes no behaviour.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * WHY: when no selective flush is configured (WP_REDIS_SELECTIVE_FLUSH), the Redis Object
 * Cache drop-in falls back to a raw FLUSHDB on wp_cache_flush(): every key of the site is
 * deleted in one command, blocking Redis for a fraction of a second to seconds on a large
 * database, followed by a cold-cache storm. The Redis slowlog tells you THAT it happens,
 * not WHO triggers it (a permalink watcher, an analytics job, a plugin update, ...).
 *
 * HOW: when the drop-in finishes a flush it runs
 *     do_action( 'redis_object_cache_flush', $results, $delay, $selective, $salt, $execute_time );
 * This file only listens to that action. It runs after the flush is complete, so it never
 * blocks, delays or alters anything. For each flush it writes the context (admin / cron /
 * ajax / rest / wp-cli / front end), request method and URI path (the query string is never logged), the current user ID and a
 * trimmed backtrace. No IP address is logged.
 *
 * WHERE: by default ONE LEVEL ABOVE the web root (a log inside the document root could be
 * downloadable). Override with define( 'ERU_HTF_REDIS_FLUSH_TRACER_LOG', '/path/file.log' );
 * in wp-config.php. Logging stops by itself once the file exceeds
 * ERU_HTF_REDIS_FLUSH_TRACER_MAX bytes (default 5 MB). If the target is not writable,
 * nothing is logged.
 *
 * Does nothing unless the Redis Object Cache drop-in is installed.
 *
 * Disable: add_filter( 'eru_htf_redis_flush_tracer_enabled', '__return_false' );
 * Removal: delete this file and the log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ERU_HTF_REDIS_FLUSH_TRACER_LOG' ) ) {
	define( 'ERU_HTF_REDIS_FLUSH_TRACER_LOG', dirname( untrailingslashit( ABSPATH ) ) . '/eru-htf-redis-flush.log' );
}

/** Upper size limit (bytes) the log may grow to. */
if ( ! defined( 'ERU_HTF_REDIS_FLUSH_TRACER_MAX' ) ) {
	define( 'ERU_HTF_REDIS_FLUSH_TRACER_MAX', 5 * 1024 * 1024 );
}

add_action(
	'redis_object_cache_flush',
	static function ( $results = null, $delay = 0, $selective = false, $salt = '', $execute_time = 0 ) {
		if ( ! apply_filters( 'eru_htf_redis_flush_tracer_enabled', true ) ) {
			return;
		}

		$log = ERU_HTF_REDIS_FLUSH_TRACER_LOG;

		if ( file_exists( $log ) && filesize( $log ) > ERU_HTF_REDIS_FLUSH_TRACER_MAX ) {
			return;
		}

		if ( ! is_writable( file_exists( $log ) ? $log : dirname( $log ) ) ) {
			return;
		}

		// Call chain. The first frames are this hook and the object-cache layer; the real
		// caller is right after them.
		$trace = function_exists( 'wp_debug_backtrace_summary' )
			? wp_debug_backtrace_summary( null, 0, false )
			: array( '(no backtrace)' );

		$trace = is_array( $trace ) ? array_slice( $trace, 0, 30 ) : array( (string) $trace );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$context = 'wp-cli';
		} elseif ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$context = 'cron';
		} elseif ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$context = 'ajax';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$context = 'rest';
		} elseif ( is_admin() ) {
			$context = 'admin';
		} else {
			$context = 'frontend';
		}

		$line = sprintf(
			"===== %s | %s | %.3f s =====\n%s %s\nuser=%s\n  %s\n\n",
			function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s' ),
			$context,
			(float) $execute_time,
			isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '-',
			isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) ) : '-',
			function_exists( 'get_current_user_id' ) ? get_current_user_id() : '?',
			implode( "\n  ", $trace )
		);

		error_log( $line, 3, $log ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	},
	10,
	5
);
