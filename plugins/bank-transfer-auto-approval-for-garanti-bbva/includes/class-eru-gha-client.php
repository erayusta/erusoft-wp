<?php
/**
 * Garanti BBVA Account Transactions client: OAuth2 client_credentials, paged transaction list.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The provider documents the access token as single-use, so no token is cached: every run mints its own.
 * A 401 on a page mints again and retries that page once; a second 401 ends the fetch. After the first
 * such retry succeeds, the remaining pages each get a fresh token up front. Every request goes through
 * wp_remote_post; credentials travel only in the POST body and never reach the log.
 */
class Eru_GHA_Client {

	const BASE       = 'https://apis.garantibbva.com.tr';
	const TOKEN_PATH = '/auth/oauth/v2/token';
	const TXN_PATH   = '/balancesandmovements/accountinformation/transaction/v1/gettransactions';
	const CACHE      = 'eru_gha_txns';
	const TIMEZONE   = 'Europe/Istanbul';

	/** @var array Settings snapshot. */
	private $s;

	/** @var string Current token. */
	private $token = '';

	/** @var bool A retry after 401 proved the token single-use. */
	private $single_use = false;

	/** @var int Tokens minted in this fetch. */
	private $mints = 0;

	/** Reads the credentials from the settings. */
	public function __construct() {
		$this->s = Eru_GHA_Settings::all();
	}

	/** @return string API base URL. */
	private function base() {
		return untrailingslashit( (string) apply_filters( 'eru_gha_api_base', self::BASE ) );
	}

	/**
	 * Mints a token.
	 *
	 * @return string|WP_Error
	 */
	public function mint_token() {
		++$this->mints;
		$r = wp_remote_post(
			$this->base() . self::TOKEN_PATH,
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'        => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => (string) $this->s['client_id'],
					'client_secret' => (string) $this->s['client_secret'],
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'eru_gha_token', __( 'Token alınamadı: ', 'bank-transfer-auto-approval-for-garanti-bbva' ) . $r->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
			return new WP_Error( 'eru_gha_token', sprintf( /* translators: %d: HTTP status */ __( 'Token alınamadı (HTTP %d)', 'bank-transfer-auto-approval-for-garanti-bbva' ), $code ) . self::api_message( $body ) );
		}
		return $body['access_token'];
	}

	/**
	 * Fetches one page.
	 *
	 * @param int    $page  Page (1-based).
	 * @param string $start Start (bank local time).
	 * @param string $end   End (bank local time).
	 * @return array{code:int, transactions?:array, error?:string}
	 */
	private function request_page( $page, $start, $end ) {
		$body = array(
			'consentId' => (string) $this->s['consent_id'],
			'startDate' => $start,
			'endDate'   => $end,
			'pageIndex' => (int) $page,
			'pageSize'  => Eru_GHA_Paginator::PAGE_SIZE,
		);
		if ( '' !== (string) $this->s['unit_number'] ) {
			$body['unitNum'] = (int) $this->s['unit_number'];
		}
		if ( '' !== (string) $this->s['account_number'] ) {
			$body['accountNum'] = (int) $this->s['account_number'];
		}
		if ( '' !== (string) $this->s['iban'] ) {
			$body['IBAN'] = (string) $this->s['iban'];
		}
		$r = wp_remote_post(
			$this->base() . self::TXN_PATH,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'GUID'          => str_replace( '-', '', wp_generate_uuid4() ),
				),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return array(
				'code'  => 0,
				'error' => sprintf( /* translators: 1: page, 2: error */ __( 'Sayfa %1$d: %2$s', 'bank-transfer-auto-approval-for-garanti-bbva' ), $page, $r->get_error_message() ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 !== $code ) {
			return array(
				'code'  => $code,
				'error' => sprintf( /* translators: 1: HTTP status, 2: page */ __( 'HTTP %1$d (sayfa %2$d)', 'bank-transfer-auto-approval-for-garanti-bbva' ), $code, $page ) . self::api_message( $data ),
			);
		}
		if ( ! is_array( $data ) ) {
			return array(
				'code'  => $code,
				'error' => sprintf( /* translators: %d: page */ __( 'Okunamayan yanıt (sayfa %d)', 'bank-transfer-auto-approval-for-garanti-bbva' ), $page ),
			);
		}
		$list = $data['transactions'] ?? array();
		return array(
			'code'         => $code,
			'transactions' => is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array(),
		);
	}

	/**
	 * One page with the 401 policy.
	 *
	 * @param int    $page  Page.
	 * @param string $start Start.
	 * @param string $end   End.
	 * @return array
	 */
	private function page_with_auth( $page, $start, $end ) {
		if ( $this->single_use && $page > 1 ) {
			$t = $this->mint_token();
			if ( is_wp_error( $t ) ) {
				return array( 'error' => $t->get_error_message() );
			}
			$this->token = $t;
		}
		$r = $this->request_page( $page, $start, $end );
		if ( 401 !== $r['code'] ) {
			return $r;
		}
		$t = $this->mint_token();
		if ( is_wp_error( $t ) ) {
			return array( 'error' => $t->get_error_message() );
		}
		$this->token = $t;
		$r           = $this->request_page( $page, $start, $end );
		if ( 401 !== $r['code'] ) {
			$this->single_use = true;
		}
		return $r;
	}

	/**
	 * All transactions of the lookback window.
	 *
	 * @param int $hours Lookback (clamped to 24–96).
	 * @return array{ok:bool, transactions:array, pages:int, error:string, truncated:bool, tokens:int}
	 */
	public function fetch_transactions( $hours ) {
		$fail = fn( $msg ) => array(
			'ok'           => false,
			'transactions' => array(),
			'pages'        => 0,
			'error'        => $msg,
			'truncated'    => false,
			'tokens'       => $this->mints,
		);
		if ( ! Eru_GHA_Settings::has_credentials() ) {
			return $fail( __( 'Kimlik bilgileri eksik (client ID, secret, consent ID, hesap no).', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		}
		$this->mints      = 0;
		$this->single_use = false;
		$t                = $this->mint_token();
		if ( is_wp_error( $t ) ) {
			return $fail( $t->get_error_message() );
		}
		$this->token = $t;
		$hours       = max( 24, min( 96, (int) $hours ) );
		$tz          = new DateTimeZone( self::TIMEZONE );
		$end         = new DateTimeImmutable( 'now', $tz );
		$start       = $end->setTimestamp( $end->getTimestamp() - $hours * HOUR_IN_SECONDS );
		$fmt         = fn( DateTimeImmutable $d ) => $d->format( 'Y-m-d\TH:i:s' ) . '.000';
		$s           = $fmt( $start );
		$e           = $fmt( $end );
		$res         = Eru_GHA_Paginator::collect( fn( $page ) => $this->page_with_auth( $page, $s, $e ) );
		$this->token = '';
		$error       = $res['error'];
		if ( '' === $error && $res['truncated'] ) {
			$error = sprintf( /* translators: %d: pages */ __( 'Hareket listesi %d sayfada bitmedi; eksik veriyle eşleştirme yapılmadı.', 'bank-transfer-auto-approval-for-garanti-bbva' ), $res['pages'] );
		}
		return array(
			'ok'           => '' === $error,
			'transactions' => $res['transactions'],
			'pages'        => $res['pages'],
			'error'        => Eru_GHA_Log::mask( $error ),
			'truncated'    => $res['truncated'],
			'tokens'       => $this->mints,
		);
	}

	/** @param mixed $body Decoded error body. @return string " — message" or ''. */
	private static function api_message( $body ) {
		if ( ! is_array( $body ) ) {
			return '';
		}
		foreach ( array( $body['result']['messageText'] ?? null, $body['result']['message'] ?? null, $body['error_description'] ?? null, $body['message'] ?? null, is_string( $body['error'] ?? null ) ? $body['error'] : null, $body['fault']['faultstring'] ?? null ) as $c ) {
			if ( is_string( $c ) && '' !== trim( $c ) ) {
				return ' — ' . mb_substr( sanitize_text_field( $c ), 0, 200 );
			}
		}
		return '';
	}
}
