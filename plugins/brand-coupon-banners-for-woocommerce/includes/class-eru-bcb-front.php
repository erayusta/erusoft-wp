<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ön yüz: banner eşleştirme ve çıktı. */
class Eru_Bcb_Front {

	private static bool $styled          = false;
	private static bool $archive_done    = false;
	private static bool $single_done     = false;
	private static int $loop_count       = 0;
	private static ?array $active_cache  = null;

	public static function init(): void {
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'archive_top' ), 5 );
		add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'archive_in_loop' ), 999 );
		add_filter( 'woocommerce_product_loop_start', array( __CLASS__, 'reset_counter' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'single' ), 6 );
		// Blok temaları klasik kancaları çalıştırmaz.
		add_filter( 'render_block_woocommerce/product-collection', array( __CLASS__, 'block_archive' ), 10, 2 );
		add_filter( 'render_block_core/post-title', array( __CLASS__, 'block_single' ), 10, 2 );
	}

	/** Etkin bannerlar (yayında + aktif), istek başına bir kez okunur. */
	private static function active_banners(): array {
		if ( null === self::$active_cache ) {
			self::$active_cache = array();
			$posts              = get_posts(
				array(
					'post_type'        => Eru_Bcb::POST_TYPE,
					'post_status'      => 'publish',
					'posts_per_page'   => 50,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'meta_key'         => '_eru_bcb_active', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
					'suppress_filters' => false,
					'no_found_rows'    => true,
				)
			);
			foreach ( $posts as $p ) {
				$m       = Eru_Bcb::banner_meta( $p->ID );
				$m['id'] = $p->ID;
				self::$active_cache[] = $m;
			}
		}
		return self::$active_cache;
	}

	/**
	 * Bağlama uyan ilk banner (tarih aralığı kontrol edilir) veya null.
	 *
	 * @param array $context ['cats' => int[], 'brands' => int[], 'scope' => 'archive'|'single'].
	 */
	public static function find_banner( array $context ): ?array {
		$now    = time();
		$banner = null;
		foreach ( self::active_banners() as $m ) {
			$start = Eru_Bcb::parse_time( $m['start'] );
			$end   = Eru_Bcb::parse_time( $m['end'] );
			if ( ( $start && $now < $start ) || ( $end && $now > $end ) ) {
				continue;
			}
			$generic = empty( $m['cats'] ) && empty( $m['brands'] );
			if ( $generic || array_intersect( $m['cats'], $context['cats'] ) || array_intersect( $m['brands'], $context['brands'] ) ) {
				$banner = $m;
				break;
			}
		}
		/**
		 * Gösterilecek bannerı değiştirir.
		 *
		 * @param array|null $banner  Banner alanları (Eru_Bcb::banner_meta + id) veya null.
		 * @param array      $context Bağlam: cats, brands, scope.
		 */
		$banner = apply_filters( 'eru_bcb_banner', $banner, $context );
		return is_array( $banner ) ? $banner : null;
	}

	private static function archive_context(): ?array {
		if ( 'yes' !== Eru_Bcb::options()['show_archive'] || ! ( is_shop() || is_product_taxonomy() ) ) {
			return null;
		}
		$cats   = array();
		$brands = array();
		$brand  = Eru_Bcb::brand_taxonomy();
		if ( is_product_category() ) {
			$cats[] = (int) get_queried_object_id();
		}
		if ( '' !== $brand && is_tax( $brand ) ) {
			$brands[] = (int) get_queried_object_id();
		}
		return array( 'cats' => $cats, 'brands' => $brands, 'scope' => 'archive' );
	}

	private static function archive_banner(): ?array {
		$ctx = self::archive_context();
		return $ctx ? self::find_banner( $ctx ) : null;
	}

	public static function archive_top(): void {
		$b = self::archive_banner();
		if ( $b && 0 === (int) $b['position'] ) {
			self::$archive_done = true;
			echo self::render( $b ); // phpcs:ignore WordPress.Security.EscapeOutput -- render() kaçışlıdır.
		}
	}

	public static function reset_counter( $html ) {
		self::$loop_count = 0;
		return $html;
	}

	public static function archive_in_loop(): void {
		++self::$loop_count;
		$b = self::archive_banner();
		if ( $b && (int) $b['position'] > 0 && self::$loop_count === (int) $b['position'] ) {
			self::$archive_done = true;
			echo '</ul><div class="eru-bcb-break">' . self::render( $b ) . '</div><ul class="products columns-' . (int) wc_get_loop_prop( 'columns' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public static function block_archive( $content, $block = array() ) {
		$inherit = ! empty( $block['attrs']['query']['inherit'] );
		if ( self::$archive_done || ! $inherit ) {
			return $content;
		}
		$b = self::archive_banner();
		if ( ! $b ) {
			return $content;
		}
		self::$archive_done = true;
		return self::render( $b ) . $content;
	}

	private static function single_banner(): ?array {
		if ( 'yes' !== Eru_Bcb::options()['show_single'] || ! is_product() ) {
			return null;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id ) {
			return null;
		}
		$brand  = Eru_Bcb::brand_taxonomy();
		$cats   = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
		$brands = '' === $brand ? array() : wp_get_post_terms( $id, $brand, array( 'fields' => 'ids' ) );
		return self::find_banner(
			array(
				'cats'   => is_wp_error( $cats ) ? array() : array_map( 'intval', $cats ),
				'brands' => is_wp_error( $brands ) ? array() : array_map( 'intval', $brands ),
				'scope'  => 'single',
			)
		);
	}

	public static function single(): void {
		if ( self::$single_done ) {
			return;
		}
		$b = self::single_banner();
		if ( $b ) {
			self::$single_done = true;
			echo self::render( $b ); // phpcs:ignore WordPress.Security.EscapeOutput -- render() kaçışlıdır.
		}
	}

	public static function block_single( $content, $block = array() ) {
		if ( self::$single_done || ! function_exists( 'is_product' ) || ! is_product() || ! empty( $block['attrs']['isLink'] ) ) {
			return $content;
		}
		// Yalnızca ana ürün başlığı (ilk post-title bloğu); ilgili ürün listeleri sonra gelir.
		$b = self::single_banner();
		if ( ! $b ) {
			return $content;
		}
		self::$single_done = true;
		return $content . self::render( $b );
	}

	/** Banner HTML'i; boşsa ''. Her değer kaçışlıdır. */
	public static function render( array $b ): string {
		$desktop = $b['desktop'] ? wp_get_attachment_url( $b['desktop'] ) : '';
		$mobile  = $b['mobile'] ? wp_get_attachment_url( $b['mobile'] ) : '';
		$text    = '' !== $b['text'] ? str_replace( '{code}', $b['code'], $b['text'] ) : '';
		$alt     = '' !== $text ? $text : $b['code'];

		if ( ! $desktop && ! $mobile && '' === $text ) {
			return '';
		}
		self::enqueue_style();

		if ( $desktop || $mobile ) {
			$inner = '';
			if ( $desktop ) {
				$inner .= '<img src="' . esc_url( $desktop ) . '" alt="' . esc_attr( $alt ) . '" class="eru-bcb-img eru-bcb-desktop">';
			}
			if ( $mobile ) {
				$inner .= '<img src="' . esc_url( $mobile ) . '" alt="' . esc_attr( $alt ) . '" class="eru-bcb-img eru-bcb-mobile">';
			}
			$class = 'eru-bcb-banner';
			if ( ! $desktop || ! $mobile ) {
				$class .= ' eru-bcb-single-img';
			}
		} else {
			$o     = Eru_Bcb::options();
			$bg    = sanitize_hex_color( $b['bg'] ) ?: ( sanitize_hex_color( (string) $o['bg'] ) ?: '#1d2327' );
			$color = sanitize_hex_color( $b['color'] ) ?: ( sanitize_hex_color( (string) $o['color'] ) ?: '#ffffff' );
			$inner = '<span class="eru-bcb-text" style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $color ) . '">' . esc_html( $text ) . '</span>';
			$class = 'eru-bcb-banner eru-bcb-is-text';
		}

		if ( '' !== $b['link'] ) {
			$inner = '<a href="' . esc_url( $b['link'] ) . '" class="eru-bcb-link">' . $inner . '</a>';
		}
		return '<div class="' . esc_attr( $class ) . '">' . $inner . '</div>';
	}

	private static function enqueue_style(): void {
		if ( self::$styled ) {
			return;
		}
		self::$styled = true;
		wp_enqueue_style( 'eru-bcb', ERU_BCB_URL . 'assets/frontend.css', array(), ERU_BCB_VERSION );
	}
}
