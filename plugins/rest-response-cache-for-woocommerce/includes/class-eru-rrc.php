<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** REST GET yanıt önbelleği. */
class Eru_Rrc {

	const VERSION_OPTION = 'eru_rrc_version';

	private static bool $bumped = false;

	/**
	 * İstek başına anahtar, işleyiciler çalışmadan ÖNCE hesaplanır: işleyiciler varsayılan parametreleri
	 * isteğe geri yazabilir (set_param), bu da kaydederken farklı bir anahtar üretirdi.
	 */
	private static ?WeakMap $keys = null;

	public static function init(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'serve' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'store' ), 99, 3 );
		add_action( 'admin_post_eru_rrc_flush', array( __CLASS__, 'handle_flush' ) );
		add_action( 'admin_notices', array( __CLASS__, 'flushed_notice' ) );

		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_delete_product', 'woocommerce_trash_product', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_term_change' ), 10, 3 );
		}
	}

	/** Metinden izin listesini ayrıştırır: normalize edilmiş, benzersiz yollar. */
	public static function parse_routes( string $text ): array {
		$routes = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = preg_replace( '/[^A-Za-z0-9_\-.\/*{}()?<>:]/', '', trim( (string) $line ) );
			$line = preg_replace( '#^/?wp-json#', '', $line );
			if ( '' === $line ) {
				continue;
			}
			$line = '/' . trim( $line, '/' );
			$line = preg_replace( '#/{2,}#', '/', $line );
			if ( '/' === $line || '/*' === $line ) {
				continue; // Her şeyi kapsayan giriş kabul edilmez.
			}
			$routes[ $line ] = $line;
		}
		return array_values( $routes );
	}

	private static function allowlist(): array {
		$routes = self::parse_routes( (string) get_option( 'eru_rrc_routes', '' ) );
		/**
		 * Önbelleğe alınacak uçların listesini değiştirir.
		 *
		 * @param string[] $routes Normalize edilmiş yollar (örn. /wc/store/v1/products veya /wc/store/v1/products/*).
		 */
		$filtered = apply_filters( 'eru_rrc_routes', $routes );
		return is_array( $filtered ) ? $filtered : $routes;
	}

	private static function route_allowed( string $route ): bool {
		$route = '/' . trim( $route, '/' );
		foreach ( self::allowlist() as $entry ) {
			if ( str_ends_with( $entry, '/*' ) ) {
				if ( str_starts_with( $route, substr( $entry, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $route === $entry ) {
				return true;
			}
		}
		return false;
	}

	public static function ttl(): int {
		return (int) apply_filters( 'eru_rrc_ttl', max( 10, min( DAY_IN_SECONDS, absint( get_option( 'eru_rrc_ttl', 300 ) ) ) ) );
	}

	/** İstek herkese açık ve kimliksiz mi? Değilse asla önbellek kullanılmaz. */
	private static function is_anonymous( WP_REST_Request $request ): bool {
		if ( is_user_logged_in() || 0 !== get_current_user_id() ) {
			return false;
		}
		foreach ( array( 'authorization', 'x_wp_nonce', 'nonce', 'cart_token', 'x_wc_store_api_nonce' ) as $header ) {
			if ( '' !== (string) $request->get_header( $header ) ) {
				return false;
			}
		}
		foreach ( array( 'consumer_key', 'consumer_secret', '_wpnonce', 'oauth_consumer_key', 'oauth_signature' ) as $param ) {
			if ( null !== $request->get_param( $param ) ) {
				return false;
			}
		}
		foreach ( array_keys( $_COOKIE ) as $name ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( preg_match( '/^(wordpress_logged_in_|wordpress_sec_|wp_woocommerce_session_|woocommerce_items_in_cart|woocommerce_cart_hash|wp-postpass_)/', (string) $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Önbellek şişirme (flooding) sınırları.
	 *
	 * @return array{max_params:int,max_query:int,max_payload:int}
	 */
	public static function limits(): array {
		$limits = array(
			'max_params'  => 12,
			'max_query'   => 512,
			'max_payload' => 512 * KB_IN_BYTES,
		);
		/**
		 * Sınırları değiştirir: en çok sorgu parametresi, en çok sorgu dizesi baytı, en çok yanıt baytı.
		 *
		 * @param array $limits max_params, max_query, max_payload.
		 */
		$filtered = apply_filters( 'eru_rrc_limits', $limits );
		return is_array( $filtered ) ? array_merge( $limits, array_map( 'intval', $filtered ) ) : $limits;
	}

	private static function eligible( WP_REST_Request $request ): bool {
		if ( 'GET' !== $request->get_method() || ! self::route_allowed( $request->get_route() ) || ! self::is_anonymous( $request ) ) {
			return false;
		}
		// _embed ek istekler üretir ve bağlantılı yanıtı değiştirir; önbelleğe alınmaz.
		if ( null !== $request->get_param( '_embed' ) ) {
			return false;
		}
		$limits = self::limits();
		$query  = $request->get_query_params();
		if ( count( $query ) > $limits['max_params'] || strlen( http_build_query( $query ) ) > $limits['max_query'] ) {
			return false;
		}
		return (bool) apply_filters( 'eru_rrc_cacheable', true, $request );
	}

	/** Önbellek anahtarı: sürüm + tam normalize edilmiş yol + sıralı sorgu + dil. */
	public static function cache_key( WP_REST_Request $request ): string {
		$query = $request->get_query_params();
		self::ksort_deep( $query );
		$route = '/' . trim( $request->get_route(), '/' );
		/**
		 * Anahtara ek parçalar ekler (para birimi, ülke vb. yanıtı değiştiren eklentiler için).
		 *
		 * @param array           $parts   Ek anahtar parçaları.
		 * @param WP_REST_Request $request İstek.
		 */
		$extra = apply_filters( 'eru_rrc_key_parts', array(), $request );
		$raw   = wp_json_encode( array( strtolower( $route ), $query, determine_locale(), $extra ) );
		return 'eru_rrc_' . self::version() . '_' . md5( (string) $raw );
	}

	private static function ksort_deep( array &$a ): void {
		ksort( $a );
		foreach ( $a as &$v ) {
			if ( is_array( $v ) ) {
				self::ksort_deep( $v );
			}
		}
	}

	private static function version(): string {
		$v = get_option( self::VERSION_OPTION );
		if ( ! $v ) {
			$v = (string) time();
			update_option( self::VERSION_OPTION, $v, true );
		}
		return (string) $v;
	}

	/** İzin listesindeki uç önbellekteyse yanıtı sunar. */
	public static function serve( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || ! self::eligible( $request ) ) {
			return $result;
		}
		self::$keys ??= new WeakMap();
		self::$keys[ $request ] = $key = self::cache_key( $request );
		$entry = get_transient( $key );
		if ( ! is_array( $entry ) || ! array_key_exists( 'data', $entry ) ) {
			return $result;
		}
		$response = new WP_REST_Response( $entry['data'], (int) ( $entry['status'] ?? 200 ) );
		$response->set_headers( is_array( $entry['headers'] ?? null ) ? $entry['headers'] : array() );
		if ( ! empty( $entry['links'] ) && is_array( $entry['links'] ) ) {
			$response->add_links( $entry['links'] ); // _links, MISS yanıtıyla aynı çıksın.
		}
		$response->header( 'X-Eru-Cache', 'HIT' );
		return $response;
	}

	/** Uygun 200 yanıtları saklar. */
	public static function store( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || ! self::eligible( $request ) ) {
			return $response;
		}
		if ( 'HIT' === ( $response->get_headers()['X-Eru-Cache'] ?? '' ) ) {
			return $response;
		}

		$headers = $response->get_headers();
		$ok      = 200 === $response->get_status() && ! $response->is_error();
		foreach ( $headers as $name => $value ) {
			$lower = strtolower( (string) $name );
			if ( in_array( $lower, array( 'set-cookie', 'nonce', 'cart-token', 'x-wc-store-api-nonce', 'x-wp-nonce' ), true ) ) {
				$ok = false;
			}
			if ( 'cache-control' === $lower && preg_match( '/no-store|private|no-cache/i', (string) $value ) ) {
				$ok = false;
			}
		}
		$data = $response->get_data();
		if ( ! $ok || ! ( is_array( $data ) || is_scalar( $data ) || null === $data ) ) {
			return $response;
		}

		if ( strlen( serialize( $data ) ) > self::limits()['max_payload'] ) {
			return $response;
		}
		$key = self::$keys[ $request ] ?? null;
		if ( null === $key ) {
			return $response; // serve() bu isteği görmediyse (örn. başka bir filtre erken döndü) kaydetme.
		}
		set_transient(
			$key,
			array(
				'data'    => $data,
				'status'  => 200,
				'headers' => $headers,
				'links'   => $response->get_links(),
			),
			self::ttl()
		);
		$response->header( 'X-Eru-Cache', 'MISS' );
		return $response;
	}

	/** Tüm önbelleği geçersiz kılar (sürüm sayacını artırır; eski kayıtlar süresi dolunca silinir). */
	public static function flush(): void {
		if ( self::$bumped ) {
			return;
		}
		self::$bumped = true;
		update_option( self::VERSION_OPTION, (string) microtime( true ), true );
		do_action( 'eru_rrc_flushed' );
	}

	public static function on_term_change( $term_id, $tt_id = 0, $taxonomy = '' ): void {
		$taxonomy = (string) $taxonomy;
		if ( str_starts_with( $taxonomy, 'product_' ) || str_starts_with( $taxonomy, 'pa_' ) ) {
			self::flush();
		}
	}

	public static function flushed_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Yalnızca bilgi mesajı gösterir.
		if ( isset( $_GET['eru_rrc_flushed'], $_GET['tab'] ) && 'eru_rrc' === $_GET['tab'] && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'REST önbelleği temizlendi.', 'rest-response-cache-for-woocommerce' ) . '</p></div>';
		}
	}

	public static function handle_flush(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'rest-response-cache-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'eru_rrc_flush' );
		self::flush();
		wp_safe_redirect( add_query_arg( 'eru_rrc_flushed', '1', admin_url( 'admin.php?page=wc-settings&tab=eru_rrc' ) ) );
		exit;
	}
}
