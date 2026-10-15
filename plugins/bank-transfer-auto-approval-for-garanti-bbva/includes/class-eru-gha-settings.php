<?php
/**
 * Settings: one non-autoloaded option, write-only client secret, TR IBAN checksum, reference pattern check.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything starts switched off: no outbound request and no scheduled job until the master switch is on
 * and the credentials are saved. Dry-run is on by default.
 */
class Eru_GHA_Settings {

	const OPTION = 'eru_gha_settings';
	const GROUP  = 'eru_gha';

	/** A 3–10 digit number that is not part of a longer number. */
	const DEFAULT_PATTERN = '(?<!\d)(\d{3,10})(?!\d)';

	/** Fields that are never rendered back into a form. */
	const SECRETS = array( 'client_secret' );

	/** @var bool True while the plugin itself writes the option (bypasses the form sanitiser). */
	private static $internal = false;

	/** @var array|null Last sanitised value (WordPress sanitises twice when the option is first added). */
	private static $last = null;

	/** @return array Defaults. */
	public static function defaults() {
		return array(
			'enabled'          => 'no',
			'dry_run'          => 'yes',
			'client_id'        => '',
			'client_secret'    => '',
			'consent_id'       => '',
			'account_number'   => '',
			'unit_number'      => '',
			'iban'             => '',
			'gateways'         => array( 'bacs' ),
			'watch_statuses'   => array( 'on-hold', 'pending' ),
			'approved_status'  => 'processing',
			'ref_pattern'      => self::DEFAULT_PATTERN,
			'ref_template'     => '',
			'lookback_hours'   => '48',
			'tolerance'        => '0',
			'allow_split'      => 'no',
			'notify_emails'    => (string) get_option( 'admin_email' ),
			'interval'         => '15',
			'log_days'         => '30',
		);
	}

	/** @return array<string,string> Poll intervals in minutes. */
	public static function intervals() {
		return array(
			'5'  => __( '5 dakika', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'15' => __( '15 dakika', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'30' => __( '30 dakika', 'bank-transfer-auto-approval-for-garanti-bbva' ),
		);
	}

	/** @return array Saved settings over defaults. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** @param string $key Key. @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/** @param string $key Key. @return bool */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/** @return bool Client id, secret, consent id and account number are saved. */
	public static function has_credentials() {
		foreach ( array( 'client_id', 'client_secret', 'consent_id', 'account_number' ) as $k ) {
			if ( '' === trim( (string) self::get( $k ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool Master switch on and credentials saved (scheduled polling runs). */
	public static function is_enabled() {
		return self::on( 'enabled' ) && self::has_credentials();
	}

	/** @return bool Dry-run: match and log only. */
	public static function dry_run() {
		return self::on( 'dry_run' );
	}

	/** @return string[] Watched statuses without the wc- prefix. */
	public static function watch_statuses() {
		$v = self::get( 'watch_statuses' );
		return is_array( $v ) ? array_values( array_diff( array_filter( array_map( 'sanitize_key', $v ) ), self::unwatchable() ) ) : array();
	}

	/** @return string[] Watched gateway ids. */
	public static function gateways() {
		$v = self::get( 'gateways' );
		return is_array( $v ) ? array_values( array_filter( array_map( 'sanitize_key', $v ) ) ) : array();
	}

	/** @return float Amount tolerance. */
	public static function tolerance() {
		return max( 0.0, min( 10.0, (float) self::get( 'tolerance' ) ) );
	}

	/** @return int Lookback hours (24–96). */
	public static function lookback() {
		return max( 24, min( 96, (int) self::get( 'lookback_hours' ) ) );
	}

	/** @return string[] Valid notification addresses. */
	public static function notify_emails() {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) self::get( 'notify_emails' ) ) as $e ) {
			$e = sanitize_email( $e );
			if ( '' !== $e && is_email( $e ) ) {
				$out[] = $e;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return string[] Statuses that may never be watched (final or already paid). */
	public static function unwatchable() {
		return array_values( array_unique( array_merge( array( 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash' ), (array) wc_get_is_paid_statuses() ) ) );
	}

	/** @return array<string,string> Statuses that may be watched. */
	public static function watchable_statuses() {
		return array_diff_key( self::order_statuses(), array_flip( self::unwatchable() ) );
	}

	/** @return array<string,string> Order statuses without the wc- prefix. */
	public static function order_statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $k => $label ) {
			$out[ preg_replace( '/^wc-/', '', $k ) ] = $label;
		}
		return $out;
	}

	/**
	 * TR IBAN: "TR", 24 digits, ISO 13616 mod-97 checksum.
	 *
	 * @param string $iban Value (spaces allowed).
	 * @return bool
	 */
	public static function valid_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		if ( ! preg_match( '/^TR\d{24}$/', $iban ) ) {
			return false;
		}
		$moved = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$num   = '';
		foreach ( str_split( $moved ) as $c ) {
			$num .= ctype_alpha( $c ) ? (string) ( ord( $c ) - 55 ) : $c;
		}
		$mod = 0;
		foreach ( str_split( $num ) as $d ) {
			$mod = ( $mod * 10 + (int) $d ) % 97;
		}
		return 1 === $mod;
	}

	/** Internal write: merge values, keep autoload off, follow the schedule. @param array $values Values. */
	public static function update( array $values ) {
		$saved          = get_option( self::OPTION, array() );
		$saved          = is_array( $saved ) ? $saved : array();
		self::$internal = true;
		try {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, array_merge( $saved, $values ), '', false );
			} else {
				update_option( self::OPTION, array_merge( $saved, $values ), false );
			}
		} finally {
			self::$internal = false;
		}
		if ( class_exists( 'Eru_GHA_Cron' ) ) {
			Eru_GHA_Cron::sync();
		}
	}

	/** Test helper. @param array $values Values. */
	public static function save_for_test( array $values ) {
		self::update( $values );
	}

	/** Hooks. */
	public static function init() {
		add_filter( 'option_page_capability_' . self::GROUP, fn() => 'manage_woocommerce' );
		add_action(
			'admin_init',
			function () {
				register_setting(
					self::GROUP,
					self::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize' ),
						'default'           => array(),
					)
				);
			}
		);
		foreach ( array( 'update_option_', 'add_option_' ) as $prefix ) {
			add_action(
				$prefix . self::OPTION,
				function () {
					wp_set_option_autoload( self::OPTION, false );
					if ( class_exists( 'Eru_GHA_Cron' ) ) {
						Eru_GHA_Cron::sync();
					}
				}
			);
		}
	}

	/** @param string $msg Message. */
	private static function error( $msg ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, 'eru-gha-' . md5( $msg ), $msg );
		}
	}

	/**
	 * options.php sanitiser. The secret is kept when left blank; invalid IBAN / pattern keep the old value.
	 *
	 * @param mixed $in Posted values (unslashed by options.php).
	 * @return array
	 */
	public static function sanitize( $in ) {
		if ( self::$internal ) {
			return is_array( $in ) ? $in : array();
		}
		if ( null !== self::$last && $in === self::$last ) {
			return $in;
		}
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( ! current_user_can( 'manage_woocommerce' ) || ! is_array( $in ) ) {
			return $saved;
		}
		$out = array_merge( self::defaults(), $saved );
		$yes = fn( $k ) => ! empty( $in[ $k ] ) ? 'yes' : 'no';

		$out['enabled']        = $yes( 'enabled' );
		$out['dry_run']        = $yes( 'dry_run' );
		$out['allow_split']    = $yes( 'allow_split' );
		$out['client_id']      = sanitize_text_field( $in['client_id'] ?? '' );
		$out['consent_id']     = sanitize_text_field( $in['consent_id'] ?? '' );
		$out['account_number'] = preg_replace( '/\D/', '', (string) ( $in['account_number'] ?? '' ) );
		$out['unit_number']    = preg_replace( '/\D/', '', (string) ( $in['unit_number'] ?? '' ) );
		foreach ( self::SECRETS as $k ) {
			$v = trim( (string) ( $in[ $k ] ?? '' ) );
			if ( '' !== $v ) {
				$out[ $k ] = $v;
			}
		}
		if ( ! empty( $in['clear_secret'] ) ) {
			$out['client_secret'] = '';
		}

		$iban = strtoupper( preg_replace( '/\s+/', '', (string) ( $in['iban'] ?? '' ) ) );
		if ( '' === $iban || self::valid_iban( $iban ) ) {
			$out['iban'] = $iban;
		} else {
			self::error( __( 'IBAN geçersiz (TR ile başlayan 26 karakter ve doğru kontrol basamakları gerekir); önceki değer korundu.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}

		$gw_all          = array_keys( WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array() );
		$gw              = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['gateways'] ?? array() ) ), $gw_all ) );
		$out['gateways'] = $gw ? $gw : array( 'bacs' );

		$statuses = array_keys( self::order_statuses() );
		$approved = sanitize_key( $in['approved_status'] ?? '' );
		$approved = in_array( $approved, $statuses, true ) ? $approved : 'processing';
		$watch    = array_values( array_diff( array_intersect( array_map( 'sanitize_key', (array) ( $in['watch_statuses'] ?? array() ) ), array_keys( self::watchable_statuses() ) ), array( $approved ) ) );
		if ( ! $watch ) {
			self::error( __( 'En az bir izlenen durum seçin (onay durumundan farklı). Önceki seçim korundu.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
			$watch = array_values( array_diff( (array) $out['watch_statuses'], array( $approved ) ) );
		}
		$out['watch_statuses']  = $watch;
		$out['approved_status'] = $approved;

		$pattern  = trim( (string) ( $in['ref_pattern'] ?? '' ) );
		$pattern  = '' === $pattern ? self::DEFAULT_PATTERN : $pattern;
		$template = sanitize_text_field( $in['ref_template'] ?? '' );
		$c        = Eru_GHA_Matcher::compile( $pattern, $template );
		if ( '' === $c['error'] ) {
			$out['ref_pattern']  = $pattern;
			$out['ref_template'] = $template;
		} else {
			self::error( __( 'Referans deseni kaydedilmedi: ', 'bank-transfer-auto-approval-for-garanti-bbva' ) . $c['error'] );
		}

		$out['lookback_hours'] = (string) max( 24, min( 96, absint( $in['lookback_hours'] ?? 48 ) ) );
		$tol                   = (float) str_replace( ',', '.', (string) ( $in['tolerance'] ?? '0' ) );
		$out['tolerance']      = (string) round( max( 0, min( 10, $tol ) ), 2 );
		$out['notify_emails']  = implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $in['notify_emails'] ?? '' ) ) ), 'is_email' ) );
		$iv                    = (string) absint( $in['interval'] ?? 15 );
		$out['interval']       = array_key_exists( $iv, self::intervals() ) ? $iv : '15';
		$out['log_days']       = (string) max( 7, min( 365, absint( $in['log_days'] ?? 30 ) ) );

		self::$last = $out;
		return $out;
	}
}
