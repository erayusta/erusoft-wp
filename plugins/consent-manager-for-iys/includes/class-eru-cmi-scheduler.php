<?php
/**
 * Action Scheduler jobs: a flush 30 s after a change (chained while rows are due), a 5-minute tick while
 * work is open (send, poll, stale handling), the hourly İYS → site pull, the nightly reconcile at the
 * configured hour (site time zone), a daily retention purge, the connection test, the consent-log table import
 * and an hourly watchdog that re-arms a lost tick, pull or reconcile.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All "already scheduled?" checks look at PENDING actions only: as_has_scheduled_action() also counts the
 * running action, so a job that re-arms itself from inside its own run would otherwise never re-arm.
 *
 * The 5-minute tick is a self-chaining single action rather than an AS recurring action: a recurring
 * action is re-created by Action Scheduler after every run, so it could not stop itself once the queue is
 * empty. The tick arms its successor at the start of its run (a crash cannot break the chain) and cancels
 * it at the end when no work is left. Nothing is scheduled while the master switch is off. Every check
 * concerns the selected İYS environment (Eru_CMI_Queue filters by it); switching environments runs sync().
 */
class Eru_CMI_Scheduler {

	const GROUP          = 'eru-cmi';
	const FLUSH          = 'eru_cmi_flush';
	const TICK           = 'eru_cmi_tick';
	const PURGE          = 'eru_cmi_log_purge';
	const TEST           = 'eru_cmi_test_connection';
	const CAPTURE        = 'eru_cmi_capture_order';
	const PULL           = 'eru_cmi_pull';
	const RECONCILE      = 'eru_cmi_reconcile';
	const TABLE          = 'eru_cmi_table_import';
	const PULL_LAST      = 'eru_cmi_pull_last';
	const FLUSH_DELAY    = 30;
	const TICK_INTERVAL  = 300;
	const WATCHDOG_EVERY = HOUR_IN_SECONDS;
	const WATCHDOG_OPT   = 'eru_cmi_watchdog_at';
	const TEST_RESULT    = 'eru_cmi_test_result';

	/** @var int When flush and tick were last confirmed pending in this request (0 = never). */
	private static $armed = 0;

	/** @var bool Purge already confirmed pending during this request. */
	private static $purge_armed = false;

	/** Hooks. */
	public static function init() {
		add_action( self::FLUSH, array( __CLASS__, 'run_flush' ) );
		add_action( self::TICK, array( __CLASS__, 'run_tick' ) );
		add_action( self::PURGE, array( __CLASS__, 'run_purge' ) );
		add_action( self::TEST, array( __CLASS__, 'run_test' ) );
		add_action( self::PULL, array( __CLASS__, 'run_pull' ) );
		add_action( self::RECONCILE, array( __CLASS__, 'run_reconcile' ) );
		add_action( self::TABLE, array( __CLASS__, 'run_table' ) );
		// Per request: autoloaded options only (no query). Switched off → nothing else is read or hooked.
		// A missing flag (deleted, DB restore) is re-created once, so it cannot cost a query on every request.
		if ( null === get_option( Eru_CMI_Settings::ACTIVE_FLAG, null ) || null === get_option( Eru_CMI_Settings::FRONT_FLAG, null ) ) {
			Eru_CMI_Settings::sync_flag();
		}
		if ( Eru_CMI_Settings::active_flag() && (int) get_option( self::WATCHDOG_OPT, 0 ) <= time() - self::WATCHDOG_EVERY ) {
			add_action( 'action_scheduler_init', array( __CLASS__, 'watchdog' ) );
		}
	}

	/** Test helper: forget the per-request "already pending" memo. */
	public static function reset_memo() {
		self::$armed       = 0;
		self::$purge_armed = false;
	}

	/** @return bool Action Scheduler is usable. */
	private static function ready() {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' );
	}

	/** @param string $hook Hook. @return int[] Pending action ids. */
	private static function pending( $hook ) {
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

	/** @param string $hook Hook. @param int $delay Seconds. Schedules a single action unless one is pending. */
	private static function ensure( $hook, $delay ) {
		if ( ! self::pending( $hook ) ) {
			as_schedule_single_action( time() + $delay, $hook, array(), self::GROUP );
		}
	}

	/**
	 * After a change was queued: flush in 30 s, and keep the 5-minute tick alive. The pending check is
	 * remembered for 20 s only, so a long-running request (import, Action Scheduler batch) re-checks after
	 * the flush it armed has run.
	 */
	public static function soon() {
		if ( self::$armed > time() - 20 || ! self::ready() || ! Eru_CMI_Settings::enabled() ) {
			return;
		}
		self::ensure( self::FLUSH, self::FLUSH_DELAY );
		self::ensure( self::TICK, self::TICK_INTERVAL );
		self::$armed = time();
	}

	/** After a log row: one daily purge while the log has rows (pending-only, once per request). */
	public static function arm_purge() {
		if ( self::$purge_armed || ! self::ready() ) {
			return;
		}
		self::ensure( self::PURGE, DAY_IN_SECONDS );
		self::$purge_armed = true;
	}

	/**
	 * @param int $at Timestamp. @param string $hook Hook. Schedules a single action at $at unless one is pending.
	 */
	private static function ensure_at( $hook, $at ) {
		if ( ! self::pending( $hook ) ) {
			as_schedule_single_action( (int) $at, $hook, array(), self::GROUP );
		}
	}

	/**
	 * Next start of the nightly reconcile: the configured hour in the site time zone, today if still ahead.
	 *
	 * @param int|null $now Now (default time()).
	 * @return int Timestamp.
	 */
	public static function next_night( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$at   = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() )->setTime( Eru_CMI_Settings::reconcile_hour(), 0 );
		if ( $at->getTimestamp() <= $now ) {
			$at = $at->modify( '+1 day' )->setTime( Eru_CMI_Settings::reconcile_hour(), 0 );
		}
		return $at->getTimestamp();
	}

	/** @return bool Pull is due to run: switched on and configured. */
	private static function pull_on() {
		return Eru_CMI_Settings::enabled() && Eru_CMI_Settings::on( 'pull_enabled' );
	}

	/** @return bool Reconcile is due to run: switched on and configured. */
	private static function reconcile_on() {
		return Eru_CMI_Settings::enabled() && Eru_CMI_Settings::on( 'reconcile_enabled' );
	}

	/**
	 * Settings changed: switched on with open work → arm; pull and reconcile follow their own switches;
	 * switched off → drop flush, tick, pull and reconcile.
	 */
	public static function sync() {
		if ( ! self::ready() ) {
			return;
		}
		self::reset_memo();
		if ( Eru_CMI_Settings::hash_days() > 0 ) {
			self::arm_purge(); // History hashes to clear later.
		}
		if ( Eru_CMI_Settings::enabled() ) {
			if ( Eru_CMI_Queue::has_open() ) {
				self::ensure( self::TICK, 5 );
			}
		} else {
			as_unschedule_all_actions( self::FLUSH, array(), self::GROUP );
			as_unschedule_all_actions( self::TICK, array(), self::GROUP );
		}
		if ( self::pull_on() ) {
			self::ensure( self::PULL, MINUTE_IN_SECONDS );
		} else {
			as_unschedule_all_actions( self::PULL, array(), self::GROUP );
		}
		if ( self::reconcile_on() ) {
			$at  = self::next_night();
			$ids = self::pending( self::RECONCILE );
			$cur = $ids ? as_next_scheduled_action( self::RECONCILE, array(), self::GROUP ) : false;
			// The hour changed: move the next night's run (a continuation within the next minutes stays).
			if ( is_int( $cur ) && $cur > time() + 10 * MINUTE_IN_SECONDS && $cur !== $at ) {
				as_unschedule_all_actions( self::RECONCILE, array(), self::GROUP );
			}
			self::ensure_at( self::RECONCILE, $at );
		} else {
			as_unschedule_all_actions( self::RECONCILE, array(), self::GROUP );
		}
	}

	/** Drops every job of the plugin. */
	public static function unschedule_all() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( array( self::FLUSH, self::TICK, self::PURGE, self::TEST, self::PULL, self::RECONCILE, self::TABLE, Eru_CMI_Gra_Bridge::BACKFILL ) as $hook ) {
			as_unschedule_all_actions( $hook, array(), self::GROUP );
		}
		as_unschedule_all_actions( self::CAPTURE ); // Carries the order id: cancel by hook (any arguments).
		self::reset_memo();
	}

	/**
	 * Re-arms a lost tick when the switch is on and work is open. Runs at most once an hour.
	 *
	 * @param bool $force Ignore the hourly stamp.
	 */
	public static function watchdog( $force = false ) {
		if ( ! $force && (int) get_option( self::WATCHDOG_OPT, 0 ) > time() - self::WATCHDOG_EVERY ) {
			return;
		}
		if ( ! self::ready() || ! Eru_CMI_Settings::enabled() ) {
			return; // Switched off: nothing written, nothing scheduled.
		}
		update_option( self::WATCHDOG_OPT, time(), true );
		if ( Eru_CMI_Queue::has_open() ) {
			self::ensure( self::TICK, 5 );
		}
		if ( self::pull_on() ) {
			self::ensure( self::PULL, MINUTE_IN_SECONDS );
		}
		if ( self::reconcile_on() ) {
			self::ensure_at( self::RECONCILE, self::next_night() );
		}
	}

	/** Flush job: one batch; chains the next flush (pending-only) while rows are still due. */
	public static function run_flush() {
		self::reset_memo();
		if ( ! Eru_CMI_Settings::enabled() ) {
			return;
		}
		Eru_CMI_Queue::flush();
		if ( Eru_CMI_Queue::has_due() ) {
			self::ensure( self::FLUSH, self::FLUSH_DELAY );
		}
		if ( Eru_CMI_Queue::has_open() ) {
			self::ensure( self::TICK, self::TICK_INTERVAL ); // Sent rows need the tick for their results.
		}
	}

	/** Tick: arm the successor first, then send one batch, poll results and handle stale requests. */
	public static function run_tick() {
		self::reset_memo();
		if ( ! Eru_CMI_Settings::enabled() ) {
			return;
		}
		if ( Eru_CMI_Queue::has_open() ) {
			self::ensure( self::TICK, self::TICK_INTERVAL );
		}
		Eru_CMI_Queue::flush();
		Eru_CMI_Queue::poll();
		if ( Eru_CMI_Queue::has_due() ) {
			self::ensure( self::FLUSH, self::FLUSH_DELAY );
		}
		if ( ! Eru_CMI_Queue::has_open() ) {
			as_unschedule_all_actions( self::TICK, array(), self::GROUP );
		}
	}

	/**
	 * Daily retention purge (API log, expired mail markers, old history hashes, addresses left in settled queue
	 * rows of erased consents); chains itself while any of it remains.
	 */
	public static function run_purge() {
		Eru_CMI_Retention::run();
		if ( Eru_CMI_Retention::has_work() ) {
			self::ensure( self::PURGE, DAY_IN_SECONDS );
		}
	}

	/**
	 * Hourly İYS → site pull: one page; the next page follows in a minute while İYS has more, otherwise in an
	 * hour. The result is kept for the Kuyruk tab.
	 */
	public static function run_pull() {
		self::reset_memo();
		if ( ! self::ready() || ! self::pull_on() ) {
			return;
		}
		$r = Eru_CMI_Pull::page();
		update_option(
			self::PULL_LAST,
			array(
				'time'    => time(),
				'env'     => Eru_CMI_Settings::env(),
				'ok'      => (bool) $r['ok'],
				'applied' => (int) $r['applied'],
				'ignored' => (int) $r['ignored'],
				'error'   => Eru_CMI_Logger::redact( (string) $r['error'] ),
			),
			false
		);
		if ( $r['ok'] && $r['more'] ) {
			self::rearm( self::PULL, MINUTE_IN_SECONDS ); // The next page comes sooner than any pending hourly run.
			return;
		}
		self::ensure( self::PULL, HOUR_IN_SECONDS );
	}

	/**
	 * Replaces every pending action of a hook with one at time() + $delay. Used for continuations, which must not
	 * be swallowed by a later pending run (the watchdog or a settings save may have armed the next night).
	 *
	 * @param string $hook  Hook.
	 * @param int    $delay Seconds.
	 */
	private static function rearm( $hook, $delay ) {
		as_unschedule_all_actions( $hook, array(), self::GROUP ); // Pending only; the running action is not touched.
		as_schedule_single_action( time() + (int) $delay, $hook, array(), self::GROUP );
	}

	/**
	 * Nightly reconcile: one budgeted step (filter `eru_cmi_reconcile_budget`, 1–500 rows); continues in a minute
	 * while the pass is unfinished (an hour after İYS throttled the look-ups, five minutes when another run holds
	 * the lock), then waits for the next night.
	 */
	public static function run_reconcile() {
		self::reset_memo();
		if ( ! self::ready() || ! self::reconcile_on() ) {
			return;
		}
		/**
		 * Rows a reconcile step looks up at İYS (1–500).
		 *
		 * @param int $budget Default 500.
		 */
		$budget = max( 1, min( Eru_CMI_Reconcile::BUDGET, (int) apply_filters( 'eru_cmi_reconcile_budget', Eru_CMI_Reconcile::BUDGET ) ) );
		$r      = Eru_CMI_Reconcile::step( false, $budget );
		if ( ! empty( $r['locked'] ) ) {
			self::rearm( self::RECONCILE, 5 * MINUTE_IN_SECONDS );
		} elseif ( ! empty( $r['limited'] ) ) {
			self::rearm( self::RECONCILE, HOUR_IN_SECONDS );
		} elseif ( empty( $r['done'] ) ) {
			self::rearm( self::RECONCILE, MINUTE_IN_SECONDS );
		} else {
			self::ensure_at( self::RECONCILE, self::next_night() );
		}
	}

	/** Table import started from the Araçlar tab: one step per run, re-armed while rows remain. */
	public static function run_table() {
		self::reset_memo();
		if ( ! self::ready() ) {
			return;
		}
		$job = Eru_CMI_Import::table_job_step();
		if ( is_array( $job ) && empty( $job['done'] ) ) {
			self::ensure( self::TABLE, 5 );
		}
	}

	/** Starts the table import job (Araçlar). @return bool Queued. */
	public static function queue_table() {
		if ( ! self::ready() ) {
			return false;
		}
		if ( ! self::pending( self::TABLE ) ) {
			as_enqueue_async_action( self::TABLE, array(), self::GROUP );
		}
		return true;
	}

	/**
	 * Runs a pull or a reconcile step soon (Kuyruk tab buttons), never inside the page request.
	 *
	 * @param string $what pull | reconcile.
	 * @return bool Queued.
	 */
	public static function run_soon( $what ) {
		if ( ! self::ready() || ! Eru_CMI_Settings::enabled() ) {
			return false;
		}
		$hook = 'pull' === $what ? self::PULL : self::RECONCILE;
		if ( ( self::PULL === $hook && ! self::pull_on() ) || ( self::RECONCILE === $hook && ! self::reconcile_on() ) ) {
			return false;
		}
		as_unschedule_all_actions( $hook, array(), self::GROUP ); // Replaces the pending run; the job re-arms itself.
		as_enqueue_async_action( $hook, array(), self::GROUP );
		return true;
	}

	/** Queues the connection test (admin button). @return bool Queued. */
	public static function queue_test() {
		if ( ! self::ready() ) {
			return false;
		}
		delete_option( self::TEST_RESULT );
		if ( ! self::pending( self::TEST ) ) {
			as_enqueue_async_action( self::TEST, array(), self::GROUP );
		}
		return true;
	}

	/** Connection test job: a fresh login; stores a redacted result for the settings screen. */
	public static function run_test() {
		$env = Eru_CMI_Settings::env();
		$r   = ( new Eru_CMI_Client( $env ) )->test();
		update_option(
			self::TEST_RESULT,
			array(
				'time'    => time(),
				'env'     => $env,
				'ok'      => (bool) $r['ok'],
				'message' => $r['ok'] ? sprintf( /* translators: %d: HTTP status */ __( 'Bağlantı başarılı (HTTP %d).', 'consent-manager-for-iys' ), (int) $r['code'] ) : Eru_CMI_Logger::redact( (string) $r['error'] ),
			),
			false
		);
	}
}
