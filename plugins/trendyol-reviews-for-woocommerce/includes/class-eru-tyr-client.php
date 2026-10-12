<?php
/**
 * Trendyol HTTP client.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two sources:
 * - The merchant's own product list from the official Trendyol Seller API
 *   (seller ID + API key + API secret, HTTP Basic). It carries barcode,
 *   stock code and content ID for every listing.
 * - Product reviews from the public review endpoint trendyol.com uses on
 *   product pages (the Seller API has no review endpoint). It is not
 *   officially documented and may change; its URL template is filterable
 *   with `eru_tyr_endpoint` ($template, $type: `reviews`).
 *
 * The Seller API URL is fixed: the API key and secret are only ever sent
 * over HTTPS to apigw.trendyol.com, redirects are not followed, and the
 * products template is not passed through `eru_tyr_endpoint`.
 */
class Eru_TYR_Client {

	/** The only host Seller API credentials may be sent to. */
	const SELLER_API_HOST = 'apigw.trendyol.com';

	const ENDPOINTS = array(
		'products' => 'https://apigw.trendyol.com/integration/product/sellers/%1$s/products?page=%2$d&size=%3$d',
		'reviews'  => 'https://apigw.trendyol.com/discovery-storefront-trproductgw-service/api/review-read/product-reviews/detailed?contentId=%1$d&page=%2$d&pageSize=%3$d&order=DESC&orderBy=LastModifiedDate&channelId=1',
	);

	/**
	 * One page of the seller's product list, grouped by content ID.
	 *
	 * Archived, rejected and blacklisted listings are skipped (filter
	 * `eru_tyr_include_item`); variants of one content are merged.
	 *
	 * @param int $page 0-based.
	 * @return array|WP_Error { products: array<{content_id:int,title:string,url:string,barcode:string[],sku:string[]}>, total_pages: int }
	 */
	public function get_seller_products_page( string $seller_id, string $api_key, string $api_secret, int $page, int $size = 100 ) {
		if ( '' === $seller_id || '' === $api_key || '' === $api_secret ) {
			return new WP_Error( 'eru_tyr_credentials', __( 'Mağaza içe aktarımı için Satıcı ID, API Key ve API Secret gerekir (Trendyol Satıcı Paneli → Hesap Bilgileri → Entegrasyon Bilgileri).', 'trendyol-reviews-for-woocommerce' ) );
		}

		$url   = sprintf( static::ENDPOINTS['products'], rawurlencode( $seller_id ), $page, $size );
		$parts = wp_parse_url( $url );
		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || self::SELLER_API_HOST !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) {
			return new WP_Error( 'eru_tyr_seller_host', __( 'Trendyol Satıcı API adresi geçersiz; kimlik bilgileri yalnızca https://apigw.trendyol.com adresine gönderilir.', 'trendyol-reviews-for-woocommerce' ) );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					// Trendyol requires "<seller ID> - SelfIntegration" for a merchant's own integration.
					'User-Agent'    => $seller_id . ' - SelfIntegration',
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'eru_tyr_auth', __( 'Trendyol Satıcı API kimlik doğrulaması başarısız. Satıcı ID, API Key ve API Secret değerlerini kontrol edin.', 'trendyol-reviews-for-woocommerce' ) );
		}
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error(
				'eru_tyr_seller_redirect',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Trendyol Satıcı API yönlendirme döndürdü (HTTP %d); güvenlik için yönlendirme izlenmez.', 'trendyol-reviews-for-woocommerce' ), $code )
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['content'] ) ) {
			return new WP_Error(
				'eru_tyr_seller_api',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Trendyol Satıcı API beklenmeyen yanıt verdi (HTTP %d).', 'trendyol-reviews-for-woocommerce' ), $code )
			);
		}

		$products = array();
		foreach ( (array) $body['content'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$include = empty( $item['archived'] ) && empty( $item['rejected'] ) && empty( $item['blacklisted'] );
			if ( ! apply_filters( 'eru_tyr_include_item', $include, $item ) ) {
				continue;
			}
			$content_id = self::content_id( $item );
			if ( ! $content_id ) {
				continue;
			}
			if ( ! isset( $products[ $content_id ] ) ) {
				$products[ $content_id ] = array(
					'content_id' => $content_id,
					'title'      => self::title( $item ),
					'url'        => isset( $item['productUrl'] ) ? esc_url_raw( strtok( (string) $item['productUrl'], '?' ) ) : '',
					'barcode'    => array(),
					'sku'        => array(),
				);
			}
			foreach ( array( 'barcode' => array( 'barcode' ), 'sku' => array( 'stockCode', 'productMainId' ) ) as $target => $keys ) {
				foreach ( $keys as $key ) {
					$value = trim( (string) ( $item[ $key ] ?? '' ) );
					if ( '' !== $value && ! in_array( $value, $products[ $content_id ][ $target ], true ) ) {
						$products[ $content_id ][ $target ][] = $value;
					}
				}
			}
		}

		return array(
			'products'    => array_values( $products ),
			'total_pages' => max( 1, (int) ( $body['totalPages'] ?? 1 ) ),
		);
	}

	/**
	 * One page of reviews for a content ID, newest first.
	 *
	 * @return array|WP_Error { reviews: array[], total_pages: int }
	 */
	public function get_reviews_page( int $content_id, int $page = 0, int $page_size = 30 ) {
		$response = wp_remote_get(
			sprintf( self::endpoint( 'reviews' ), $content_id, $page, $page_size ),
			array(
				'timeout' => 20,
				'headers' => array(
					'User-Agent'      => 'Trendyol Reviews for WooCommerce/' . ERU_TYR_VERSION . '; +https://wordpress.erusoft.com/trendyol-reviews-for-woocommerce/',
					'Accept'          => 'application/json',
					'Accept-Language' => 'tr-TR,tr;q=0.9',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'eru_tyr_http',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Trendyol yorum servisi HTTP %d döndürdü; istek engellenmiş veya servis değişmiş olabilir.', 'trendyol-reviews-for-woocommerce' ), $code )
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! is_array( $body['result'] ?? null ) ) {
			return new WP_Error( 'eru_tyr_bad_body', __( 'Yorum yanıtı çözümlenemedi; Trendyol uç noktası değişmiş olabilir.', 'trendyol-reviews-for-woocommerce' ) );
		}

		$result  = $body['result'];
		$reviews = array();
		foreach ( (array) ( $result['reviews'] ?? array() ) as $review ) {
			if ( ! is_array( $review ) || empty( $review['id'] ) ) {
				continue;
			}
			$reviews[] = array(
				'id'        => (int) $review['id'],
				'author'    => (string) ( $review['userFullName'] ?? '' ),
				'rating'    => (int) ( $review['rate'] ?? 0 ),
				'comment'   => (string) ( $review['comment'] ?? '' ),
				'timestamp' => isset( $review['createdAt'] ) ? (int) round( $review['createdAt'] / 1000 ) : 0,
				'seller_id' => self::review_seller( $review ),
				'photos'    => self::extract_photos( $review ),
			);
		}

		return array(
			'reviews'     => $reviews,
			'total_pages' => max( 1, (int) ( $result['summary']['totalPages'] ?? 1 ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Filterable URL template (public review endpoint only).
	 */
	protected static function endpoint( string $type ): string {
		return (string) apply_filters( 'eru_tyr_endpoint', self::ENDPOINTS[ $type ], $type );
	}

	protected static function content_id( array $item ): int {
		foreach ( array( 'productContentId', 'contentId' ) as $key ) {
			if ( ! empty( $item[ $key ] ) ) {
				return (int) $item[ $key ];
			}
		}
		if ( ! empty( $item['productUrl'] ) && preg_match( '/-p-(\d+)/', (string) $item['productUrl'], $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * The Seller API may return the title without the brand; put it back.
	 */
	protected static function title( array $item ): string {
		$title = trim( (string) ( $item['title'] ?? '' ) );
		$brand = trim( (string) ( $item['brand'] ?? '' ) );
		if ( '' === $brand || 0 === mb_stripos( $title, $brand, 0, 'UTF-8' ) ) {
			return $title;
		}
		return trim( $brand . ' ' . $title );
	}

	/**
	 * Seller identifier on a review, when the payload carries one ('' if absent).
	 */
	protected static function review_seller( array $review ): string {
		foreach ( array( 'sellerId', 'merchantId', 'supplierId' ) as $key ) {
			if ( isset( $review[ $key ] ) && is_scalar( $review[ $key ] ) && '' !== (string) $review[ $key ] ) {
				return (string) $review[ $key ];
			}
		}
		foreach ( array( 'seller', 'merchant', 'supplier' ) as $key ) {
			if ( is_array( $review[ $key ] ?? null ) && isset( $review[ $key ]['id'] ) && is_scalar( $review[ $key ]['id'] ) ) {
				return (string) $review[ $key ]['id'];
			}
		}
		return '';
	}

	/**
	 * @return array<array{url:string,thumb:string}>
	 */
	protected static function extract_photos( array $review ): array {
		$photos = array();
		foreach ( (array) ( $review['mediaFiles'] ?? array() ) as $media ) {
			if ( ! is_array( $media ) || ( isset( $media['mediaType'] ) && 'IMAGE' !== $media['mediaType'] ) ) {
				continue;
			}
			$url   = isset( $media['url'] ) ? esc_url_raw( (string) $media['url'] ) : '';
			$thumb = isset( $media['thumbnailUrl'] ) ? esc_url_raw( (string) $media['thumbnailUrl'] ) : $url;
			if ( $url ) {
				$photos[] = array( 'url' => $url, 'thumb' => $thumb );
			}
		}
		return $photos;
	}
}
