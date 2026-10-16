<?php
/**
 * Action Scheduler wiring: the self re-arming status sync, the daily clean-up and the queued connection test.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is scheduled while there is nothing to track: the sync is a single action that re-arms itself at
 * the end of a run while open rows exist (checking PENDING actions only, because the running action itself
 * still counts as "scheduled"), and recording an instruction arms it. The daily clean-up exists while the
 * gateway is on or rows are kept. Deactivation unschedules the group; activation sets a flag so the next
 * request re-arms what is needed.
 */
class Eru_VNV_Cron {

	const GROUP = 'eru-vnv';
	const SYNC  = 'eru_vnv_sync';
	const DAILY = 'eru_vnv_daily';
	const CONN   = 'eru_vnv_conn_test';
	const CANCEL = 'eru_vnv_bank_cancel';
	const REARM  = 'eru_vnv_rearm';

	/** Attempts for a queued bank cancel before the order is flagged for review. */
	const CANCEL_TRIES = 5;

	/** Hooks. */
	public static function init() {
		add_action( self::SYNC, array( __CLASS__, 'run_sync' ) );
		add_action( self::DAILY, array( __CLASS__, 'daily' ) );
		add_action( self::CONN, array( 'Eru_VNV_Admin', 'connection_test' ) );
		add_action( self::CANCEL, array( 'Eru_VNV_Sync', 'bank_cancel_job' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_rearm' ), 20 );
		add_action(
			'admin_init',
			function () {
				if ( current_user_can( 'manage_woocommerce' ) && false === get_transient( 'eru_vnv_cron_sync' ) ) {
					set_transient( 'eru_vnv_cron_sync', 1, HOUR_IN_SECONDS );
					self::sync_schedules();
				}
			},
			30
		);
		// The flag is added (not updated) the first time the gateway is saved, so both hooks are needed.
		foreach ( array( 'add_option_', 'update_option_' ) as $prefix ) {
			add_action(
				$prefix . Eru_VNV_Settings::FLAG,
				function () {
					self::sync_schedules();
				}
			);
		}
	}

	/** @return bool Action Scheduler can be used now. */
	private static function ready() {
		return function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler_Store' ) && did_action( 'action_scheduler_init' );
	}

	/** @param string $hook Hook. @return int[] Pending action ids of the hook in this group. */
	public static function pending_ids( $hook ) {
		if ( ! self::ready() ) {
			return array();
		}
		return array_map(
			'intval',
			as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'group'    => self::GROUP,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 5,
				),
				'ids'
			)
		);
	}

	/**
	 * Arms the sync when rows are open and no sync is PENDING (a running one does not count: it is the caller,
	 * or it will finish without seeing new rows).
	 *
	 * @param int|null $delay Seconds until the run (default: the interval setting).
	 */
	public static function ensure( $delay = null ) {
		if ( ! self::ready() || self::pending_ids( self::SYNC ) || Eru_VNV_Instructions::open_count() < 1 ) {
			return;
		}
		as_schedule_single_action( time() + ( null === $delay ? Eru_VNV_Settings::sync_interval() * MINUTE_IN_SECONDS : max( 0, (int) $delay ) ), self::SYNC, array(), self::GROUP );
	}

	/**
	 * After activation: re-arms the jobs, and drops the flag only when that worked (Action Scheduler may not be
	 * ready yet on this request; the next request tries again).
	 *
	 * @return bool Re-armed (or nothing to do).
	 */
	public static function maybe_rearm() {
		if ( ! get_option( self::REARM ) ) {
			return true;
		}
		if ( ! self::sync_schedules() ) {
			return false;
		}
		delete_option( self::REARM );
		return true;
	}

	/**
	 * Queues a bank cancel of the order's waiting instruction (one pending per order).
	 *
	 * @param int $order_id Order id.
	 * @param int $try      Attempt number.
	 * @param int $delay    Seconds until it runs.
	 * @return bool Queued.
	 */
	public static function queue_cancel( $order_id, $try = 1, $delay = 0 ) {
		if ( ! self::ready() ) {
			return false;
		}
		// Exact duplicate check: one query per possible try, matching the args (pending only; the running job
		// re-queues its own next try).
		for ( $t = 1; $t <= self::CANCEL_TRIES; $t++ ) {
			$args = array(
				'order_id' => (int) $order_id,
				'try'      => $t,
			);
			if ( as_get_scheduled_actions( array( 'hook' => self::CANCEL, 'group' => self::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING, 'args' => $args, 'per_page' => 1 ), 'ids' ) ) {
				return false;
			}
		}
		return (bool) as_schedule_single_action(
			time() + max( 0, (int) $delay ),
			self::CANCEL,
			array(
				'order_id' => (int) $order_id,
				'try'      => max( 1, (int) $try ),
			),
			self::GROUP
		);
	}

	/** Action callback: one sync run (it re-arms itself). */
	public static function run_sync() {
		Eru_VNV_Sync::run();
	}

	/**
	 * Makes the scheduled jobs match the current state (sync while rows are open, daily while useful).
	 *
	 * @return bool False when Action Scheduler is not ready.
	 */
	public static function sync_schedules() {
		if ( ! self::ready() ) {
			return false;
		}
		self::ensure();
		$want = Eru_VNV_Settings::enabled() || Eru_VNV_Instructions::has_rows();
		$have = self::pending_ids( self::DAILY );
		if ( $want && ! $have ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::DAILY, array(), self::GROUP );
		} elseif ( ! $want && $have ) {
			as_unschedule_all_actions( self::DAILY, array(), self::GROUP );
		}
		return true;
	}

	/** Deactivation: every pending action of the group goes. */
	public static function deactivate() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( array( self::SYNC, self::DAILY, self::CONN, self::CANCEL ) as $hook ) {
			as_unschedule_all_actions( $hook, null, self::GROUP );
		}
	}

	/**
	 * Queues the connection test (one pending at a time).
	 *
	 * @return bool Queued (false: one is already waiting or Action Scheduler is not ready).
	 */
	public static function queue_connection_test() {
		if ( ! self::ready() || self::pending_ids( self::CONN ) ) {
			return false;
		}
		return (bool) as_schedule_single_action( time(), self::CONN, array(), self::GROUP );
	}

	/**
	 * Daily clean-up, all in bounded batches: closed instruction rows and the masked identity number / phone
	 * of orders older than the retention period, verification claim rows older than a day, and lock rows left
	 * behind by a crashed request.
	 */
	public static function daily() {
		global $wpdb;
		$days = Eru_VNV_Settings::retention_days();
		Eru_VNV_Instructions::purge( $days );
		Eru_VNV_Privacy::purge( $days );
		foreach ( array( 'eru_vnv_vused_', 'eru_vnv_lock_' ) as $prefix ) {
			for ( $i = 0; $i < 20; $i++ ) {
				// Claim rows hold a unix time; lock rows hold "time|token".
				$d = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT 500", $wpdb->esc_like( $prefix ) . '%', time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $d < 500 ) {
					break;
				}
			}
		}
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		// A sync chain cut by a crash is picked up again at least daily.
		self::ensure();
	}
}
