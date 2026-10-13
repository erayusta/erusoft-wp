<?php
/**
 * Stored per-term order and its application on archive queries.
 *
 * @package brand-product-sorting-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BPS {

	const META = '_eru_bps_order';

	public static function init() {
		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 20, 2 );
	}

	/**
	 * Product taxonomies the order can be set on (default: all taxonomies of the product type).
	 *
	 * @return string[]
	 */
	public static function taxonomies() {
		$tax = array_values( get_object_taxonomies( 'product' ) );
		/**
		 * Filters the taxonomies that can carry a manual product order.
		 *
		 * @param string[] $tax Taxonomy slugs.
		 */
		return array_values( array_intersect( $tax, (array) apply_filters( 'eru_bps_taxonomies', $tax ) ) );
	}

	/**
	 * @param int $term_id Term ID.
	 * @return int[] Product IDs in manual order (empty = no manual order).
	 */
	public static function get_order( $term_id ) {
		$v = get_term_meta( (int) $term_id, self::META, true );
		return is_array( $v ) ? array_values( array_filter( array_map( 'absint', $v ) ) ) : array();
	}

	/**
	 * Stores a manual order. Unknown IDs and non-products are dropped, duplicates keep their first position.
	 *
	 * @param int   $term_id Term ID.
	 * @param array $ids     Product IDs in the wanted order.
	 * @return bool
	 */
	public static function save_order( $term_id, $ids ) {
		$term_id = (int) $term_id;
		$term    = $term_id ? get_term( $term_id ) : null;
		if ( ! $term || is_wp_error( $term ) || ! in_array( $term->taxonomy, self::taxonomies(), true ) ) {
			return false;
		}
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		$valid = $ids ? get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'any',
				'post__in'         => $ids,
				'posts_per_page'   => count( $ids ),
				'fields'           => 'ids',
				'orderby'          => 'post__in',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		) : array();
		$valid = array_map( 'intval', $valid );
		$ids   = array_values( array_filter( $ids, fn( $id ) => in_array( $id, $valid, true ) ) );
		if ( ! $ids ) {
			delete_term_meta( $term_id, self::META );
			return true;
		}
		update_term_meta( $term_id, self::META, $ids );
		return true;
	}

	public static function clear_order( $term_id ) {
		delete_term_meta( (int) $term_id, self::META );
	}

	/**
	 * The manual order only replaces the shop's default sort. If the shopper picked another sort
	 * (price, popularity...) it is left alone.
	 */
	private static function default_sort_selected() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only comparison of a sort keyword.
		$raw = isset( $_GET['orderby'] ) ? wc_clean( wp_unslash( $_GET['orderby'] ) ) : '';
		if ( '' === $raw ) {
			$raw = apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) );
		}
		return 'menu_order' === $raw;
	}

	/**
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public static function clauses( $clauses, $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_tax( self::taxonomies() ) ) {
			return $clauses;
		}
		$term = $query->get_queried_object();
		if ( ! $term || is_wp_error( $term ) || ! isset( $term->term_id ) ) {
			return $clauses;
		}
		$order = self::get_order( $term->term_id );
		if ( ! $order || ! self::default_sort_selected() ) {
			return $clauses;
		}
		global $wpdb;
		$max   = (int) apply_filters( 'eru_bps_max_products', 2000 );
		$list  = implode( ',', array_map( 'intval', array_slice( $order, 0, max( 1, $max ) ) ) );
		$field = "FIELD( {$wpdb->posts}.ID, {$list} )";
		$sort  = "( {$field} = 0 ) ASC, {$field} ASC";
		$clauses['orderby'] = '' !== trim( (string) ( $clauses['orderby'] ?? '' ) ) ? $sort . ', ' . $clauses['orderby'] : $sort;
		return $clauses;
	}
}
