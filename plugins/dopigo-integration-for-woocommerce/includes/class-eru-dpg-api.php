<?php
/**
 * Dopigo panel API client.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One HTTP attempt per call. 401/403 refreshes the token once (password mode); 429, 503, other 5xx and
 * transport errors come back as a retryable WP_Error for Action Scheduler to reschedule. Never sleeps.
 */
class Eru_DPG_API {

	const BASE      = 'https://panel.dopigo.com';
	const TOKEN_TTL = 12 * HOUR_IN_SECONDS;

	/** @return string Base URL (filterable for self-hosted proxies). */
	public static function base() {
		return untrailingslashit( (string) apply_filters( 'eru_dpg_api_base', self::BASE ) );
	}

	/**
	 * A failed write whose outcome is unknown: the request was sent, but a timeout or 5xx (other than 503)
	 * may have come after the server processed it. 429 and 503 mean "not processed".
	 *
	 * @param mixed $r Result.
	 * @return bool
	 */
	public static function is_ambiguous( $r ) {
		if ( ! self::is_retryable( $r ) ) {
			return false;
		}
		$d = $r->get_error_data();
		if ( ! is_array( $d ) || empty( $d['sent'] ) ) {
			return false;
		}
		$code = (int) ( $d['status'] ?? 0 );
		return 0 === $code || ( $code >= 500 && 503 !== $code );
	}

	/** @param mixed $r Result. @return bool A WP_Error worth retrying later. */
	public static function is_retryable( $r ) {
		return is_wp_error( $r ) && 'eru_dpg_retry' === $r->get_error_code();
	}

	/**
	 * Returns a token, from the direct-token setting or the 12 h cache, or by logging in.
	 *
	 * @param bool $force Ignore the cache.
	 * @return string|WP_Error
	 */
	public function token( $force = false ) {
		if ( 'token' === Eru_DPG_Settings::get( 'auth_mode' ) ) {
			$t = trim( (string) Eru_DPG_Settings::get( 'token' ) );
			return '' !== $t ? $t : new WP_Error( 'eru_dpg_no_credentials', __( 'Dopigo API anahtarı girilmemiş.', 'dopigo-integration-for-woocommerce' ) );
		}
		if ( ! $force ) {
			$c = get_option( 'eru_dpg_token' );
			if ( is_array( $c ) && ! empty( $c['token'] ) && (int) ( $c['expires'] ?? 0 ) > time() ) {
				return (string) $c['token'];
			}
		}
		$user = trim( (string) Eru_DPG_Settings::get( 'username' ) );
		$pass = (string) Eru_DPG_Settings::get( 'password' );
		if ( '' === $user || '' === $pass ) {
			return new WP_Error( 'eru_dpg_no_credentials', __( 'Dopigo kullanıcı adı veya şifresi girilmemiş.', 'dopigo-integration-for-woocommerce' ) );
		}
		$resp = wp_remote_post(
			self::base() . '/users/get_auth_token/',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'username' => $user,
					'password' => $pass,
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			Eru_DPG_Logger::log( 'auth', 'failed', __( 'Token alınamadı: ', 'dopigo-integration-for-woocommerce' ) . $resp->get_error_message() );
			return new WP_Error( 'eru_dpg_retry', $resp->get_error_message(), array( 'status' => 0 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['token'] ) && is_string( $data['token'] ) ) {
			delete_option( 'eru_dpg_token' );
			add_option(
				'eru_dpg_token',
				array(
					'token'   => $data['token'],
					'expires' => time() + self::TOKEN_TTL,
				),
				'',
				false
			);
			return $data['token'];
		}
		Eru_DPG_Logger::log( 'auth', 'failed', sprintf( /* translators: %d: HTTP status */ __( 'Dopigo girişi reddedildi (HTTP %d). Kullanıcı adı ve şifreyi kontrol edin.', 'dopigo-integration-for-woocommerce' ), $code ) );
		if ( 429 === $code || $code >= 500 ) {
			return new WP_Error( 'eru_dpg_retry', 'HTTP ' . $code, array( 'status' => $code ) );
		}
		return new WP_Error( 'eru_dpg_auth', sprintf( /* translators: %d: HTTP status */ __( 'Dopigo girişi reddedildi (HTTP %d).', 'dopigo-integration-for-woocommerce' ), $code ), array( 'status' => $code ) );
	}

	/**
	 * @param string $method   HTTP method.
	 * @param string $endpoint Path.
	 * @param array  $args     query (array), json (array).
	 * @return array|WP_Error Decoded body.
	 */
	public function request( $method, $endpoint, array $args = array() ) {
		$method = strtoupper( $method );
		if ( 'GET' !== $method && Eru_DPG_Settings::dry_run() ) {
			return array( 'dry_run' => true );
		}
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = self::base() . '/' . ltrim( $endpoint, '/' );
		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args['query'] ) ), $url );
		}
		$http = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Token ' . $token,
				'User-Agent'    => 'EruDPG/' . ERU_DPG_VERSION . '; WordPress',
			),
		);
		if ( isset( $args['json'] ) ) {
			$http['headers']['Content-Type'] = 'application/json';
			$http['body']                    = wp_json_encode( $args['json'] );
		}

		$refreshed = false;
		while ( true ) {
			$resp = wp_remote_request( $url, $http );
			if ( is_wp_error( $resp ) ) {
				// "sent": the request left this site, so the server may have processed it (ambiguous for creates).
				return new WP_Error( 'eru_dpg_retry', Eru_DPG_Logger::redact( $resp->get_error_message() ), array( 'status' => 0, 'sent' => true ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$body = (string) wp_remote_retrieve_body( $resp );
			if ( $code >= 200 && $code < 300 ) {
				$data = json_decode( $body, true );
				return is_array( $data ) ? $data : array();
			}
			if ( ( 401 === $code || 403 === $code ) && ! $refreshed && 'token' !== Eru_DPG_Settings::get( 'auth_mode' ) ) {
				$refreshed = true;
				$fresh     = $this->token( true );
				if ( ! is_wp_error( $fresh ) ) {
					$http['headers']['Authorization'] = 'Token ' . $fresh;
					continue;
				}
				return $fresh;
			}
			$snippet = trim( wp_strip_all_tags( $body ) );
			$snippet = Eru_DPG_Logger::redact( mb_substr( $snippet, 0, 300 ) );
			$data    = array(
				'status' => $code,
				'body'   => Eru_DPG_Logger::redact( mb_substr( $body, 0, 2000 ) ),
				'path'   => '/' . ltrim( $endpoint, '/' ),
				'sent'   => true,
			);
			if ( 429 === $code || $code >= 500 ) {
				$data['retry_after'] = (int) wp_remote_retrieve_header( $resp, 'retry-after' );
				return new WP_Error( 'eru_dpg_retry', sprintf( 'Dopigo HTTP %d: %s', $code, $snippet ), $data );
			}
			$hint = 403 === $code ? ' ' . __( '(Dopigo erişimi reddetti: hesap yetkisi veya API erişimi olmayabilir.)', 'dopigo-integration-for-woocommerce' ) : '';
			return new WP_Error( 'eru_dpg_http', sprintf( 'Dopigo HTTP %d%s: %s', $code, $hint, $snippet ), $data );
		}
	}

	/** @param array $resp Response. @return array List results. */
	private static function results( $resp ) {
		if ( isset( $resp['results'] ) && is_array( $resp['results'] ) ) {
			return $resp['results'];
		}
		return is_array( $resp ) && ( array() === $resp || isset( $resp[0] ) ) ? $resp : array();
	}

	/** @param int $limit Limit. @param int $offset Offset. @return array|WP_Error Meta products. */
	public function get_products( $limit = 100, $offset = 0 ) {
		$r = $this->request(
			'GET',
			'/api/v1/products/all/',
			array(
				'query' => array(
					'limit'  => (int) $limit,
					'offset' => (int) $offset,
				),
			)
		);
		return is_wp_error( $r ) ? $r : self::results( $r );
	}

	/** @param string $sku Foreign SKU. @return array|null|WP_Error First meta product. */
	public function find_by_foreign_sku( $sku ) {
		$sku = trim( (string) $sku );
		if ( '' === $sku ) {
			return null;
		}
		$r = $this->request(
			'GET',
			'/api/v1/products/all/',
			array(
				'query' => array(
					'foreign_sku' => $sku,
					'limit'       => 1,
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$first = self::results( $r )[0] ?? null;
		return is_array( $first ) ? $first : null;
	}

	/** @param int $meta_id Meta id. @return array|WP_Error Product detail (category with name). */
	public function get_product_meta( $meta_id ) {
		return $this->request( 'GET', '/api/v1/products/product_meta/' . rawurlencode( (string) (int) $meta_id ) . '/' );
	}

	/** @param array $payload Meta product. @return array|WP_Error */
	public function create_product( array $payload ) {
		return $this->request( 'POST', '/api/v1/products/create_product/', array( 'json' => $payload ) );
	}

	/**
	 * Stock/price update by foreign SKU (all records sharing it).
	 *
	 * @param string $sku     Foreign SKU.
	 * @param array  $payload {stock?, price?, listing_price?}.
	 * @return array|WP_Error
	 */
	public function update_by_foreign_sku( $sku, array $payload ) {
		return $this->request( 'PUT', '/api/v1/products/update_product_by_foreign_sku/' . rawurlencode( (string) $sku ) . '/true', array( 'json' => $payload ) );
	}

	/** @param array $query Query. @return array|WP_Error Orders. */
	public function get_orders( array $query = array() ) {
		$r = $this->request( 'GET', '/api/v1/orders/', array( 'query' => $query ) );
		return is_wp_error( $r ) ? $r : self::results( $r );
	}

	/** @param array $payload Order. @return array|WP_Error */
	public function create_order( array $payload ) {
		return $this->request( 'POST', '/api/v1/orders/', array( 'json' => $payload ) );
	}

	/** @param string $id Dopigo order id. @param array $payload Fields. @return array|WP_Error */
	public function update_order( $id, array $payload ) {
		return $this->request( 'PUT', '/api/v1/orders/' . rawurlencode( (string) $id ) . '/', array( 'json' => $payload ) );
	}

	/**
	 * Builds slim lookup indexes from a page of meta products. Pure; chain pages through $seed.
	 * Meta products carry "meta_id" (no top-level "id"); a flat record without variants acts as its own variant.
	 *
	 * @param array $batch Meta products.
	 * @param array $seed  Previous result.
	 * @return array by_foreign_sku, by_sku, by_variant_id, foreign_sku_counts, categories, category_names.
	 */
	public static function index_from_batch( array $batch, array $seed = array() ) {
		$ix = array_merge(
			array(
				'by_foreign_sku'     => array(),
				'by_sku'             => array(),
				'by_variant_id'      => array(),
				'foreign_sku_counts' => array(),
				'categories'         => array(),
				'category_names'     => array(),
			),
			$seed
		);
		$keep = array( 'id', 'meta_id', 'sku', 'foreign_sku', 'barcode', 'stock', 'available_stock', 'price', 'listing_price', 'updated_at' );
		foreach ( $batch as $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$variants = ( isset( $meta['products'] ) && is_array( $meta['products'] ) && $meta['products'] ) ? $meta['products'] : ( isset( $meta['id'] ) || isset( $meta['meta_id'] ) ? array( $meta ) : array() );
			$meta_id  = $meta['meta_id'] ?? ( $meta['id'] ?? null );
			$c        = $meta['category'] ?? null;
			$cid      = is_array( $c ) ? (int) ( $c['id'] ?? 0 ) : (int) $c;
			if ( $cid > 0 ) {
				$ix['categories'][ $cid ] = ( $ix['categories'][ $cid ] ?? 0 ) + count( $variants );
				if ( is_array( $c ) && ! empty( $c['name'] ) ) {
					$ix['category_names'][ $cid ] = trim( (string) $c['name'] );
				}
			}
			foreach ( $variants as $v ) {
				if ( ! is_array( $v ) ) {
					continue;
				}
				$slim             = array_intersect_key( $v, array_flip( $keep ) );
				$slim['_meta_id'] = $v['meta_id'] ?? $meta_id;
				if ( isset( $v['id'] ) ) {
					$ix['by_variant_id'][ (string) $v['id'] ] = $slim;
				}
				$fs = trim( (string) ( $v['foreign_sku'] ?? '' ) );
				if ( '' !== $fs ) {
					$ix['foreign_sku_counts'][ $fs ] = ( $ix['foreign_sku_counts'][ $fs ] ?? 0 ) + 1;
					if ( ! isset( $ix['by_foreign_sku'][ $fs ] ) ) {
						$ix['by_foreign_sku'][ $fs ] = $slim;
					}
				}
				$sk = trim( (string) ( $v['sku'] ?? '' ) );
				if ( '' !== $sk ) {
					$ix['by_sku'][ $sk ] = $slim;
				}
			}
		}
		return $ix;
	}
}
