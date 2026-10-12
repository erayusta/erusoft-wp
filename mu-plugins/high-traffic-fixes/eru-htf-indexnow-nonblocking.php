<?php
/**
 * Plugin Name: HTF — IndexNow Non-Blocking
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Sends IndexNow pings (e.g. from Rank Math Instant Indexing) without waiting for the response.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: SEO plugins with an instant-indexing module notify search engines through
 * wp_remote_post() to the IndexNow endpoint. WordPress waits for the response by default,
 * so when the external service is slow the PHP worker is held for that long. On busy sites
 * these calls show up in the PHP-FPM slow log (curl_exec).
 *
 * FIX: only requests to api.indexnow.org are sent with 'blocking' => false (fire and
 * forget; IndexNow's response body carries no useful information) and a short timeout so
 * even the connection phase cannot hang.
 *
 * TRADE-OFF: the plugin's own "history" screen can no longer see the response and may not
 * mark a submission as confirmed. The ping is still sent. If you rely on that screen for
 * auditing, keep only the timeout:
 *   add_filter( 'eru_htf_indexnow_nonblocking_blocking', '__return_false' );
 *
 * CONFIGURATION
 *   - Disable:  add_filter( 'eru_htf_indexnow_nonblocking_enabled', '__return_false' );
 *   - Timeout:  define( 'ERU_HTF_INDEXNOW_TIMEOUT', 3 );  // seconds, default 2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'http_request_args',
	static function ( $args, $url ) {
		if ( false === stripos( (string) $url, 'api.indexnow.org' ) ) {
			return $args;
		}

		if ( ! apply_filters( 'eru_htf_indexnow_nonblocking_enabled', true ) ) {
			return $args;
		}

		if ( apply_filters( 'eru_htf_indexnow_nonblocking_blocking', true ) ) {
			$args['blocking'] = false;
		}

		$timeout         = defined( 'ERU_HTF_INDEXNOW_TIMEOUT' ) ? (int) ERU_HTF_INDEXNOW_TIMEOUT : 2;
		$args['timeout'] = max( 1, $timeout );

		return $args;
	},
	10,
	2
);
