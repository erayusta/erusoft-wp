<?php
/**
 * Action Scheduler wiring: recurring pull/import/daily jobs that follow the switches, de-duplicated single
 * actions, and retry with backoff (1, 2, 4 … minutes, capped at 30) instead of sleeping.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nothing is scheduled while the master switch is off and the log table is empty.
 */
class Eru_DPG_Cron {

	const GROUP        = 'eru-dpg';
	const MAX_ATTEMPTS = 8;
	const PULL_EVERY   = 5 * MINUTE_IN_SECONDS;

	/** @return array<string,array> Import intervals: key => [seconds, label]. */
	public static function intervals() {
		return array(
			'hourly'     => array( HOUR_IN_SECONDS, __( 'Saatte bir', 'dopigo-integration-for-woocommerce' ) ),
			'twicedaily' => array( 12 * HOUR_IN_SECONDS, __( 'Günde iki kez', 'dopigo-integration-for-woocommerce' ) ),
			'daily'      => array( DAY_IN_SECONDS, __( 'Günde bir', 'dopigo-integration-for-woocommerce' ) ),
		);
	}

	/** Hooks. */
	public static function init() {
		add_action( 'eru_dpg_push_product', array( 'Eru_DPG_Products', 'run_push_job' ) );
		add_action( 'eru_dpg_push_order', array( 'Eru_DPG_Orders', 'run_job' ), 10, 2 );
		add_action( 'eru_dpg_push_status', array( 'Eru_DPG_Status', 'run_push_job' ) );
		add_action( 'eru_dpg_pull', array( __CLASS__, 'run_pull' ) );
		add_action( 'eru_dpg_import', array( __CLASS__, 'run_import' ) );
		add_action( 'eru_dpg_daily', array( __CLASS__, 'run_daily' ) );
		add_action( 'eru_dpg_tool', array( 'Eru_DPG_Tools', 'run' ) );
		add_action(
			'admin_init',
			function () {
				// Self-heal missing schedules (another plugin cleared them). Cheap and hourly at most.
				if ( current_user_can( 'manage_woocommerce' ) && get_option( Eru_DPG_Settings::OPTION ) && false === get_transient( 'eru_dpg_sync' ) ) {
					set_transient( 'eru_dpg_sync', 1, HOUR_IN_SECONDS );
					self::sync();
				}
			},
			30
		);
	}

	/** @return bool Action Scheduler is usable now. */
	private static function ready() {
		return function_exists( 'as_schedule_single_action' ) && did_action( 'action_scheduler_init' );
	}

	/** Make the recurring jobs match the settings. */
	public static function sync() {
		if ( ! self::ready() ) {
			return;
		}
		$on   = Eru_DPG_Settings::is_enabled();
		$pull = $on && ( Eru_DPG_Settings::allows( 'stock_direction', 'dp_to_wc' ) || Eru_DPG_Settings::allows( 'price_direction', 'dp_to_wc' ) || Eru_DPG_Settings::allows( 'status_direction', 'dp_to_wc' ) );
		self::recurring( 'eru_dpg_pull', $pull, self::PULL_EVERY );

		$ints = self::intervals();
		$int  = $ints[ Eru_DPG_Settings::get( 'import_interval' ) ][0] ?? DAY_IN_SECONDS;
		self::recurring( 'eru_dpg_import', $on && Eru_DPG_Settings::on( 'import_enabled' ), $int );

		self::recurring( 'eru_dpg_daily', $on || Eru_DPG_Logger::has_rows(), DAY_IN_SECONDS );
	}

	/** @param string $hook Hook. @param bool $want Wanted. @param int $every Interval. */
	private static function recurring( $hook, $want, $every ) {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			)
		);
		$current = 0;
		foreach ( $actions as $a ) {
			$s = $a->get_schedule();
			if ( method_exists( $s, 'get_recurrence' ) ) {
				$current = (int) $s->get_recurrence();
			}
		}
		if ( $want && $actions && $current === (int) $every ) {
			return;
		}
		if ( $actions ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		if ( $want ) {
			as_schedule_recurring_action( time() + min( $every, 2 * MINUTE_IN_SECONDS ), $every, $hook, array(), self::GROUP );
		}
	}

	/** @param string $hook Hook. @param array|null $args Args. @return int Pending (not running) actions. */
	public static function pending( $hook, $args = null ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		$q = array(
			'hook'     => $hook,
			'group'    => self::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 100,
		);
		if ( null !== $args ) {
			$q['args'] = $args;
		}
		return count( as_get_scheduled_actions( $q, 'ids' ) );
	}

	/**
	 * Schedules a single action unless the same hook+args is already pending.
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Args.
	 * @param int    $delay Seconds.
	 * @return bool Scheduled.
	 */
	public static function enqueue( $hook, array $args, $delay = 0 ) {
		if ( ! function_exists( 'as_schedule_single_action' ) || self::pending( $hook, $args ) ) {
			return false;
		}
		return (bool) as_schedule_single_action( time() + max( 0, (int) $delay ), $hook, $args, self::GROUP );
	}

	/** @param int $attempt Attempt number (1-based). @return int Minutes. */
	public static function backoff_minutes( $attempt ) {
		return (int) min( 30, 2 ** max( 0, (int) $attempt - 1 ) );
	}

	/** @param string $hook Hook. @param array $args Args. @return string Counter key. */
	private static function key( $hook, array $args ) {
		return 'eru_dpg_try_' . md5( $hook . '|' . wp_json_encode( array_values( $args ) ) );
	}

	/** @param string $hook Hook. @param array $args Args. @return int Failed attempts so far. */
	public static function attempts( $hook, array $args ) {
		return (int) get_transient( self::key( $hook, $args ) );
	}

	/** @param string $hook Hook. @param array $args Args. */
	public static function reset_attempts( $hook, array $args ) {
		delete_transient( self::key( $hook, $args ) );
	}

	/**
	 * Reschedules a job after a retryable error.
	 *
	 * @param string   $hook  Hook.
	 * @param array    $args  Args.
	 * @param WP_Error $error Error.
	 * @param string   $ref   Log reference.
	 * @return bool Rescheduled (false: gave up after MAX_ATTEMPTS).
	 */
	public static function retry( $hook, array $args, $error, $ref = '' ) {
		$n = self::attempts( $hook, $args ) + 1;
		if ( $n > self::MAX_ATTEMPTS ) {
			self::reset_attempts( $hook, $args );
			Eru_DPG_Logger::log( 'cron', 'failed', sprintf( /* translators: %d: attempts */ __( '%d denemeden sonra vazgeçildi: ', 'dopigo-integration-for-woocommerce' ), self::MAX_ATTEMPTS ) . $error->get_error_message(), array( 'hook' => $hook ), '', $ref );
			return false;
		}
		set_transient( self::key( $hook, $args ), $n, DAY_IN_SECONDS );
		$data  = $error->get_error_data();
		$after = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;
		$delay = max( self::backoff_minutes( $n ) * MINUTE_IN_SECONDS, min( 30 * MINUTE_IN_SECONDS, $after ) );
		if ( function_exists( 'as_schedule_single_action' ) && ! self::pending( $hook, $args ) ) {
			as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
		}
		Eru_DPG_Logger::log( 'cron', 'warning', sprintf( /* translators: 1: attempt, 2: minutes */ __( 'Geçici hata, %1$d. deneme %2$d dakika sonra: ', 'dopigo-integration-for-woocommerce' ), $n, (int) ceil( $delay / 60 ) ) . $error->get_error_message(), array( 'hook' => $hook ), '', $ref );
		return true;
	}

	/** Every 5 minutes: DP→WC stock/price and order statuses, under a lock. */
	public static function run_pull() {
		if ( ! Eru_DPG_Settings::is_enabled() || ! Eru_DPG_Lock::acquire( 'pull', 15 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		try {
			update_option( 'eru_dpg_last_pull', time(), false );
			if ( Eru_DPG_Settings::allows( 'stock_direction', 'dp_to_wc' ) || Eru_DPG_Settings::allows( 'price_direction', 'dp_to_wc' ) ) {
				Eru_DPG_Products::instance()->pull_batch( (int) Eru_DPG_Settings::get( 'time_budget' ), (int) Eru_DPG_Settings::get( 'page_size' ) );
			}
			if ( Eru_DPG_Settings::allows( 'status_direction', 'dp_to_wc' ) ) {
				Eru_DPG_Status::pull();
			}
		} finally {
			Eru_DPG_Lock::release( 'pull' );
		}
	}

	/** Scheduled import pass: one budgeted batch, then chained batches until the pass is complete. */
	public static function run_import() {
		if ( Eru_DPG_Settings::is_enabled() && Eru_DPG_Settings::on( 'import_enabled' ) ) {
			Eru_DPG_Tools::run( 'import' );
		}
	}

	/** Daily: log retention, stale order locks. */
	public static function run_daily() {
		global $wpdb;
		Eru_DPG_Logger::purge();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500", $wpdb->esc_like( 'eru_dpg_lock_' ) . '%' ) ); // phpcs:ignore
		foreach ( $rows as $r ) {
			if ( (int) $r->option_value < time() - DAY_IN_SECONDS ) {
				delete_option( $r->option_name );
			}
		}
	}
}
