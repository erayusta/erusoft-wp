<?php
/**
 * Plugin Name: HTF — AutomateWoo Redis Flush
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Makes AutomateWoo group flushes use a version counter instead of a full Redis key-space SCAN.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: AutomateWoo calls Cache::flush_group( 'dashboard' ) on every cart/customer
 * change. With the Redis Object Cache drop-in, wp_cache_flush_group() runs a Lua
 * SCAN ... MATCH <salt>* over the whole key space. On a database with a million keys this
 * takes over a second. Redis is single-threaded, so for that time the whole site
 * (object cache and PHP sessions) is blocked; PHP workers pile up and MySQL connections
 * sit idle.
 *
 * FIX: AutomateWoo has its own escape hatch. When 'automatewoo_cache_supports_flush_group'
 * returns false, the plugin uses invalidate_cache_group() instead: a single wp_cache_set()
 * refreshes the group version prefix, old keys become unreachable and expire through
 * their TTL. Same behaviour, O(1) instead of O(number of keys).
 *
 * Does nothing when AutomateWoo is not installed (the filter is simply never applied).
 *
 * Disable: add_filter( 'eru_htf_automatewoo_redis_flush_enabled', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'automatewoo_cache_supports_flush_group',
	static function ( $supports ) {
		if ( ! apply_filters( 'eru_htf_automatewoo_redis_flush_enabled', true ) ) {
			return $supports;
		}

		return false;
	}
);
