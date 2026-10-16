<?php
/**
 * Settings: one non-autoloaded option, per-environment İYS credentials (write-only passwords),
 * queue and rule switches.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything starts switched off. `enabled()` is true only when the master switch is on and the
 * selected environment has a username, password, İYS code and brand code.
 */
class Eru_CMI_Settings {

	const OPTION = 'eru_cmi_settings';

	/**
	 * Tiny autoloaded flag ('1' = switched on and configured, '0' otherwise), kept in step with every
	 * save. Per-request code reads only this, so a switched-off site pays no query for the settings.
	 */
	const ACTIVE_FLAG = 'eru_cmi_active';

	/**
	 * Autoloaded mirror of the capture switches (checkout, register, account, endpoint), kept in step with
	 * every save, so front-end hooks decide what to do without reading the settings option.
	 */
	const FRONT_FLAG = 'eru_cmi_front';

	/** Default Hesabım endpoint slug. */
	const DEFAULT_ENDPOINT = 'iletisim-tercihleri';

	const GROUP  = 'eru_cmi';
	const ENVS   = array( 'sandbox', 'live' );

	/** İYS's documented public API hosts; each can be overridden per environment. */
	const DEFAULT_BASE = array(
		'sandbox' => 'https://api.sandbox.iys.org.tr',
		'live'    => 'https://api.iys.org.tr',
	);

	/** Per-environment keys (stored as "<env>_<key>"). */
	const ENV_KEYS = array( 'username', 'password', 'iys_code', 'brand_code', 'base_url' );

	/** @var bool True while the plugin writes the option itself (skips the form sanitiser). */
	private static $internal = false;

	/** @var array|null Last sanitised value (WordPress sanitises twice when the option is first added). */
	private static $last = null;

	/** @return array Defaults. */
	public static function defaults() {
		$d = array(
			'enabled'            => 'no',
			'environment'        => 'sandbox',
			'batch_size'         => '500',
			'restamp_old'        => 'no',
			'tacir_from_company' => 'no',
			// Toplama (capture) tab: every surface starts switched off.
			'channels'           => 'EPOSTA,MESAJ',
			'checkout_box'       => 'no',
			'checkout_label'     => '',
			'policy_page'        => '0',
			'register_box'       => 'no',
			'account_tab'        => 'no',
			'account_endpoint'   => self::DEFAULT_ENDPOINT,
			'token_days'         => '30',
			// Senkron: İYS → site pull, nightly reconcile (site time), retention.
			'pull_enabled'       => 'no',
			'reconcile_enabled'  => 'no',
			'reconcile_hour'     => '3',
			'log_days'           => '7',
			'hash_days'          => '0',
			// Harici API (write-only): off; the key is stored only as a hash, the secret signs requests.
			'ext_enabled'        => 'no',
			'ext_key_hash'       => '',
			'ext_key_hint'       => '',
			'ext_secret'         => '',
		);
		foreach ( self::ENVS as $env ) {
			foreach ( self::ENV_KEYS as $k ) {
				$d[ $env . '_' . $k ] = '';
			}
		}
		return $d;
	}

	/** @return array Saved values over the defaults. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** @param string $key Key. @return string */
	public static function get( $key ) {
		$all = self::all();
		return (string) ( $all[ $key ] ?? '' );
	}

	/** @param string $key Key. @return bool */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/** @return string sandbox | live */
	public static function env() {
		$e = self::get( 'environment' );
		return in_array( $e, self::ENVS, true ) ? $e : 'sandbox';
	}

	/**
	 * A per-environment value.
	 *
	 * @param string      $key username | password | iys_code | brand_code | base_url.
	 * @param string|null $env Environment (default: selected).
	 * @return string
	 */
	public static function cred( $key, $env = null ) {
		$env = $env ?? self::env();
		$v   = trim( self::get( $env . '_' . $key ) );
		if ( 'base_url' === $key && '' === $v ) {
			$v = self::DEFAULT_BASE[ $env ] ?? self::DEFAULT_BASE['sandbox'];
		}
		return $v;
	}

	/** @param string|null $env Environment. @return bool All four connection values are present. */
	public static function has_credentials( $env = null ) {
		foreach ( array( 'username', 'password', 'iys_code', 'brand_code' ) as $k ) {
			if ( '' === self::cred( $k, $env ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool Master switch on and the selected environment configured. */
	public static function enabled() {
		return self::on( 'enabled' ) && self::has_credentials();
	}

	/** @return bool The autoloaded flag says the integration is on (no settings read). */
	public static function active_flag() {
		return '1' === get_option( self::ACTIVE_FLAG, '0' );
	}

	/** Writes the flags from the saved settings (autoloaded; created on activation). */
	public static function sync_flag() {
		$v = self::enabled() ? '1' : '0';
		if ( get_option( self::ACTIVE_FLAG, null ) !== $v ) {
			update_option( self::ACTIVE_FLAG, $v, true );
		}
		self::sync_front();
	}

	/**
	 * @return array{checkout:int,register:int,account:int,endpoint:string,external:int} Capture switches and the
	 *         external API switch (autoloaded, no query).
	 */
	public static function front() {
		$f = get_option( self::FRONT_FLAG, null );
		$d = array(
			'checkout' => 0,
			'register' => 0,
			'account'  => 0,
			'endpoint' => self::DEFAULT_ENDPOINT,
			'external' => 0,
		);
		return is_array( $f ) ? array_merge( $d, array_intersect_key( $f, $d ) ) : $d;
	}

	/** Writes the capture mirror; a changed account tab or slug drops the rewrite rules (rebuilt on the next request). */
	public static function sync_front() {
		$v    = array(
			'checkout' => self::on( 'checkout_box' ) ? 1 : 0,
			'register' => self::on( 'register_box' ) ? 1 : 0,
			'account'  => self::on( 'account_tab' ) ? 1 : 0,
			'endpoint' => self::endpoint(),
			'external' => self::external_ready() ? 1 : 0,
		);
		$prev = get_option( self::FRONT_FLAG, null );
		if ( $prev === $v ) {
			return;
		}
		update_option( self::FRONT_FLAG, $v, true );
		if ( ! is_array( $prev ) || (int) ( $prev['account'] ?? 0 ) !== $v['account'] || ( $prev['endpoint'] ?? '' ) !== $v['endpoint'] ) {
			delete_option( 'rewrite_rules' );
		}
	}

	/** @return string[] Channels offered on the capture surfaces (EPOSTA, MESAJ, ARAMA). */
	public static function channels() {
		$c = array_values( array_intersect( Eru_CMI_Recipient::TYPES, array_map( 'strtoupper', array_map( 'trim', explode( ',', self::get( 'channels' ) ) ) ) ) );
		return $c ? $c : array( 'EPOSTA' );
	}

	/** @return string Hesabım endpoint slug. */
	public static function endpoint() {
		$s = sanitize_title( self::get( 'account_endpoint' ) );
		return '' === $s ? self::DEFAULT_ENDPOINT : $s;
	}

	/** @return int Opt-out link lifetime in seconds (1–365 days, default 30). */
	public static function token_ttl() {
		$d = (int) self::get( 'token_days' );
		return ( $d >= 1 && $d <= 365 ? $d : 30 ) * DAY_IN_SECONDS;
	}

	/** @return string Checkout and registration box label. */
	public static function box_label() {
		$l = trim( self::get( 'checkout_label' ) );
		return '' !== $l ? $l : __( 'Kampanya, indirim ve duyurulardan seçtiğim kanallarla haberdar olmak istiyorum.', 'consent-manager-for-iys' );
	}

	/** @return bool The external API is switched on and has a key and a signing secret. */
	public static function external_ready() {
		return self::on( 'ext_enabled' ) && 64 === strlen( self::get( 'ext_key_hash' ) ) && 64 === strlen( self::get( 'ext_secret' ) );
	}

	/** @return int Hour (0–23, site time zone) of the nightly reconcile. */
	public static function reconcile_hour() {
		$h = (int) self::get( 'reconcile_hour' );
		return $h >= 0 && $h <= 23 ? $h : 3;
	}

	/** @return int Days the İYS API log is kept (1–90, default 7). */
	public static function log_days() {
		$d = (int) self::get( 'log_days' );
		return $d >= 1 && $d <= 90 ? $d : 7;
	}

	/** @return int Days after which IP and user-agent hashes in the history are cleared (0 = kept). */
	public static function hash_days() {
		$d = (int) self::get( 'hash_days' );
		return $d >= 1 && $d <= 3650 ? $d : 0;
	}

	/** @return int Records per batch request (1–1000, İYS's maximum). */
	public static function batch_size() {
		return max( 1, min( 1000, (int) self::get( 'batch_size' ) ) );
	}

	/**
	 * Internal write: merges values, keeps autoload off, drops cached tokens when credentials change and
	 * lets the scheduler follow the switch.
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$next  = array_merge( $saved, $values );
		self::$internal = true;
		try {
			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, $next, '', false );
			} else {
				update_option( self::OPTION, $next, false );
			}
		} finally {
			self::$internal = false;
		}
		self::after_save( $saved, $next );
	}

	/**
	 * Follow-up after any save: token cache and schedule.
	 *
	 * @param array $before Previous value.
	 * @param array $after  New value.
	 */
	private static function after_save( $before, $after ) {
		self::sync_flag();
		foreach ( self::ENVS as $env ) {
			foreach ( array( 'username', 'password', 'base_url' ) as $k ) {
				if ( ( $before[ $env . '_' . $k ] ?? '' ) !== ( $after[ $env . '_' . $k ] ?? '' ) ) {
					if ( class_exists( 'Eru_CMI_Client' ) ) {
						Eru_CMI_Client::forget_tokens();
					}
					break 2;
				}
			}
		}
		if ( class_exists( 'Eru_CMI_Scheduler' ) ) {
			Eru_CMI_Scheduler::sync();
		}
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
		add_action(
			'update_option_' . self::OPTION,
			function ( $old, $new ) {
				wp_set_option_autoload( self::OPTION, false );
				if ( ! self::$internal ) {
					self::after_save( is_array( $old ) ? $old : array(), is_array( $new ) ? $new : array() );
				}
			},
			10,
			2
		);
		add_action( 'delete_option_' . self::OPTION, array( __CLASS__, 'sync_flag' ) );
		add_action(
			'add_option_' . self::OPTION,
			function ( $name, $value ) {
				wp_set_option_autoload( self::OPTION, false );
				if ( ! self::$internal ) {
					self::after_save( array(), is_array( $value ) ? $value : array() );
				}
			},
			10,
			2
		);
	}

	/**
	 * options.php sanitiser. Only the posted tab's fields change; a blank password keeps the stored one.
	 *
	 * @param mixed $in Posted values.
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
		$tab = sanitize_key( $in['_tab'] ?? '' );
		$yes = fn( $k ) => ! empty( $in[ $k ] ) ? 'yes' : 'no';

		if ( 'connection' === $tab ) {
			$out['enabled']            = $yes( 'enabled' );
			$out['environment']        = in_array( $in['environment'] ?? '', self::ENVS, true ) ? $in['environment'] : 'sandbox';
			$out['batch_size']         = (string) max( 1, min( 1000, absint( $in['batch_size'] ?? 500 ) ) );
			$out['restamp_old']        = $yes( 'restamp_old' );
			$out['tacir_from_company'] = $yes( 'tacir_from_company' );
			foreach ( self::ENVS as $env ) {
				$out[ $env . '_username' ]   = sanitize_text_field( (string) ( $in[ $env . '_username' ] ?? '' ) );
				$out[ $env . '_iys_code' ]   = preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $in[ $env . '_iys_code' ] ?? '' ) );
				$out[ $env . '_brand_code' ] = preg_replace( '/[^0-9A-Za-z]/', '', (string) ( $in[ $env . '_brand_code' ] ?? '' ) );
				$url                         = esc_url_raw( trim( (string) ( $in[ $env . '_base_url' ] ?? '' ) ), array( 'https' ) );
				$out[ $env . '_base_url' ]   = untrailingslashit( $url );
				$pass                        = (string) ( $in[ $env . '_password' ] ?? '' );
				if ( '' !== trim( $pass ) ) {
					$out[ $env . '_password' ] = trim( $pass );
				}
				if ( ! empty( $in[ $env . '_clear_password' ] ) ) {
					$out[ $env . '_password' ] = '';
				}
			}
		} elseif ( 'collect' === $tab ) {
			$chosen                  = array_values( array_intersect( Eru_CMI_Recipient::TYPES, array_map( 'strval', (array) ( $in['channels'] ?? array() ) ) ) );
			$out['channels']         = implode( ',', $chosen ? $chosen : array( 'EPOSTA' ) );
			$out['checkout_box']     = $yes( 'checkout_box' );
			$out['checkout_label']   = mb_substr( sanitize_text_field( (string) ( $in['checkout_label'] ?? '' ) ), 0, 300 );
			$page                    = absint( $in['policy_page'] ?? 0 );
			$out['policy_page']      = (string) ( $page && 'page' === get_post_type( $page ) && 'publish' === get_post_status( $page ) ? $page : 0 );
			$out['register_box']     = $yes( 'register_box' );
			$out['account_tab']      = $yes( 'account_tab' );
			$slug                    = sanitize_title( (string) ( $in['account_endpoint'] ?? '' ) );
			$taken                   = function_exists( 'WC' ) && WC()->query ? array_diff( array_values( WC()->query->get_query_vars() ), array( self::endpoint() ) ) : array();
			$out['account_endpoint'] = ( '' === $slug || in_array( $slug, $taken, true ) ) ? self::DEFAULT_ENDPOINT : $slug;
			$days                    = absint( $in['token_days'] ?? 30 );
			$out['token_days']       = (string) ( $days >= 1 && $days <= 365 ? $days : 30 );
		} elseif ( 'sync' === $tab ) {
			$out['pull_enabled']      = $yes( 'pull_enabled' );
			$out['reconcile_enabled'] = $yes( 'reconcile_enabled' );
			$hour                     = isset( $in['reconcile_hour'] ) ? (int) $in['reconcile_hour'] : 3;
			$out['reconcile_hour']    = (string) ( $hour >= 0 && $hour <= 23 ? $hour : 3 );
			$log                      = absint( $in['log_days'] ?? 7 );
			$out['log_days']          = (string) ( $log >= 1 && $log <= 90 ? $log : 7 );
			$hash                     = absint( $in['hash_days'] ?? 0 );
			$out['hash_days']         = (string) ( $hash <= 3650 ? $hash : 0 );
		} elseif ( 'external' === $tab ) {
			$out['ext_enabled'] = $yes( 'ext_enabled' );
			if ( ! empty( $in['ext_revoke'] ) ) {
				$out['ext_enabled']  = 'no';
				$out['ext_key_hash'] = '';
				$out['ext_key_hint'] = '';
				$out['ext_secret']   = '';
			}
		}
		self::$last = $out;
		return $out;
	}
}
