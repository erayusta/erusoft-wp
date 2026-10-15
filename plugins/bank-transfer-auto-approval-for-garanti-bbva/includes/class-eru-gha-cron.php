<?php
/**
 * Action Scheduler wiring: the polling run follows the master switch and interval; the daily purge runs
 * while there is something to purge; "Şimdi çalıştır" and "Hareketleri getir" enqueue single actions.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is scheduled while the integration is off and the log is empty.
 */
class Eru_GHA_Cron {

	const GROUP = 'eru-gha';
	const CHECK = 'eru_gha_check';
	const NOW   = 'eru_gha_run_now';
	const FETCH = 'eru_gha_fetch';
	const DAILY = 'eru_gha_daily';

	/** Hooks. */
	public static function init() {
		add_action( self::CHECK, array( __CLASS__, 'scheduled' ) );
		add_action( self::NOW, array( __CLASS__, 'manual' ) );
		add_action( self::FETCH, array( 'Eru_GHA_Approver', 'fetch_only' ) );
		add_action( self::DAILY, array( 'Eru_GHA_Log', 'purge' ) );
		add_action(
			'admin_init',
			function () {
				if ( current_user_can( 'manage_woocommerce' ) && get_option( Eru_GHA_Settings::OPTION ) && false === get_transient( 'eru_gha_sync' ) ) {
					set_transient( 'eru_gha_sync', 1, HOUR_IN_SECONDS );
					self::sync();
				}
			},
			30
		);
	}

	/** Recurring run: only while the master switch is on. */
	public static function scheduled() {
		if ( Eru_GHA_Settings::is_enabled() ) {
			Eru_GHA_Approver::run();
		}
	}

	/** "Şimdi çalıştır": credentials are enough (the master switch only controls the schedule). */
	public static function manual() {
		Eru_GHA_Approver::run();
	}

	/** @return bool Action Scheduler is usable now. */
	private static function ready() {
		return function_exists( 'as_schedule_recurring_action' ) && did_action( 'action_scheduler_init' );
	}

	/** Makes the recurring jobs match the settings. */
	public static function sync() {
		if ( ! self::ready() ) {
			return;
		}
		$on = Eru_GHA_Settings::is_enabled();
		self::recurring( self::CHECK, $on, max( 5, (int) Eru_GHA_Settings::get( 'interval' ) ) * MINUTE_IN_SECONDS );
		self::recurring( self::DAILY, $on || Eru_GHA_Log::has_rows(), DAY_IN_SECONDS );
	}

	/** @param string $hook Hook. @param bool $want Wanted. @param int $every Interval. */
	private static function recurring( $hook, $want, $every ) {
		$q       = array(
			'hook'     => $hook,
			'group'    => self::GROUP,
			'per_page' => 5,
		);
		$pending = as_get_scheduled_actions( array_merge( $q, array( 'status' => ActionScheduler_Store::STATUS_PENDING ) ) );
		$running = as_get_scheduled_actions( array_merge( $q, array( 'status' => ActionScheduler_Store::STATUS_RUNNING ) ), 'ids' );
		$current = 0;
		foreach ( $pending as $a ) {
			$s = $a->get_schedule();
			if ( method_exists( $s, 'get_recurrence' ) ) {
				$current = (int) $s->get_recurrence();
			}
		}
		if ( $want && ( ( $pending && $current === (int) $every ) || ( ! $pending && $running ) ) ) {
			return;
		}
		if ( $pending ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		if ( $want ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, $every, $hook, array(), self::GROUP );
		}
	}

	/**
	 * Enqueues a single action unless one is already pending.
	 *
	 * @param string $hook self::NOW or self::FETCH.
	 * @return bool Enqueued (false: already pending).
	 */
	public static function enqueue( $hook ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( $pending ) {
			return false;
		}
		return (bool) as_schedule_single_action( time(), $hook, array(), self::GROUP );
	}
}
