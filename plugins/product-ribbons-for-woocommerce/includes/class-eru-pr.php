<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Şerit kuralları ve ön yüz çıktısı. */
class Eru_Pr {

	public const OPTION = 'eru_pr_options';

	public const RULE_TYPES = array( 'product_cat', 'product_tag', 'on_sale', 'new', 'low_stock' );

	private static bool $styled = false;

	/** Blok temalarında ürün görseli bloğunun az önce şerit eklediği ürün (klasik kancayla çift çıktıyı önler). */
	private static int $block_rendered = 0;

	public static function defaults(): array {
		return array(
			'bg'        => '#e74c3c',
			'color'     => '#ffffff',
			'font_size' => 13,
			'width'     => 150,
			'new_days'  => 14,
			'low_stock' => 3,
			'rules'     => array(),
		);
	}

	public static function options(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function init(): void {
		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'render_loop' ), 9 );
		add_filter( 'render_block_woocommerce/product-image', array( __CLASS__, 'render_block_image' ), 10, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'single_product_js' ) );
	}

	/**
	 * Ürün için şerit: ['text','bg','color','font_size'] veya null.
	 * Önce ürüne elle girilen şerit, sonra kurallar (ilk eşleşen kazanır).
	 *
	 * @param WC_Product $product Ürün.
	 */
	public static function ribbon_for( WC_Product $product ): ?array {
		$opts   = self::options();
		$ribbon = null;
		$id     = $product->get_id();

		if ( 'yes' === get_post_meta( $id, '_eru_pr_enabled', true ) ) {
			$text = (string) get_post_meta( $id, '_eru_pr_text', true );
			if ( '' !== $text ) {
				$ribbon = array(
					'text'      => $text,
					'bg'        => (string) ( get_post_meta( $id, '_eru_pr_bg', true ) ?: $opts['bg'] ),
					'color'     => (string) ( get_post_meta( $id, '_eru_pr_color', true ) ?: $opts['color'] ),
					'font_size' => (int) ( get_post_meta( $id, '_eru_pr_font_size', true ) ?: $opts['font_size'] ),
				);
			}
		}
		if ( null === $ribbon ) {
			foreach ( (array) $opts['rules'] as $rule ) {
				if ( self::rule_matches( $rule, $product, $opts ) ) {
					$ribbon = array(
						'text'      => (string) $rule['label'],
						'bg'        => (string) ( $rule['bg'] ?: $opts['bg'] ),
						'color'     => (string) ( $rule['color'] ?: $opts['color'] ),
						'font_size' => (int) $opts['font_size'],
					);
					break;
				}
			}
		}

		/**
		 * Üründe gösterilecek şeridi değiştirir.
		 *
		 * @param array|null $ribbon  ['text','bg','color','font_size'] veya null (şerit yok).
		 * @param WC_Product $product Ürün.
		 */
		$ribbon = apply_filters( 'eru_pr_ribbon', $ribbon, $product );
		return is_array( $ribbon ) && ! empty( $ribbon['text'] ) ? $ribbon : null;
	}

	private static function rule_matches( array $rule, WC_Product $product, array $opts ): bool {
		if ( empty( $rule['label'] ) || empty( $rule['type'] ) ) {
			return false;
		}
		switch ( $rule['type'] ) {
			case 'product_cat':
			case 'product_tag':
				if ( empty( $rule['term'] ) ) {
					return false;
				}
				$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
				return has_term( $rule['term'], $rule['type'], $id );
			case 'on_sale':
				return $product->is_on_sale();
			case 'new':
				$created = $product->get_date_created();
				return $created && ( time() - $created->getTimestamp() ) <= max( 1, (int) $opts['new_days'] ) * DAY_IN_SECONDS;
			case 'low_stock':
				if ( ! $product->managing_stock() || ! $product->is_in_stock() ) {
					return false;
				}
				$qty = $product->get_stock_quantity();
				return null !== $qty && $qty <= (int) $opts['low_stock'];
		}
		return false;
	}

	/** Şerit HTML'i; her zaman kaçışlı. */
	public static function html( WC_Product $product ): string {
		$ribbon = self::ribbon_for( $product );
		if ( ! $ribbon ) {
			return '';
		}
		self::enqueue_style();
		$bg    = sanitize_hex_color( $ribbon['bg'] ?? '' ) ?: '#e74c3c';
		$color = sanitize_hex_color( $ribbon['color'] ?? '' ) ?: '#ffffff';
		return sprintf(
			'<span class="eru-pr-wrap"><span class="eru-pr-ribbon" style="background:%s;color:%s;font-size:%dpx">%s</span></span>',
			esc_attr( $bg ),
			esc_attr( $color ),
			max( 8, min( 24, (int) ( $ribbon['font_size'] ?? 13 ) ) ),
			esc_html( (string) $ribbon['text'] )
		);
	}

	/** Şerit ilk kez çıktıya girerken stili ekler; şerit yoksa ön yüze hiçbir şey eklenmez. */
	private static function enqueue_style(): void {
		if ( self::$styled ) {
			return;
		}
		self::$styled = true;
		$opts         = self::options();
		wp_register_style( 'eru-pr', ERU_PR_URL . 'assets/frontend.css', array(), ERU_PR_VERSION );
		wp_enqueue_style( 'eru-pr' );
		wp_add_inline_style( 'eru-pr', sprintf( ':root{--eru-pr-width:%dpx;--eru-pr-size:%dpx}', (int) $opts['width'], (int) $opts['font_size'] ) );
	}

	public static function render_loop(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( self::$block_rendered === $product->get_id() ) {
			self::$block_rendered = 0;
			return;
		}
		echo self::html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput -- html() kaçışlıdır.
	}

	/** Blok temalarında ürün görseli bloğuna şerit ekler. */
	public static function render_block_image( $content, $block = array(), $instance = null ) {
		$post_id = 0;
		if ( $instance instanceof WP_Block && ! empty( $instance->context['postId'] ) ) {
			$post_id = (int) $instance->context['postId'];
		}
		$product = $post_id ? wc_get_product( $post_id ) : null;
		if ( ! $product || ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		$html = self::html( $product );
		if ( '' === $html ) {
			return $content;
		}
		self::$block_rendered = $product->get_id();
		// İlk açılış etiketinin hemen ardına ekle.
		return preg_replace_callback( '#^(\s*<[^>]+>)#', static fn( $m ) => $m[1] . $html, $content, 1 ) ?? $content;
	}

	/** Tekil ürün sayfası: şeridi galeri kapsayıcısına yerleştirir. */
	public static function single_product_js(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		$html    = $product ? self::html( $product ) : '';
		if ( '' === $html ) {
			return;
		}
		?>
		<script>
		( function () {
			// Yalnızca ana ürünün galerisi: ilgili/yukarı satış kartları ve ürün koleksiyonları hariç.
			var skip = '.related, .up-sells, .cross-sells, li.product, .wc-block-product, .wp-block-woocommerce-product-collection';
			var g = null;
			document.querySelectorAll( '.woocommerce-product-gallery, .product .images' ).forEach( function ( el ) {
				if ( ! g && ! el.closest( skip ) ) { g = el; }
			} );
			if ( ! g || g.querySelector( '.eru-pr-wrap' ) ) { return; }
			var t = document.createElement( 'div' );
			t.innerHTML = <?php echo wp_json_encode( $html ); ?>;
			g.classList.add( 'eru-pr-host', 'eru-pr-has' );
			g.insertBefore( t.firstElementChild, g.firstChild );
		}() );
		</script>
		<?php
	}
}
