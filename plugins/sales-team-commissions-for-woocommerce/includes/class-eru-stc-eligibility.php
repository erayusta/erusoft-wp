<?php
/**
 * Approval job: pending rows whose hold has passed become approved, in batches. One Action Scheduler
 * action (group eru-stc) that re-arms itself: soon while a full batch was processed, otherwise after the
 * interval (daily; filter eru_stc_eligibility_interval). A throttled watchdog arms it while the module is
 * on; nothing is scheduled while it is off. Routine runs write no log line.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Eligibility {

	const HOOK  = 'eru_stc_eligibility';
	const GROUP = 'eru-stc';
	const LOCK  = 'eru_stc_lock_eligibility';

	/** Wires the job and the watchdog. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 0 );
		add_action( 'action_scheduler_init', array( __CLASS__, 'watchdog' ) );
	}

	/** Arms the job at most once an hour per site while the module is on (one autoloaded option read). */
	public static function watchdog() {
		if ( ! Eru_STC_Settings::enabled() || (int) get_option( 'eru_stc_watchdog_at', 0 ) > time() - HOUR_IN_SECONDS ) {
			return;
		}
		update_option( 'eru_stc_watchdog_at', time(), true );
		self::ensure_scheduled();
	}

	/** @return int[] Pending (not running) actions of the job. */
	private static function pending() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}
		return as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/** @param int $delay Seconds from now. Schedules the next run unless one is already pending. */
	public static function ensure_scheduled( $delay = null ) {
		if ( ! function_exists( 'as_schedule_single_action' ) || self::pending() ) {
			return;
		}
		as_schedule_single_action( time() + ( null === $delay ? self::interval() : max( 0, (int) $delay ) ), self::HOOK, array(), self::GROUP );
	}

	/** @return int Seconds between runs (daily by default). */
	public static function interval() {
		/**
		 * Seconds between approval runs.
		 *
		 * @param int $seconds Default DAY_IN_SECONDS.
		 */
		return max( 300, (int) apply_filters( 'eru_stc_eligibility_interval', DAY_IN_SECONDS ) );
	}

	/**
	 * Approves one batch of due rows, then re-arms.
	 *
	 * @param int|null $now Unix time (default: now).
	 * @return int Rows approved by this run.
	 */
	public static function run( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$token = self::lock();
		if ( '' === $token ) {
			return 0;
		}
		$done = 0;
		$full = false;
		try {
			/**
			 * Rows approved per run.
			 *
			 * @param int $size Default 200.
			 */
			$size   = max( 1, min( 1000, (int) apply_filters( 'eru_stc_eligibility_batch', 200 ) ) );
			$cutoff = gmdate( 'Y-m-d H:i:s', $now - (int) Eru_STC_Settings::get( 'hold_days' ) * DAY_IN_SECONDS );
			$ids    = Eru_STC_Commissions::due_ids( $cutoff, $size );
			foreach ( $ids as $id ) {
				if ( Eru_STC_Commissions::approve( $id ) ) {
					++$done;
				}
			}
			$full = count( $ids ) >= $size;
		} finally {
			self::unlock( $token );
		}
		if ( Eru_STC_Settings::enabled() ) {
			if ( $full && function_exists( 'as_unschedule_all_actions' ) ) {
				// A backlog remains: replace the pending daily run with one in a minute (pending actions only;
				// the running one is untouched).
				as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			}
			// Pending-only check: this very action is "running", not pending, while it executes.
			self::ensure_scheduled( $full ? 60 : null );
		}
		return $done;
	}

	/**
	 * Takes the run lock: INSERT IGNORE of an option row holding "time|token" (atomic on the unique
	 * option_name, unlike add_option's upsert); a lock older than 10 minutes is taken over.
	 *
	 * @return string Owner token, '' when another run holds the lock.
	 */
	private static function lock() {
		global $wpdb;
		$token = wp_generate_password( 16, false );
		$n     = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, time() . '|' . $token ) );
		if ( 1 === (int) $n ) {
			wp_cache_delete( 'notoptions', 'options' );
			return $token;
		}
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		$at   = (int) strtok( $held, '|' );
		if ( '' !== $held && $at < time() - 10 * MINUTE_IN_SECONDS ) {
			// Compare-and-swap so two processes cannot both take over the same stale lock.
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", time() . '|' . $token, self::LOCK, $held ) );
			wp_cache_delete( self::LOCK, 'options' );
			return 1 === (int) $n ? $token : '';
		}
		return '';
	}

	/** @param string $token Owner token. Releases the lock only if this process still owns it. */
	private static function unlock( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK, '%|' . $wpdb->esc_like( $token ) ) );
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Removes every scheduled run. */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}
}
