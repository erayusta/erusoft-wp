<?php
/**
 * Client for the legacy ShippingOrderDispatcherServices XML (SOAP 1.1) service over wp_remote_post.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One HTTP attempt per call, never sleeps. Every call is written to the raw log with the password
 * redacted. The result says whether the service answered, whether the error is worth retrying, and
 * whether the request may have been processed although no answer arrived (ambiguous).
 */
class Eru_YK_Client {

	const TIMEOUT = 20;

	/**
	 * errCode values that mean "this cargoKey is already registered". Only these trigger the
	 * query-and-recover path; error texts are never matched. Extend with the eru_yk_duplicate_codes filter.
	 */
	const DUPLICATE_CODES = array( '60020' );

	/** errCode values the provider documents as temporary (retried). Extend with eru_yk_transient_codes. */
	const TRANSIENT_CODES = array();

	/**
	 * errCode values of a queryShipment answer that mean "no record for this key". Only these let the
	 * dispatcher create again after an ambiguous failure and count as a "no detail" poll. Extend with
	 * eru_yk_not_found_codes.
	 */
	const NOT_FOUND_CODES = array( '820' );

	/** @var array Account. */
	private $account;

	/** @param array $account Account (id, label, username, password). */
	public function __construct( array $account ) {
		$this->account = $account;
	}

	/** @return string[] Duplicate codes (filtered). */
	public static function duplicate_codes() {
		return array_map( 'strval', (array) apply_filters( 'eru_yk_duplicate_codes', self::DUPLICATE_CODES ) );
	}

	/** @return string[] Transient remote codes (filtered). */
	public static function transient_codes() {
		return array_map( 'strval', (array) apply_filters( 'eru_yk_transient_codes', self::TRANSIENT_CODES ) );
	}

	/** @return string[] Not-found codes (filtered). */
	public static function not_found_codes() {
		return array_map( 'strval', (array) apply_filters( 'eru_yk_not_found_codes', self::NOT_FOUND_CODES ) );
	}

	/**
	 * Outcome of a queryShipment result.
	 *
	 * @param array $q Result.
	 * @return string found | absent (explicit not-found code) | error (anything else)
	 */
	public static function outcome( array $q ) {
		if ( $q['answered'] && Eru_YK_Client::delivery( $q['data'] ) ) {
			return 'found';
		}
		if ( $q['answered'] && ! $q['transient'] && in_array( (string) $q['code'], self::not_found_codes(), true ) ) {
			return 'absent';
		}
		return 'error';
	}

	/** @param bool $query queryShipment uses wsLanguage instead of userLanguage. @return array Auth fields. */
	private function auth( $query = false ) {
		return array(
			'wsUserName'                              => (string) $this->account['username'],
			'wsPassword'                              => (string) $this->account['password'],
			( $query ? 'wsLanguage' : 'userLanguage' ) => 'TR',
		);
	}

	/**
	 * createShipment.
	 *
	 * @param array $vo       ShippingOrderVO fields.
	 * @param int   $order_id Order (log).
	 * @return array Result (see call()).
	 */
	public function create( array $vo, $order_id = 0 ) {
		return $this->call( 'createShipment', array_merge( $this->auth(), array( 'ShippingOrderVO' => $vo ) ), $order_id, (string) ( $vo['cargoKey'] ?? '' ) );
	}

	/**
	 * queryShipment by cargo key (keyType 0).
	 *
	 * @param string $cargo_key Cargo key.
	 * @param int    $order_id  Order (log).
	 * @return array
	 */
	public function query( $cargo_key, $order_id = 0 ) {
		return $this->call(
			'queryShipment',
			array_merge(
				$this->auth( true ),
				array(
					'keys'              => array( (string) $cargo_key ),
					'keyType'           => '0',
					'addHistoricalData' => false,
					'onlyTracking'      => false,
				)
			),
			$order_id,
			(string) $cargo_key
		);
	}

	/**
	 * cancelShipment.
	 *
	 * @param string $cargo_key Cargo key.
	 * @param int    $order_id  Order (log).
	 * @return array
	 */
	public function cancel( $cargo_key, $order_id = 0 ) {
		return $this->call( 'cancelShipment', array_merge( $this->auth(), array( 'cargoKeys' => array( (string) $cargo_key ) ) ), $order_id, (string) $cargo_key );
	}

	/**
	 * @param string $op        Operation.
	 * @param array  $fields    Fields.
	 * @param int    $order_id  Order.
	 * @param string $cargo_key Cargo key.
	 * @return array{answered:bool,ok:bool,transient:bool,ambiguous:bool,code:string,message:string,http:int,data:array}
	 */
	private function call( $op, array $fields, $order_id, $cargo_key ) {
		$xml  = Eru_YK_Xml::envelope( $op, $fields );
		$url  = Eru_YK_Settings::endpoint();
		$t0   = microtime( true );
		$resp = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => '""',
				),
				'body'        => $xml,
			)
		);
		$ms     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$result = self::classify( $resp, $op, $cargo_key );
		Eru_YK_Logger::request(
			array(
				'order_id'    => $order_id,
				'account'     => (string) ( $this->account['label'] ?? '' ),
				'operation'   => $op,
				'cargo_key'   => $cargo_key,
				'http_code'   => $result['http'],
				'duration_ms' => $ms,
				'request'     => $xml,
				'response'    => is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_body( $resp ),
				'error'       => $result['ok'] ? '' : trim( $result['code'] . ' ' . $result['message'] ),
			)
		);
		return $result;
	}

	/**
	 * Turns an HTTP response into a result, following the WSDL result types:
	 * createShipment → ShippingOrderResultVO (outFlag, outResult, errCode, jobId, shippingOrderDetailVO*),
	 * queryShipment → ShippingDeliveryVO (… shippingDeliveryDetailVO*),
	 * cancelShipment → ShippingOrderResultVO of type shippingCancelResultVO (… shippingCancelDetailVO*),
	 * faults → YurticikargoWSException (errorCode, errText).
	 * create and cancel succeed only when outFlag is "0" and nothing reports an errCode; cancel also needs a
	 * detail row for the requested key. An answer that does not have that shape is an error (an unrecognised
	 * createShipment answer is ambiguous: the shipment may exist).
	 *
	 * @param array|WP_Error $resp Response.
	 * @param string         $op   Operation.
	 * @param string         $key  Cargo key of the request.
	 * @return array
	 */
	public static function classify( $resp, $op = '', $key = '' ) {
		$r = array(
			'answered'  => false,
			'ok'        => false,
			'transient' => false,
			'ambiguous' => false,
			'code'      => '',
			'message'   => '',
			'http'      => 0,
			'data'      => array(),
		);
		if ( is_wp_error( $resp ) ) {
			$msg            = $resp->get_error_message();
			$r['transient'] = true;
			// Name resolution and refused connections fail before anything is sent.
			$r['ambiguous'] = ! preg_match( '/could not resolve|resolve host|connection refused|failed to connect|couldn\'t connect|cURL error (6|7):/i', $msg );
			$r['code']      = 'http_request_failed';
			$r['message']   = $msg;
			return $r;
		}
		$code      = (int) wp_remote_retrieve_response_code( $resp );
		$r['http'] = $code;
		$parsed    = Eru_YK_Xml::parse( (string) wp_remote_retrieve_body( $resp ) );
		if ( is_array( $parsed ) && $parsed['fault'] ) {
			$r['answered'] = true;
			$r['code']     = '' !== $parsed['fault']['error_code'] ? 'fault_' . $parsed['fault']['error_code'] : 'soap_fault';
			$r['message']  = trim( '' !== $parsed['fault']['error_text'] ? $parsed['fault']['error_text'] : $parsed['fault']['code'] . ' ' . $parsed['fault']['string'] );
			return $r;
		}
		if ( 503 === $code || 429 === $code ) {
			$r['transient'] = true;
			$r['code']      = 'http_' . $code;
			$r['message']   = __( 'Servis geçici olarak yanıt veremiyor.', 'yurtici-kargo-integration-for-woocommerce' );
			return $r;
		}
		if ( $code >= 500 || ( 200 <= $code && $code < 300 && is_wp_error( $parsed ) ) ) {
			// A 5xx without a SOAP fault, or garbage with 200: the request may have been processed.
			$r['transient'] = true;
			$r['ambiguous'] = true;
			$r['code']      = $code >= 500 ? 'http_' . $code : 'parse';
			$r['message']   = is_wp_error( $parsed ) ? $parsed->get_error_message() : __( 'Sunucu hatası.', 'yurtici-kargo-integration-for-woocommerce' );
			return $r;
		}
		if ( $code < 200 || $code >= 300 || is_wp_error( $parsed ) ) {
			$r['code']    = 'http_' . $code;
			$r['message'] = is_wp_error( $parsed ) ? $parsed->get_error_message() : __( 'Beklenmeyen HTTP yanıtı.', 'yurtici-kargo-integration-for-woocommerce' );
			return $r;
		}

		$expected = array(
			'createShipment' => array( 'ShippingOrderResultVO', 'shippingOrderDetailVO' ),
			'queryShipment'  => array( 'ShippingDeliveryVO', 'shippingDeliveryDetailVO' ),
			'cancelShipment' => array( 'ShippingOrderResultVO', 'shippingCancelDetailVO' ),
		);
		list( $root_name, $detail_name ) = $expected[ $op ] ?? array( '', '' );
		$root = $root_name && isset( $parsed['body'][ $root_name ] ) ? Eru_YK_Xml::first( $parsed['body'][ $root_name ] ) : null;
		if ( ! is_array( $root ) || ! is_string( $root['outFlag'] ?? null ) ) {
			$r['code']    = 'unrecognised';
			$r['message'] = __( 'Yanıt beklenen biçimde değil.', 'yurtici-kargo-integration-for-woocommerce' );
			if ( 'createShipment' === $op ) {
				$r['transient'] = true;
				$r['ambiguous'] = true;
			}
			return $r;
		}
		$r['answered'] = true;
		$r['data']     = $root;
		$flag          = trim( $root['outFlag'] );
		$s             = fn( $a, $k ) => is_string( $a[ $k ] ?? null ) ? trim( $a[ $k ] ) : '';
		$details       = $root[ $detail_name ] ?? null;
		$details       = is_array( $details ) ? ( array_is_list( $details ) ? $details : array( $details ) ) : array();
		$err           = '';
		$msg           = '';
		$own           = false;
		foreach ( $details as $d ) {
			if ( ! is_array( $d ) ) {
				continue;
			}
			if ( '' !== $key && $s( $d, 'cargoKey' ) === $key ) {
				$own = true;
			}
			$c = $s( $d, 'errCode' );
			if ( '' === $err && '' !== $c && '0' !== $c ) {
				$err = $c;
				$msg = $s( $d, 'errMessage' );
			}
		}
		$rc = $s( $root, 'errCode' );
		if ( '' === $err && '' !== $rc && '0' !== $rc ) {
			$err = $rc;
			$msg = $s( $root, 'errMessage' );
		}
		if ( '' === $err && '0' !== $flag ) {
			$err = 'outFlag_' . $flag;
		}
		if ( '' === $err && 'cancelShipment' === $op && ! $own ) {
			$err = 'unrecognised';
			$msg = __( 'İptal yanıtında bu kargo anahtarı yok.', 'yurtici-kargo-integration-for-woocommerce' );
		}
		if ( '' === $msg ) {
			$msg = $s( $root, 'outResult' );
		}
		$r['ok']      = '' === $err;
		$r['code']    = $err;
		$r['message'] = $r['ok'] ? '' : $msg;
		if ( ! $r['ok'] && in_array( $err, self::transient_codes(), true ) ) {
			$r['transient'] = true;
		}
		return $r;
	}

	/**
	 * The delivery record of a queryShipment answer, when it is a real record (not a "not found").
	 *
	 * @param array $data Result data (ShippingDeliveryVO).
	 * @return array|null {cargo_key, status, message, event_id, event, doc_id, tracking_url}
	 */
	public static function delivery( array $data ) {
		$d = Eru_YK_Xml::first( $data['shippingDeliveryDetailVO'] ?? null );
		if ( ! $d ) {
			return null;
		}
		$err = is_string( $d['errCode'] ?? null ) ? trim( $d['errCode'] ) : '';
		if ( '' !== $err && '0' !== $err ) {
			return null;
		}
		$item = Eru_YK_Xml::first( $d['shippingDeliveryItemDetailVO'] ?? null ) ?? array();
		$s    = fn( $a, $k ) => is_string( $a[ $k ] ?? null ) ? trim( $a[ $k ] ) : '';
		$out  = array(
			'cargo_key'    => $s( $d, 'cargoKey' ),
			'status'       => strtoupper( $s( $d, 'operationStatus' ) ),
			'message'      => $s( $d, 'operationMessage' ),
			'event_id'     => strtoupper( $s( $item, 'cargoEventId' ) ),
			'event'        => $s( $item, 'cargoEventExplanation' ),
			'doc_id'       => $s( $item, 'docId' ),
			'tracking_url' => $s( $item, 'trackingUrl' ),
		);
		if ( '' === $out['status'] && '' === $out['event_id'] && '' === $out['doc_id'] ) {
			return null;
		}
		return $out;
	}
}
