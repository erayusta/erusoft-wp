<?php
/**
 * Public REST routes: eru-cmi/v1/consent (GET, POST, DELETE).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may call:
 * - A signed opt-out token (`token`): only the token's recipient and channel. GET returns that channel's status
 *   with the recipient masked; POST/DELETE write RET for it (a token never grants consent). Any recipient in
 *   the body is ignored.
 * - The signed-in user (cookie + X-WP-Nonce, or another WordPress auth): only their account e-mail (EPOSTA) and
 *   billing phone (MESAJ, ARAMA). Another recipient → 403. GET lists their own channels, masked. Neither is
 *   proof of ownership (WooCommerce does not verify them), so a RET is not lifted here: ONAY over an e-mail RET
 *   mails a confirmation link and answers 202 {confirmation: sent|pending} (429 when the client asked for too
 *   many mails, 503 when the mail could not be sent); over a phone RET it answers 403
 *   eru_cmi_reconsent_required (only İYS or an admin with a note can lift it).
 * - Anyone else → 401. A plain e-mail address is never a token.
 *
 * Responses never contain a user id, a display name or an unmasked address. Every call counts against the
 * public rate limit (20 per 10 minutes per client fingerprint) → 429.
 */
class Eru_CMI_Rest {

	const NS = 'eru-cmi/v1';

	/** Hooks. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Routes. Parameters are validated in the callbacks so that every call passes the rate limit first. */
	public static function routes() {
		register_rest_route(
			self::NS,
			'/consent',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
	}

	/** @param WP_REST_Request $r Request. @return string Token parameter ('' when absent). */
	private static function token( $r ) {
		$t = $r->get_param( 'token' );
		return is_string( $t ) ? trim( $t ) : '';
	}

	/**
	 * Rate limit, then a valid token or a signed-in user.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return true|WP_Error
	 */
	public static function permission( $r ) {
		if ( ! Eru_CMI_Rate_Limit::hit() ) {
			return new WP_Error( 'eru_cmi_rate_limited', __( 'Çok fazla istek gönderildi. Lütfen birkaç dakika sonra tekrar deneyin.', 'consent-manager-for-iys' ), array( 'status' => 429 ) );
		}
		$token = self::token( $r );
		if ( '' !== $token ) {
			$v = Eru_CMI_Tokens::verify( $token, 'optout' );
			if ( is_wp_error( $v ) ) {
				return new WP_Error( $v->get_error_code(), $v->get_error_message(), array( 'status' => 401 ) );
			}
			return true;
		}
		if ( is_user_logged_in() ) {
			return true;
		}
		return new WP_Error( 'eru_cmi_unauthorized', __( 'Bu işlem için giriş yapın ya da size gönderilen imzalı bağlantıyı kullanın.', 'consent-manager-for-iys' ), array( 'status' => 401 ) );
	}

	/** @param object|null $row Row. @param string $type Channel. @return array Public view of one channel. */
	private static function view( $row, $type, $recipient = '' ) {
		$shown = '';
		if ( $row && ! Eru_CMI_Repository::is_placeholder( $row ) ) {
			$shown = Eru_CMI_Recipient::mask( $row->recipient );
		} elseif ( '' !== $recipient ) {
			$shown = Eru_CMI_Recipient::mask( $recipient );
		}
		return array(
			'type'      => $type,
			'recipient' => '' === $shown ? null : $shown,
			'status'    => $row ? $row->status : null,
		);
	}

	/** @param WP_REST_Request $r Request. @return WP_REST_Response|WP_Error */
	public static function get( $r ) {
		$token = self::token( $r );
		if ( '' !== $token ) {
			$v = Eru_CMI_Tokens::verify( $token, 'optout' );
			if ( is_wp_error( $v ) ) {
				return new WP_Error( $v->get_error_code(), $v->get_error_message(), array( 'status' => 401 ) );
			}
			return rest_ensure_response( self::view( Eru_CMI_Repository::by_hash( $v['hash'], $v['channel'] ), $v['channel'] ) );
		}
		$out = array();
		foreach ( Eru_CMI_Account::recipients( get_current_user_id() ) as $type => $recipient ) {
			if ( '' !== $recipient ) {
				$out[] = self::view( Eru_CMI_Repository::get( $recipient, $type ), $type, $recipient );
			}
		}
		return rest_ensure_response( array( 'consents' => $out ) );
	}

	/** @param WP_REST_Request $r Request. @return WP_REST_Response|WP_Error */
	public static function post( $r ) {
		$status = strtoupper( trim( (string) $r->get_param( 'status' ) ) );
		return self::write( $r, '' === $status ? null : $status );
	}

	/** @param WP_REST_Request $r Request. @return WP_REST_Response|WP_Error */
	public static function delete( $r ) {
		return self::write( $r, 'RET' );
	}

	/**
	 * @param WP_REST_Request $r      Request.
	 * @param string|null     $status ONAY | RET (null: RET with a token, required otherwise).
	 * @return WP_REST_Response|WP_Error
	 */
	private static function write( $r, $status ) {
		$token = self::token( $r );
		$ctx   = array(
			'actor'    => 'user',
			'source'   => 'api',
			'actor_id' => get_current_user_id(),
		) + Eru_CMI_Links::client();

		if ( '' !== $token ) {
			$v = Eru_CMI_Tokens::verify( $token, 'optout' );
			if ( is_wp_error( $v ) ) {
				return new WP_Error( $v->get_error_code(), $v->get_error_message(), array( 'status' => 401 ) );
			}
			if ( null !== $status && 'RET' !== $status ) {
				return new WP_Error( 'eru_cmi_forbidden', __( 'Bu bağlantıyla yalnızca izin geri çekilebilir.', 'consent-manager-for-iys' ), array( 'status' => 403 ) );
			}
			$res = Eru_CMI_Repository::withdraw_hash( $v['hash'], $v['channel'], $ctx );
			if ( is_wp_error( $res ) ) {
				return self::error( $res );
			}
			return rest_ensure_response( self::view( Eru_CMI_Repository::by_hash( $v['hash'], $v['channel'] ), $v['channel'] ) );
		}

		$type = Eru_CMI_Recipient::type( $r->get_param( 'type' ) );
		if ( '' === $type || ! in_array( $status, Eru_CMI_Recipient::STATUSES, true ) ) {
			return new WP_Error( 'eru_cmi_invalid', __( 'Geçerli bir kanal (EPOSTA, MESAJ, ARAMA) ve durum (ONAY, RET) gönderin.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		$uid  = get_current_user_id();
		$own  = Eru_CMI_Account::recipients( $uid )[ $type ];
		$body = $r->get_param( 'recipient' );
		if ( null !== $body && '' !== $body ) {
			$norm = Eru_CMI_Recipient::normalize( is_scalar( $body ) ? (string) $body : '', $type );
			if ( is_wp_error( $norm ) || '' === $own || ! hash_equals( $own, $norm ) ) {
				return new WP_Error( 'eru_cmi_forbidden', __( 'Yalnızca kendi hesabınızın e-posta adresi ve fatura telefonu için izin değiştirebilirsiniz.', 'consent-manager-for-iys' ), array( 'status' => 403 ) );
			}
		}
		if ( '' === $own ) {
			return new WP_Error( 'eru_cmi_forbidden', __( 'Hesabınızda bu kanal için geçerli bir adres ya da numara yok.', 'consent-manager-for-iys' ), array( 'status' => 403 ) );
		}
		$row = Eru_CMI_Repository::get( $own, $type );
		if ( 'ONAY' === $status && $row && 'RET' === $row->status ) {
			if ( 'EPOSTA' !== $type ) {
				return new WP_Error( 'eru_cmi_reconsent_required', Eru_CMI_Account::phone_notice(), array( 'status' => 403 ) );
			}
			$sent = Eru_CMI_Links::request_confirm( $own );
			if ( 'limited' === $sent ) {
				return new WP_Error( 'eru_cmi_rate_limited', __( 'Çok fazla istek gönderildi. Lütfen birkaç dakika sonra tekrar deneyin.', 'consent-manager-for-iys' ), array( 'status' => 429 ) );
			}
			if ( 'failed' === $sent ) {
				return new WP_Error( 'eru_cmi_mail_failed', __( 'Onay e-postası şu anda gönderilemedi. Lütfen biraz sonra tekrar deneyin.', 'consent-manager-for-iys' ), array( 'status' => 503 ) );
			}
			$view                 = self::view( $row, $type );
			$view['confirmation'] = $sent; // sent | pending (one already went out today).
			return new WP_REST_Response( $view, 202 );
		}
		$res = Eru_CMI_Repository::set( $own, $type, $status, $ctx + array( 'user_id' => $uid ) );
		if ( is_wp_error( $res ) ) {
			return self::error( $res );
		}
		return rest_ensure_response( self::view( Eru_CMI_Repository::get( $own, $type ), $type ) );
	}

	/** @param WP_Error $e Store error. @return WP_Error With an HTTP status. */
	private static function error( WP_Error $e ) {
		$map  = array(
			'eru_cmi_busy'                => 503,
			'eru_cmi_reconsent_required' => 403,
		);
		$code = $map[ $e->get_error_code() ] ?? 400;
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), array( 'status' => $code ) );
	}
}
