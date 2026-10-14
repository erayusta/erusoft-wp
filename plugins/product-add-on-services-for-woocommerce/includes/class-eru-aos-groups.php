<?php
/**
 * Service groups: the eru_aos_group post type, its data model and the product scope.
 *
 * @package product-add-on-services-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Group storage and lookup.
 *
 * A group is a post (title = name shown to the customer, menu_order = sort order) with meta:
 * _eru_aos_description, _eru_aos_type (single|multi), _eru_aos_required (yes|no), _eru_aos_min, _eru_aos_max,
 * _eru_aos_scope (all|categories|tags|products), _eru_aos_scope_ids, _eru_aos_exclude, _eru_aos_options.
 */
class Eru_AOS_Groups {

	const CPT = 'eru_aos_group';

	/**
	 * Published groups, request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'clean_post_cache', array( __CLASS__, 'flush' ), 10, 2 );
	}

	public static function register() {
		$cap = 'manage_woocommerce';
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'               => __( 'Ek Hizmetler', 'product-add-on-services-for-woocommerce' ),
					'singular_name'      => __( 'Hizmet grubu', 'product-add-on-services-for-woocommerce' ),
					'menu_name'          => __( 'Ek Hizmetler', 'product-add-on-services-for-woocommerce' ),
					'all_items'          => __( 'Ek Hizmetler', 'product-add-on-services-for-woocommerce' ),
					'add_new'            => __( 'Grup ekle', 'product-add-on-services-for-woocommerce' ),
					'add_new_item'       => __( 'Yeni hizmet grubu', 'product-add-on-services-for-woocommerce' ),
					'edit_item'          => __( 'Hizmet grubunu düzenle', 'product-add-on-services-for-woocommerce' ),
					'new_item'           => __( 'Yeni hizmet grubu', 'product-add-on-services-for-woocommerce' ),
					'search_items'       => __( 'Grup ara', 'product-add-on-services-for-woocommerce' ),
					'not_found'          => __( 'Henüz hizmet grubu yok.', 'product-add-on-services-for-woocommerce' ),
					'not_found_in_trash' => __( 'Çöpte hizmet grubu yok.', 'product-add-on-services-for-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'page-attributes' ),
				'map_meta_cap'    => false,
				'capability_type' => 'post',
				'capabilities'    => array_fill_keys(
					array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'read', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ),
					$cap
				),
			)
		);
	}

	public static function flush( $post_id = 0, $post = null ) {
		if ( ! $post || self::CPT === $post->post_type ) {
			self::$cache = null;
		}
	}

	/**
	 * Normalises group data coming from the admin form, the CLI or code.
	 *
	 * @param array $d Raw data.
	 * @return array
	 */
	public static function sanitize( array $d ) {
		$type  = ( $d['type'] ?? 'single' ) === 'multi' ? 'multi' : 'single';
		$scope = in_array( $d['scope'] ?? '', array( 'all', 'categories', 'tags', 'products' ), true ) ? $d['scope'] : 'all';
		$ids   = fn( $v ) => array_values( array_unique( array_filter( array_map( 'absint', is_array( $v ) ? $v : explode( ',', (string) $v ) ) ) ) );
		$bool  = fn( $v ) => ! empty( $v ) && 'no' !== $v && '0' !== (string) $v;

		$options = array();
		$seen    = array();
		foreach ( (array) ( $d['options'] ?? array() ) as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$label = sanitize_text_field( $o['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_key( $o['id'] ?? '' );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = 'o' . substr( md5( uniqid( $label, true ) ), 0, 8 );
			}
			$seen[ $id ] = true;
			$options[]   = array(
				'id'          => $id,
				'label'       => $label,
				'description' => sanitize_text_field( $o['description'] ?? '' ),
				'price_type'  => ( $o['price_type'] ?? 'fixed' ) === 'percent' ? 'percent' : 'fixed',
				'amount'      => max( 0, (float) wc_format_decimal( (string) ( $o['amount'] ?? 0 ) ) ),
				'charge'      => ( $o['charge'] ?? 'per_unit' ) === 'per_line' ? 'per_line' : 'per_unit',
				'default'     => $bool( $o['default'] ?? false ),
				'enabled'     => array_key_exists( 'enabled', $o ) ? $bool( $o['enabled'] ) : true,
			);
		}
		if ( 'single' === $type ) {
			// A radio group can only have one default.
			$has = false;
			foreach ( $options as $k => $o ) {
				if ( $o['default'] && ( $has || ! $o['enabled'] ) ) {
					$options[ $k ]['default'] = false;
				}
				$has = $has || $options[ $k ]['default'];
			}
		}

		$min = 'multi' === $type ? absint( $d['min'] ?? 0 ) : 0;
		$max = 'multi' === $type ? absint( $d['max'] ?? 0 ) : 0;
		if ( $max && $min > $max ) {
			$min = $max;
		}

		return array(
			'description' => sanitize_textarea_field( $d['description'] ?? '' ),
			'type'        => $type,
			'required'    => $bool( $d['required'] ?? false ),
			'min'         => $min,
			'max'         => $max,
			'scope'       => $scope,
			'scope_ids'   => 'all' === $scope ? array() : $ids( $d['scope_ids'] ?? array() ),
			'exclude'     => $ids( $d['exclude'] ?? array() ),
			'options'     => $options,
		);
	}

	/**
	 * Saves sanitised group meta.
	 *
	 * @param int   $post_id Group post ID.
	 * @param array $d       Raw data.
	 */
	public static function save_meta( $post_id, array $d ) {
		$c = self::sanitize( $d );
		update_post_meta( $post_id, '_eru_aos_description', $c['description'] );
		update_post_meta( $post_id, '_eru_aos_type', $c['type'] );
		update_post_meta( $post_id, '_eru_aos_required', $c['required'] ? 'yes' : 'no' );
		update_post_meta( $post_id, '_eru_aos_min', $c['min'] );
		update_post_meta( $post_id, '_eru_aos_max', $c['max'] );
		update_post_meta( $post_id, '_eru_aos_scope', $c['scope'] );
		update_post_meta( $post_id, '_eru_aos_scope_ids', $c['scope_ids'] );
		update_post_meta( $post_id, '_eru_aos_exclude', $c['exclude'] );
		update_post_meta( $post_id, '_eru_aos_options', $c['options'] );
		self::$cache = null;
	}

	/**
	 * Creates a published group.
	 *
	 * @param array $d Group data (title, menu_order, status + the fields of sanitize()).
	 * @return int|WP_Error
	 */
	public static function create( array $d ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_title'  => sanitize_text_field( $d['title'] ?? '' ),
				'post_status' => in_array( $d['status'] ?? '', array( 'publish', 'draft' ), true ) ? $d['status'] : 'publish',
				'menu_order'  => (int) ( $d['menu_order'] ?? 0 ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::save_meta( $id, $d );
		return (int) $id;
	}

	/**
	 * One group as an array, or null.
	 *
	 * @param int|WP_Post $post Group.
	 * @return array|null
	 */
	public static function get( $post ) {
		$post = get_post( $post );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return null;
		}
		$m    = fn( $k, $def = '' ) => metadata_exists( 'post', $post->ID, $k ) ? get_post_meta( $post->ID, $k, true ) : $def;
		$data = self::sanitize(
			array(
				'description' => $m( '_eru_aos_description' ),
				'type'        => $m( '_eru_aos_type', 'single' ),
				'required'    => 'yes' === $m( '_eru_aos_required', 'no' ),
				'min'         => $m( '_eru_aos_min', 0 ),
				'max'         => $m( '_eru_aos_max', 0 ),
				'scope'       => $m( '_eru_aos_scope', 'all' ),
				'scope_ids'   => (array) $m( '_eru_aos_scope_ids', array() ),
				'exclude'     => (array) $m( '_eru_aos_exclude', array() ),
				'options'     => (array) $m( '_eru_aos_options', array() ),
			)
		);
		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'menu_order' => (int) $post->menu_order,
		) + $data;
	}

	/**
	 * Published groups ordered by menu order, then ID.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$posts       = get_posts(
				array(
					'post_type'        => self::CPT,
					'post_status'      => 'publish',
					'numberposts'      => -1,
					'orderby'          => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
					'suppress_filters' => true,
				)
			);
			self::$cache = array_values( array_filter( array_map( array( __CLASS__, 'get' ), $posts ) ) );
		}
		return self::$cache;
	}

	/**
	 * Groups that apply to a product (variations use their parent), with only enabled options.
	 * Groups without an enabled option are left out.
	 *
	 * @param int|WC_Product $product          Product or ID.
	 * @param bool           $respect_disabled Honour the per-product "disable add-on services" box.
	 * @return array
	 */
	public static function for_product( $product, $respect_disabled = true ) {
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
		if ( ! $product ) {
			return array();
		}
		$pid = $product->get_parent_id() && $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		if ( $respect_disabled && 'yes' === get_post_meta( $pid, '_eru_aos_disabled', true ) ) {
			return array();
		}

		$out = array();
		foreach ( self::all() as $g ) {
			if ( in_array( $pid, $g['exclude'], true ) || ! self::in_scope( $g, $pid ) ) {
				continue;
			}
			$g['options'] = array_values( array_filter( $g['options'], fn( $o ) => $o['enabled'] ) );
			if ( $g['options'] ) {
				$out[] = $g;
			}
		}

		/**
		 * Filters the service groups shown for a product.
		 *
		 * @param array $out Groups (see Eru_AOS_Groups::get()).
		 * @param int   $pid Product ID (parent ID for variations).
		 */
		return array_values( (array) apply_filters( 'eru_aos_groups_for_product', $out, $pid ) );
	}

	/**
	 * Whether a group's scope covers a product.
	 *
	 * @param array $g   Group.
	 * @param int   $pid Product ID.
	 * @return bool
	 */
	private static function in_scope( array $g, $pid ) {
		switch ( $g['scope'] ) {
			case 'products':
				return in_array( $pid, $g['scope_ids'], true );
			case 'categories':
				return (bool) array_intersect( $g['scope_ids'], wc_get_product_term_ids( $pid, 'product_cat' ) );
			case 'tags':
				return (bool) array_intersect( $g['scope_ids'], wc_get_product_term_ids( $pid, 'product_tag' ) );
			default:
				return true;
		}
	}

	/**
	 * Scope summary for admin lists.
	 *
	 * @param array $g Group.
	 * @return string
	 */
	public static function scope_label( array $g ) {
		switch ( $g['scope'] ) {
			case 'products':
				/* translators: %d: number of products */
				return sprintf( _n( '%d ürün', '%d ürün', count( $g['scope_ids'] ), 'product-add-on-services-for-woocommerce' ), count( $g['scope_ids'] ) );
			case 'categories':
			case 'tags':
				$names = array();
				foreach ( $g['scope_ids'] as $tid ) {
					$t = get_term( $tid );
					if ( $t && ! is_wp_error( $t ) ) {
						$names[] = $t->name;
					}
				}
				return ( 'tags' === $g['scope'] ? __( 'Etiket: ', 'product-add-on-services-for-woocommerce' ) : __( 'Kategori: ', 'product-add-on-services-for-woocommerce' ) ) . implode( ', ', $names );
			default:
				return __( 'Tüm ürünler', 'product-add-on-services-for-woocommerce' );
		}
	}
}
