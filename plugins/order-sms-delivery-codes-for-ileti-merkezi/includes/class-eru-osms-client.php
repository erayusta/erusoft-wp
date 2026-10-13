<?php
/**
 * İleti Merkezi JSON API client.
 *
 * POST https://api.iletimerkezi.com/v1/send-sms/json
 * Body: {"request":{"authentication":{"key":…,"hash":…},"order":{"sender":…,"iys":"0","message":{"text":…,"receipents":{"number":["905…"]}}}}}
 * The field name really is "receipents" (the API's own spelling). Success: response.status.code = 200.
 * Credentials are only ever sent to https://api.iletimerkezi.com.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Client {

	const API_HOST = 'api.iletimerkezi.com';
	const ENDPOINT = 'https://api.iletimerkezi.com/v1/send-sms/json';

	/** Endpoint (filterable for a future API path); refused unless it is https on the İleti Merkezi API host. */
	public static function endpoint(): string {
		return (string) apply_filters( 'eru_osms_endpoint', self::ENDPOINT );
	}

	public static function endpoint_allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) && self::API_HOST === strtolower( (string) ( $parts['host'] ?? '' ) ) && empty( $parts['port'] );
	}

	/**
	 * Send one SMS.
	 *
	 * @param string $phone905 905XXXXXXXXX
	 * @param string $text     Final text (placeholders filled).
	 * @return array{class:string,error:string,response:string}  class: sent | final | uncertain
	 */
	public static function send( string $phone905, string $text ): array {
		if ( ! Eru_OSMS_Settings::has_credentials() ) {
			return array( 'class' => 'final', 'error' => __( 'İleti Merkezi kimlik bilgileri veya gönderici başlığı eksik.', 'order-sms-delivery-codes-for-ileti-merkezi' ), 'response' => '' );
		}
		$url = self::endpoint();
		if ( ! self::endpoint_allowed( $url ) ) {
			return array( 'class' => 'final', 'error' => __( 'API adresi geçersiz; kimlik bilgileri yalnızca https://api.iletimerkezi.com adresine gönderilir.', 'order-sms-delivery-codes-for-ileti-merkezi' ), 'response' => '' );
		}
		$cred = Eru_OSMS_Settings::credentials();
		$body = array(
			'request' => array(
				'authentication' => array(
					'key'  => $cred['key'],
					'hash' => $cred['hash'],
				),
				'order'          => array(
					'sender'  => (string) Eru_OSMS_Settings::get( 'sender' ),
					'iys'     => '0', // Transactional (informational) message: no commercial İYS consent needed.
					'message' => array(
						'text'       => $text,
						'receipents' => array( 'number' => array( $phone905 ) ),
					),
				),
			),
		);
		$body = (array) apply_filters( 'eru_osms_request_body', $body, $phone905, $text );

		$res      = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
			)
		);
		$conn_err = is_wp_error( $res );
		$http     = $conn_err ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$raw      = $conn_err ? $res->get_error_message() : (string) wp_remote_retrieve_body( $res );
		$json     = $conn_err ? null : json_decode( $raw, true );
		$class    = Eru_OSMS_Decision::response_class( $conn_err, $http, $json );

		$error = '';
		if ( 'sent' !== $class ) {
			if ( $conn_err ) {
				/* translators: %s: connection error message */
				$error = sprintf( __( 'Bağlantı hatası: %s', 'order-sms-delivery-codes-for-ileti-merkezi' ), $raw );
			} elseif ( isset( $json['response']['status']['message'] ) ) {
				$error = sprintf( 'İleti Merkezi (%d): %s', (int) ( $json['response']['status']['code'] ?? 0 ), (string) $json['response']['status']['message'] );
			} else {
				$error = 'HTTP ' . $http;
			}
		}
		return array(
			'class'    => $class,
			'error'    => $error,
			'response' => mb_substr( $raw, 0, 500 ),
		);
	}
}
