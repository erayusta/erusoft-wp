<?php
/**
 * Action Scheduler wiring: the 5-minute tracker and the daily retention purge follow the master switch.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is scheduled while the integration is off and both tables are empty.
 */
class Eru_YK_Cron {

	const GROUP       = 'eru-yk';
	const DAILY       = 'eru_yk_daily';
	const TRACK_EVERY = 5 * MINUTE_IN_SECONDS;

	/** Hooks. */
	public static function init() {
		add_action( Eru_YK_Tracker::HOOK, array( 'Eru_YK_Tracker', 'run' ) );
		add_action( self::DAILY, array( 'Eru_YK_Logger', 'purge' ) );
		add_action( 'update_option_' . Eru_YK_Settings::OPTION, array( __CLASS__, 'sync' ), 20 );
		add_action( 'add_option_' . Eru_YK_Settings::OPTION, array( __CLASS__, 'sync' ), 20 );
		add_action(
			'admin_init',
			function () {
				if ( current_user_can( 'manage_woocommerce' ) && get_option( Eru_YK_Settings::OPTION ) && false === get_transient( 'eru_yk_sync' ) ) {
					set_transient( 'eru_yk_sync', 1, HOUR_IN_SECONDS );
					self::sync();
				}
			},
			30
		);
	}

	/** Makes the recurring jobs match the settings. */
	public static function sync() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		Eru_YK_Settings::flush();
		$on = Eru_YK_Settings::is_enabled();
		self::recurring( Eru_YK_Tracker::HOOK, $on, self::TRACK_EVERY );
		self::recurring( self::DAILY, $on || Eru_YK_Logger::has_rows(), DAY_IN_SECONDS );
	}

	/** @param string $hook Hook. @param bool $want Wanted. @param int $every Interval. */
	private static function recurring( $hook, $want, $every ) {
		$q       = array(
			'hook'     => $hook,
			'group'    => self::GROUP,
			'per_page' => 5,
		);
		$ids     = as_get_scheduled_actions( array_merge( $q, array( 'status' => ActionScheduler_Store::STATUS_PENDING ) ), 'ids' );
		$running = as_get_scheduled_actions( array_merge( $q, array( 'status' => ActionScheduler_Store::STATUS_RUNNING ) ), 'ids' );
		// A running recurring action schedules its own next instance when it finishes: do not add another.
		if ( $want && ( $ids || $running ) ) {
			return;
		}
		if ( ! $want && $ids ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		if ( $want ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $every, $hook, array(), self::GROUP );
		}
	}
}
