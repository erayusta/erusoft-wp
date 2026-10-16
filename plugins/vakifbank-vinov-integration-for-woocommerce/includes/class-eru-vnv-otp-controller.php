<?php
/**
 * Public OTP endpoints (WooCommerce AJAX): send a code, verify it at the bank and run the debtor check.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Both endpoints need the checkout nonce, refuse a filled honeypot and are rate limited per client
 * fingerprint and per identity-number HMAC (5 sends and 10 verifies per 10 minutes).
 *
 * send:   validates the identity number checksum and the mobile number, calls sendOTP and keeps
 *         {tckn_hmac, phone_hash, otp_txn_id, sent_at} in the session. The transaction id never reaches the
 *         browser. The answer is the same whether or not the bank accepted the number ("a code arrives if a
 *         phone is registered"), so the endpoint does not reveal which identity numbers have a phone at the
 *         bank; a refused send leaves nothing pending and the verify step reports the failure.
 * verify: the identity number must hash to the stored HMAC; verifyOTP is called with the stored transaction
 *         id (in every mode — there is no local bypass), then checkDebtor with the cart total the server just
 *         calculated (installment fee included). Only when the bank approves both is the verification stored;
 *         the browser receives a boolean for the UI and nothing it could replay. After the input-shape checks,
 *         "no accepted send in this session" and "code rejected by the bank" give one identical answer, so
 *         verify does not reveal whether the bank accepted the send either.
 */
class Eru_VNV_OTP_Controller {

	const NONCE        = 'eru_vnv_otp';
	const SEND_LIMIT   = 5;
	const VERIFY_LIMIT = 10;

	/** Hooks. */
	public static function init() {
		add_action( 'wc_ajax_eru_vnv_send_otp', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wc_ajax_eru_vnv_verify_otp', array( __CLASS__, 'ajax_verify' ) );
	}

	/** @param string $key Posted key. @return string Sanitised value. */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in the callers.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/** Checks the nonce or ends the request. */
	private static function guard() {
		if ( ! Eru_VNV_Settings::active_flag() ) {
			wp_send_json_error( array( 'message' => __( 'Vinov ile ödeme şu anda kullanılamıyor.', 'vakifbank-vinov-integration-for-woocommerce' ) ), 404 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Oturumunuzun süresi doldu. Sayfayı yenileyip tekrar deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ) ), 403 );
		}
	}

	/** wc-ajax=eru_vnv_send_otp */
	public static function ajax_send() {
		self::guard();
		$r = self::send(
			array(
				'tckn'  => self::posted( 'tckn' ),
				'phone' => self::posted( 'phone' ),
				'hp'    => self::posted( 'eru_vnv_website' ),
			)
		);
		$r['ok'] ? wp_send_json_success( array( 'message' => $r['message'] ) ) : wp_send_json_error( array( 'message' => $r['message'] ) );
	}

	/** wc-ajax=eru_vnv_verify_otp */
	public static function ajax_verify() {
		self::guard();
		$r = self::verify(
			array(
				'tckn'         => self::posted( 'tckn' ),
				'otp'          => self::posted( 'otp' ),
				'installments' => self::posted( 'installments' ),
				'hp'           => self::posted( 'eru_vnv_website' ),
			)
		);
		$r['ok'] ? wp_send_json_success( array( 'verified' => true, 'message' => $r['message'] ) ) : wp_send_json_error( array( 'verified' => false, 'message' => $r['message'] ) );
	}

	/** @return array{ok:bool, message:string} */
	private static function fail( $message, $verify = false ) {
		return $verify ? array( 'ok' => false, 'verified' => false, 'message' => $message ) : array( 'ok' => false, 'message' => $message );
	}

	/**
	 * Common gate: switch, honeypot, rate limits.
	 *
	 * @param array  $in     Input.
	 * @param string $bucket send | verify.
	 * @return string '' when allowed, else the message.
	 */
	private static function gate( array $in, $bucket ) {
		if ( ! Eru_VNV_Settings::enabled() ) {
			return __( 'Vinov ile ödeme şu anda kullanılamıyor.', 'vakifbank-vinov-integration-for-woocommerce' );
		}
		if ( '' !== trim( (string) ( $in['hp'] ?? '' ) ) ) {
			return __( 'İstek reddedildi.', 'vakifbank-vinov-integration-for-woocommerce' );
		}
		$limit = 'send' === $bucket ? self::SEND_LIMIT : self::VERIFY_LIMIT;
		$tckn  = Eru_VNV_Identity::clean_tckn( $in['tckn'] ?? '' );
		$ok    = Eru_VNV_Rate_Limit::hit( $bucket, Eru_VNV_Rate_Limit::fingerprint(), $limit );
		// Per-identity counters: always on send; on verify only for the number this session sent a code to
		// (anything else is refused without a bank call and must not create counter rows).
		if ( $ok && Eru_VNV_Identity::valid_tckn( $tckn ) && ( 'send' === $bucket || Eru_VNV_Session::matches_tckn( $tckn ) ) ) {
			$ok = Eru_VNV_Rate_Limit::hit_isolated( $bucket . '_id', Eru_VNV_Identity::hmac( $tckn ), $limit );
		}
		return $ok ? '' : __( 'Çok fazla deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.', 'vakifbank-vinov-integration-for-woocommerce' );
	}

	/**
	 * Sends a code.
	 *
	 * @param array $in {tckn, phone, hp}.
	 * @return array{ok:bool, message:string}
	 */
	public static function send( array $in ) {
		$block = self::gate( $in, 'send' );
		if ( '' !== $block ) {
			return self::fail( $block );
		}
		$tckn  = Eru_VNV_Identity::clean_tckn( $in['tckn'] ?? '' );
		$phone = Eru_VNV_Identity::normalize_phone( $in['phone'] ?? '' );
		if ( ! Eru_VNV_Identity::valid_tckn( $tckn ) ) {
			return self::fail( __( 'Geçerli bir TC kimlik numarası girin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( '' === $phone ) {
			return self::fail( __( 'Geçerli bir cep telefonu numarası girin (5XX XXX XX XX).', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return self::fail( __( 'Sepetiniz boş.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$res  = ( new Eru_VNV_API_Client() )->send_otp( $tckn );
		$txn  = $res['data']['OTPInfo']['OTPTransactionId'] ?? '';
		$same = array(
			'ok'      => true,
			'message' => __( 'Bankada kayıtlı telefonunuza bir kod gönderildiyse birkaç dakika içinde ulaşır.', 'vakifbank-vinov-integration-for-woocommerce' ),
		);
		if ( ! $res['success'] || ! is_scalar( $txn ) || '' === (string) $txn ) {
			// Same answer as a success: nothing pending, the verify step tells the customer to ask again.
			WC()->session->set( Eru_VNV_Session::KEY, null );
			Eru_VNV_Logger::info( sprintf( 'sendOTP kabul edilmedi (%s)', '' !== $res['status_code'] ? $res['status_code'] : 'HTTP ' . $res['http_code'] ) );
			return $same;
		}
		Eru_VNV_Session::start( $tckn, $phone, $txn );
		/** Fires after the bank sent a code (no personal data passed). */
		do_action( 'eru_vnv_otp_sent' );
		return $same;
	}

	/**
	 * Verifies a code at the bank and runs the debtor check for the server-computed total.
	 *
	 * @param array $in {tckn, otp, installments, hp}. Any other key (amount, verified …) is ignored.
	 * @return array{ok:bool, verified:bool, message:string}
	 */
	public static function verify( array $in ) {
		$block = self::gate( $in, 'verify' );
		if ( '' !== $block ) {
			return self::fail( $block, true );
		}
		// 1. Input shape only (same answers whatever happened at send).
		$tckn = Eru_VNV_Identity::clean_tckn( $in['tckn'] ?? '' );
		$code = trim( (string) ( $in['otp'] ?? '' ) );
		if ( ! Eru_VNV_Identity::valid_tckn( $tckn ) ) {
			return self::fail( __( 'Geçerli bir TC kimlik numarası girin.', 'vakifbank-vinov-integration-for-woocommerce' ), true );
		}
		if ( ! preg_match( '/^[0-9A-Za-z]{4,10}$/', $code ) ) {
			return self::fail( __( 'SMS ile gelen doğrulama kodunu girin.', 'vakifbank-vinov-integration-for-woocommerce' ), true );
		}
		$n = Eru_VNV_Installments::normalize( $in['installments'] ?? '' );
		if ( 0 === $n ) {
			return self::fail( __( 'Geçersiz taksit seçimi.', 'vakifbank-vinov-integration-for-woocommerce' ), true );
		}
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return self::fail( __( 'Sepetiniz boş.', 'vakifbank-vinov-integration-for-woocommerce' ), true );
		}
		// 2. No accepted send for this number in this session, and a code the bank rejects, answer the same, so
		// verify does not reveal whether the bank accepted the send (no registration oracle).
		if ( ! Eru_VNV_Session::matches_tckn( $tckn ) || ! Eru_VNV_Session::pending() ) {
			return self::not_verified();
		}

		// The amount the bank checks is what the order will total: recalculated here with the chosen count.
		WC()->session->set( 'chosen_payment_method', Eru_VNV_Settings::GATEWAY_ID );
		Eru_VNV_Installments::remember( $n );
		WC()->cart->calculate_totals();
		$total = Eru_VNV_Installments::to_minor( WC()->cart->get_total( 'edit' ) );
		$hash  = (string) WC()->cart->get_cart_hash();
		if ( $total <= 0 ) {
			return self::fail( __( 'Sepet tutarı geçersiz.', 'vakifbank-vinov-integration-for-woocommerce' ), true );
		}

		$state  = Eru_VNV_Session::get();
		$txn    = (string) $state['otp_txn_id'];
		$client = new Eru_VNV_API_Client();
		$res    = $client->verify_otp( $code, $txn );
		if ( ! $res['success'] || true !== ( $res['data']['Result']['Success'] ?? null ) ) {
			// Expired (ACBH000302) or attempts used up (ACBH000303): the code is dead, a new one is needed.
			if ( in_array( $res['status_code'], array( 'ACBH000302', 'ACBH000303' ), true ) ) {
				self::spend_code();
			}
			return self::not_verified();
		}
		// The code is used up at the bank from here on.
		self::spend_code();
		$check = $client->check_debtor( $tckn, $total, $code, $txn );
		if ( ! $check['success'] || true !== ( $check['data']['IsSucceeded'] ?? null ) ) {
			return self::fail( __( 'Vinov ile bu tutarda ödeme yapılamıyor. Lütfen başka bir ödeme yöntemi seçin.', 'vakifbank-vinov-integration-for-woocommerce' ), true );
		}
		Eru_VNV_Session::mark_verified( $total, $n, $hash );
		/** Fires after the bank verified the code and approved the debtor (amount in minor units, count). */
		do_action( 'eru_vnv_otp_verified', $total, $n );
		return array(
			'ok'       => true,
			'verified' => true,
			'message'  => __( 'Doğrulama tamamlandı.', 'vakifbank-vinov-integration-for-woocommerce' ),
		);
	}

	/** @return array The one answer for "no accepted send" and "code rejected by the bank". */
	private static function not_verified() {
		return self::fail( __( 'Kod doğrulanamadı. Kodu kontrol edin ya da yeni kod isteyin.', 'vakifbank-vinov-integration-for-woocommerce' ), true );
	}

	/** Removes the OTP transaction id from the session (a spent or expired code). */
	private static function spend_code() {
		$s = Eru_VNV_Session::get();
		unset( $s['otp_txn_id'] );
		WC()->session->set( Eru_VNV_Session::KEY, $s );
	}
}
