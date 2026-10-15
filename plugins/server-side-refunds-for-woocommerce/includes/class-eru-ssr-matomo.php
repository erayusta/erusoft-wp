<?php
/**
 * Matomo Tracking HTTP API destination (form POST; token_auth only in the body).
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SSR_Matomo {

	public static function ready() {
		return Eru_SSR_Settings::is_on( 'matomo_enabled' )
			&& Eru_SSR_Settings::valid_matomo_url( Eru_SSR_Settings::get( 'matomo_url' ) )
			&& (int) Eru_SSR_Settings::get( 'matomo_site_id' ) > 0
			&& '' !== Eru_SSR_Settings::get( 'matomo_token' );
	}

	/** Endpoint: <url>/matomo.php unless the URL already names a .php file. */
	public static function endpoint() {
		$url = Eru_SSR_Settings::get( 'matomo_url' );
		$p   = wp_parse_url( $url );
		if ( ! empty( $p['path'] ) && str_ends_with( $p['path'], '.php' ) ) {
			return $url;
		}
		return trailingslashit( $url ) . 'matomo.php';
	}

	/** Form fields for a stored payload (without token_auth, which is added at send time). */
	public static function build_params( array $p ) {
		$value  = (float) ( $p['value'] ?? 0 );
		$number = (string) ( $p['order_number'] ?? $p['transaction_id'] ?? '' );
		$params = array(
			'idsite'      => (int) Eru_SSR_Settings::get( 'matomo_site_id' ),
			'rec'         => 1,
			'apiv'        => 1,
			'send_image'  => 0,
			'cdt'         => gmdate( 'Y-m-d H:i:s', (int) ( $p['ts'] ?? time() ) ),
			'url'         => home_url( '/' ),
		);
		if ( ! empty( $p['user_id'] ) ) {
			$params['uid'] = (string) $p['user_id'];
		}
		if ( 'b' === Eru_SSR_Settings::get( 'matomo_mode' ) ) {
			$params['e_c'] = 'ecommerce';
			$params['e_a'] = 'refund';
			$params['e_n'] = $number;
			$params['e_v'] = number_format( $value, 2, '.', '' );
		} else {
			$params['idgoal']  = 0;
			$params['ec_id']   = ! empty( $p['refund_id'] ) ? $number . '-refund-' . (int) $p['refund_id'] : $number . '-cancel';
			$params['revenue'] = '-' . number_format( $value, 2, '.', '' );
		}
		/** Filters the Matomo form fields before sending (token_auth is added afterwards). */
		return apply_filters( 'eru_ssr_matomo_params', $params, $p );
	}

	public static function send( array $p ) {
		if ( ! self::ready() ) {
			return array( 'ok' => false, 'permanent' => false, 'error' => 'Matomo not configured' );
		}
		$params               = self::build_params( $p );
		$params['token_auth'] = Eru_SSR_Settings::get( 'matomo_token' );
		$res                  = wp_remote_post( self::endpoint(), array( 'timeout' => 15, 'body' => $params ) );
		return Eru_SSR_Queue::classify( $res );
	}

	/**
	 * Test button: bulk request for the invalid site 0. Matomo validates reachability and token_auth
	 * but records nothing (the request is counted as invalid).
	 */
	public static function test() {
		if ( ! Eru_SSR_Settings::valid_matomo_url( Eru_SSR_Settings::get( 'matomo_url' ) ) || '' === Eru_SSR_Settings::get( 'matomo_token' ) ) {
			return array( 'ok' => false, 'messages' => array( __( 'https izleme adresi ve token_auth kayıtlı olmalı.', 'server-side-refunds-for-woocommerce' ) ) );
		}
		$res = wp_remote_post(
			self::endpoint(),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'requests'   => array( '?idsite=0&rec=1&url=' . rawurlencode( home_url( '/' ) ) . '&action_name=eru-ssr-test' ),
						'token_auth' => Eru_SSR_Settings::get( 'matomo_token' ),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'messages' => array( Eru_SSR_Queue::redact( $res->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) && 'success' === ( $data['status'] ?? '' ) && 1 === (int) ( $data['invalid'] ?? 0 ) && 0 === (int) ( $data['tracked'] ?? 0 ) ) {
			return array( 'ok' => true, 'messages' => array( __( 'Matomo erişilebilir ve token_auth kabul edildi; test isteği geçersiz site kimliğiyle gönderildi, hiçbir şey kaydedilmedi.', 'server-side-refunds-for-woocommerce' ) ) );
		}
		$msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
		return array( 'ok' => false, 'messages' => array( Eru_SSR_Queue::redact( mb_substr( wp_strip_all_tags( $msg ), 0, 200 ) ) ) );
	}
}
