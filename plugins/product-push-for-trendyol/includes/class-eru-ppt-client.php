<?php
/**
 * Trendyol integration API client (Product V2) over wp_remote_request.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One HTTP attempt per call, never sleeps. Refuses to send anything while the master switch is off or a
 * credential is missing. Basic auth goes in a header only; the log keeps method, path, code and a redacted
 * error. The result says whether the request may have been processed although no clear answer came back
 * (ambiguous), so callers can look the item up before sending it again.
 */
class Eru_PPT_Client {

	const TIMEOUT = 30;

	/** Endpoint paths relative to the base URL (filter eru_ppt_paths). */
	const PATHS = array(
		'create'              => '/product/sellers/{sellerId}/v2/products',
		'content_update'      => '/product/sellers/{sellerId}/products/content-bulk-update',
		'variant_update'      => '/product/sellers/{sellerId}/products/variant-bulk-update',
		'unapproved_update'   => '/product/sellers/{sellerId}/products/unapproved-bulk-update',
		'approved'            => '/product/sellers/{sellerId}/products/approved',
		'unapproved'          => '/product/sellers/{sellerId}/products/unapproved',
		'batch'               => '/product/sellers/{sellerId}/products/batch-requests/{id}',
		'categories'          => '/product/product-categories',
		'category_attributes' => '/product/categories/{id}/attributes',
		'attribute_values'    => '/product/categories/{id}/attributes/{attr}/values',
		'brands'              => '/product/brands/by-name',
		'addresses'           => '/sellers/{sellerId}/addresses',
	);

	/**
	 * @param string $name Path key.
	 * @param array  $vars {id}, {attr} replacements.
	 * @return string Path.
	 */
	public static function path( $name, array $vars = array() ) {
		$paths = (array) apply_filters( 'eru_ppt_paths', self::PATHS );
		$path  = (string) ( $paths[ $name ] ?? self::PATHS[ $name ] ?? '' );
		foreach ( $vars as $k => $v ) {
			$path = str_replace( '{' . $k . '}', rawurlencode( (string) $v ), $path );
		}
		return $path;
	}

	/**
	 * @param string     $method GET|POST.
	 * @param string     $path   Path with optional {sellerId}.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query args (never credentials).
	 * @return array{ok:bool,code:int,data:array|null,error:string,ambiguous:bool,sent:bool}
	 */
	public static function request( $method, $path, $body = null, array $query = array() ) {
		$fail = fn( $msg ) => array(
			'ok'        => false,
			'code'      => 0,
			'data'      => null,
			'error'     => $msg,
			'ambiguous' => false,
			'sent'      => false,
		);
		if ( ! Eru_PPT_Settings::is_on() ) {
			return $fail( __( 'Trendyol entegrasyonu kapalı (WooCommerce → Trendyol Ürün Gönderimi).', 'product-push-for-trendyol' ) );
		}
		$c = Eru_PPT_Settings::credentials();
		if ( '' === $c['seller_id'] || '' === $c['api_key'] || '' === $c['api_secret'] ) {
			return $fail( __( 'Trendyol API bilgileri eksik: satıcı ID, API anahtarı ve API secret gerekli (WooCommerce → Trendyol Ürün Gönderimi).', 'product-push-for-trendyol' ) );
		}
		$method = strtoupper( $method );
		$path   = str_replace( '{sellerId}', rawurlencode( $c['seller_id'] ), $path );
		$url    = Eru_PPT_Settings::base_url() . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $c['api_key'] . ':' . $c['api_secret'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'User-Agent'    => $c['seller_id'] . ' - ' . $c['integration_ref'],
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = Eru_PPT_Builder::encode( $body );
		}
		$t0  = microtime( true );
		$res = wp_remote_request( $url, $args );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $res ) ) {
			$err = Eru_PPT_Log::redact( $res->get_error_message() );
			Eru_PPT_Log::add(
				array(
					'method' => $method,
					'path'   => $path,
					'code'   => 0,
					'ms'     => $ms,
					'error'  => $err,
				)
			);
			return array(
				'ok'        => false,
				'code'      => 0,
				'data'      => null,
				/* translators: %s: transport error */
				'error'     => sprintf( __( 'Trendyol yanıt vermedi: %s', 'product-push-for-trendyol' ), $err ),
				'ambiguous' => true,
				'sent'      => true,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		$ok   = $code >= 200 && $code < 300 && is_array( $data );
		$err  = '';
		if ( ! $ok ) {
			$msg = '';
			if ( is_array( $data ) ) {
				$msg = (string) ( $data['errors'][0]['message'] ?? $data['message'] ?? $data['error'] ?? '' );
			}
			if ( '' === $msg ) {
				$msg = wp_strip_all_tags( substr( $raw, 0, 200 ) );
			}
			/* translators: 1: HTTP status, 2: message */
			$err = Eru_PPT_Log::redact( sprintf( __( 'Trendyol HTTP %1$d: %2$s', 'product-push-for-trendyol' ), $code, $msg ) );
		}
		Eru_PPT_Log::add(
			array(
				'method' => $method,
				'path'   => $path,
				'code'   => $code,
				'ms'     => $ms,
				'error'  => $err,
			)
		);
		return array(
			'ok'        => $ok,
			'code'      => $code,
			'data'      => is_array( $data ) ? $data : null,
			'error'     => $err,
			// 5xx, or a 2xx we cannot read: the server may have accepted the request.
			'ambiguous' => ! $ok && ( $code >= 500 || ( $code >= 200 && $code < 300 ) ),
			'sent'      => true,
		);
	}

	/** @param string $name Path key. @param array|null $body Body. @param array $vars Vars. @param array $query Query. @return array */
	public static function call( $name, $body = null, array $vars = array(), array $query = array() ) {
		return self::request( null === $body ? 'GET' : 'POST', self::path( $name, $vars ), $body, $query );
	}

	/**
	 * Brand search (never cached: the query is free text).
	 *
	 * @param string $name Name (min 2 chars).
	 * @return array|WP_Error List of [id, name] (at most 50).
	 */
	public static function brands( $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		if ( mb_strlen( $name ) < 2 ) {
			return array();
		}
		$r = self::call( 'brands', null, array(), array( 'name' => mb_substr( $name, 0, 100 ) ) );
		if ( ! $r['ok'] ) {
			return new WP_Error( 'eru_ppt_brands', $r['error'] );
		}
		$list = isset( $r['data']['brands'] ) && is_array( $r['data']['brands'] ) ? $r['data']['brands'] : $r['data'];
		$out  = array();
		foreach ( (array) $list as $b ) {
			if ( is_array( $b ) && ! empty( $b['id'] ) ) {
				$out[] = array(
					'id'   => absint( $b['id'] ),
					'name' => sanitize_text_field( (string) ( $b['name'] ?? '' ) ),
				);
			}
		}
		return array_slice( $out, 0, 50 );
	}

	/**
	 * Seller addresses.
	 *
	 * @return array|WP_Error List of [id, type, address, default].
	 */
	public static function addresses() {
		$r = self::call( 'addresses' );
		if ( ! $r['ok'] ) {
			return new WP_Error( 'eru_ppt_addresses', $r['error'] );
		}
		$out = array();
		foreach ( (array) ( $r['data']['supplierAddresses'] ?? array() ) as $a ) {
			if ( is_array( $a ) && ! empty( $a['id'] ) ) {
				$out[] = array(
					'id'      => absint( $a['id'] ),
					'type'    => sanitize_text_field( (string) ( $a['addressType'] ?? '' ) ),
					'address' => sanitize_text_field( (string) ( $a['fullAddress'] ?? $a['address'] ?? '' ) ),
					'default' => ! empty( $a['isDefault'] ) ? 'evet' : '',
				);
			}
		}
		return $out;
	}
}
