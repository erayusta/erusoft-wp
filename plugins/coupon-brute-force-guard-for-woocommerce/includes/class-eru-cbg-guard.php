<?php
/**
 * Başarısız kupon denemelerini sayar, eşik aşılınca istemciyi kilitler.
 *
 * Sayaçlar transient olarak tutulur (kalıcı object cache olmasa da çalışır).
 * İstemci anahtarı: giriş yapmış kullanıcıda kullanıcı kimliği, aksi halde
 * tuzlanmış IP özeti. Ham IP saklanmaz.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_Cbg_Guard {

	/** WC_Coupon hata kodları: kuponun var olduğunu sızdıranlar. */
	private const LEAKY_CODES = array( 102, 106, 107, 115, 116 );

	/** Başarısız deneme sayılan kodlar (yok + sızıntı kodları). */
	private const FAILURE_CODES = array( 105, 102, 106, 107, 115, 116 );

	/** Bu istekte bir kupon uygulama denemesi yapılıyor mu (REST için kanca ile işaretlenir). */
	private static bool $rest_apply = false;

	/** @var array<string,bool> Bu istekte sayılmış kuponlar (aynı denemenin iki kez sayılmasını önler). */
	private static array $counted = array();

	public static function init(): void {
		add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'on_coupon_error' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'block_when_locked' ), 1, 1 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_before' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'rest_after' ), 10, 3 );
	}

	public static function rest_before( $response, $handler, $request ) {
		if ( $request instanceof WP_REST_Request && preg_match( '#^/wc/store(/v\d+)?/cart/apply-coupon/?$#', $request->get_route() ) ) {
			self::$rest_apply = true;
			self::$counted    = array();
		}
		return $response;
	}

	public static function rest_after( $response, $handler, $request ) {
		self::$rest_apply = false;
		return $response;
	}

	/**
	 * Yalnızca açık kupon uygulama denemeleri sayılır: klasik AJAX/form isteği ve Store API
	 * `/cart/apply-coupon`. Sepetteki mevcut kuponların yeniden doğrulanması sayılmaz.
	 * Filtre: eru_cbg_is_apply_request.
	 */
	public static function is_apply_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$apply = self::$rest_apply
			|| ( isset( $_GET['wc-ajax'] ) && 'apply_coupon' === $_GET['wc-ajax'] )
			|| ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['apply_coupon'] ) );
		// phpcs:enable
		return (bool) apply_filters( 'eru_cbg_is_apply_request', $apply );
	}

	/**
	 * Sınırlar (dakika). Filtre: eru_cbg_limits.
	 *
	 * @return array{attempts:int,window:int,lockout:int}
	 */
	public static function limits(): array {
		$limits = array(
			'attempts' => max( 1, (int) get_option( 'eru_cbg_attempts', 5 ) ),
			'window'   => max( 1, (int) get_option( 'eru_cbg_window', 15 ) ),
			'lockout'  => max( 1, (int) get_option( 'eru_cbg_lockout', 60 ) ),
		);
		$filtered = apply_filters( 'eru_cbg_limits', $limits );
		if ( is_array( $filtered ) ) {
			foreach ( $limits as $key => $default ) {
				if ( isset( $filtered[ $key ] ) ) {
					$limits[ $key ] = max( 1, (int) $filtered[ $key ] );
				}
			}
		}
		return $limits;
	}

	public static function lockout_message(): string {
		$msg = (string) get_option( 'eru_cbg_message', '' );
		if ( '' === trim( $msg ) ) {
			$msg = __( 'Çok fazla başarısız kupon denemesi yaptınız. Lütfen daha sonra tekrar deneyin.', 'coupon-brute-force-guard-for-woocommerce' );
		}
		return $msg;
	}

	private static function enabled(): bool {
		return 'yes' === get_option( 'eru_cbg_enabled', 'yes' ) && ! self::is_exempt();
	}

	private static function is_exempt(): bool {
		return (bool) apply_filters( 'eru_cbg_is_exempt', current_user_can( 'manage_woocommerce' ) );
	}

	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return (string) apply_filters( 'eru_cbg_client_ip', $ip );
	}

	/** İstemci anahtarları: giriş yapmışsa kullanıcı kimliği ve IP özeti, değilse yalnız IP özeti. */
	public static function fingerprints(): array {
		$fps = array( 'i' . substr( hash( 'sha256', self::client_ip() . '|' . wp_salt( 'auth' ) ), 0, 20 ) );
		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			array_unshift( $fps, 'u' . $uid );
		}
		return $fps;
	}

	public static function is_locked(): bool {
		foreach ( self::fingerprints() as $fp ) {
			if ( false !== get_transient( 'eru_cbg_lock_' . $fp ) ) {
				return true;
			}
		}
		return false;
	}

	/** Atomik sayaç: kalıcı object cache varsa wp_cache_incr, yoksa tek SQL UPDATE. */
	private static function increment( string $key, int $ttl ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'eru_cbg', $ttl );
			$n = wp_cache_incr( $key, 1, 'eru_cbg' );
			if ( false !== $n ) {
				return (int) $n;
			}
		}
		global $wpdb;
		if ( false === get_transient( $key ) ) {
			set_transient( $key, 0, $ttl );
		}
		$name = '_transient_' . $key;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Bir başarısız deneme kaydeder; eşik aşılırsa kilitler. */
	public static function record_failure(): void {
		$limits = self::limits();
		foreach ( self::fingerprints() as $fp ) {
			$n = self::increment( 'eru_cbg_hits_' . $fp, $limits['window'] * MINUTE_IN_SECONDS );
			if ( $n >= $limits['attempts'] ) {
				set_transient( 'eru_cbg_lock_' . $fp, time(), $limits['lockout'] * MINUTE_IN_SECONDS );
				delete_transient( 'eru_cbg_hits_' . $fp );
				do_action( 'eru_cbg_locked', $fp, $limits );
			}
		}
	}

	/** Kilitliyken geçerli kuponlar da reddedilir. */
	public static function block_when_locked( $valid ) {
		if ( self::enabled() && self::is_apply_request() && self::is_locked() ) {
			throw new Exception( esc_html( self::lockout_message() ), 100 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $valid;
	}

	/**
	 * woocommerce_coupon_error: kilitliyse kilit mesajı; değilse başarısız denemeyi say,
	 * sızıntı kodlarını tek tip mesaja çevir.
	 */
	public static function on_coupon_error( $message, $code = 0, $coupon = null ) {
		if ( ! self::enabled() ) {
			return $message;
		}
		$code     = (int) $code;
		$applying = self::is_apply_request();

		if ( $applying && self::is_locked() ) {
			return self::lockout_message();
		}

		if ( $applying && in_array( $code, self::FAILURE_CODES, true ) ) {
			$id = ( $coupon instanceof WC_Coupon ) ? $coupon->get_code() : '';
			if ( '' === $id || ! isset( self::$counted[ $id ] ) ) {
				if ( '' !== $id ) {
					self::$counted[ $id ] = true;
				}
				self::record_failure();
				if ( self::is_locked() ) {
					return $message;
				}
			}
		}

		if ( 'yes' === get_option( 'eru_cbg_uniform_errors', 'yes' ) && in_array( $code, self::LEAKY_CODES, true ) ) {
			return __( 'Bu kupon kodu geçerli değildir.', 'coupon-brute-force-guard-for-woocommerce' );
		}
		return $message;
	}
}
