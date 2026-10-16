<?php
/**
 * İYS REST client: OAuth2 password grant with refresh token, batch consent requests, request status and
 * the change feed. One attempt per call; retries belong to the queue (no sleeping here).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tokens are cached per environment and username (the key holds a hash of the username, never the
 * username). On a 401 the client refreshes once, falls back to a full login when the refresh is refused,
 * and retries the call once. Credentials travel only in POST bodies; every call is logged redacted.
 *
 * Every method returns array{ok:bool, code:int, data:mixed, error:string}; code 0 = transport error.
 *
 * A failed login (any answer of the token endpoint other than a 2xx with an access token, such as OAuth2's
 * "400 invalid_grant", or no answer at all) is reported by the authenticated calls as code 401 with
 * auth_failed = true and the endpoint's own code in token_code: the request itself never left, so callers
 * treat it as "not processed" (back off), never as a refused or missing record.
 */
class Eru_CMI_Client {

	const TIMEOUT    = 20;
	const MAX_BATCH  = 1000;
	const MAX_PULL   = 999;
	const TOKEN_PFX  = 'eru_cmi_tok_';
	const REFRESH_PFX = 'eru_cmi_rt_';

	/** @var string Environment. */
	private $env;

	/** @param string|null $env sandbox | live (default: selected). */
	public function __construct( $env = null ) {
		$this->env = in_array( $env, Eru_CMI_Settings::ENVS, true ) ? $env : Eru_CMI_Settings::env();
	}

	/** @return string Environment. */
	public function env() {
		return $this->env;
	}

	/** @return bool Username, password, İYS code and brand code present. */
	public function is_configured() {
		return Eru_CMI_Settings::has_credentials( $this->env );
	}

	/** @return string Base URL without trailing slash. */
	private function base() {
		return untrailingslashit( Eru_CMI_Settings::cred( 'base_url', $this->env ) );
	}

	/** @return string "/sps/{iys}/brands/{brand}/consents". */
	private function consents_path() {
		return '/sps/' . rawurlencode( Eru_CMI_Settings::cred( 'iys_code', $this->env ) ) . '/brands/' . rawurlencode( Eru_CMI_Settings::cred( 'brand_code', $this->env ) ) . '/consents';
	}

	/** @return string Suffix shared by the token caches: environment + username hash. */
	private function key_suffix() {
		return $this->env . '_' . substr( hash( 'sha256', Eru_CMI_Settings::cred( 'username', $this->env ) ), 0, 16 );
	}

	/** @return string Transient key of the access token. */
	public function token_key() {
		return self::TOKEN_PFX . $this->key_suffix();
	}

	/** @return string Option name of the refresh token. */
	private function refresh_key() {
		return self::REFRESH_PFX . $this->key_suffix();
	}

	/** Drops every cached access and refresh token (all environments and usernames). */
	public static function forget_tokens() {
		global $wpdb;
		$like = array( '_transient_' . self::TOKEN_PFX, '_transient_timeout_' . self::TOKEN_PFX, self::REFRESH_PFX );
		foreach ( $like as $l ) {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $l ) . '%' ) ); // phpcs:ignore
			foreach ( $names as $n ) {
				if ( 0 === strpos( $n, '_transient_timeout_' ) ) {
					delete_option( $n );
				} elseif ( 0 === strpos( $n, '_transient_' ) ) {
					delete_transient( substr( $n, strlen( '_transient_' ) ) );
				} else {
					delete_option( $n );
				}
			}
		}
	}

	/** Test helper: the cached access token of the selected environment. @return string */
	public static function cached_access_token_for_test() {
		return (string) get_transient( ( new self() )->token_key() );
	}

	/** Test helper: the access-token cache key. @return string */
	public static function token_key_for_test() {
		return ( new self() )->token_key();
	}

	/**
	 * Low-level request; logs the call. Bodies of the token endpoint are never logged.
	 *
	 * @param string     $method Method.
	 * @param string     $path   Path (with query).
	 * @param mixed      $body   JSON body or null.
	 * @param string     $token  Bearer token ('' = none).
	 * @return array{ok:bool,code:int,data:mixed,error:string}
	 */
	private function raw( $method, $path, $body, $token ) {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);
		$json = null;
		if ( null !== $body ) {
			$json         = (string) wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$args['body'] = $json;
		}
		$start = microtime( true );
		$resp  = wp_remote_request( $this->base() . $path, $args );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		$is_tk = 0 === strpos( $path, '/oauth2/' );
		if ( is_wp_error( $resp ) ) {
			Eru_CMI_Logger::api( $this->env, $method, $path, $is_tk ? null : $json, 0, $resp->get_error_message(), $ms );
			return array(
				'ok'    => false,
				'code'  => 0,
				'data'  => null,
				'error' => Eru_CMI_Logger::redact( $resp->get_error_message() ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$raw  = (string) wp_remote_retrieve_body( $resp );
		Eru_CMI_Logger::api( $this->env, $method, $path, $is_tk ? null : $json, $code, $raw, $ms );
		$data = json_decode( $raw, true );
		$ok   = $code >= 200 && $code < 300;
		return array(
			'ok'    => $ok,
			'code'  => $code,
			'data'  => $data,
			'error' => $ok ? '' : self::error_text( $code, $data, $raw ),
		);
	}

	/**
	 * Short, redacted error text: "HTTP 422 H120: message".
	 *
	 * @param int    $code HTTP status.
	 * @param mixed  $data Decoded body.
	 * @param string $raw  Raw body.
	 * @return string
	 */
	public static function error_text( $code, $data, $raw ) {
		$text = 'HTTP ' . (int) $code;
		$err  = is_array( $data ) ? ( $data['errors'][0] ?? ( $data['error'] ?? null ) ) : null;
		if ( is_array( $err ) ) {
			$text .= ' ' . trim( ( $err['code'] ?? '' ) . ': ' . ( $err['message'] ?? '' ), ': ' );
		} elseif ( is_string( $err ) ) {
			$text .= ' ' . $err;
		} elseif ( '' !== trim( (string) $raw ) ) {
			$text .= ' ' . mb_substr( wp_strip_all_tags( (string) $raw ), 0, 160 );
		}
		return Eru_CMI_Logger::redact( mb_substr( $text, 0, 300 ) );
	}

	/**
	 * Stores a token response. @param mixed $data Body. @return string Access token or ''.
	 */
	private function store_tokens( $data ) {
		if ( ! is_array( $data ) || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return '';
		}
		$ttl = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 60 ) : 600;
		set_transient( $this->token_key(), $data['access_token'], $ttl );
		if ( ! empty( $data['refresh_token'] ) && is_string( $data['refresh_token'] ) ) {
			$rttl = (int) ( $data['refresh_expires_in'] ?? 0 );
			delete_option( $this->refresh_key() );
			add_option(
				$this->refresh_key(),
				array(
					'token'   => $data['refresh_token'],
					'expires' => $rttl > 0 ? time() + max( 0, $rttl - 120 ) : 0,
				),
				'',
				false
			);
		}
		return $data['access_token'];
	}

	/** @return array{ok:bool,code:int,data:mixed,error:string} Password grant. */
	private function password_grant() {
		$r = $this->raw(
			'POST',
			'/oauth2/token',
			array(
				'username'   => Eru_CMI_Settings::cred( 'username', $this->env ),
				'password'   => Eru_CMI_Settings::cred( 'password', $this->env ),
				'grant_type' => 'password',
			),
			''
		);
		if ( $r['ok'] && '' === $this->store_tokens( $r['data'] ) ) {
			$r['ok']    = false;
			$r['error'] = __( 'İYS yanıtında erişim anahtarı yok.', 'consent-manager-for-iys' );
		}
		return $r;
	}

	/** @return string New access token from the refresh token, or '' (the stored refresh token is then dropped). */
	private function refresh_grant() {
		$rt = get_option( $this->refresh_key() );
		if ( ! is_array( $rt ) || empty( $rt['token'] ) || ( ! empty( $rt['expires'] ) && (int) $rt['expires'] <= time() ) ) {
			delete_option( $this->refresh_key() );
			return '';
		}
		$r = $this->raw(
			'POST',
			'/oauth2/token',
			array(
				'refresh_token' => $rt['token'],
				'grant_type'    => 'refresh_token',
			),
			''
		);
		$token = $r['ok'] ? $this->store_tokens( $r['data'] ) : '';
		if ( '' === $token ) {
			delete_option( $this->refresh_key() );
		}
		return $token;
	}

	/**
	 * Returns an access token: cache, then refresh token, then password grant.
	 *
	 * @param bool $force Skip the access-token cache.
	 * @return array{ok:bool,code:int,data:mixed,error:string,token?:string}
	 */
	public function access_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( $this->token_key() );
			if ( is_string( $cached ) && '' !== $cached ) {
				return array( 'ok' => true, 'code' => 200, 'data' => null, 'error' => '', 'token' => $cached );
			}
		}
		delete_transient( $this->token_key() );
		$token = $this->refresh_grant();
		if ( '' !== $token ) {
			return array( 'ok' => true, 'code' => 200, 'data' => null, 'error' => '', 'token' => $token );
		}
		$r = $this->password_grant();
		if ( $r['ok'] ) {
			$r['token'] = (string) get_transient( $this->token_key() );
		}
		return $r;
	}

	/**
	 * A failed login as the answer of an authenticated call (see the class doc).
	 *
	 * @param array $t Result of access_token().
	 * @return array{ok:bool,code:int,data:mixed,error:string,auth_failed:bool,token_code:int}
	 */
	private static function auth_failure( array $t ) {
		/* translators: %s: short error from the token endpoint */
		$error = sprintf( __( 'İYS oturumu açılamadı (kullanıcı adı, şifre ya da IP izni): %s', 'consent-manager-for-iys' ), '' !== (string) $t['error'] ? (string) $t['error'] : 'HTTP ' . (int) $t['code'] );
		return array(
			'ok'          => false,
			'code'        => 401,
			'data'        => null,
			'error'       => Eru_CMI_Logger::redact( $error ),
			'auth_failed' => true,
			'token_code'  => (int) $t['code'],
		);
	}

	/**
	 * Authenticated call; a 401 refreshes (or logs in again) once and retries once.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param mixed  $body   Body.
	 * @return array{ok:bool,code:int,data:mixed,error:string,auth_failed?:bool}
	 */
	private function authed( $method, $path, $body = null ) {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'code' => 0, 'data' => null, 'error' => __( 'İYS bağlantı bilgileri eksik.', 'consent-manager-for-iys' ) );
		}
		$t = $this->access_token();
		if ( ! $t['ok'] ) {
			return self::auth_failure( $t );
		}
		$r = $this->raw( $method, $path, $body, $t['token'] );
		if ( 401 === $r['code'] ) {
			$t = $this->access_token( true );
			if ( ! $t['ok'] ) {
				return self::auth_failure( $t );
			}
			$r = $this->raw( $method, $path, $body, $t['token'] );
		}
		return $r;
	}

	/**
	 * Sends up to 1000 consent records as one asynchronous request.
	 *
	 * @param array[] $records İYS records (recipient, recipientType, type, source, status, consentDate).
	 * @return array{ok:bool,code:int,data:mixed,error:string,request_id:string}
	 */
	public function send_batch( array $records ) {
		$records = array_slice( array_values( $records ), 0, self::MAX_BATCH );
		$r       = $this->authed( 'POST', $this->consents_path() . '/request', $records );
		$id      = '';
		if ( $r['ok'] && is_array( $r['data'] ) ) {
			$id = (string) ( $r['data']['requestId'] ?? ( $r['data']['data']['requestId'] ?? '' ) );
		}
		$r['request_id'] = $id;
		return $r;
	}

	/**
	 * Status of an asynchronous request.
	 *
	 * @param string $request_id Request ID.
	 * @return array{ok:bool,code:int,data:mixed,error:string,pending:bool,items:array<int,array>}
	 *         items are keyed by the record's index in the batch.
	 */
	public function request_status( $request_id ) {
		$r            = $this->authed( 'GET', $this->consents_path() . '/request/' . rawurlencode( (string) $request_id ) );
		$r['pending'] = false;
		$r['items']   = array();
		if ( in_array( $r['code'], array( 202, 425 ), true ) ) {
			$r['pending'] = true;
			return $r;
		}
		if ( ! $r['ok'] ) {
			return $r;
		}
		$data = is_array( $r['data'] ) ? $r['data'] : array();
		$list = self::is_list( $data ) ? $data : ( $data['subRequests'] ?? ( $data['subRequest'] ?? ( $data['list'] ?? array() ) ) );
		if ( ! self::is_list( $data ) && in_array( strtolower( (string) ( $data['status'] ?? '' ) ), array( 'enqueue', 'inprogress', 'in_progress', 'pending' ), true ) ) {
			$r['pending'] = true;
			return $r;
		}
		foreach ( (array) $list as $pos => $item ) {
			if ( is_array( $item ) ) {
				$r['items'][ isset( $item['index'] ) ? (int) $item['index'] : (int) $pos ] = $item;
			}
		}
		if ( ! $r['items'] ) {
			$r['pending'] = true;
		}
		return $r;
	}

	/**
	 * Current İYS state of one consent (used to settle a batch whose answer was lost).
	 *
	 * @param array $record recipient, recipientType, type.
	 * @return array{ok:bool,code:int,data:mixed,error:string,status:string} status ONAY|RET, '' when unknown.
	 */
	public function consent_status( array $record ) {
		$r           = $this->authed(
			'POST',
			$this->consents_path() . '/status',
			array(
				'recipient'     => (string) ( $record['recipient'] ?? '' ),
				'recipientType' => (string) ( $record['recipientType'] ?? '' ),
				'type'          => (string) ( $record['type'] ?? '' ),
			)
		);
		$r['status'] = $r['ok'] && is_array( $r['data'] ) ? strtoupper( (string) ( $r['data']['status'] ?? '' ) ) : '';
		return $r;
	}

	/**
	 * One page of consent changes made at İYS.
	 *
	 * @param string $cursor Cursor returned by the previous page ('' = from the start).
	 * @param int    $limit  Page size; İYS accepts 1–999.
	 * @return array{ok:bool,code:int,data:mixed,error:string,items:array[],cursor:string}
	 */
	public function pull_changes( $cursor = '', $limit = self::MAX_PULL ) {
		$q = array( 'limit' => max( 1, min( self::MAX_PULL, (int) $limit ) ) );
		if ( '' !== (string) $cursor ) {
			$q['after'] = (string) $cursor;
		}
		$r           = $this->authed( 'GET', $this->consents_path() . '/changes?' . http_build_query( $q ) );
		$r['items']  = array();
		$r['cursor'] = '';
		if ( $r['ok'] && is_array( $r['data'] ) ) {
			$d           = $r['data'];
			$r['items']  = array_values( array_filter( self::is_list( $d ) ? $d : (array) ( $d['list'] ?? ( $d['data'] ?? array() ) ), 'is_array' ) );
			$r['cursor'] = is_scalar( $d['after'] ?? null ) ? (string) $d['after'] : '';
		}
		return $r;
	}

	/** @param mixed $a Value. @return bool A JSON array (0..n-1 keys); PHP 8.0 has no array_is_list(). */
	private static function is_list( $a ) {
		return is_array( $a ) && array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/** Connection test: a fresh login only. @return array{ok:bool,code:int,data:mixed,error:string} */
	public function test() {
		if ( ! $this->is_configured() ) {
			return array( 'ok' => false, 'code' => 0, 'data' => null, 'error' => __( 'İYS bağlantı bilgileri eksik.', 'consent-manager-for-iys' ) );
		}
		delete_transient( $this->token_key() );
		delete_option( $this->refresh_key() );
		return $this->password_grant();
	}
}
