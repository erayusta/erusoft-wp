<?php
/**
 * Settings: one non-autoloaded option for the general tab and one per marketplace (eru_mpo_mp_<key>).
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything starts inert: the master switch is off and observe mode is on, so even after the switch is turned
 * on nothing is created until observe mode is turned off. Keys of the general option:
 *
 * - enabled              yes|no   master switch (default no)
 * - observe              yes|no   observe mode: fetch and log "aktarılırdı", create nothing (default yes)
 * - window_days          int      order scan window in days (default 3, 1–30)
 * - lifecycle_days       int      lifecycle (cargo/cancel/return) window in days (default 14, 1–60)
 * - raw_days             int      raw payload retention (default 30, 1–365)
 * - log_days             int      log retention (default 14, 1–365)
 * - stock_mode           leave|reduce  WooCommerce stock on import (default leave)
 * - status_mode          own|core      marketplace statuses or core statuses (default own)
 * - mute_notifications   yes|no   isolated save (default yes)
 * - store_identity       yes|no   copy the buyer's identity number to order meta for e-Arşiv (default no)
 *
 * Marketplace options (eru_mpo_mp_<key>) hold that marketplace's switches and credentials; adapters read them
 * with mp_get(). Secret values never leave the option: forms render them empty with a "Kayıtlı" hint.
 */
class Eru_MPO_Settings {

	const OPTION       = 'eru_mpo_settings';
	const MP_PREFIX    = 'eru_mpo_mp_';
	const STATUS_MODES = array( 'own', 'core' );
	const STOCK_MODES  = array( 'leave', 'reduce' );
	const BOUNDS       = array(
		'window_days'    => array( 1, 30 ),
		'lifecycle_days' => array( 1, 60 ),
		'raw_days'       => array( 1, 365 ),
		'log_days'       => array( 1, 365 ),
	);

	/** @var array|null Request cache. */
	private static $cache = null;

	/** @var array<string,array> Request cache of marketplace options. */
	private static $mp_cache = array();

	/** @return array Defaults. */
	public static function defaults() {
		return array(
			'enabled'            => 'no',
			'observe'            => 'yes',
			'window_days'        => 3,
			'lifecycle_days'     => 14,
			'raw_days'           => 30,
			'log_days'           => 14,
			'stock_mode'         => 'leave',
			'status_mode'        => 'own',
			'mute_notifications' => 'yes',
			'store_identity'     => 'no',
		);
	}

	/** @return array Saved settings over defaults. */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( array_merge( self::defaults(), is_array( $saved ) ? $saved : array() ) );
		}
		return self::$cache;
	}

	/** @param string $key Key. @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/**
	 * Saves the general settings (unknown keys dropped, values clamped), autoload off.
	 *
	 * @param array $in Values.
	 */
	public static function update( array $in ) {
		$clean = self::sanitize( array_merge( self::all(), $in ) );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $clean, '', false );
		} else {
			update_option( self::OPTION, $clean, false );
		}
		self::$cache = null;
	}

	/** Forgets the request cache (tests, after direct option writes). */
	public static function reset_cache() {
		self::$cache    = null;
		self::$mp_cache = array();
	}

	/**
	 * @param array $in Raw values.
	 * @return array Clean values (only known keys).
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( array( 'enabled', 'observe', 'mute_notifications', 'store_identity' ) as $k ) {
			$out[ $k ] = 'yes' === ( $in[ $k ] ?? $d[ $k ] ) ? 'yes' : 'no';
		}
		foreach ( self::BOUNDS as $k => $b ) {
			$v         = isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? (int) $in[ $k ] : $d[ $k ];
			$out[ $k ] = max( $b[0], min( $b[1], $v ) );
		}
		$out['stock_mode']  = in_array( $in['stock_mode'] ?? '', self::STOCK_MODES, true ) ? $in['stock_mode'] : $d['stock_mode'];
		$out['status_mode'] = in_array( $in['status_mode'] ?? '', self::STATUS_MODES, true ) ? $in['status_mode'] : $d['status_mode'];
		return $out;
	}

	/** @return bool Master switch. */
	public static function enabled() {
		return 'yes' === self::get( 'enabled' );
	}

	/** @return bool Observe mode (global). */
	public static function observe() {
		return 'yes' === self::get( 'observe' );
	}

	/** @return bool Isolated save on. */
	public static function mute() {
		return 'yes' === self::get( 'mute_notifications' );
	}

	/** @return bool Copy the identity number to order meta. */
	public static function store_identity() {
		return 'yes' === self::get( 'store_identity' );
	}

	/** @return string leave | reduce. */
	public static function stock_mode() {
		return (string) self::get( 'stock_mode' );
	}

	/** @return string own | core. */
	public static function status_mode() {
		return (string) self::get( 'status_mode' );
	}

	/** @param string $key window_days | lifecycle_days | raw_days | log_days. @return int */
	public static function days( $key ) {
		return (int) self::get( $key );
	}

	/** @param string $key Marketplace key. @return string Option name. */
	public static function mp_option( $key ) {
		return self::MP_PREFIX . sanitize_key( $key );
	}

	/** @param string $key Marketplace key. @return array Its stored settings. */
	public static function mp( $key ) {
		$key = sanitize_key( $key );
		if ( ! isset( self::$mp_cache[ $key ] ) ) {
			$v                      = get_option( self::mp_option( $key ), array() );
			self::$mp_cache[ $key ] = is_array( $v ) ? $v : array();
		}
		return self::$mp_cache[ $key ];
	}

	/**
	 * @param string $key     Marketplace key.
	 * @param string $field   Field.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function mp_get( $key, $field, $default = '' ) {
		$mp = self::mp( $key );
		return array_key_exists( $field, $mp ) ? $mp[ $field ] : $default;
	}

	/**
	 * Merges values into a marketplace option (autoload off). Callers sanitise their own fields.
	 *
	 * @param string $key    Marketplace key.
	 * @param array  $values Field => value.
	 */
	public static function mp_update( $key, array $values ) {
		$name = self::mp_option( $key );
		$new  = array_merge( self::mp( $key ), $values );
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $new, '', false );
		} else {
			update_option( $name, $new, false );
		}
		unset( self::$mp_cache[ sanitize_key( $key ) ] );
	}
}
