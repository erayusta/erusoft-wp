<?php
/**
 * Free-shipping threshold, progress bar, gap-filling suggestions and cart recommendations.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The threshold comes from the WooCommerce free_shipping method of the customer's zone unless set manually.
 */
class Eru_CB_Shipping {

	const CACHE_VER = 'eru_cb_cache_ver';

	/** Hooks. */
	public static function init() {
		add_shortcode( 'eru_free_shipping_bar', array( __CLASS__, 'shortcode' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'fragments' ) );
	}

	/** @return float|null Free-shipping threshold, or null when none is known. */
	public static function threshold() {
		if ( 'manual' === Eru_CB_Settings::get( 'ship_threshold_mode' ) ) {
			$m = (float) Eru_CB_Settings::get( 'ship_threshold_manual' );
			return $m > 0 ? $m : null;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return null;
		}
		$package = array(
			'destination' => array(
				'country'  => WC()->customer->get_shipping_country(),
				'state'    => WC()->customer->get_shipping_state(),
				'postcode' => WC()->customer->get_shipping_postcode(),
				'city'     => WC()->customer->get_shipping_city(),
				'address'  => '',
			),
		);
		$zone    = WC_Shipping_Zones::get_zone_matching_package( $package );
		$mins    = array();
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( 'free_shipping' !== $method->id || 'yes' !== $method->enabled ) {
				continue;
			}
			$min = (float) $method->get_option( 'min_amount' );
			if ( $min > 0 && in_array( $method->get_option( 'requires' ), array( 'min_amount', 'either', 'both' ), true ) ) {
				$mins[] = $min;
			}
		}
		return $mins ? min( $mins ) : null;
	}

	/** @return float Cart amount compared with the threshold (after discounts, like the free-shipping method). */
	public static function cart_amount() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return 0.0;
		}
		$total  = (float) $cart->get_displayed_subtotal();
		$total -= (float) $cart->get_discount_total() + ( $cart->display_prices_including_tax() ? (float) $cart->get_discount_tax() : 0.0 );
		return max( 0.0, $total );
	}

	/** @return float Remaining amount to free shipping; 0 when reached or no threshold. */
	public static function gap() {
		$t = self::threshold();
		return $t ? max( 0.0, $t - self::cart_amount() ) : 0.0;
	}

	/** Invalidate cached suggestion queries. */
	public static function flush_cache() {
		update_option( self::CACHE_VER, time(), false );
	}

	/** wp_kses_post plus the markup wc_price() emits (bdi, translate). @param string $html HTML. @return string */
	public static function kses( $html ) {
		$allowed        = wp_kses_allowed_html( 'post' );
		$allowed['bdi'] = array();
		$allowed['span']['translate'] = true;
		return wp_kses( $html, $allowed );
	}

	/** @param bool $with_suggestions Include the suggestion cards. @return string Bar HTML, empty when not applicable. */
	public static function bar_html( $with_suggestions = false ) {
		$t = self::threshold();
		if ( ! $t || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}
		$amount = self::cart_amount();
		$gap    = max( 0.0, $t - $amount );
		$pct    = (int) max( 0, min( 100, floor( $amount / $t * 100 ) ) );
		$text   = $gap > 0
			? str_replace( '{kalan}', '<strong>' . wc_price( $gap ) . '</strong>', esc_html( Eru_CB_Settings::get( 'ship_text' ) ) )
			: esc_html( Eru_CB_Settings::get( 'ship_text_done' ) );
		$html   = '<div class="eru-cb-bar' . ( $gap > 0 ? '' : ' is-done' ) . ( $with_suggestions ? '' : ' eru-cb-bar--mini' ) . '">';
		$html  .= '<p class="eru-cb-bar__text">' . self::kses( $text ) . '</p>';
		$html  .= '<div class="eru-cb-bar__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span class="eru-cb-bar__fill" style="width:' . (int) $pct . '%"></span></div>';
		if ( $with_suggestions && $gap > 0 ) {
			$items = self::suggestions();
			if ( $items ) {
				$html .= '<div class="eru-cb-suggest"><p class="eru-cb-suggest__title">' . esc_html__( 'Kargoyu ücretsiz yapan ürünler', 'conversion-boosters-for-woocommerce' ) . '</p>' . self::cards( $items ) . '</div>';
			}
		}
		return $html . '</div>';
	}

	/** @param array $atts Unused. @return string Mini bar (progress only) for the mini-cart or any widget area. */
	public static function shortcode( $atts = array() ) {
		unset( $atts );
		if ( ! Eru_CB_Settings::is_on( 'ship_enabled' ) || ! WC()->cart ) {
			return '';
		}
		$html = self::bar_html( false );
		return '' === $html ? '' : self::enqueue() . $html;
	}

	/** @param array $fragments WooCommerce fragments. @return array */
	public static function fragments( $fragments ) {
		if ( Eru_CB_Settings::is_on( 'ship_enabled' ) ) {
			$html = self::bar_html( false );
			if ( '' !== $html ) {
				$fragments['div.eru-cb-bar--mini'] = $html;
			}
		}
		return $fragments;
	}

	/** Enqueue the front assets. @return string Empty string, so it can be concatenated. */
	public static function enqueue() {
		wp_enqueue_style( 'eru-cb-front', ERU_CB_URL . 'assets/front.css', array(), ERU_CB_VERSION );
		wp_enqueue_script( 'eru-cb-front', ERU_CB_URL . 'assets/front.js', array(), ERU_CB_VERSION, true );
		wp_localize_script(
			'eru-cb-front',
			'eruCb',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'wcAjax'  => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : '',
				'claimUrl' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'eru_cb_claim' ) : '',
				'nonce'   => wp_create_nonce( 'eru_cb' ),
				'reload'  => (int) ( is_cart() || is_checkout() ),
				'adding'  => __( 'Ekleniyor…', 'conversion-boosters-for-woocommerce' ),
				'added'   => __( 'Eklendi', 'conversion-boosters-for-woocommerce' ),
				'error'   => __( 'Bir sorun oluştu, lütfen tekrar deneyin.', 'conversion-boosters-for-woocommerce' ),
				'expired' => __( 'Süre doldu', 'conversion-boosters-for-woocommerce' ),
			)
		);
		return '';
	}

	/** @param WC_Product[] $items Products. @return string Product cards with AJAX add buttons. */
	public static function cards( array $items ) {
		$html = '<ul class="eru-cb-cards">';
		foreach ( $items as $p ) {
			$html .= '<li class="eru-cb-card"><a class="eru-cb-card__img" href="' . esc_url( $p->get_permalink() ) . '">' . wp_kses_post( $p->get_image( 'woocommerce_thumbnail' ) ) . '</a>';
			$html .= '<a class="eru-cb-card__name" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( $p->get_name() ) . '</a>';
			$html .= '<span class="eru-cb-card__price">' . self::kses( $p->get_price_html() ) . '</span>';
			if ( $p->is_type( 'simple' ) && $p->is_purchasable() && $p->is_in_stock() ) {
				$html .= '<button type="button" class="button eru-cb-card__add" data-product-id="' . (int) $p->get_id() . '">' . esc_html__( 'Sepete ekle', 'conversion-boosters-for-woocommerce' ) . '</button>';
			} else {
				$html .= '<a class="button eru-cb-card__add" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html__( 'İncele', 'conversion-boosters-for-woocommerce' ) . '</a>';
			}
			$html .= '</li>';
		}
		return $html . '</ul>';
	}

	/** @return int[] Product IDs currently in the cart (parents for variations). */
	private static function cart_product_ids() {
		$ids = array();
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids[] = (int) $item['product_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** @return int[] Category IDs of the cart items. */
	private static function cart_cat_ids() {
		$cats = array();
		foreach ( self::cart_product_ids() as $id ) {
			$cats = array_merge( $cats, wc_get_product_term_ids( $id, 'product_cat' ) );
		}
		return array_values( array_unique( array_map( 'intval', $cats ) ) );
	}

	/**
	 * One bounded query: visible, in-stock simple products priced in [lo, hi], excluded ones left out.
	 * Cached 10 minutes per price bucket; cart-specific filtering happens afterwards in PHP.
	 *
	 * @param float $lo Lower price. @param float $hi Upper price. @return int[]
	 */
	private static function price_window( $lo, $hi ) {
		$s   = Eru_CB_Settings::all();
		$key = 'eru_cb_w_' . md5( wp_json_encode( array( $lo, $hi, $s['ship_exclude_products'], $s['ship_exclude_cats'], get_option( self::CACHE_VER ) ) ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$tax = array(
			array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => array( 'simple' ) ),
			array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog', 'outofstock' ), 'operator' => 'NOT IN' ),
		);
		if ( ! empty( $s['ship_exclude_cats'] ) ) {
			$tax[] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_map( 'intval', $s['ship_exclude_cats'] ), 'operator' => 'NOT IN' );
		}
		$q   = new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => 60,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'post__not_in'        => array_map( 'intval', $s['ship_exclude_products'] ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'orderby'             => 'menu_order date',
				'tax_query'           => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_price', 'value' => array( $lo, $hi ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(12,2)' ),
					array( 'key' => '_stock_status', 'value' => 'instock' ),
				),
			)
		);
		$ids = array_map( 'intval', $q->posts );
		set_transient( $key, $ids, 10 * MINUTE_IN_SECONDS );
		return $ids;
	}

	/** @param int $id Product ID. @param float $lo Lower price. @param float $hi Upper price. @return WC_Product|null */
	private static function usable( $id, $lo, $hi ) {
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->is_type( 'simple' ) || 'publish' !== $p->get_status() || ! $p->is_visible() || ! $p->is_purchasable() || ! $p->is_in_stock() ) {
			return null;
		}
		$price = (float) wc_get_price_to_display( $p );
		return ( $price >= $lo && $price <= $hi ) ? $p : null;
	}

	/**
	 * Products that fill the free-shipping gap: priced in [gap, gap + max_extra].
	 * Order: cross-sells of cart items, then same category, then the manual list.
	 *
	 * @return WC_Product[]
	 */
	public static function suggestions() {
		$extra = (float) Eru_CB_Settings::get( 'ship_gap_extra' );
		$gap   = self::gap();
		if ( $extra <= 0 || $gap <= 0 ) {
			return array();
		}
		$max   = max( 1, min( 8, (int) Eru_CB_Settings::get( 'ship_gap_count' ) ) );
		$lo    = $gap;
		$hi    = $gap + $extra;
		$cart  = self::cart_product_ids();
		$excl  = array_merge( $cart, array_map( 'intval', (array) Eru_CB_Settings::get( 'ship_exclude_products' ) ) );
		$cats  = self::cart_cat_ids();
		$ex_c  = array_map( 'intval', (array) Eru_CB_Settings::get( 'ship_exclude_cats' ) );
		$found = array();

		$add = function ( $id ) use ( &$found, $excl, $ex_c, $lo, $hi, $max ) {
			$id = (int) $id;
			if ( count( $found ) >= $max || isset( $found[ $id ] ) || in_array( $id, $excl, true ) ) {
				return;
			}
			if ( $ex_c && array_intersect( $ex_c, wc_get_product_term_ids( $id, 'product_cat' ) ) ) {
				return;
			}
			$p = self::usable( $id, $lo, $hi );
			if ( $p ) {
				$found[ $id ] = $p;
			}
		};

		foreach ( array_slice( WC()->cart->get_cross_sells(), 0, 30 ) as $id ) {
			$add( $id );
		}
		// Bucket the window to the nearest 10 so the cache key stays stable between page views.
		$window = self::price_window( floor( $lo / 10 ) * 10, ceil( $hi / 10 ) * 10 );
		foreach ( $window as $id ) {
			if ( $cats && array_intersect( $cats, wc_get_product_term_ids( $id, 'product_cat' ) ) ) {
				$add( $id );
			}
		}
		foreach ( array_slice( array_map( 'intval', (array) Eru_CB_Settings::get( 'ship_manual_products' ) ), 0, 30 ) as $id ) {
			$add( $id );
		}
		if ( count( $found ) < $max && ! $cats ) {
			foreach ( $window as $id ) {
				$add( $id );
			}
		}
		return array_values( $found );
	}

	/** @return WC_Product[] Up to N products from the cart's categories, when the cart has no cross-sells. */
	public static function recommendations() {
		if ( ! WC()->cart || WC()->cart->is_empty() || WC()->cart->get_cross_sells() ) {
			return array();
		}
		$cats = self::cart_cat_ids();
		if ( ! $cats ) {
			return array();
		}
		$n  = max( 1, min( 8, (int) Eru_CB_Settings::get( 'recs_count' ) ) );
		$q  = new WP_Query(
			array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => $n,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'post__not_in'        => self::cart_product_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'orderby'             => 'menu_order date',
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cats ),
					array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-catalog', 'outofstock' ), 'operator' => 'NOT IN' ),
				),
				'meta_query'          => array( array( 'key' => '_stock_status', 'value' => 'instock' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$out = array();
		foreach ( $q->posts as $id ) {
			$p = wc_get_product( $id );
			if ( $p && $p->is_visible() && $p->is_in_stock() ) {
				$out[] = $p;
			}
		}
		return $out;
	}
}
