<?php
/**
 * Turkish-aware product search: folding, index meta, SQL matching, REST endpoint, main-query hook.
 *
 * @package smart-product-search-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SPS {

	const OPTION     = 'eru_sps_settings';
	const META_INDEX = '_eru_sps_index';
	const META_TITLE = '_eru_sps_title';
	const TD         = 'smart-product-search-for-woocommerce';

	public static function defaults() {
		return array(
			'main_query'  => 0,
			'live'        => 0,
			'selector'    => '.woocommerce-product-search input[type="search"]',
			'limit'       => 8,
			'min_chars'   => 2,
			'rate_limit'  => 30,
			'show_sku'    => 1,
			'show_price'  => 1,
			'show_cats'   => 1,
			'synonyms'    => '',
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$d   = self::defaults();
		$sel = isset( $in['selector'] ) ? trim( wp_strip_all_tags( (string) $in['selector'] ) ) : $d['selector'];
		return array(
			'main_query' => empty( $in['main_query'] ) ? 0 : 1,
			'live'       => empty( $in['live'] ) ? 0 : 1,
			'selector'   => '' === $sel ? $d['selector'] : substr( $sel, 0, 300 ),
			'limit'      => min( 20, max( 1, isset( $in['limit'] ) ? absint( $in['limit'] ) : $d['limit'] ) ),
			'min_chars'  => min( 5, max( 1, isset( $in['min_chars'] ) ? absint( $in['min_chars'] ) : $d['min_chars'] ) ),
			'rate_limit' => min( 600, max( 0, isset( $in['rate_limit'] ) ? absint( $in['rate_limit'] ) : $d['rate_limit'] ) ),
			'show_sku'   => empty( $in['show_sku'] ) ? 0 : 1,
			'show_price' => empty( $in['show_price'] ) ? 0 : 1,
			'show_cats'  => empty( $in['show_cats'] ) ? 0 : 1,
			'synonyms'   => isset( $in['synonyms'] ) ? substr( sanitize_textarea_field( (string) $in['synonyms'] ), 0, 5000 ) : '',
		);
	}

	/* ------------------------------------------------------------------ folding */

	/** Lower-cases (Turkish I/İ aware) and folds ı ş ğ ü ö ç to ASCII; punctuation becomes a space. */
	public static function fold( $text ) {
		$text = str_replace( array( 'İ', 'I' ), array( 'i', 'ı' ), (string) $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = strtr(
			$text,
			array(
				'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
				'â' => 'a', 'î' => 'i', 'û' => 'u', "\u{0307}" => '',
			)
		);
		$text = preg_replace( '/[^\p{L}\p{N}\-._\/]+/u', ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/** Synonym groups from the textarea: one group per line, comma separated, folded. */
	public static function synonym_groups() {
		$groups = array();
		foreach ( preg_split( '/\R/', (string) self::get_settings()['synonyms'] ) as $line ) {
			$words = array_values( array_filter( array_unique( array_map( array( __CLASS__, 'fold' ), explode( ',', $line ) ) ) ) );
			if ( count( $words ) > 1 ) {
				$groups[] = $words;
			}
		}
		return apply_filters( 'eru_sps_synonym_groups', $groups );
	}

	/** Query -> list of words, each a list of alternatives (the word plus its synonyms). */
	public static function words( $query, $max_words = 5 ) {
		$folded = self::fold( $query );
		if ( '' === $folded ) {
			return array();
		}
		$groups = self::synonym_groups();
		$out    = array();
		foreach ( array_slice( array_unique( explode( ' ', $folded ) ), 0, $max_words ) as $word ) {
			$alts = array( $word );
			foreach ( $groups as $g ) {
				if ( in_array( $word, $g, true ) ) {
					$alts = array_merge( $alts, $g );
				}
			}
			$out[] = array_slice( array_values( array_unique( $alts ) ), 0, 6 );
		}
		return $out;
	}

	/* -------------------------------------------------------------------- index */

	public static function index_text( $product ) {
		$parts = array( $product->get_name(), $product->get_sku() );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$parts[] = $child->get_sku();
				}
			}
		}
		$cats = get_the_terms( $product->get_id(), 'product_cat' );
		if ( is_array( $cats ) ) {
			foreach ( $cats as $c ) {
				$parts[] = $c->name;
			}
		}
		$parts = apply_filters( 'eru_sps_index_parts', $parts, $product );
		return self::fold( implode( ' ', array_filter( array_map( 'strval', (array) $parts ) ) ) );
	}

	public static function index_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) {
			return;
		}
		update_post_meta( $product->get_id(), self::META_INDEX, self::index_text( $product ) );
		update_post_meta( $product->get_id(), self::META_TITLE, self::fold( $product->get_name() ) );
	}

	/** A variation's SKU lives in its parent's index. */
	public static function index_variation_parent( $variation_id ) {
		$parent = (int) wp_get_post_parent_id( $variation_id );
		if ( $parent ) {
			self::index_product( $parent );
		}
	}

	/** Index one batch of products; returns array( processed, total ). */
	public static function reindex_batch( $offset, $limit = 200 ) {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('auto-draft','trash')" );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('auto-draft','trash') ORDER BY ID ASC LIMIT %d OFFSET %d",
				max( 1, (int) $limit ),
				max( 0, (int) $offset )
			)
		);
		foreach ( $ids as $id ) {
			self::index_product( (int) $id );
		}
		return array( count( $ids ), $total );
	}

	/* ------------------------------------------------------------------- search */

	/** Folded-title expression for products that have no index row yet (constant strings only). */
	private static function title_chain_sql() {
		$expr = 'p.post_title';
		$map  = array( 'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o', 'ö' => 'o', 'Ç' => 'c', 'ç' => 'c' );
		foreach ( $map as $from => $to ) {
			$expr = "REPLACE({$expr},'{$from}','{$to}')";
		}
		return "LOWER({$expr})";
	}

	/** Bounded, prepared product search. Returns product IDs, best match first. */
	public static function search_ids( $query, $limit = 8 ) {
		global $wpdb;
		$words = self::words( $query );
		if ( ! $words ) {
			return array();
		}
		$limit = min( 200, max( 1, (int) $limit ) );
		$chain = self::title_chain_sql();
		$args  = array();
		$where = array();
		foreach ( $words as $alts ) {
			$or = array();
			foreach ( $alts as $a ) {
				$like  = '%' . $wpdb->esc_like( $a ) . '%';
				$or[]  = "(m.meta_value LIKE %s OR (m.meta_id IS NULL AND ({$chain} LIKE %s OR LOWER(k.meta_value) LIKE %s)))";
				$args  = array_merge( $args, array( $like, $like, $like ) );
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}
		$full = self::fold( $query );
		$sql  = "SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '" . self::META_INDEX . "'
			LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '" . self::META_TITLE . "'
			LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = '_sku'
			WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.post_password = ''";
		$hide = get_term_by( 'name', 'exclude-from-search', 'product_visibility' );
		if ( $hide ) {
			$sql .= " AND p.ID NOT IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = " . (int) $hide->term_taxonomy_id . ')';
		}
		/**
		 * Filters product_cat term IDs whose products are left out of search results (children are not expanded).
		 *
		 * @param int[] $term_ids Term IDs.
		 */
		$excl = array_values( array_filter( array_map( 'absint', (array) apply_filters( 'eru_sps_exclude_term_ids', array() ) ) ) );
		if ( $excl ) {
			$sql .= " AND p.ID NOT IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . implode( ',', $excl ) . '))';
		}
		$sql .= ' AND ' . implode( ' AND ', $where );
		$sql .= ' ORDER BY CASE WHEN LOWER(k.meta_value) = %s THEN 0 WHEN t.meta_value = %s THEN 1 WHEN t.meta_value LIKE %s THEN 2 WHEN t.meta_value LIKE %s THEN 3 ELSE 4 END, p.post_title ASC LIMIT %d';
		$args  = array_merge( $args, array( $full, $full, $wpdb->esc_like( $full ) . '%', '%' . $wpdb->esc_like( $full ) . '%', $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fragments are constant strings and %s placeholders; values are bound below.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		/**
		 * Filters the matched product IDs (best match first).
		 *
		 * @param int[]  $ids   Product IDs.
		 * @param string $query Search query.
		 */
		return array_values( array_map( 'intval', (array) apply_filters( 'eru_sps_search_ids', $ids, $query ) ) );
	}

	/** Categories whose folded name matches every word (small set, cached). */
	public static function search_categories( $query, $limit = 4 ) {
		$words = self::words( $query );
		if ( ! $words ) {
			return array();
		}
		/**
		 * Filters the category-cache version; a change rebuilds the cached category list.
		 *
		 * @param string $version Version string.
		 */
		$ver   = (string) apply_filters( 'eru_sps_categories_version', '1' );
		$cache = get_transient( 'eru_sps_cats' );
		$list  = ( is_array( $cache ) && isset( $cache['v'], $cache['list'] ) && $cache['v'] === $ver && is_array( $cache['list'] ) ) ? $cache['list'] : null;
		if ( null === $list ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 500 ) );
			$list  = array();
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$list[] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'fold' => self::fold( $t->name ), 'count' => (int) $t->count );
				}
			}
			set_transient( 'eru_sps_cats', array( 'v' => $ver, 'list' => $list ), HOUR_IN_SECONDS );
		}
		/**
		 * Filters the category list (rows with id, name, fold, count) before matching.
		 *
		 * @param array[] $list Categories.
		 */
		$list = (array) apply_filters( 'eru_sps_categories', $list );
		$out = array();
		foreach ( $list as $c ) {
			foreach ( $words as $alts ) {
				$hit = false;
				foreach ( $alts as $a ) {
					if ( false !== strpos( $c['fold'], $a ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue 2;
				}
			}
			$link = get_term_link( $c['id'], 'product_cat' );
			if ( ! is_wp_error( $link ) ) {
				$out[] = array( 'name' => $c['name'], 'url' => $link, 'count' => $c['count'] );
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Result payload shared by REST and tests. */
	public static function results( $query ) {
		$s    = self::get_settings();
		$rows = array();
		foreach ( self::search_ids( $query, $s['limit'] ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			$img    = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
			$rows[] = array(
				'id'    => $id,
				'title' => wp_strip_all_tags( $product->get_name() ),
				'url'   => get_permalink( $id ),
				'image' => $img ? $img : '',
				'sku'   => $s['show_sku'] ? (string) $product->get_sku() : '',
				'price' => $s['show_price'] ? wp_kses_post( $product->get_price_html() ) : '',
			);
		}
		return array(
			'products'   => $rows,
			'categories' => $s['show_cats'] ? self::search_categories( $query ) : array(),
			'all_url'    => add_query_arg( array( 's' => rawurlencode( $query ), 'post_type' => 'product' ), home_url( '/' ) ),
		);
	}

	/* --------------------------------------------------------------------- misc */

	/** Client IP for rate limiting. Behind a proxy/CDN, filter it to the real address. */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'eru_sps_client_ip', $ip );
	}

	/** True when the request is allowed; counts one hit per call. */
	public static function rate_ok() {
		$max = (int) self::get_settings()['rate_limit'];
		if ( $max <= 0 ) {
			return true;
		}
		$key = 'eru_sps_rl_' . md5( self::client_ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public static function rest_search( WP_REST_Request $req ) {
		$q = trim( wp_strip_all_tags( (string) $req->get_param( 'q' ) ) );
		$q = function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 80, 'UTF-8' ) : substr( $q, 0, 80 );
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $q, 'UTF-8' ) < (int) self::get_settings()['min_chars'] : strlen( $q ) < (int) self::get_settings()['min_chars'] ) {
			return new WP_REST_Response( array( 'products' => array(), 'categories' => array(), 'all_url' => '' ), 200 );
		}
		if ( ! self::rate_ok() ) {
			return new WP_REST_Response( array( 'message' => __( 'Çok fazla istek. Lütfen biraz bekleyin.', 'smart-product-search-for-woocommerce' ) ), 429 );
		}
		$res = new WP_REST_Response( self::results( $q ), 200 );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function init() {
		$s = self::get_settings();
		add_action( 'woocommerce_new_product', array( __CLASS__, 'index_product' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'index_product' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'index_variation_parent' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'index_variation_parent' ) );
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'eru-sps/v1',
					'/search',
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'rest_search' ),
						'permission_callback' => '__return_true',
						'args'                => array( 'q' => array( 'type' => 'string', 'required' => true ) ),
					)
				);
			}
		);
		foreach ( array( 'created_product_cat', 'edited_product_cat', 'delete_product_cat' ) as $hook ) {
			add_action( $hook, fn() => delete_transient( 'eru_sps_cats' ) );
		}
		add_shortcode( 'eru_product_search', array( __CLASS__, 'shortcode' ) );
		if ( $s['main_query'] ) {
			add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
		}
		if ( $s['live'] ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		}
	}

	public static function enqueue() {
		$s = self::get_settings();
		wp_enqueue_style( 'eru-sps', ERU_SPS_URL . 'assets/css/search.css', array(), ERU_SPS_VERSION );
		wp_enqueue_script( 'eru-sps', ERU_SPS_URL . 'assets/js/search.js', array(), ERU_SPS_VERSION, true );
		wp_localize_script(
			'eru-sps',
			'ERU_SPS',
			array(
				'url'      => esc_url_raw( rest_url( 'eru-sps/v1/search' ) ),
				'selector' => $s['selector'],
				'min'      => (int) $s['min_chars'],
				'i18n'     => array(
					'none' => __( 'Sonuç bulunamadı.', 'smart-product-search-for-woocommerce' ),
					'cats' => __( 'Kategoriler', 'smart-product-search-for-woocommerce' ),
					'all'  => __( 'Tüm sonuçları gör', 'smart-product-search-for-woocommerce' ),
					'sku'  => __( 'Stok kodu', 'smart-product-search-for-woocommerce' ),
				),
			)
		);
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'placeholder' => __( 'Ürün ara…', 'smart-product-search-for-woocommerce' ) ), $atts, 'eru_product_search' );
		// Attach the live dropdown to this form even when the global selector setting is off.
		if ( ! self::get_settings()['live'] ) {
			self::enqueue();
		}
		return '<form role="search" method="get" class="eru-sps-form" action="' . esc_url( home_url( '/' ) ) . '">'
			. '<input type="search" class="eru-sps-input" name="s" placeholder="' . esc_attr( $atts['placeholder'] ) . '" autocomplete="off" aria-label="' . esc_attr__( 'Ürün ara', 'smart-product-search-for-woocommerce' ) . '">'
			. '<input type="hidden" name="post_type" value="product">'
			. '<button type="submit">' . esc_html__( 'Ara', 'smart-product-search-for-woocommerce' ) . '</button></form>';
	}

	/** Main product search uses the index; WordPress' own LIKE clause is replaced by an ID list (bounded). */
	public static function pre_get_posts( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( 'product', $types, true ) ) {
			return;
		}
		$q = (string) $query->get( 's' );
		if ( '' === trim( $q ) ) {
			return;
		}
		$ids = self::search_ids( $q, 200 );
		$query->set( 'eru_sps_ids', $ids ? $ids : array( 0 ) );
		add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 20, 2 );
		add_filter( 'posts_orderby', array( __CLASS__, 'posts_orderby' ), 20, 2 );
	}

	public static function posts_search( $search, $query ) {
		$ids = $query->get( 'eru_sps_ids' );
		if ( ! is_array( $ids ) ) {
			return $search;
		}
		global $wpdb;
		return " AND {$wpdb->posts}.ID IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')';
	}

	public static function posts_orderby( $orderby, $query ) {
		$ids = $query->get( 'eru_sps_ids' );
		if ( ! is_array( $ids ) || ! $ids || $query->get( 'orderby' ) && ! in_array( $query->get( 'orderby' ), array( 'relevance', '' ), true ) ) {
			return $orderby;
		}
		global $wpdb;
		return "FIELD({$wpdb->posts}.ID," . implode( ',', array_map( 'intval', $ids ) ) . ')';
	}
}
