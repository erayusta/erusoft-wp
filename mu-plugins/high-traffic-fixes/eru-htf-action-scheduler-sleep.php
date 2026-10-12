<?php
/**
 * Plugin Name: HTF — Action Scheduler Sleep
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Shortens the pause between Action Scheduler async queue runs so PHP workers are not held for 5 seconds.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: after every queue batch, the Action Scheduler async runner
 * (ActionScheduler_AsyncRequest_QueueRunner::handle) sleeps for 5 seconds. During that
 * time a PHP-FPM worker is occupied doing nothing. On busy sites this shows up in the
 * slow log as many requests longer than 3 seconds. (Sites often bundle several copies of
 * Action Scheduler; the highest version wins, so the effective copy can live in any plugin.)
 *
 * This is NOT a CPU fix: a sleeping process burns no CPU. The gain is worker slots, i.e.
 * concurrency headroom during peaks. The pause is not removed entirely because it is a
 * deliberate brake that stops consecutive queue runs from chasing each other. One second
 * keeps the brake and cuts the worker hold time by a factor of five.
 *
 * CONFIGURATION
 *   - Disable:  add_filter( 'eru_htf_action_scheduler_sleep_enabled', '__return_false' );
 *   - Seconds:  define( 'ERU_HTF_AS_SLEEP_SECONDS', 2 );  // default 1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'action_scheduler_async_request_sleep_seconds',
	static function ( $seconds ) {
		if ( ! apply_filters( 'eru_htf_action_scheduler_sleep_enabled', true ) ) {
			return $seconds;
		}

		$value = defined( 'ERU_HTF_AS_SLEEP_SECONDS' ) ? (int) ERU_HTF_AS_SLEEP_SECONDS : 1;

		return max( 0, $value );
	}
);
