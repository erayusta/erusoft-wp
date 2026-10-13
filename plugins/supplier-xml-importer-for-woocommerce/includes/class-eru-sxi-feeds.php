<?php
/**
 * Feed definitions, storage, uploaded files and fetching.
 *
 * @package supplier-xml-importer-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SXI_Feeds {

	const OPTION = 'eru_sxi_feeds';

	/** Fields a feed can map to an XPath expression (relative to one item). */
	public static function map_fields() {
		return array(
			'sku'         => __( 'SKU (zorunlu)', 'supplier-xml-importer-for-woocommerce' ),
			'name'        => __( 'Ürün adı', 'supplier-xml-importer-for-woocommerce' ),
			'description' => __( 'Açıklama', 'supplier-xml-importer-for-woocommerce' ),
			'price'       => __( 'Fiyat', 'supplier-xml-importer-for-woocommerce' ),
			'sale_price'  => __( 'İndirimli fiyat', 'supplier-xml-importer-for-woocommerce' ),
			'stock'       => __( 'Stok adedi', 'supplier-xml-importer-for-woocommerce' ),
			'images'      => __( 'Görseller (çoklu)', 'supplier-xml-importer-for-woocommerce' ),
			'categories'  => __( 'Kategori (çoklu)', 'supplier-xml-importer-for-woocommerce' ),
			'parent_sku'  => __( 'Ana ürün SKU (varyasyon için)', 'supplier-xml-importer-for-woocommerce' ),
		);
	}

	public static function update_fields() {
		return array(
			'name'        => __( 'Ad', 'supplier-xml-importer-for-woocommerce' ),
			'description' => __( 'Açıklama', 'supplier-xml-importer-for-woocommerce' ),
			'price'       => __( 'Fiyat', 'supplier-xml-importer-for-woocommerce' ),
			'stock'       => __( 'Stok', 'supplier-xml-importer-for-woocommerce' ),
			'images'      => __( 'Görseller', 'supplier-xml-importer-for-woocommerce' ),
			'categories'  => __( 'Kategoriler', 'supplier-xml-importer-for-woocommerce' ),
			'attributes'  => __( 'Özellikler', 'supplier-xml-importer-for-woocommerce' ),
		);
	}

	public static function defaults() {
		return array(
			'id'            => '',
			'name'          => '',
			'source'        => 'url',
			'url'           => '',
			'file'          => '',
			'item_path'     => '',
			'map'           => array_fill_keys( array_keys( self::map_fields() ), '' ),
			'attrs'         => '',
			'markup'        => 0.0,
			'rate'          => 1.0,
			'rounding'      => 'none',
			'mode'          => 'create_update',
			'update_fields' => array_keys( self::update_fields() ),
			'missing'       => 'ignore',
			'new_status'    => 'draft',
			'images'        => 1,
			'cat_sep'       => '>',
			'cat_map'       => '',
			'schedule'      => 'off',
		);
	}

	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? wp_parse_args( $all[ $id ], self::defaults() ) : null;
	}

	/**
	 * Validate and normalise raw input (already unslashed).
	 */
	public static function sanitize( array $in, array $old = array() ) {
		$d    = self::defaults();
		$feed = wp_parse_args( $old, $d );
		$str  = fn( $k ) => isset( $in[ $k ] ) ? sanitize_text_field( (string) $in[ $k ] ) : '';

		$feed['name']      = $str( 'name' );
		$feed['source']    = in_array( $in['source'] ?? '', array( 'url', 'file' ), true ) ? $in['source'] : 'url';
		$url               = isset( $in['url'] ) ? esc_url_raw( trim( (string) $in['url'] ), array( 'http', 'https' ) ) : '';
		$feed['url']       = $url;
		$feed['item_path'] = trim( preg_replace( '/[^\p{L}\p{N}_.:\/-]/u', '', (string) ( $in['item_path'] ?? '' ) ), '/' );
		foreach ( array_keys( self::map_fields() ) as $f ) {
			$feed['map'][ $f ] = trim( sanitize_text_field( (string) ( $in['map'][ $f ] ?? '' ) ) );
		}
		$feed['attrs']         = self::clean_lines( (string) ( $in['attrs'] ?? '' ) );
		$feed['cat_map']       = self::clean_lines( (string) ( $in['cat_map'] ?? '' ) );
		$feed['markup']        = max( -90.0, min( 1000.0, (float) str_replace( ',', '.', (string) ( $in['markup'] ?? 0 ) ) ) );
		$rate                  = (float) str_replace( ',', '.', (string) ( $in['rate'] ?? 1 ) );
		$feed['rate']          = $rate > 0 ? $rate : 1.0;
		$feed['rounding']      = in_array( $in['rounding'] ?? '', array( 'none', '90', '99', 'int' ), true ) ? $in['rounding'] : 'none';
		$feed['mode']          = in_array( $in['mode'] ?? '', array( 'create_update', 'update_only', 'create_only' ), true ) ? $in['mode'] : 'create_update';
		$feed['missing']       = in_array( $in['missing'] ?? '', array( 'ignore', 'outofstock', 'draft' ), true ) ? $in['missing'] : 'ignore';
		$feed['new_status']    = in_array( $in['new_status'] ?? '', array( 'draft', 'publish', 'private' ), true ) ? $in['new_status'] : 'draft';
		$feed['images']        = empty( $in['images'] ) ? 0 : 1;
		$feed['cat_sep']       = '' !== $str( 'cat_sep' ) ? mb_substr( $str( 'cat_sep' ), 0, 3 ) : '>';
		$feed['schedule']      = in_array( $in['schedule'] ?? '', array( 'off', 'hourly', 'twicedaily', 'daily' ), true ) ? $in['schedule'] : 'off';
		$feed['update_fields'] = array_values( array_intersect( array_keys( self::update_fields() ), (array) ( $in['update_fields'] ?? array() ) ) );
		return $feed;
	}

	private static function clean_lines( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line && false !== strpos( $line, '=' ) ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Validation problems of a feed definition (empty array = usable).
	 */
	public static function problems( array $feed ) {
		$p = array();
		if ( 'url' === $feed['source'] && '' === $feed['url'] ) {
			$p[] = __( 'Besleme adresi (URL) boş.', 'supplier-xml-importer-for-woocommerce' );
		}
		if ( 'file' === $feed['source'] && ( '' === $feed['file'] || ! is_file( self::dir() . '/' . $feed['file'] ) ) ) {
			$p[] = __( 'Yüklenmiş XML dosyası yok.', 'supplier-xml-importer-for-woocommerce' );
		}
		if ( '' === $feed['item_path'] ) {
			$p[] = __( 'Ürün öğesi (item) yolu boş.', 'supplier-xml-importer-for-woocommerce' );
		}
		if ( '' === $feed['map']['sku'] ) {
			$p[] = __( 'SKU eşlemesi zorunludur.', 'supplier-xml-importer-for-woocommerce' );
		}
		foreach ( $feed['map'] as $field => $expr ) {
			if ( '' !== $expr && ! Eru_SXI_Reader::valid_xpath( $expr ) ) {
				/* translators: %s: field key */
				$p[] = sprintf( __( 'Geçersiz XPath ifadesi: %s', 'supplier-xml-importer-for-woocommerce' ), $field );
			}
		}
		return $p;
	}

	public static function save( array $feed ) {
		$all = self::all();
		if ( '' === $feed['id'] ) {
			$feed['id'] = 'f' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}
		$all[ $feed['id'] ] = $feed;
		update_option( self::OPTION, $all, false );
		self::sync_schedule( $feed );
		return $feed['id'];
	}

	public static function delete( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return;
		}
		self::remove_file( $all[ $id ]['file'] ?? '' );
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'eru_sxi_scheduled', array( $id ), 'eru-sxi' );
			as_unschedule_all_actions( 'eru_sxi_batch', array( $id ), 'eru-sxi' );
		}
		Eru_SXI_Runner::forget( $id );
	}

	/** Keep the recurring Action Scheduler job in line with the feed's schedule. */
	public static function sync_schedule( array $feed ) {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! did_action( 'init' ) ) {
			return;
		}
		$args = array( $feed['id'] );
		as_unschedule_all_actions( 'eru_sxi_scheduled', $args, 'eru-sxi' );
		$intervals = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
		);
		if ( isset( $intervals[ $feed['schedule'] ] ) ) {
			as_schedule_recurring_action( time() + $intervals[ $feed['schedule'] ], $intervals[ $feed['schedule'] ], 'eru_sxi_scheduled', $args, 'eru-sxi' );
		}
	}

	/* ---- files ---- */

	public static function max_bytes() {
		return max( 1024, (int) apply_filters( 'eru_sxi_max_bytes', 50 * MB_IN_BYTES ) );
	}

	/** Private working directory (denied from the web). */
	public static function dir() {
		$upload = wp_upload_dir( null, false );
		$dir    = trailingslashit( $upload['basedir'] ) . 'eru-sxi';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	public static function remove_file( $basename ) {
		$basename = is_string( $basename ) ? basename( $basename ) : '';
		if ( '' !== $basename && is_file( self::dir() . '/' . $basename ) ) {
			wp_delete_file( self::dir() . '/' . $basename );
		}
	}

	/**
	 * Store an uploaded (or local) XML file as the source of a feed. Returns the stored basename or WP_Error.
	 */
	public static function store_file( $tmp_path, $original_name, $feed_id ) {
		if ( 'xml' !== strtolower( pathinfo( (string) $original_name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'eru_sxi_ext', __( 'Yalnızca .xml dosyaları yüklenebilir.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$size = (int) filesize( $tmp_path );
		if ( $size < 1 || $size > self::max_bytes() ) {
			return new WP_Error( 'eru_sxi_size', __( 'Dosya boş veya izin verilen boyuttan büyük.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$head = (string) file_get_contents( $tmp_path, false, null, 0, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === strpos( $head, '<' ) ) {
			return new WP_Error( 'eru_sxi_type', __( 'Dosya XML gibi görünmüyor.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$name = sanitize_file_name( 'feed-' . $feed_id . '-' . wp_generate_password( 12, false ) . '.xml' );
		$dest = self::dir() . '/' . $name;
		if ( ! copy( $tmp_path, $dest ) ) {
			return new WP_Error( 'eru_sxi_copy', __( 'Dosya kaydedilemedi.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		return $name;
	}

	/**
	 * Download the feed URL to $dest. Uses wp_safe_remote_get (blocks internal hosts) with a timeout and size cap.
	 *
	 * @param int $limit Maximum bytes to accept.
	 * @return true|WP_Error
	 */
	public static function fetch( $url, $dest, $limit = 0 ) {
		$limit = $limit ? (int) $limit : self::max_bytes();
		$url   = (string) $url;
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new WP_Error( 'eru_sxi_url', __( 'Yalnızca http ve https adresleri kullanılabilir.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$resp = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => (int) apply_filters( 'eru_sxi_timeout', 30 ),
				'redirection'         => 3,
				'stream'              => true,
				'filename'            => $dest,
				'limit_response_size' => $limit,
				'headers'             => array( 'Accept' => 'application/xml, text/xml, */*' ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			wp_delete_file( $dest );
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			wp_delete_file( $dest );
			/* translators: %d: HTTP status */
			return new WP_Error( 'eru_sxi_http', sprintf( __( 'Besleme adresi %d durum kodu döndürdü.', 'supplier-xml-importer-for-woocommerce' ), $code ) );
		}
		return true;
	}
}
