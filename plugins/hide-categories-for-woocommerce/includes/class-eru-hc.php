<?php
/**
 * Hiding logic.
 *
 * @package hide-categories-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HC {

	const OPTION = 'eru_hc_settings';
	const TAX    = 'product_cat';

	/** @var int[]|null */
	private static $ids = null;

	/** @var array<string,int[]> Expanded trees keyed by the hidden-ID set. */
	private static $trees = array();

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ), 20 );
		add_filter( 'get_terms_args', array( __CLASS__, 'get_terms_args' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 1 );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
		foreach ( array( 'created_product_cat', 'edited_product_cat', 'delete_product_cat' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		// Product lists that are not WP_Query loops.
		add_filter( 'woocommerce_related_products', array( __CLASS__, 'filter_product_ids' ), 20 );
		add_filter( 'woocommerce_product_get_upsell_ids', array( __CLASS__, 'filter_product_ids' ), 20 );
		add_filter( 'woocommerce_product_get_cross_sell_ids', array( __CLASS__, 'filter_product_ids' ), 20 );
		// Smart Product Search live dropdown (served over REST).
		add_filter( 'eru_sps_exclude_term_ids', array( __CLASS__, 'sps_exclude_terms' ) );
		add_filter( 'eru_sps_categories', array( __CLASS__, 'sps_categories' ) );
		add_filter( 'eru_sps_categories_version', array( __CLASS__, 'sps_categories_version' ) );
	}

	/** Drops the per-request caches. */
	public static function flush() {
		self::$ids   = null;
		self::$trees = array();
	}

	/**
	 * Hidden category IDs (default: none).
	 *
	 * @return int[]
	 */
	public static function hidden_ids() {
		if ( null === self::$ids ) {
			$s         = get_option( self::OPTION, array() );
			$ids       = ( is_array( $s ) && isset( $s['ids'] ) && is_array( $s['ids'] ) ) ? $s['ids'] : array();
			self::$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}
		/**
		 * Filters the hidden category IDs.
		 *
		 * @param int[] $ids Term IDs of the hidden categories.
		 */
		return array_values( array_filter( array_map( 'absint', (array) apply_filters( 'eru_hc_hidden_ids', self::$ids ) ) ) );
	}

	public static function block_direct() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) && isset( $s['block'] ) && 'yes' === $s['block'];
	}

	/**
	 * Hidden IDs plus all their descendants.
	 *
	 * @return int[]
	 */
	public static function hidden_tree() {
		$ids = self::hidden_ids();
		$key = implode( ',', $ids );
		if ( isset( self::$trees[ $key ] ) ) {
			return self::$trees[ $key ];
		}
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = $id;
			$kids  = get_term_children( $id, self::TAX );
			if ( is_array( $kids ) ) {
				$out = array_merge( $out, $kids );
			}
		}
		return self::$trees[ $key ] = array_values( array_unique( array_map( 'intval', $out ) ) );
	}

	/**
	 * Front-end only; never in wp-admin or REST (editors and the Store API are left alone).
	 */
	private static function is_front() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		// Admin-screen AJAX (product search in order/product editors) sees everything.
		if ( wp_doing_ajax() && is_user_logged_in() && current_user_can( 'edit_products' ) && ! empty( $_REQUEST['action'] ) && 0 === strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'woocommerce_' ) && 0 === strpos( (string) wp_get_referer(), admin_url() ) ) {
			return false;
		}
		return ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/** Storefront output: front-end requests plus the Store API (block cart cross-sells). */
	private static function is_storefront() {
		if ( self::is_front() ) {
			return true;
		}
		return function_exists( 'WC' ) && method_exists( WC(), 'is_store_api_request' ) && WC()->is_store_api_request();
	}

	/**
	 * Removes products that sit in a hidden category (or a child of one) from an ID list.
	 *
	 * @param int[] $ids Product IDs.
	 * @return int[]
	 */
	public static function strip_hidden( $ids ) {
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids || ! self::hidden_ids() ) {
			return $ids;
		}
		$tree = array_flip( self::hidden_tree() );
		$rows = wp_get_object_terms( $ids, self::TAX, array( 'fields' => 'all_with_object_id', 'eru_hc_skip' => 1 ) );
		if ( is_wp_error( $rows ) ) {
			return $ids;
		}
		$drop = array();
		foreach ( $rows as $row ) {
			if ( isset( $tree[ (int) $row->term_id ] ) ) {
				$drop[ (int) $row->object_id ] = true;
			}
		}
		return array_values( array_filter( $ids, fn( $id ) => ! isset( $drop[ $id ] ) ) );
	}

	/**
	 * Related products, upsells and cross-sells on the storefront.
	 *
	 * @param int[] $ids Product IDs.
	 * @return int[]
	 */
	public static function filter_product_ids( $ids ) {
		if ( ! is_array( $ids ) || ! $ids || ! self::is_storefront() || ! apply_filters( 'eru_hc_filter_product_lists', true ) ) {
			return $ids;
		}
		return self::strip_hidden( $ids );
	}

	/** Smart Product Search: product_cat term IDs excluded from the SQL search. */
	public static function sps_exclude_terms( $term_ids ) {
		return array_values( array_unique( array_merge( array_map( 'absint', (array) $term_ids ), self::hidden_tree() ) ) );
	}

	/** Smart Product Search: drop hidden categories from the cached category list. */
	public static function sps_categories( $list ) {
		$tree = array_flip( self::hidden_tree() );
		if ( ! $tree || ! is_array( $list ) ) {
			return $list;
		}
		return array_values( array_filter( $list, fn( $c ) => ! isset( $tree[ (int) ( $c['id'] ?? 0 ) ] ) ) );
	}

	/** Smart Product Search: category cache version follows the hidden set. */
	public static function sps_categories_version( $version ) {
		return $version . '|hc:' . md5( implode( ',', self::hidden_tree() ) );
	}

	/**
	 * @param WP_Query $query Query.
	 */
	public static function pre_get_posts( $query ) {
		if ( ! self::is_front() || ! self::hidden_ids() ) {
			return;
		}
		$types = $query->get( 'post_type' );
		$types = is_array( $types ) ? $types : array( $types );
		$is_product_query = in_array( 'product', $types, true );
		$is_site_search   = $query->is_main_query() && $query->is_search() && ( empty( array_filter( $types ) ) || $is_product_query );
		if ( ! $is_product_query && ! $is_site_search ) {
			return;
		}
		// Single products, explicit lookups and related/upsell ID lists are not loops.
		if ( $query->is_singular() || $query->get( 'p' ) || $query->get( 'name' ) || $query->get( 'pagename' ) || $query->get( 'post__in' ) || $query->get( 'eru_hc_skip' ) ) {
			return;
		}
		// The archive of a hidden category itself still lists its products unless direct access is blocked.
		if ( $query->is_main_query() && $query->is_tax( self::TAX ) ) {
			$obj = $query->get_queried_object();
			if ( $obj && ! is_wp_error( $obj ) && in_array( (int) $obj->term_id, self::hidden_tree(), true ) ) {
				return;
			}
		}
		if ( ! apply_filters( 'eru_hc_apply_to_query', true, $query ) ) {
			return;
		}
		$ours = array(
			'taxonomy'         => self::TAX,
			'field'            => 'term_id',
			'terms'            => self::hidden_ids(),
			'operator'         => 'NOT IN',
			'include_children' => true,
		);
		$tax  = $query->get( 'tax_query' );
		if ( empty( $tax ) || ! is_array( $tax ) ) {
			$query->set( 'tax_query', array( $ours ) );
		} else {
			$query->set( 'tax_query', array( 'relation' => 'AND', $tax, $ours ) );
		}
	}

	/**
	 * Excludes hidden categories (and children) from front-end term lists: widgets, blocks, sub-category loops, dropdowns.
	 *
	 * @param array        $args       get_terms args.
	 * @param string|array $taxonomies Taxonomies.
	 * @return array
	 */
	public static function get_terms_args( $args, $taxonomies ) {
		if ( ! self::is_front() || ! in_array( self::TAX, (array) $taxonomies, true ) ) {
			return $args;
		}
		// Internal lookups (hierarchy cache, slug/name/ID resolution, per-object term lists) must stay complete.
		if ( 'all' === ( $args['get'] ?? '' ) || ! empty( $args['include'] ) || ! empty( $args['slug'] ) || ! empty( $args['name'] ) || ! empty( $args['object_ids'] ) || ! empty( $args['eru_hc_skip'] ) ) {
			return $args;
		}
		$ids = self::hidden_ids();
		if ( ! $ids ) {
			return $args;
		}
		// Exclude the expanded tree directly (exclude_tree would trigger a nested child_of lookup).
		$cur             = wp_parse_id_list( $args['exclude'] ?? array() );
		$args['exclude'] = array_values( array_unique( array_merge( $cur, self::hidden_tree() ) ) );
		return $args;
	}

	/**
	 * Is this term hidden, or the child of a hidden term?
	 */
	public static function term_is_hidden( $term_id ) {
		$term_id = (int) $term_id;
		$hidden  = self::hidden_ids();
		if ( ! $hidden ) {
			return false;
		}
		if ( in_array( $term_id, $hidden, true ) ) {
			return true;
		}
		return (bool) array_intersect( array_map( 'intval', get_ancestors( $term_id, self::TAX, 'taxonomy' ) ), $hidden );
	}

	/**
	 * A product is blocked when every category it has is hidden.
	 */
	public static function product_is_blocked( $product_id ) {
		if ( ! self::hidden_ids() ) {
			return false;
		}
		$cats = wp_get_post_terms( (int) $product_id, self::TAX, array( 'fields' => 'ids', 'eru_hc_skip' => 1 ) );
		if ( is_wp_error( $cats ) || ! $cats ) {
			return false;
		}
		foreach ( $cats as $c ) {
			if ( ! self::term_is_hidden( $c ) ) {
				return false;
			}
		}
		return true;
	}

	public static function template_redirect() {
		global $wp_query;
		self::maybe_404( $wp_query );
	}

	/**
	 * Optional 404 for hidden category archives and for products that only live in hidden categories.
	 *
	 * @param WP_Query $query Main query.
	 */
	public static function maybe_404( $query ) {
		if ( ! self::block_direct() || ! self::hidden_ids() || ! $query instanceof WP_Query ) {
			return;
		}
		$block = false;
		if ( $query->is_tax( self::TAX ) ) {
			$obj   = $query->get_queried_object();
			$block = $obj && ! is_wp_error( $obj ) && self::term_is_hidden( $obj->term_id );
		} elseif ( $query->is_singular( 'product' ) ) {
			$block = self::product_is_blocked( $query->get_queried_object_id() );
		}
		if ( $block && ! current_user_can( 'edit_products' ) ) {
			$query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}
}
