<?php
/**
 * OTP state in the WooCommerce session.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session key "eru_vnv_otp":
 * - after sendOTP: {tckn_hmac, phone_hash, otp_txn_id, sent_at}
 * - after the bank verified the code and approved the debtor for the server-computed total:
 *   {tckn_hmac, phone_hash, verified_at, total_minor, installments, cart_hash, vid} (otp_txn_id spent).
 * Nothing in it is the identity number or the phone in clear.
 *
 * Single use: a parallel request may still hold the verification in its own in-memory session copy, so
 * clearing the session is not enough. claim() wins the verification id once, atomically, with INSERT IGNORE on
 * a unique option row ("eru_vnv_vused_<vid>"); a second request carrying the same id loses and is refused.
 * Claim rows are purged an hour later (a verification is only valid for VERIFY_TTL anyway).
 */
class Eru_VNV_Session {

	const KEY = 'eru_vnv_otp';

	/** A code must be verified within this many seconds of sending it. */
	const SEND_TTL = 600;

	/** A verification is valid for this many seconds. */
	const VERIFY_TTL = 600;

	/** @return array Current state ([] when none). */
	public static function get() {
		$v = function_exists( 'WC' ) && WC()->session ? WC()->session->get( self::KEY ) : null;
		return is_array( $v ) ? $v : array();
	}

	/** @param array $state State. */
	private static function put( array $state ) {
		WC()->session->set( self::KEY, $state );
	}

	/**
	 * After a successful sendOTP.
	 *
	 * @param string     $tckn   Identity number (hashed here, not stored).
	 * @param string     $phone  Normalised phone (hashed here).
	 * @param int|string $txn_id OTPTransactionId.
	 */
	public static function start( $tckn, $phone, $txn_id ) {
		self::put(
			array(
				'tckn_hmac'  => Eru_VNV_Identity::hmac( $tckn ),
				'phone_hash' => Eru_VNV_Identity::phone_hash( $phone ),
				'otp_txn_id' => (string) $txn_id,
				'sent_at'    => time(),
			)
		);
	}

	/**
	 * After verifyOTP + checkDebtor succeeded: records what the bank approved and spends the code.
	 *
	 * @param int    $total_minor  Approved total.
	 * @param int    $installments Count.
	 * @param string $cart_hash    Cart hash at verification.
	 */
	public static function mark_verified( $total_minor, $installments, $cart_hash ) {
		$s = self::get();
		unset( $s['otp_txn_id'], $s['sent_at'] );
		$s['verified_at']  = time();
		$s['total_minor']  = (int) $total_minor;
		$s['installments'] = (int) $installments;
		$s['cart_hash']    = (string) $cart_hash;
		$s['vid']          = wp_generate_password( 20, false );
		self::put( $s );
	}

	/** @param string $tckn Identity number. @return bool The state belongs to this number. */
	public static function matches_tckn( $tckn ) {
		$s = self::get();
		return ! empty( $s['tckn_hmac'] ) && hash_equals( (string) $s['tckn_hmac'], Eru_VNV_Identity::hmac( $tckn ) );
	}

	/** @param string $phone Normalised phone. @return bool The code went to this number. */
	public static function matches_phone( $phone ) {
		$s = self::get();
		return ! empty( $s['phone_hash'] ) && hash_equals( (string) $s['phone_hash'], Eru_VNV_Identity::phone_hash( $phone ) );
	}

	/** @return bool A code was sent and can still be verified. */
	public static function pending() {
		$s = self::get();
		return ! empty( $s['otp_txn_id'] ) && (int) ( $s['sent_at'] ?? 0 ) >= time() - self::SEND_TTL;
	}

	/** @return bool A bank verification exists and is younger than VERIFY_TTL. */
	public static function verified() {
		$s = self::get();
		return ! empty( $s['verified_at'] ) && (int) $s['verified_at'] >= time() - self::VERIFY_TTL;
	}

	/**
	 * UI helper: the stored verification still matches the current cart (total, count, contents).
	 *
	 * @return bool
	 */
	public static function verified_for_cart() {
		if ( ! self::verified() || ! WC()->cart ) {
			return false;
		}
		$s = self::get();
		return (int) $s['total_minor'] === Eru_VNV_Installments::to_minor( WC()->cart->get_total( 'edit' ) ) && (int) $s['installments'] === Eru_VNV_Installments::selected() && hash_equals( (string) $s['cart_hash'], (string) WC()->cart->get_cart_hash() );
	}

	/**
	 * Wins the current verification id once, atomically (never released; purged after an hour).
	 *
	 * @return bool True for exactly one request per verification.
	 */
	public static function claim() {
		global $wpdb;
		$vid = (string) ( self::get()['vid'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9]{16,40}$/', $vid ) ) {
			return false;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 100", $wpdb->esc_like( 'eru_vnv_vused_' ) . '%', time() - HOUR_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$won = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'eru_vnv_vused_' . $vid, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( 'eru_vnv_vused_' . $vid, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $won;
	}

	/** Drops the state and writes the session at once (the claim, not this, is what makes it single use). */
	public static function clear() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::KEY, null );
			if ( method_exists( WC()->session, 'save_data' ) ) {
				WC()->session->save_data();
			}
		}
	}
}
