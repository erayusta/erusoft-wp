<?php
/**
 * VakıfBank API client: OAuth2 tokens per scope, OTP services and the Vinov instruction services.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two token flows: client_credentials with scope "oob" for the OTP services, b2b_credentials with the consent id
 * and scope "payment" for Vinov. Tokens are cached in transients whose key carries the environment, the scope
 * and a hash of the client id, base URL and consent id, so a mode or credential change never reuses a token.
 * Credentials travel only in the POST body of the token request. A 401 drops the cached token, mints a new one
 * and retries the call once. A call succeeds only on HTTP 2xx with Header.StatusCode APIGW000000.
 *
 * Every method returns array{success:bool, data:array, status_code:string, error:string, http_code:int,
 * ambiguous:bool}. ambiguous is true for a transport error (timeout), HTTP 5xx, or any reply without a parsed
 * Header.StatusCode (an HTML/empty page from a proxy, an unparsable body): the bank may or may not have acted.
 * Only a parsed, non-success status is a definitive answer. Request/response bodies are logged at debug level
 * only, after redaction; list and detail calls (which return third-party data) log only the status and the
 * number of records.
 *
 * Payment operations (createPayment, assign, reduce, update, cancelPayment) are deliberately not implemented.
 */
class Eru_VNV_API_Client {

	const TOKEN_PATH = '/auth/oauth/v2/token';
	const OK         = 'APIGW000000';
	const TIMEZONE   = 'Europe/Istanbul';

	/** Calls whose bodies are never logged (they list debtors, amounts and movements). */
	const QUIET = array( '/vinov/listInstruction', '/vinov/listInstructionDetail', '/vinov/listCampaignReceiver', '/vinov/receiverTransactionList', '/vinov/debtorTransactionList' );

	/** @var string test | live */
	private $env;

	/** @var string Base URL. */
	private $base;

	/** @param string|null $env test | live (default: the selected environment). */
	public function __construct( $env = null ) {
		$this->env  = in_array( $env, Eru_VNV_Settings::ENVS, true ) ? $env : Eru_VNV_Settings::env();
		$this->base = Eru_VNV_Settings::cred( 'base_url', $this->env );
	}

	/** @return string test | live */
	public function env() {
		return $this->env;
	}

	/** @param string $scope oob | payment. @return string Transient key of the cached token. */
	public function token_key( $scope ) {
		$scope = 'oob' === $scope ? 'oob' : 'payment';
		$id    = Eru_VNV_Settings::cred( 'client_id', $this->env ) . '|' . $this->base . '|' . ( 'payment' === $scope ? Eru_VNV_Settings::str( 'consent_id' ) : '' );
		return 'eru_vnv_tok_' . $this->env . '_' . $scope . '_' . substr( hash( 'sha256', $id ), 0, 16 );
	}

	/** Drops the cached tokens of this environment. */
	public function clear_tokens() {
		delete_transient( $this->token_key( 'oob' ) );
		delete_transient( $this->token_key( 'payment' ) );
	}

	/**
	 * A bearer token for a scope (cached).
	 *
	 * @param string $scope oob | payment.
	 * @param bool   $probe Always mint a new token into a separate key (connection test), leaving the cached
	 *                      token the checkout uses untouched.
	 * @return string|WP_Error
	 */
	public function token( $scope, $probe = false ) {
		$scope = 'oob' === $scope ? 'oob' : 'payment';
		$key   = $this->token_key( $scope ) . ( $probe ? '_probe' : '' );
		if ( $probe ) {
			delete_transient( $key );
		}
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$client = Eru_VNV_Settings::cred( 'client_id', $this->env );
		$secret = Eru_VNV_Settings::cred( 'client_secret', $this->env );
		if ( '' === $this->base || '' === $client || '' === $secret ) {
			return new WP_Error( 'eru_vnv_token', __( 'Vinov API adresi veya istemci bilgileri eksik.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$body = array(
			'client_id'     => $client,
			'client_secret' => $secret,
			'scope'         => $scope,
			'resource'      => 'live' === $this->env ? 'production' : 'sandbox',
		);
		if ( 'oob' === $scope ) {
			$body['grant_type'] = 'client_credentials';
		} else {
			$body['grant_type'] = 'b2b_credentials';
			$body['consentId']  = Eru_VNV_Settings::str( 'consent_id' );
		}
		$r = wp_remote_post(
			$this->base . self::TOKEN_PATH,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'        => $body,
			)
		);
		if ( is_wp_error( $r ) ) {
			Eru_VNV_Logger::error( sprintf( 'token [%s/%s]: %s', $this->env, $scope, $r->get_error_message() ) );
			return new WP_Error( 'eru_vnv_token', __( 'Bankadan yetki alınamadı.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			Eru_VNV_Logger::error( sprintf( 'token [%s/%s]: HTTP %d%s', $this->env, $scope, $code, self::message( $data ) ? ' — ' . self::message( $data ) : '' ) );
			return new WP_Error( 'eru_vnv_token', __( 'Bankadan yetki alınamadı.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - 60 : 1740;
		set_transient( $key, $data['access_token'], $probe ? 60 : max( 60, min( DAY_IN_SECONDS, $ttl ) ) );
		Eru_VNV_Logger::debug( sprintf( 'token [%s/%s]: yeni token alındı', $this->env, $scope ) );
		return $data['access_token'];
	}

	/**
	 * One API call with the 401 policy.
	 *
	 * @param string $path  Endpoint path.
	 * @param array  $body  JSON body.
	 * @param string $scope oob | payment.
	 * @return array
	 */
	private function request( $path, array $body, $scope = 'payment' ) {
		$token = $this->token( $scope );
		if ( is_wp_error( $token ) ) {
			return self::result( false, array(), '', $token->get_error_message(), 0, false );
		}
		$url  = $this->base . $path;
		$args = array(
			'method'      => 'POST',
			'timeout'     => 45,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'        => (string) wp_json_encode( $body ),
		);
		$t0   = microtime( true );
		$r    = wp_remote_request( $url, $args );
		if ( ! is_wp_error( $r ) && 401 === (int) wp_remote_retrieve_response_code( $r ) ) {
			delete_transient( $this->token_key( $scope ) );
			$token = $this->token( $scope );
			if ( is_wp_error( $token ) ) {
				return self::result( false, array(), '', $token->get_error_message(), 401, false );
			}
			$args['headers']['Authorization'] = 'Bearer ' . $token;
			$r                                = wp_remote_request( $url, $args );
		}
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $r ) ) {
			Eru_VNV_Logger::error( sprintf( '%s [%s]: %s (%d ms)', $path, $this->env, $r->get_error_message(), $ms ) );
			return self::result( false, array(), '', __( 'Bankaya ulaşılamadı.', 'vakifbank-vinov-integration-for-woocommerce' ), 0, true );
		}
		$code   = (int) wp_remote_retrieve_response_code( $r );
		$raw    = (string) wp_remote_retrieve_body( $r );
		$data   = json_decode( $raw, true );
		$status = is_array( $data ) && isset( $data['Header']['StatusCode'] ) && is_scalar( $data['Header']['StatusCode'] ) ? (string) $data['Header']['StatusCode'] : '';
		$ok     = $code >= 200 && $code < 300 && self::OK === $status;
		if ( in_array( $path, self::QUIET, true ) ) {
			Eru_VNV_Logger::debug( sprintf( '%s [%s]: HTTP %d %s (%d ms) kayıt=%d', $path, $this->env, $code, $status, $ms, self::records( $data ) ) );
		} else {
			Eru_VNV_Logger::debug( sprintf( '%s [%s]: HTTP %d %s (%d ms) istek=%s yanıt=%s', $path, $this->env, $code, $status, $ms, $args['body'], mb_substr( $raw, 0, 4000 ) ) );
		}
		if ( ! $ok ) {
			Eru_VNV_Logger::error( sprintf( '%s [%s]: HTTP %d %s%s', $path, $this->env, $code, $status, self::message( $data ) ? ' — ' . self::message( $data ) : '' ) );
		}
		return self::result(
			$ok,
			is_array( $data ) && is_array( $data['Data'] ?? null ) ? $data['Data'] : array(),
			$status,
			$ok ? '' : ( self::message( $data ) ? self::message( $data ) : sprintf( /* translators: %d: HTTP status */ __( 'Banka hatası (HTTP %d)', 'vakifbank-vinov-integration-for-woocommerce' ), $code ) ),
			$code,
			! $ok && ( $code >= 500 || '' === $status )
		);
	}

	/** @param mixed $data Decoded body. @return int Records in the first list under Data (0 when none). */
	private static function records( $data ) {
		foreach ( is_array( $data ) && is_array( $data['Data'] ?? null ) ? $data['Data'] : array() as $v ) {
			if ( is_array( $v ) && array_values( $v ) === $v ) {
				return count( $v );
			}
		}
		return 0;
	}

	/** @return array The normalised result shape. */
	private static function result( $success, array $data, $status_code, $error, $http_code, $ambiguous ) {
		return array(
			'success'     => (bool) $success,
			'data'        => $data,
			'status_code' => (string) $status_code,
			'error'       => (string) $error,
			'http_code'   => (int) $http_code,
			'ambiguous'   => (bool) $ambiguous,
		);
	}

	/** @param mixed $data Decoded body. @return string Bank message (sanitised, redacted, ≤ 200 chars). */
	private static function message( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}
		foreach ( array( $data['Header']['StatusDescription'] ?? null, $data['error_description'] ?? null, $data['message'] ?? null, is_string( $data['error'] ?? null ) ? $data['error'] : null ) as $c ) {
			if ( is_string( $c ) && '' !== trim( $c ) ) {
				return mb_substr( Eru_VNV_Logger::redact( sanitize_text_field( $c ) ), 0, 200 );
			}
		}
		return '';
	}

	/** @param int $minor Minor units. @return float Amount with two decimals. */
	private static function amount( $minor ) {
		return Eru_VNV_Installments::from_minor( (int) $minor );
	}

	/** @param mixed $v Id. @return int|string Numeric ids as int. */
	private static function id( $v ) {
		return is_numeric( $v ) && (string) (int) $v === (string) $v ? (int) $v : (string) $v;
	}

	// ----- OTP (scope oob) -------------------------------------------------------------------------------

	/**
	 * POST /sendOTP. Data.OTPInfo.OTPTransactionId on success.
	 *
	 * @param string $tckn Identity number.
	 * @return array
	 */
	public function send_otp( $tckn ) {
		return $this->request(
			'/sendOTP',
			array(
				'IdentityNumber'  => (int) $tckn,
				'TransactionCode' => Eru_VNV_Settings::str( 'otp_transaction_code' ),
			),
			'oob'
		);
	}

	/**
	 * POST /verifyOTP. Data.Result.Success true on a correct code.
	 *
	 * @param string     $code   Code the customer typed.
	 * @param int|string $txn_id OTPTransactionId from sendOTP (kept server-side).
	 * @return array
	 */
	public function verify_otp( $code, $txn_id ) {
		return $this->request(
			'/verifyOTP',
			array(
				'OTPCode'          => (string) $code,
				'OTPTransactionId' => self::id( $txn_id ),
			),
			'oob'
		);
	}

	// ----- Vinov (scope payment) ---------------------------------------------------------------------------

	/**
	 * POST /vinov/checkDebtor. Data.IsSucceeded true when the customer may pay this amount.
	 *
	 * @param string     $tckn         Identity number.
	 * @param int        $amount_minor Amount in minor units (server-computed).
	 * @param string     $otp          Verified code.
	 * @param int|string $otp_id       OTP transaction id.
	 * @return array
	 */
	public function check_debtor( $tckn, $amount_minor, $otp, $otp_id ) {
		return $this->request(
			'/vinov/checkDebtor',
			array(
				'IdentityNumber' => (string) $tckn,
				'Amount'         => self::amount( $amount_minor ),
				'WorkPlaceId'    => Eru_VNV_Settings::workplace_id(),
				'Otp'            => (string) $otp,
				'OtpId'          => self::id( $otp_id ),
			)
		);
	}

	/**
	 * POST /vinov/createInstruction. Data.Result.{IsSucceeded, Id} on success.
	 *
	 * @param array $p {tckn, amount_minor, installments, plan: [{amount_minor, maturity}], expiry_date,
	 *                 invoice_number, description, campaign_code?}.
	 * @return array
	 */
	public function create_instruction( array $p ) {
		$n    = max( 1, (int) ( $p['installments'] ?? 1 ) );
		$body = array(
			'InstructionCreationType' => 1,
			'IdentityTaxNumber'       => (string) ( $p['tckn'] ?? '' ),
			'InstructionAmount'       => self::amount( (int) ( $p['amount_minor'] ?? 0 ) ),
			'NumberofInstallments'    => $n,
			'PaymentPurpose'          => Eru_VNV_Settings::payment_purpose(),
			'WorkPlaceId'             => Eru_VNV_Settings::workplace_id(),
			'InvoiceNumber'           => (string) ( $p['invoice_number'] ?? '' ),
			'InstructionDescription'  => (string) ( $p['description'] ?? '' ),
		);
		if ( $n <= 1 ) {
			$body['ExpiryDate'] = (string) ( $p['expiry_date'] ?? '' );
		} else {
			$plan = array();
			foreach ( (array) ( $p['plan'] ?? array() ) as $row ) {
				$plan[] = array(
					'DebtAmount'   => self::amount( (int) $row['amount_minor'] ),
					'MaturityDate' => (string) $row['maturity'],
				);
			}
			$body['InstallmentPlan']   = $plan;
			$body['FirstMaturityDate'] = $plan ? $plan[0]['MaturityDate'] : '';
		}
		if ( ! empty( $p['campaign_code'] ) ) {
			$body['CampaignCode'] = (int) $p['campaign_code'];
		}
		return $this->request( '/vinov/createInstruction', $body );
	}

	/**
	 * POST /vinov/cancelInstruction (by instruction id; optional partial DebtInformation).
	 *
	 * @param int        $id        Instruction id.
	 * @param array|null $debt_info [{TransactionId, CancellationAmount}] for a partial cancel.
	 * @return array
	 */
	public function cancel_instruction( $id, $debt_info = null ) {
		$body = array(
			'WorkPlaceId' => Eru_VNV_Settings::workplace_id(),
			'Id'          => (int) $id,
		);
		if ( is_array( $debt_info ) && $debt_info ) {
			$body['DebtInformation'] = array_values( $debt_info );
		}
		return $this->request( '/vinov/cancelInstruction', $body );
	}

	/**
	 * POST /vinov/listInstruction. Data.WorkPlaceInstruction[] (InstructionStatusCode 1 waiting, 2 approved,
	 * 3 cancelled).
	 *
	 * @param array $f {id, status_code, expiry_start_date, expiry_end_date, instruction_start_date,
	 *                 instruction_end_date} (dates as Y-m-d\TH:i:sP).
	 * @return array
	 */
	public function list_instructions( array $f = array() ) {
		$body = array( 'WorkPlaceId' => Eru_VNV_Settings::workplace_id() );
		$map  = array(
			'expiry_start_date'      => 'ExpiryStartDate',
			'expiry_end_date'        => 'ExpiryEndDate',
			'instruction_start_date' => 'InstructionStartDate',
			'instruction_end_date'   => 'InstructionEndDate',
		);
		if ( ! empty( $f['id'] ) ) {
			$body['Id'] = (int) $f['id'];
		}
		if ( ! empty( $f['status_code'] ) ) {
			$body['InstructionStatusCode'] = (int) $f['status_code'];
		}
		foreach ( $map as $k => $field ) {
			if ( ! empty( $f[ $k ] ) ) {
				$body[ $field ] = (string) $f[ $k ];
			}
		}
		return $this->request( '/vinov/listInstruction', $body );
	}

	/**
	 * POST /vinov/listInstructionDetail. Data.DebtInformations[] (DebtStatusCode 1 not due, 2 collected,
	 * 3 cancelled).
	 *
	 * @param int $id Instruction id.
	 * @return array
	 */
	public function instruction_detail( $id ) {
		return $this->request(
			'/vinov/listInstructionDetail',
			array(
				'WorkPlaceId' => Eru_VNV_Settings::workplace_id(),
				'Id'          => (int) $id,
			)
		);
	}

	/** POST /vinov/listCampaignReceiver. Data.Campaign[]. @return array */
	public function campaigns() {
		return $this->request( '/vinov/listCampaignReceiver', array( 'WorkPlaceId' => Eru_VNV_Settings::workplace_id() ) );
	}

	/**
	 * POST /vinov/receiverTransactionList.
	 *
	 * @param array $f {id, expiry_start_date, expiry_end_date, identity_number}.
	 * @return array
	 */
	public function receiver_transactions( array $f = array() ) {
		return $this->request( '/vinov/receiverTransactionList', self::movement_filters( $f, true ) );
	}

	/**
	 * POST /vinov/debtorTransactionList. The identity number comes from the caller (an admin form) and is sent
	 * once; it is never stored, and redaction keeps it out of the log.
	 *
	 * @param array $f {expiry_start_date, expiry_end_date, identity_number}.
	 * @return array
	 */
	public function debtor_transactions( array $f = array() ) {
		return $this->request( '/vinov/debtorTransactionList', self::movement_filters( $f, false ) );
	}

	/** @return array Movement list body. */
	private static function movement_filters( array $f, $with_id ) {
		$body = array();
		if ( $with_id && ! empty( $f['id'] ) ) {
			$body['Id'] = (int) $f['id'];
		}
		if ( ! empty( $f['expiry_start_date'] ) ) {
			$body['ExpiryStartDate'] = (string) $f['expiry_start_date'];
		}
		if ( ! empty( $f['expiry_end_date'] ) ) {
			$body['ExpiryEndDate'] = (string) $f['expiry_end_date'];
		}
		if ( ! empty( $f['identity_number'] ) ) {
			$body['IdentityTaxNumber'] = (string) $f['identity_number'];
		}
		return $body;
	}

	/**
	 * A date in the bank's format.
	 *
	 * @param string $modify Relative time ("now", "+30 days", "+1 month midnight").
	 * @return string Y-m-d\TH:i:sP in Europe/Istanbul.
	 */
	public static function date( $modify = 'now' ) {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( self::TIMEZONE ) ) )->modify( $modify )->format( 'Y-m-d\TH:i:sP' );
	}
}
