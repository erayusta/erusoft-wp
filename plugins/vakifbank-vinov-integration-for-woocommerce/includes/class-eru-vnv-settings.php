<?php
/**
 * Settings getter over the gateway's option (one non-autoloaded array) plus a tiny autoloaded "active" flag.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The gateway form writes `woocommerce_eru_vnv_settings` through the WooCommerce settings API; every other part
 * of the plugin reads it through this class, so a change made in one request is seen by the next call. The
 * option is kept out of autoload (it holds client secrets); per-request hooks check only the autoloaded flag,
 * which is '1' when the gateway is switched on and configured.
 */
class Eru_VNV_Settings {

	const GATEWAY_ID = 'eru_vnv';
	const OPTION     = 'woocommerce_eru_vnv_settings';
	const FLAG       = 'eru_vnv_active';
	const ENVS       = array( 'test', 'live' );

	/** Highest installment count the form offers. */
	const MAX_INSTALLMENTS = 12;

	/** Registers the flag and autoload keepers. */
	public static function init() {
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action(
				$hook,
				function ( $option ) {
					if ( self::OPTION === $option ) {
						self::after_write();
					}
				}
			);
		}
	}

	/** @return array Defaults (strings, as the WooCommerce settings API stores them). */
	public static function defaults() {
		return array(
			'enabled'              => 'no',
			'title'                => __( 'Vinov ile öde', 'vakifbank-vinov-integration-for-woocommerce' ),
			'description'          => __( 'VakıfBank Vinov ile ödeyin. TC kimlik numaranıza bankanın gönderdiği SMS koduyla doğrulama yapılır.', 'vakifbank-vinov-integration-for-woocommerce' ),
			'testmode'             => 'yes',
			'test_base_url'        => '',
			'test_client_id'       => '',
			'test_client_secret'   => '',
			'live_base_url'        => '',
			'live_client_id'       => '',
			'live_client_secret'   => '',
			'workplace_id'         => '',
			'consent_id'           => '',
			'payment_purpose'      => '',
			'otp_transaction_code' => '',
			'expiry_days'          => '30',
			'max_installments'     => '12',
			'installment_rates'    => array(),
			'allowed_categories'   => array(),
			'allowed_products'     => '',
			'pending_status'       => 'on-hold',
			'log_level'            => 'error',
			'sync_interval'        => '30',
			'giveup_days'          => '30',
			'retention_days'       => '365',
		);
	}

	/** @return array Saved values over the defaults. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** @param string $key Key. @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/** @param string $key Key. @return string Trimmed string value. */
	public static function str( $key ) {
		$v = self::get( $key );
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** @return string test | live */
	public static function env() {
		return 'no' === self::str( 'testmode' ) ? 'live' : 'test';
	}

	/**
	 * A per-environment value.
	 *
	 * @param string      $key client_id | client_secret | base_url.
	 * @param string|null $env test | live (default: the selected one).
	 * @return string
	 */
	public static function cred( $key, $env = null ) {
		$env = in_array( $env, self::ENVS, true ) ? $env : self::env();
		$v   = self::str( $env . '_' . $key );
		if ( 'base_url' === $key ) {
			$v = self::clean_base_url( $v );
			/**
			 * Filters the API base URL of an environment.
			 *
			 * @param string $url Base URL (no trailing slash).
			 * @param string $env test | live.
			 */
			$v = self::clean_base_url( apply_filters( 'eru_vnv_api_base', untrailingslashit( $v ), $env ) );
		}
		return $v;
	}

	/**
	 * An https API base URL without credentials, query or fragment (no trailing slash), or ''. No DNS lookup.
	 * The plugin ships no default host: the merchant enters the address the bank gives them.
	 *
	 * @param mixed $url Candidate.
	 * @return string
	 */
	public static function clean_base_url( $url ) {
		$url = trim( is_scalar( $url ) ? (string) $url : '' );
		$p   = '' !== $url ? wp_parse_url( $url ) : false;
		if ( ! is_array( $p ) || 'https' !== strtolower( (string) ( $p['scheme'] ?? '' ) ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['query'] ) || isset( $p['fragment'] ) || ! preg_match( '/^[a-z0-9.\-]+$/i', (string) $p['host'] ) ) {
			return '';
		}
		$port = isset( $p['port'] ) ? ':' . (int) $p['port'] : '';
		$path = isset( $p['path'] ) ? untrailingslashit( (string) $p['path'] ) : '';
		return 'https://' . strtolower( (string) $p['host'] ) . $port . $path;
	}

	/** @param string|null $env Environment. @return bool Base URL, credentials and merchant identifiers are present. */
	public static function ready( $env = null ) {
		if ( '' === self::cred( 'base_url', $env ) || '' === self::cred( 'client_id', $env ) || '' === self::cred( 'client_secret', $env ) ) {
			return false;
		}
		return self::workplace_id() > 0 && '' !== self::str( 'consent_id' ) && self::payment_purpose() > 0 && '' !== self::str( 'otp_transaction_code' );
	}

	/** @return bool Switched on and configured. */
	public static function enabled() {
		return 'yes' === self::str( 'enabled' ) && self::ready();
	}

	/** @return bool The autoloaded flag says the gateway is on (no settings read). */
	public static function active_flag() {
		return '1' === get_option( self::FLAG, '0' );
	}

	/** @return int WorkPlace ID. */
	public static function workplace_id() {
		return max( 0, (int) self::str( 'workplace_id' ) );
	}

	/** @return int Payment purpose code. */
	public static function payment_purpose() {
		return max( 0, (int) self::str( 'payment_purpose' ) );
	}

	/** @return int Days until a single payment's expiry (1–365, default 30). */
	public static function expiry_days() {
		$d = (int) self::str( 'expiry_days' );
		return $d >= 1 && $d <= 365 ? $d : 30;
	}

	/** @return int Highest installment count (1–12). */
	public static function max_installments() {
		return max( 1, min( self::MAX_INSTALLMENTS, (int) self::str( 'max_installments' ) ) );
	}

	/** @return array<int,float> Enabled commission rates (percent) by installment count, 2…max. */
	public static function rates() {
		$raw = self::get( 'installment_rates' );
		$out = array();
		$max = self::max_installments();
		foreach ( is_array( $raw ) ? $raw : array() as $n => $row ) {
			$n = (int) $n;
			if ( $n < 2 || $n > $max || ! is_array( $row ) || 'yes' !== ( $row['enabled'] ?? '' ) ) {
				continue;
			}
			$rate = (float) str_replace( ',', '.', (string) ( $row['rate'] ?? '0' ) );
			if ( $rate >= 0 && $rate <= 100 ) {
				$out[ $n ] = $rate;
			}
		}
		ksort( $out );
		return $out;
	}

	/** @return string Order status (without "wc-") set after the instruction is created. */
	public static function pending_status() {
		$s = sanitize_key( preg_replace( '/^wc-/', '', self::str( 'pending_status' ) ) );
		return '' !== $s && array_key_exists( 'wc-' . $s, wc_get_order_statuses() ) && ! in_array( $s, array( 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ? $s : 'on-hold';
	}

	/** @return int Minutes between status-sync runs (15, 30 or 60; default 30). */
	public static function sync_interval() {
		$m = (int) self::str( 'sync_interval' );
		return in_array( $m, array( 15, 30, 60 ), true ) ? $m : 30;
	}

	/**
	 * Days after which an open instruction (or an unresolved attempt) is no longer polled: the setting (1–180,
	 * default 30), but never less than the single-payment expiry + 1 day, so tracking cannot stop while the
	 * customer can still approve the instruction.
	 *
	 * @return int
	 */
	public static function giveup_days() {
		$d = (int) self::str( 'giveup_days' );
		$d = $d >= 1 && $d <= 180 ? $d : 30;
		return max( $d, self::expiry_days() + 1 );
	}

	/** @return int Days the masked identity number, the phone and closed instruction rows are kept (30–3650, default 365). */
	public static function retention_days() {
		$d = (int) self::str( 'retention_days' );
		return $d >= 30 && $d <= 3650 ? $d : 365;
	}

	/** @return string off | error | info | debug */
	public static function log_level() {
		$l = self::str( 'log_level' );
		return in_array( $l, array( 'off', 'error', 'info', 'debug' ), true ) ? $l : 'error';
	}

	/** @return int[] Allowed category ids (empty = every product). */
	public static function allowed_categories() {
		$c = self::get( 'allowed_categories' );
		return array_values( array_filter( array_map( 'absint', is_array( $c ) ? $c : array() ) ) );
	}

	/** @return int[] Allowed product ids (empty = every product). */
	public static function allowed_products() {
		return array_values( array_filter( array_map( 'absint', preg_split( '/[\s,]+/', self::str( 'allowed_products' ) ) ) ) );
	}

	/** @return string[] Stored secrets and client ids (for log redaction). */
	public static function secrets() {
		$out = array();
		foreach ( self::ENVS as $env ) {
			foreach ( array( 'client_secret', 'client_id' ) as $k ) {
				$v = self::str( $env . '_' . $k );
				if ( strlen( $v ) >= 4 ) {
					$out[] = $v;
				}
			}
		}
		$c = self::str( 'consent_id' );
		if ( strlen( $c ) >= 4 ) {
			$out[] = $c;
		}
		return $out;
	}

	/**
	 * Internal write (tests, migrations): merges values over the saved ones; the write hooks keep the
	 * option out of autoload and the flag in step.
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $all, '', false );
		} else {
			update_option( self::OPTION, $all, false );
		}
		self::after_write();
	}

	/** Keeps the settings option out of autoload and writes the flag. */
	public static function after_write() {
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null !== $autoload && ! in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ) ) {
			wp_set_option_autoload( self::OPTION, false );
		}
		$v = self::enabled() ? '1' : '0';
		if ( get_option( self::FLAG, null ) !== $v ) {
			update_option( self::FLAG, $v, true );
		}
	}
}
