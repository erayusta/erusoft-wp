<?php
/**
 * Action Scheduler wiring: the 10-minute batch poll follows the master switch and the credentials.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is scheduled while the integration is off or not configured. The plugin uses its own recurring
 * action (no WP-Cron interval from another plugin).
 */
class Eru_PPT_Cron {

	const GROUP = 'eru-ppt';
	const POLL  = 'eru_ppt_poll';
	const EVERY = 10 * MINUTE_IN_SECONDS;

	/** Hooks. */
	public static function init() {
		add_action( self::POLL, array( 'Eru_PPT_Poller', 'run' ) );
		add_action( 'update_option_' . Eru_PPT_Settings::OPTION, array( __CLASS__, 'sync' ), 20 );
		add_action( 'add_option_' . Eru_PPT_Settings::OPTION, array( __CLASS__, 'sync' ), 20 );
		add_action(
			'admin_init',
			function () {
				if ( current_user_can( 'manage_woocommerce' ) && get_option( Eru_PPT_Settings::OPTION ) && false === get_transient( 'eru_ppt_sync' ) ) {
					set_transient( 'eru_ppt_sync', 1, HOUR_IN_SECONDS );
					self::sync();
				}
			},
			30
		);
	}

	/** Makes the recurring poll match the settings. */
	public static function sync() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		Eru_PPT_Settings::flush();
		$want    = Eru_PPT_Settings::is_enabled();
		$q       = array(
			'hook'     => self::POLL,
			'group'    => self::GROUP,
			'per_page' => 5,
		);
		$pending = as_get_scheduled_actions( array_merge( $q, array( 'status' => ActionScheduler_Store::STATUS_PENDING ) ), 'ids' );
		$running = as_get_scheduled_actions( array_merge( $q, array( 'status' => ActionScheduler_Store::STATUS_RUNNING ) ), 'ids' );
		if ( ! $want ) {
			if ( $pending ) {
				as_unschedule_all_actions( self::POLL, array(), self::GROUP );
			}
			return;
		}
		// A running recurring action schedules its own next instance when it finishes: do not add another.
		if ( $pending || $running ) {
			return;
		}
		as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, self::EVERY, self::POLL, array(), self::GROUP );
	}
}
