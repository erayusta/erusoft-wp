<?php
/**
 * GA4 Measurement Protocol destination.
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SSR_GA4 {

	const ENDPOINT = 'https://www.google-analytics.com/mp/collect';
	const DEBUG    = 'https://www.google-analytics.com/debug/mp/collect';

	/** Enabled and configured. */
	public static function ready() {
		return Eru_SSR_Settings::is_on( 'ga4_enabled' ) && '' !== Eru_SSR_Settings::get( 'ga4_measurement_id' ) && '' !== Eru_SSR_Settings::get( 'ga4_api_secret' );
	}

	/** Request body for a stored payload. */
	public static function build_body( array $p ) {
		$body = array( 'client_id' => (string) ( $p['client_id'] ?? '' ) );
		if ( ! empty( $p['user_id'] ) ) {
			$body['user_id'] = (string) $p['user_id'];
		}
		$body['events'] = array(
			array(
				'name'   => 'refund',
				'params' => array(
					'transaction_id' => (string) ( $p['transaction_id'] ?? '' ),
					'currency'       => (string) ( $p['currency'] ?? '' ),
					'value'          => (float) ( $p['value'] ?? 0 ),
				),
			),
		);
		/** Filters the GA4 request body before it is sent. */
		return apply_filters( 'eru_ssr_ga4_payload', $body, $p );
	}

	/** The secret is a query parameter by design of Google's endpoint; it is redacted in anything logged. */
	public static function url( $debug = false ) {
		return add_query_arg(
			array(
				'measurement_id' => Eru_SSR_Settings::get( 'ga4_measurement_id' ),
				'api_secret'     => Eru_SSR_Settings::get( 'ga4_api_secret' ),
			),
			$debug ? self::DEBUG : self::ENDPOINT
		);
	}

	public static function post( array $body, $debug = false ) {
		return wp_remote_post(
			self::url( $debug ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
	}

	/** @return array {ok, permanent, error} */
	public static function send( array $p ) {
		if ( ! self::ready() ) {
			return array( 'ok' => false, 'permanent' => false, 'error' => 'GA4 not configured' );
		}
		return Eru_SSR_Queue::classify( self::post( self::build_body( $p ) ) );
	}

	/** Test button: debug endpoint, reports validationMessages. */
	public static function test() {
		if ( '' === Eru_SSR_Settings::get( 'ga4_measurement_id' ) || '' === Eru_SSR_Settings::get( 'ga4_api_secret' ) ) {
			return array( 'ok' => false, 'messages' => array( __( 'Ölçüm Kimliği ve API gizli anahtarı kayıtlı olmalı.', 'server-side-refunds-for-woocommerce' ) ) );
		}
		$res = self::post(
			array(
				'client_id' => 'eru-ssr-test.1',
				'events'    => array( array( 'name' => 'refund', 'params' => array( 'transaction_id' => 'ERU-SSR-TEST', 'currency' => 'TRY', 'value' => 1.0 ) ) ),
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'messages' => array( Eru_SSR_Queue::redact( $res->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$msgs = array();
		foreach ( (array) ( $data['validationMessages'] ?? array() ) as $m ) {
			$msgs[] = Eru_SSR_Queue::redact( trim( ( $m['fieldPath'] ?? '' ) . ' ' . ( $m['description'] ?? '' ) ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			array_unshift( $msgs, 'HTTP ' . $code );
		}
		return array( 'ok' => 200 <= $code && $code < 300 && empty( $msgs ), 'messages' => $msgs ? $msgs : array( __( 'Doğrulama başarılı: uyarı yok.', 'server-side-refunds-for-woocommerce' ) ) );
	}
}
