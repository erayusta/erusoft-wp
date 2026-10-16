<?php
/**
 * Scheduled work of the core: the daily retention purge, and resuming a migration after reactivation.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler, group eru-mpo. Nothing is scheduled while the master switch is off and there is no retained
 * data (inert default). The daily job (eru_mpo_daily) purges raw payloads and log rows past their retention and
 * re-arms itself with a pending-only check, as long as the switch is on or retained data is left.
 */
class Eru_MPO_Cron {

	const GROUP = 'eru-mpo';
	const DAILY = 'eru_mpo_daily';

	/** @var bool The daily job is known to be pending in this request. */
	private static $armed = false;

	/** Hooks. */
	public static function init() {
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		if ( get_option( 'eru_mpo_rearm' ) ) {
			add_action( 'init', array( __CLASS__, 'rearm' ), 20 );
		}
	}

	/** After (re)activation: re-arm what was left. */
	public static function rearm() {
		delete_option( 'eru_mpo_rearm' );
		self::maybe_arm();
		Eru_MPO_Migrator::resume();
	}

	/**
	 * @param string $hook Hook.
	 * @return int[] Pending (not running) actions of the hook.
	 */
	public static function pending( $hook ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}
		return as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			),
			'ids'
		);
	}

	/**
	 * Schedules the daily purge when needed and none is pending.
	 *
	 * @param int $delay Seconds until it runs (a day; a minute while a purge is unfinished).
	 */
	public static function maybe_arm( $delay = DAY_IN_SECONDS ) {
		if ( self::$armed || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( ! Eru_MPO_Settings::enabled() && ! Eru_MPO_Raw::has_rows() && ! Eru_MPO_Logger::has_rows() ) {
			return;
		}
		if ( ! self::pending( self::DAILY ) ) {
			as_schedule_single_action( time() + (int) $delay, self::DAILY, array(), self::GROUP );
		}
		self::$armed = true;
	}

	/**
	 * Daily: purge, then re-arm (pending-only, the running action does not count). A purge that hit its batch cap
	 * comes back a minute later, until nothing past retention is left.
	 */
	public static function daily() {
		Eru_MPO_Raw::purge();
		$more = Eru_MPO_Raw::$more;
		Eru_MPO_Logger::purge();
		$more        = $more || Eru_MPO_Logger::$more;
		self::$armed = false;
		self::maybe_arm( $more ? MINUTE_IN_SECONDS : DAY_IN_SECONDS );
	}

	/** Removes every job of the group (deactivation, uninstall, tests). */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		self::$armed = false;
	}
}
