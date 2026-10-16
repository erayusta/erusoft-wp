<?php
/**
 * Write-only external endpoint: POST /wp-json/eru-cmi/v1/external/consents.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For another system of the same business (a CRM, a call centre, a store) that collects consents and must
 * record them here. Off by default; the route exists only while it is switched on and a key was generated.
 *
 * Request: JSON array of up to 999 records (or {"consents": [...]}), each with recipient, type, status,
 * consentDate (Turkish time, required), optional source (İYS source, default HS_WEB) and recipientType.
 * Headers:
 * - X-Eru-Cmi-Key: the API key (stored only as a SHA-256 hash),
 * - X-Eru-Cmi-Timestamp: Unix time, within ±5 minutes,
 * - X-Eru-Cmi-Signature: hex HMAC-SHA256 of "<timestamp>.<raw body>" with the signing secret.
 * A request is accepted once: the same signature again within the window is refused.
 *
 * Response: {accepted, rejected, errors: [{index, code}]} — counts and codes only, never an address, a status
 * or anything read from the store. Codes describe the submitted record only (recipient, type, status,
 * date_required, date, source, recipient_type); a valid record that was not stored (an opt-out is never lifted,
 * a newer record is never overwritten, a concurrent change) always gets the same code, not_applied. There is no
 * read route. Records are stored with actor "api", site source "external". Every failure of key, timestamp,
 * signature or replay is the same 401. Rate limited per client (EXT_LIMIT per 10 minutes).
 *
 * The signature covers the timestamp and the body (one POST route). The signing secret is stored in clear in
 * the (non-autoloaded) settings option, as HMAC needs it; the key only as a hash. The replay guard is a cache
 * entry for the time window, not a lock.
 */
class Eru_CMI_External {

	const MAX       = 999;
	const MAX_BYTES = 1048576;
	const SKEW      = 300;
	const EXT_LIMIT = 60;
	const ROUTE     = '/external/consents';

	/** The single code of a valid record that was not stored. */
	const NOT_APPLIED = 'not_applied';

	/** Hooks. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Route (POST only), registered only while switched on with a key (autoloaded flag, no query). */
	public static function routes() {
		if ( empty( Eru_CMI_Settings::front()['external'] ) ) {
			return;
		}
		register_rest_route(
			Eru_CMI_Rest::NS,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Signature of a request.
	 *
	 * @param string $secret Signing secret (hex).
	 * @param string $ts     Timestamp header.
	 * @param string $body   Raw body.
	 * @return string Hex HMAC-SHA256.
	 */
	public static function sign( $secret, $ts, $body ) {
		return hash_hmac( 'sha256', (string) $ts . '.' . (string) $body, (string) $secret );
	}

	/**
	 * Generates a new key and signing secret (the old pair stops working) and stores the key's hash.
	 *
	 * @return string The key, to be shown once.
	 */
	public static function generate() {
		$key    = 'cmi_' . bin2hex( random_bytes( 20 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		Eru_CMI_Settings::update(
			array(
				'ext_key_hash' => hash( 'sha256', $key ),
				'ext_key_hint' => substr( $key, -4 ),
				'ext_secret'   => $secret,
			)
		);
		return $key;
	}

	/** @return WP_Error The single answer for every authentication failure. */
	private static function denied() {
		return new WP_Error( 'eru_cmi_ext_auth', __( 'Kimlik doğrulanamadı.', 'consent-manager-for-iys' ), array( 'status' => 401 ) );
	}

	/**
	 * Rate limit, key, timestamp, signature and replay.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function permission( $r ) {
		if ( ! Eru_CMI_Rate_Limit::hit( 'external', self::EXT_LIMIT ) ) {
			return new WP_Error( 'eru_cmi_rate_limited', __( 'Çok fazla istek gönderildi. Lütfen birkaç dakika sonra tekrar deneyin.', 'consent-manager-for-iys' ), array( 'status' => 429 ) );
		}
		if ( ! Eru_CMI_Settings::external_ready() ) {
			return self::denied();
		}
		$key  = (string) $r->get_header( 'x_eru_cmi_key' );
		$ts   = (string) $r->get_header( 'x_eru_cmi_timestamp' );
		$sig  = strtolower( (string) $r->get_header( 'x_eru_cmi_signature' ) );
		$body = (string) $r->get_body();
		if ( '' === $key || ! hash_equals( Eru_CMI_Settings::get( 'ext_key_hash' ), hash( 'sha256', $key ) ) ) {
			return self::denied();
		}
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::SKEW ) {
			return self::denied();
		}
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $sig ) || ! hash_equals( self::sign( Eru_CMI_Settings::get( 'ext_secret' ), $ts, $body ), $sig ) ) {
			return self::denied();
		}
		// One use per signature inside the time window (a cache entry, not a lock).
		$seen = 'eru_cmi_xs_' . substr( $sig, 0, 40 );
		if ( get_transient( $seen ) ) {
			return self::denied();
		}
		set_transient( $seen, 1, 2 * self::SKEW + 60 );
		return true;
	}

	/**
	 * Stores the records.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( $r ) {
		$body = (string) $r->get_body();
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'eru_cmi_too_large', __( 'İstek çok büyük.', 'consent-manager-for-iys' ), array( 'status' => 413 ) );
		}
		$data = json_decode( $body, true );
		if ( is_array( $data ) && isset( $data['consents'] ) && is_array( $data['consents'] ) ) {
			$data = $data['consents'];
		}
		if ( ! is_array( $data ) || ( $data && array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
			return new WP_Error( 'eru_cmi_invalid', __( 'Gövde bir JSON izin listesi olmalı.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		if ( count( $data ) > self::MAX ) {
			/* translators: %d: maximum records */
			return new WP_Error( 'eru_cmi_too_large', sprintf( __( 'Bir istekte en fazla %d kayıt gönderilebilir.', 'consent-manager-for-iys' ), self::MAX ), array( 'status' => 413 ) );
		}
		$accepted = 0;
		$errors   = array();
		foreach ( $data as $i => $rec ) {
			$code = self::one( is_array( $rec ) ? $rec : array() );
			if ( '' === $code ) {
				++$accepted;
			} else {
				$errors[] = array(
					'index' => (int) $i,
					'code'  => $code,
				);
			}
		}
		return rest_ensure_response(
			array(
				'accepted' => $accepted,
				'rejected' => count( $errors ),
				'errors'   => $errors,
			)
		);
	}

	/**
	 * @param array $rec Record (camelCase or snake_case keys).
	 * @return string '' when stored (or unchanged), otherwise an error code.
	 */
	private static function one( array $rec ) {
		$v = Eru_CMI_Import::validate(
			array(
				'recipient'      => is_scalar( $rec['recipient'] ?? null ) ? (string) $rec['recipient'] : '',
				'type'           => is_scalar( $rec['type'] ?? null ) ? (string) $rec['type'] : '',
				'status'         => is_scalar( $rec['status'] ?? null ) ? (string) $rec['status'] : '',
				'consent_date'   => is_scalar( $rec['consentDate'] ?? ( $rec['consent_date'] ?? null ) ) ? (string) ( $rec['consentDate'] ?? $rec['consent_date'] ) : '',
				'source'         => is_scalar( $rec['source'] ?? null ) ? (string) $rec['source'] : '',
				'recipient_type' => is_scalar( $rec['recipientType'] ?? ( $rec['recipient_type'] ?? null ) ) ? (string) ( $rec['recipientType'] ?? $rec['recipient_type'] ) : '',
			),
			true
		);
		if ( is_wp_error( $v ) ) {
			return $v->get_error_code();
		}
		$res = Eru_CMI_Import::apply(
			$v,
			array(
				'actor'  => 'api',
				'source' => 'external',
			)
		);
		// Refused for a reason that depends on what is stored (an opt-out, a newer record, a concurrent change):
		// one generic code, so the answer never tells the caller anything about a person's consent.
		return in_array( $res, array( 'imported', 'unchanged' ), true ) ? '' : self::NOT_APPLIED;
	}
}
