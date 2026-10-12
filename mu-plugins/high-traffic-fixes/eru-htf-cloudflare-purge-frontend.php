<?php
/**
 * Plugin Name: HTF — Cloudflare Purge Frontend
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Blocks synchronous Cloudflare API purge calls on front-end and REST requests. Admin, cron and WP-CLI are unaffected.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: clearing a post's page cache (for example when checkout reduces stock and the
 * product cache is cleaned) makes WP Rocket purge Cloudflare synchronously:
 *
 *   clean_post_cache()
 *     -> rocket_clean_post()
 *       -> rocket_clean_files() / rocket_clean_home()
 *         -> purge_cloudflare_partial() / purge_cloudflare_home()
 *           -> a first API request just to READ a zone setting
 *           -> a second API request for the purge itself
 *
 * The customer waits for these network calls on the checkout / thank-you page; a PHP-FPM
 * slow log entry can show a single order page taking over ten seconds.
 *
 * WHY HOOKS ARE NOT REMOVED (ONLY): Cloudflare purging is triggered from eight different
 * hooks (WP Rocket's third-party integration plus the Cloudflare plugin's own
 * transition_post_status callback). Most of those hooks also carry non-Cloudflare
 * listeners (preload, lazyload CSS, font cleanup, purge engine), so remove_all_actions()
 * would risk breaking WP Rocket.
 *
 * FIX - A CHOKE POINT: every path ends in one HTTP request to the Cloudflare API. It is
 * short-circuited with 'pre_http_request' (layer 1). The expensive part happens BEFORE the
 * network (url_to_postid() for each URL runs a WP_Query), so layer 2 removes only the
 * Cloudflare subscribers (matched by class name) from WP Rocket's hooks, and layer 3
 * removes the Cloudflare plugin's transition_post_status callback. Everything else in WP
 * Rocket keeps working.
 *
 * TRADE-OFF: a page changed from the front end (an order, an integration) can stay stale
 * at the edge until its edge TTL expires. If your edge TTL for HTML is short, that is the
 * upper bound anyway. Admin and cron purge exactly as before, so an editor's update shows
 * immediately.
 *
 * EXEMPT (purge works as usual): admin and admin-ajax, cron, WP-CLI, and REST requests
 * made by logged-in users who can edit_posts (block editor, WooCommerce admin and plugin
 * REST saves). Layers 2 and 3 additionally skip every REST URI, because the user is not
 * known yet when they run; layer 1 still applies to REST requests from other users.
 *
 * Disable: add_filter( 'eru_htf_cloudflare_purge_frontend_enabled', '__return_false' );
 *          (or delete the file). Layer 3 reads the filter at plugins_loaded, so register
 *          the filter in a mu-plugin (a theme or regular plugin loads too late for it).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Does the current request look like a REST request? Usable before REST_REQUEST is
 * defined (it is only defined after wp_loaded).
 *
 * @return bool
 */
function eru_htf_cf_purge_is_rest_uri() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	return 0 === strpos( $uri, '/wp-json' ) || isset( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

/**
 * Should this request be left alone (purge works normally)? True for the admin
 * (including admin-ajax), cron, WP-CLI, REST requests from logged-in users who can
 * edit posts (block editor, WooCommerce admin, plugin REST saves), and when the fix is
 * switched off. Evaluated at call time.
 *
 * @return bool
 */
function eru_htf_cf_purge_is_exempt_context() {
	if ( is_admin()
		|| wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ! apply_filters( 'eru_htf_cloudflare_purge_frontend_enabled', true ) ) {
		return true;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST
		&& function_exists( 'is_user_logged_in' ) && is_user_logged_in()
		&& current_user_can( 'edit_posts' ) ) {
		return true;
	}

	return false;
}

/**
 * Layer 1: short-circuit HTTP requests to the Cloudflare API on the front end.
 *
 * A well-formed empty success response is returned so callers do not enter their error
 * path and write a log line on every request. The purge did not happen, so the content
 * of the result does not matter.
 */
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt; // Another integration already short-circuited.
		}

		if ( eru_htf_cf_purge_is_exempt_context() ) {
			return $preempt;
		}

		if ( false === strpos( (string) $url, 'api.cloudflare.com' ) ) {
			return $preempt;
		}

		return array(
			'headers'       => array(),
			'body'          => wp_json_encode(
				array(
					'success'  => true,
					'errors'   => array(),
					'messages' => array(),
					'result'   => null,
				)
			),
			'response'      => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'       => array(),
			'http_response' => null,
		);
	},
	10,
	3
);

/**
 * Layer 2: remove WP Rocket's Cloudflare subscribers from its hooks on the front end.
 *
 * Only subscribers whose object class is listed below are removed; other listeners on
 * the same hooks are untouched.
 */
add_action(
	'wp_loaded',
	static function () {
		if ( eru_htf_cf_purge_is_exempt_context() || eru_htf_cf_purge_is_rest_uri() ) {
			return;
		}

		global $wp_filter;

		$target_classes = array(
			'WP_Rocket\\ThirdParty\\Plugins\\CDN\\Cloudflare',
			'WP_Rocket\\Addon\\Cloudflare\\Subscriber',
		);

		$hooks = array(
			'after_rocket_clean_files',
			'after_rocket_clean_home',
			'after_rocket_clean_post',
			'rocket_after_clean_domain',
			'rocket_after_automatic_cache_purge',
			'rocket_saas_complete_job_status',
			'rocket_rucss_after_clearing_usedcss',
		);

		foreach ( $hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$fn = $callback['function'];

					if ( ! is_array( $fn ) || ! isset( $fn[0] ) || ! is_object( $fn[0] ) ) {
						continue;
					}

					if ( in_array( get_class( $fn[0] ), $target_classes, true ) ) {
						remove_action( $hook, $fn, $priority );
					}
				}
			}
		}
	},
	PHP_INT_MAX
);

/**
 * Layer 3: the Cloudflare plugin's own transition_post_status callback.
 *
 * Removing this single callback also skips the post/URL preparation work. It targets one
 * callback only, so there is no remove_all_actions() side effect.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( eru_htf_cf_purge_is_exempt_context() || eru_htf_cf_purge_is_rest_uri() ) {
			return;
		}

		global $cloudflareHooks;

		if ( ! isset( $cloudflareHooks ) || ! is_object( $cloudflareHooks ) ) {
			return;
		}

		remove_action(
			'transition_post_status',
			array( $cloudflareHooks, 'purgeCacheOnPostStatusChange' ),
			PHP_INT_MAX
		);
	},
	PHP_INT_MAX
);
