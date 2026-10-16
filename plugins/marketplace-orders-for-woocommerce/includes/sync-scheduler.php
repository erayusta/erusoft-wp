<?php
/**
 * Sync scheduler (order and lifecycle pulls per marketplace) and the acknowledgement queue.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler, group eru-mpo, one chain per enabled marketplace and kind:
 *
 * - eru_mpo_sync_orders( $key ) every 5 minutes, eru_mpo_sync_lifecycle( $key ) every 15 minutes (filter
 *   eru_mpo_sync_interval( $seconds, $kind, $key )). Each run first arms its next run, checking PENDING actions
 *   only (the running action does not count), then pulls through Eru_MPO_Importer::pull(). A Throwable from the
 *   pull is caught and logged; a fatal error or a killed process still leaves the next run pending. Only a backoff
 *   moves that pending run; a normal outcome keeps it (a slow pull does not cancel and re-create it).
 * - A run holds an atomic lock per marketplace and kind (Eru_MPO_Lock "sync_<kind>_<key>"); a second runner finds
 *   it held, does nothing and keeps the chain alive.
 * - A pull that failed with a retryable HTTP error (429, 5xx, timeout; Eru_MPO_Http::last_failure()) comes back
 *   after 1, 2, 4 … minutes (capped at 30; a longer Retry-After is honoured up to the cap) instead of the normal
 *   interval. Nothing ever sleeps. The count resets after a run without such an error.
 * - Nothing is scheduled while the master switch is off, the marketplace is off or not configured, or the older
 *   plugin of that marketplace is active (logged once a day). ensure() arms and disarms the chains: after settings
 *   change (once, at the end of the request), from the daily job and after reactivation. Deactivation removes the
 *   whole group (Eru_MPO_Plugin::deactivate()).
 *
 * Run state per marketplace and kind: option eru_mpo_sync_<kind>_<key> (autoload off): last (UTC), result, fails,
 * next (UTC timestamp), code (last HTTP status of a retryable failure).
 */
class Eru_MPO_Sync {

	const ORDERS    = 'eru_mpo_sync_orders';
	const LIFECYCLE = 'eru_mpo_sync_lifecycle';
	const KINDS     = array(
		'orders'    => self::ORDERS,
		'lifecycle' => self::LIFECYCLE,
	);
	const MAX_DELAY = 1800;

	/** @var bool ensure() is queued for the end of this request. */
	private static $dirty = false;

	/** Hooks. */
	public static function init() {
		add_action( self::ORDERS, array( __CLASS__, 'run_orders' ) );
		add_action( self::LIFECYCLE, array( __CLASS__, 'run_lifecycle' ) );
		add_action( 'added_option', array( __CLASS__, 'on_option' ) );
		add_action( 'updated_option', array( __CLASS__, 'on_option' ) );
		add_action( Eru_MPO_Cron::DAILY, array( __CLASS__, 'ensure' ), 5 );
		if ( get_option( 'eru_mpo_rearm' ) ) {
			add_action( 'init', array( __CLASS__, 'ensure' ), 19 );
		}
	}

	/** Settings of the plugin changed: arm/disarm at the end of the request. @param string $name Option. */
	public static function on_option( $name ) {
		$name = (string) $name;
		if ( self::$dirty || ( Eru_MPO_Settings::OPTION !== $name && 0 !== strpos( $name, Eru_MPO_Settings::MP_PREFIX ) ) ) {
			return;
		}
		self::$dirty = true;
		add_action( 'shutdown', array( __CLASS__, 'ensure_deferred' ) );
	}

	/** End of a request that changed settings. */
	public static function ensure_deferred() {
		self::$dirty = false;
		Eru_MPO_Settings::reset_cache();
		self::ensure();
	}

	/** @param string $key Marketplace. */
	public static function run_orders( $key ) {
		return self::run( 'orders', $key );
	}

	/** @param string $key Marketplace. */
	public static function run_lifecycle( $key ) {
		return self::run( 'lifecycle', $key );
	}

	/** @param string $kind orders | lifecycle. @param string $key Marketplace. @return int Seconds. */
	public static function interval( $kind, $key ) {
		$sec = 'lifecycle' === $kind ? 15 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
		return max( MINUTE_IN_SECONDS, (int) apply_filters( 'eru_mpo_sync_interval', $sec, $kind, $key ) );
	}

	/** @param string $key Marketplace. @return bool Pulls should run for it. */
	public static function wanted( $key ) {
		if ( ! Eru_MPO_Settings::enabled() ) {
			return false;
		}
		$a = Eru_MPO_Registry::get( $key );
		return $a && $a->is_enabled() && ! in_array( $key, Eru_MPO_Migrator::legacy_active(), true );
	}

	/**
	 * @param string $hook Hook.
	 * @param string $key  Marketplace.
	 * @return int[] PENDING actions of the hook for this marketplace (a running one is not pending).
	 */
	public static function pending( $hook, $key ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}
		return as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => array( (string) $key ),
				'group'    => Eru_MPO_Cron::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			),
			'ids'
		);
	}

	/** Arms the chains that should run and removes the others; re-arms queued acknowledgements. */
	public static function ensure() {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		if ( ! did_action( 'action_scheduler_init' ) ) {
			add_action( 'action_scheduler_init', array( __CLASS__, 'ensure' ) );
			return;
		}
		foreach ( array_keys( Eru_MPO_Registry::all() ) as $key ) {
			$want = self::wanted( $key );
			foreach ( self::KINDS as $kind => $hook ) {
				if ( $want ) {
					self::arm( $kind, $key, 'orders' === $kind ? 30 : 2 * MINUTE_IN_SECONDS );
				} elseif ( self::pending( $hook, $key ) ) {
					as_unschedule_all_actions( $hook, array( (string) $key ), Eru_MPO_Cron::GROUP );
				}
			}
		}
		if ( Eru_MPO_Settings::enabled() ) {
			Eru_MPO_Ack::rearm();
		}
		/** Other modules (stock bridge) arm or disarm their jobs at the same moments. */
		do_action( 'eru_mpo_ensure' );
	}

	/**
	 * "Şimdi senkronla": moves the pending order and lifecycle runs of a marketplace to now (Action Scheduler runs
	 * them; nothing is fetched in the calling request).
	 *
	 * @param string $key Marketplace.
	 * @return true|WP_Error
	 */
	public static function run_soon( $key ) {
		$key = sanitize_key( (string) $key );
		if ( ! self::wanted( $key ) ) {
			return new WP_Error( 'eru_mpo_not_ready', __( 'Bu pazaryeri için çekim kapalı: ana anahtarı ve pazaryerini açın, API bilgilerini girin (eski eklenti etkin olmamalı).', 'marketplace-orders-for-woocommerce' ) );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return new WP_Error( 'eru_mpo_no_scheduler', __( 'Action Scheduler bulunamadı.', 'marketplace-orders-for-woocommerce' ) );
		}
		self::rearm( 'orders', $key, 1 );
		self::rearm( 'lifecycle', $key, 30 );
		return true;
	}

	/** Moves the pending next run to $delay from now (or schedules it). */
	private static function rearm( $kind, $key, $delay ) {
		$hook = self::KINDS[ $kind ];
		$want = time() + max( 1, (int) $delay );
		foreach ( self::pending( $hook, $key ) as $id ) {
			$a = ActionScheduler::store()->fetch_action( $id );
			$d = $a && $a->get_schedule() ? $a->get_schedule()->get_date() : null;
			if ( $d && abs( $d->getTimestamp() - $want ) <= 5 ) {
				return; // Already where it should be.
			}
			ActionScheduler::store()->cancel_action( $id );
		}
		as_schedule_single_action( $want, $hook, array( (string) $key ), Eru_MPO_Cron::GROUP );
	}

	/** Schedules the next run unless one is pending. */
	private static function arm( $kind, $key, $delay ) {
		$hook = self::KINDS[ $kind ];
		if ( ! self::pending( $hook, $key ) ) {
			as_schedule_single_action( time() + max( 1, (int) $delay ), $hook, array( (string) $key ), Eru_MPO_Cron::GROUP );
		}
	}

	/** @param string $kind Kind. @param string $key Marketplace. @return string Option name of the run state. */
	public static function state_option( $kind, $key ) {
		return 'eru_mpo_sync_' . sanitize_key( $kind ) . '_' . sanitize_key( $key );
	}

	/** @param string $kind Kind. @param string $key Marketplace. @return array Run state. */
	public static function state( $kind, $key ) {
		$s = get_option( self::state_option( $kind, $key ), array() );
		return is_array( $s ) ? $s : array();
	}

	/** Stores the run state (autoload off). */
	private static function save_state( $kind, $key, array $s ) {
		$name = self::state_option( $kind, $key );
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $s, '', false );
		} else {
			update_option( $name, $s, false );
		}
	}

	/**
	 * One scheduled pull, then the next one is armed (normal interval, or the backoff after a retryable failure).
	 *
	 * @param string $kind orders | lifecycle.
	 * @param string $key  Marketplace.
	 * @return array Pull summary plus delay (seconds until the next run; 0 = not re-armed).
	 */
	public static function run( $kind, $key ) {
		$kind = isset( self::KINDS[ $kind ] ) ? $kind : 'orders';
		$key  = sanitize_key( (string) $key );
		if ( ! self::wanted( $key ) ) {
			if ( Eru_MPO_Settings::enabled() && in_array( $key, Eru_MPO_Migrator::legacy_active(), true ) ) {
				$tag = __( 'Eski eklenti etkin', 'marketplace-orders-for-woocommerce' );
				if ( ! Eru_MPO_Logger::seen_since( $key, '', $tag, gmdate( 'Y-m-d 00:00:00' ) ) ) {
					/* translators: %s: "Eski eklenti etkin" */
					Eru_MPO_Logger::warning( sprintf( __( '%s: zamanlanmış çekim durduruldu; eski eklentiyi devre dışı bırakıp geçişi çalıştırın.', 'marketplace-orders-for-woocommerce' ), $tag ), $key );
				}
			}
			return array(
				'result' => 'not_wanted',
				'delay'  => 0,
			);
		}
		$lock  = 'sync_' . $kind . '_' . $key;
		$token = Eru_MPO_Lock::acquire( $lock, 15 * MINUTE_IN_SECONDS );
		if ( ! $token ) {
			$delay = self::interval( $kind, $key );
			self::arm( $kind, $key, $delay );
			return array(
				'result' => 'locked',
				'delay'  => $delay,
			);
		}
		try {
			// The next run is armed before pulling: a fatal error or a killed process inside the pull leaves the chain
			// alive. A backoff below replaces it.
			self::arm( $kind, $key, self::interval( $kind, $key ) );
			Eru_MPO_Http::reset( $key );
			$thrown = '';
			try {
				$res = Eru_MPO_Importer::pull( $key, array( 'lifecycle' => 'lifecycle' === $kind ) );
			} catch ( Throwable $e ) {
				$thrown = $e->getMessage();
				$res    = array(
					'result'  => 'error',
					'fetched' => 0,
					'created' => 0,
					'updated' => 0,
					'errors'  => 1,
				);
				/* translators: %s: error */
				Eru_MPO_Logger::error( sprintf( __( 'Zamanlanmış çekim beklenmeyen bir hatayla bitti: %s', 'marketplace-orders-for-woocommerce' ), $thrown ), $key );
			}
			$fail = Eru_MPO_Http::last_failure( $key );
			if ( '' !== $thrown && ! is_array( $fail ) ) {
				$fail = array(
					'retryable'   => true,
					'code'        => 0,
					'retry_after' => 0,
				);
			}
			$state = self::state( $kind, $key );
			if ( 'error' === $res['result'] && is_array( $fail ) && ! empty( $fail['retryable'] ) ) {
				$n     = (int) ( $state['fails'] ?? 0 ) + 1;
				$delay = (int) min( self::MAX_DELAY, MINUTE_IN_SECONDS * ( 2 ** min( 10, $n - 1 ) ) );
				$delay = (int) min( self::MAX_DELAY, max( $delay, (int) $fail['retry_after'] ) );
				$state = array(
					'last'   => gmdate( 'Y-m-d H:i:s' ),
					'result' => 'retry',
					'fails'  => $n,
					'code'   => (int) $fail['code'],
					'next'   => time() + $delay,
				);
				/* translators: 1: HTTP status (0 = no answer), 2: minutes, 3: attempt number */
				Eru_MPO_Logger::warning( sprintf( __( 'Geçici hata (HTTP %1$d): %2$d dakika sonra yeniden denenecek (%3$d. deneme).', 'marketplace-orders-for-woocommerce' ), (int) $fail['code'], (int) ceil( $delay / MINUTE_IN_SECONDS ), $n ), $key );
			} else {
				$delay = self::interval( $kind, $key );
				$state = array(
					'last'    => gmdate( 'Y-m-d H:i:s' ),
					'result'  => (string) $res['result'],
					'fails'   => 0,
					'next'    => time() + $delay,
					'fetched' => (int) $res['fetched'],
					'created' => (int) $res['created'],
					'updated' => (int) $res['updated'],
					'errors'  => (int) $res['errors'],
				);
			}
			self::save_state( $kind, $key, $state );
			if ( 'retry' === $state['result'] ) {
				self::rearm( $kind, $key, $delay ); // Backoff: move the pending run.
			} else {
				self::arm( $kind, $key, $delay ); // Normal: keep the run armed before the pull (never re-created).
			}
			return $res + array( 'delay' => $delay );
		} finally {
			Eru_MPO_Lock::release( $lock, $token );
		}
	}
}

/**
 * Acknowledgement queue: tells the marketplace the shop took the order (Trendyol: package to Picking;
 * Hepsiburada: packing). Ack mode per marketplace (eru_mpo_mp_<key>[ack_mode]):
 *
 * - never (default): nothing is sent.
 * - import: queued when a new order is imported (eru_mpo_order_imported, logical status new).
 * - status: queued when the shop moves the order to eru_mpo_mp_<key>[ack_status] (default eru-mpo-accepted).
 *
 * Each order runs through Action Scheduler (eru_mpo_ack( $order_id ), group eru-mpo) under an atomic per-order lock.
 * The adapter's ack_profile() names the order meta (Trendyol: _eru_mpo_ack_state / _ack_tries / _ack_error /
 * _ack_at; Hepsiburada: _eru_mpo_hb_pack_state / _hb_pack_tries / _hb_pack_error / _hb_packed_at), the backoff and
 * the attempt cap. State: queued → done (Hepsiburada "packed") | retry → … | failed (note + review flag) | skipped
 * (order closed). A done or failed order is never sent again; retries check pending actions only (a re-linked
 * order starts over for its new package). Observe mode,
 * a switched-off marketplace or ack mode "never" send nothing. After success the order moves forward to the
 * profile's status (Trendyol accepted, Hepsiburada ready) when that is a forward step.
 */
class Eru_MPO_Ack {

	const HOOK           = 'eru_mpo_ack';
	const DEFAULT_STATUS = 'eru-mpo-accepted';
	/** Per-order lock lifetime: longer than the worst-case acknowledgement run (Hepsiburada's 10-minute pack budget plus one request timeout). */
	const LOCK_TTL = 30 * MINUTE_IN_SECONDS;

	/** Hooks. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'eru_mpo_order_imported', array( __CLASS__, 'on_imported' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 10, 4 );
		add_action( 'eru_mpo_order_relinked', array( __CLASS__, 'on_relinked' ), 10, 3 );
	}

	/** @return array<string,string> Ack modes. */
	public static function modes() {
		return array(
			'never'  => __( 'Gönderme', 'marketplace-orders-for-woocommerce' ),
			'import' => __( 'Sipariş içe aktarılınca', 'marketplace-orders-for-woocommerce' ),
			'status' => __( 'Sipariş seçilen duruma geçince', 'marketplace-orders-for-woocommerce' ),
		);
	}

	/** @return array<string,string> Order statuses without "wc-" => label. */
	public static function status_options() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$out = array();
		foreach ( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() as $slug => $label ) {
			$out[ (string) preg_replace( '/^wc-/', '', (string) $slug ) ] = (string) $label;
		}
		foreach ( Eru_MPO_Statuses::labels() as $k => $label ) {
			$out[ Eru_MPO_Statuses::slug( $k ) ] = $out[ Eru_MPO_Statuses::slug( $k ) ] ?? $label;
		}
		if ( did_action( 'init' ) ) {
			$memo = $out; // Statuses are registered on init; before that the list may still change.
		}
		return $out;
	}

	/** @param string $key Marketplace. @return string never | import | status. */
	public static function mode( $key ) {
		$m = (string) Eru_MPO_Settings::mp_get( $key, 'ack_mode', 'never' );
		return isset( self::modes()[ $m ] ) ? $m : 'never';
	}

	/** @param string $key Marketplace. @return string Trigger status without "wc-". */
	public static function trigger_status( $key ) {
		$s = (string) Eru_MPO_Settings::mp_get( $key, 'ack_status', self::DEFAULT_STATUS );
		return '' !== $s ? (string) preg_replace( '/^wc-/', '', $s ) : self::DEFAULT_STATUS;
	}

	/** @param Eru_MPO_Adapter $adapter Adapter. @return array Profile with defaults. */
	public static function profile( $adapter ) {
		$p = method_exists( $adapter, 'ack_profile' ) ? (array) $adapter->ack_profile() : array();
		return array_merge(
			array(
				'state'   => '_eru_mpo_ack_state',
				'tries'   => '_eru_mpo_ack_tries',
				'error'   => '_eru_mpo_ack_error',
				'at'      => '_eru_mpo_ack_at',
				'done'    => 'done',
				'delays'  => array( MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS ),
				'max'     => 5,
				'advance' => '',
				'label'   => __( 'Pazaryeri bildirimi', 'marketplace-orders-for-woocommerce' ),
			),
			$p
		);
	}

	/** @param WC_Order $order Order. @return Eru_MPO_Adapter|null Its adapter. */
	private static function adapter_for( $order ) {
		$mp = $order instanceof WC_Order ? (string) $order->get_meta( '_eru_mpo_marketplace' ) : '';
		return '' !== $mp ? Eru_MPO_Registry::get( $mp ) : null;
	}

	/** eru_mpo_order_imported. @param WC_Order $order Order. @param Eru_MPO_Order_DTO $dto DTO. */
	public static function on_imported( $order, $dto ) {
		$a = self::adapter_for( $order );
		if ( $a && 'import' === self::mode( $a->key() ) && 'new' === Eru_MPO_Statuses::current( $order ) ) {
			self::enqueue( $order );
		}
	}

	/** woocommerce_order_status_changed. */
	public static function on_status( $order_id, $from, $to, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		$a     = self::adapter_for( $order );
		if ( $a && 'status' === self::mode( $a->key() ) && (string) $to === self::trigger_status( $a->key() ) ) {
			self::enqueue( $order );
		}
	}

	/**
	 * eru_mpo_order_relinked: the order now follows a new package, which has not been acknowledged yet. The
	 * acknowledgement state of the earlier package is cleared and, in "on import" mode, the new one is queued.
	 *
	 * @param WC_Order          $order Order.
	 * @param string            $old   Earlier package.
	 * @param Eru_MPO_Order_DTO $dto   New package.
	 */
	public static function on_relinked( $order, $old, $dto ) {
		$a = self::adapter_for( $order );
		if ( ! $a ) {
			return;
		}
		$p = self::profile( $a );
		foreach ( array( $p['state'], $p['tries'], $p['error'], $p['at'], '_eru_mpo_ack_check' ) as $k ) {
			$order->delete_meta_data( $k );
		}
		$order->save_meta_data();
		if ( 'import' === self::mode( $a->key() ) && ! in_array( Eru_MPO_Statuses::current( $order ), Eru_MPO_Statuses::TERMINAL, true ) ) {
			self::enqueue( $order );
		}
	}

	/**
	 * @param int $order_id Order.
	 * @return int[] Pending acknowledgement actions of the order (the running one is not pending).
	 */
	public static function pending( $order_id ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}
		return as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'args'     => array( (int) $order_id ),
				'group'    => Eru_MPO_Cron::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			),
			'ids'
		);
	}

	/**
	 * Queues the acknowledgement of an order (once: a done or failed order is never queued again).
	 *
	 * @param WC_Order $order Order.
	 * @param int      $delay Seconds.
	 * @return bool Queued (or already pending).
	 */
	public static function enqueue( $order, $delay = 0 ) {
		$a = self::adapter_for( $order );
		if ( ! $a || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$p     = self::profile( $a );
		$state = (string) $order->get_meta( $p['state'] );
		if ( in_array( $state, array( $p['done'], 'failed', 'skipped' ), true ) ) {
			return false;
		}
		if ( '' === $state ) {
			// Meta only: this also runs inside a status change of the order being saved.
			$order->update_meta_data( $p['state'], 'queued' );
			$order->save_meta_data();
		}
		if ( ! self::pending( $order->get_id() ) ) {
			as_schedule_single_action( time() + max( 0, (int) $delay ), self::HOOK, array( (int) $order->get_id() ), Eru_MPO_Cron::GROUP );
		}
		return true;
	}

	/** Saves an order with listeners muted (a listener error after the save is ignored here). */
	private static function save( $order ) {
		try {
			Eru_MPO_Isolated_Save::save( $order );
		} catch ( Eru_MPO_Listener_Error $e ) {
			unset( $e );
		}
	}

	/**
	 * Runs one acknowledgement attempt (Action Scheduler).
	 *
	 * @param int $order_id Order.
	 * @return string Outcome: done | retry | failed | skipped | idle | locked.
	 */
	public static function run( $order_id ) {
		$order_id = (int) $order_id;
		$lock     = 'ack_' . $order_id;
		$token    = Eru_MPO_Lock::acquire( $lock, self::LOCK_TTL );
		if ( ! $token ) {
			return 'locked';
		}
		try {
			Eru_MPO_Importer::forget_order( $order_id );
			$order = wc_get_order( $order_id );
			$a     = self::adapter_for( $order );
			if ( ! $order || ! $a ) {
				return 'idle';
			}
			$mp    = $a->key();
			$ref   = (string) $order->get_meta( '_eru_mpo_remote_ref' );
			$p     = self::profile( $a );
			$state = (string) $order->get_meta( $p['state'] );
			if ( in_array( $state, array( $p['done'], 'failed', 'skipped' ), true ) ) {
				return 'idle';
			}
			if ( ! Eru_MPO_Settings::enabled() || ! $a->is_enabled() ) {
				return 'idle'; // Re-armed by Eru_MPO_Sync::ensure() once the marketplace is on again.
			}
			if ( 'never' === self::mode( $mp ) || $a->is_observe_mode() ) {
				if ( $a->is_observe_mode() ) {
					/* translators: %s: acknowledgement label */
					Eru_MPO_Logger::info( sprintf( __( 'Gözlem modu: %s gönderilmedi.', 'marketplace-orders-for-woocommerce' ), $p['label'] ), $mp, $ref );
				}
				$order->delete_meta_data( $p['state'] );
				self::save( $order );
				return 'idle';
			}
			if ( in_array( Eru_MPO_Statuses::current( $order ), Eru_MPO_Statuses::TERMINAL, true ) ) {
				$order->update_meta_data( $p['state'], 'skipped' );
				self::save( $order );
				return 'skipped';
			}
			$tries = (int) $order->get_meta( $p['tries'] ) + 1;
			$order->update_meta_data( $p['tries'], $tries );
			self::save( $order );

			try {
				$r = $a->push_acknowledge( $order );
			} catch ( Throwable $e ) {
				$r = new WP_Error( 'eru_mpo_ack_exception', $e->getMessage(), array( 'retryable' => true ) );
			}

			if ( true === $r ) {
				$order->update_meta_data( $p['state'], $p['done'] );
				$order->update_meta_data( $p['at'], gmdate( 'Y-m-d H:i:s' ) );
				$order->delete_meta_data( $p['error'] );
				/* translators: %s: acknowledgement label */
				$order->add_order_note( sprintf( __( '%s tamamlandı.', 'marketplace-orders-for-woocommerce' ), $p['label'] ) );
				self::save( $order );
				/* translators: %s: acknowledgement label */
				Eru_MPO_Logger::info( sprintf( __( '%s tamamlandı.', 'marketplace-orders-for-woocommerce' ), $p['label'] ), $mp, $ref );
				// Forward only, also against the status the order shows (a hand move is never undone by the ack).
				$shown = Eru_MPO_Statuses::key_from_wc_status( $order->get_status() );
				if ( '' !== $p['advance'] && Eru_MPO_Statuses::can_advance( Eru_MPO_Statuses::current( $order ), $p['advance'] ) && ( '' === $shown || Eru_MPO_Statuses::can_advance( $shown, $p['advance'] ) ) ) {
					Eru_MPO_Statuses::advance( $order, $p['advance'], $p['label'] );
				}
				do_action( 'eru_mpo_order_acknowledged', $order, $mp );
				return 'done';
			}

			$err   = is_wp_error( $r ) ? $r : new WP_Error( 'eru_mpo_ack_failed', __( 'Pazaryeri beklenmeyen bir sonuç döndü.', 'marketplace-orders-for-woocommerce' ), array( 'retryable' => true ) );
			$msg   = Eru_MPO_Logger::redact( $err->get_error_message() );
			$retry = Eru_MPO_Http::is_retryable( $err ) && $tries < (int) $p['max'];
			$order->update_meta_data( $p['error'], substr( $msg, 0, 500 ) );
			if ( $retry ) {
				$delays = array_values( (array) $p['delays'] );
				$delay  = (int) ( $delays[ min( $tries - 1, count( $delays ) - 1 ) ] ?? HOUR_IN_SECONDS );
				$data   = $err->get_error_data();
				$delay  = max( $delay, min( HOUR_IN_SECONDS, (int) ( is_array( $data ) ? ( $data['retry_after'] ?? 0 ) : 0 ) ) );
				$order->update_meta_data( $p['state'], 'retry' );
				if ( 1 === $tries ) {
					/* translators: 1: acknowledgement label, 2: error */
					$order->add_order_note( sprintf( __( '%1$s şimdilik yapılamadı (%2$s); otomatik olarak yeniden denenecek.', 'marketplace-orders-for-woocommerce' ), $p['label'], $msg ) );
				}
				self::save( $order );
				if ( ! self::pending( $order_id ) ) {
					as_schedule_single_action( time() + $delay, self::HOOK, array( $order_id ), Eru_MPO_Cron::GROUP );
				}
				/* translators: 1: acknowledgement label, 2: attempt, 3: minutes, 4: error */
				Eru_MPO_Logger::warning( sprintf( __( '%1$s denemesi %2$d başarısız; %3$d dakika sonra yeniden denenecek: %4$s', 'marketplace-orders-for-woocommerce' ), $p['label'], $tries, (int) ceil( $delay / MINUTE_IN_SECONDS ), $msg ), $mp, $ref );
				return 'retry';
			}
			$order->update_meta_data( $p['state'], 'failed' );
			$order->update_meta_data( '_eru_mpo_review', 'yes' );
			/* translators: 1: acknowledgement label, 2: attempts, 3: error */
			$order->add_order_note( sprintf( __( '%1$s %2$d denemede yapılamadı: %3$s. Pazaryeri panelinden elle tamamlayın.', 'marketplace-orders-for-woocommerce' ), $p['label'], $tries, $msg ) );
			self::save( $order );
			Eru_MPO_Map::set_review( $mp, $ref, true );
			/* translators: 1: acknowledgement label, 2: attempts, 3: error */
			Eru_MPO_Logger::error( sprintf( __( '%1$s %2$d denemede yapılamadı: %3$s', 'marketplace-orders-for-woocommerce' ), $p['label'], $tries, $msg ), $mp, $ref );
			return 'failed';
		} finally {
			Eru_MPO_Lock::release( $lock, $token );
		}
	}

	/** Re-arms queued or retrying acknowledgements that have no pending action (after reactivation). */
	public static function rearm() {
		global $wpdb;
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$keys = array();
		foreach ( Eru_MPO_Registry::all() as $a ) {
			$keys[] = self::profile( $a )['state'];
		}
		$keys = array_values( array_unique( $keys ) );
		if ( ! $keys ) {
			return;
		}
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		if ( Eru_MPO_Importer::hpos() ) {
			$sql = "SELECT DISTINCT m.order_id FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id AND o.type = 'shop_order' AND o.status <> 'trash' WHERE m.meta_key IN ({$in}) AND m.meta_value IN ('queued','retry') ORDER BY m.order_id ASC LIMIT 50";
		} else {
			$sql = "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} o ON o.ID = m.post_id AND o.post_type = 'shop_order' AND o.post_status <> 'trash' WHERE m.meta_key IN ({$in}) AND m.meta_value IN ('queued','retry') ORDER BY m.post_id ASC LIMIT 50";
		}
		foreach ( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $keys ) ) ) as $id ) { // phpcs:ignore
			if ( ! self::pending( $id ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK, array( $id ), Eru_MPO_Cron::GROUP );
			}
		}
	}
}
