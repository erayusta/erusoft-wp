<?php
/**
 * "My device is not listed" request form.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX lead capture with nonce, honeypot and an IP rate limit.
 */
class Eru_DTI_Lead {

	const LIMIT = 3;

	/** Hooks. */
	public static function init() {
		add_action( 'wp_ajax_eru_dti_lead', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_eru_dti_lead', array( __CLASS__, 'ajax' ) );
	}

	/** @return string */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'eru_dti_client_ip', $ip );
	}

	/** AJAX handler. */
	public static function ajax() {
		if ( ! check_ajax_referer( 'eru_dti_lead', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Oturum süresi doldu, sayfayı yenileyin.', 'device-trade-in-for-woocommerce' ) ), 403 );
		}
		$data = array(
			'name'        => isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'email'       => isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'phone'       => isset( $_POST['phone'] ) ? wp_unslash( $_POST['phone'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'device_text' => isset( $_POST['device_text'] ) ? wp_unslash( $_POST['device_text'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			'hp'          => isset( $_POST['website'] ) ? wp_unslash( $_POST['website'] ) : '', // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		);
		$r = self::submit( $data, self::client_ip() );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 'eru_dti_rate' === $r->get_error_code() ? 429 : 400 );
		}
		wp_send_json_success( array( 'message' => __( 'Talebiniz alındı. Size e-posta ile dönüş yapacağız.', 'device-trade-in-for-woocommerce' ) ) );
	}

	/**
	 * Validates, rate limits, stores and notifies.
	 *
	 * @param array  $data name, email, phone, device_text, hp (honeypot).
	 * @param string $ip   Client IP.
	 * @return true|WP_Error
	 */
	public static function submit( array $data, $ip ) {
		if ( ! Eru_DTI_Settings::is_on( 'leads_enabled' ) ) {
			return new WP_Error( 'eru_dti_off', __( 'Talep formu kapalı.', 'device-trade-in-for-woocommerce' ) );
		}
		if ( '' !== trim( (string) ( $data['hp'] ?? '' ) ) ) {
			return true; // Honeypot: pretend success, store nothing.
		}
		$key   = 'eru_dti_rl_' . md5( (string) $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::LIMIT ) {
			return new WP_Error( 'eru_dti_rate', __( 'Çok fazla deneme yaptınız. Lütfen daha sonra tekrar deneyin.', 'device-trade-in-for-woocommerce' ) );
		}
		$name  = mb_substr( sanitize_text_field( $data['name'] ?? '' ), 0, 190 );
		$email = sanitize_email( $data['email'] ?? '' );
		$phone = mb_substr( sanitize_text_field( $data['phone'] ?? '' ), 0, 60 );
		$dev   = mb_substr( sanitize_text_field( $data['device_text'] ?? '' ), 0, 255 );
		if ( '' === $name || ! is_email( $email ) || '' === $dev ) {
			return new WP_Error( 'eru_dti_invalid', __( 'Ad, geçerli bir e-posta ve cihaz bilgisi zorunludur.', 'device-trade-in-for-woocommerce' ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		global $wpdb;
		$wpdb->insert(
			Eru_DTI_DB::table( 'leads' ),
			array( 'name' => $name, 'email' => $email, 'phone' => $phone, 'device_text' => $dev, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		Eru_DTI_DB::flag_data();
		$to = Eru_DTI_Settings::get( 'notify_email' );
		$to = is_email( $to ) ? $to : get_option( 'admin_email' );
		wp_mail(
			$to,
			__( 'Takas talebi: listede olmayan cihaz', 'device-trade-in-for-woocommerce' ),
			sprintf( "%s\n%s\n%s\n%s", $name, $email, $phone, $dev )
		);
		return true;
	}
}
