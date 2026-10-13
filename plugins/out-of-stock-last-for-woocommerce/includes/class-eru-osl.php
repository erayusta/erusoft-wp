<?php
/**
 * Query modification: out-of-stock products last.
 *
 * @package out-of-stock-last-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSL {

	const OPTION = 'eru_osl_settings';

	public static function init() {
		// Late priority: runs after WooCommerce and other plugins built their ORDER BY, then prepends the stock criterion.
		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 9999, 2 );
	}

	/**
	 * @return array{enabled:string,search:string}
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'enabled' => ( isset( $s['enabled'] ) && 'no' === $s['enabled'] ) ? 'no' : 'yes',
			'search'  => ( isset( $s['search'] ) && 'no' === $s['search'] ) ? 'no' : 'yes',
		);
	}

	/**
	 * Whether the stock criterion applies to this query: only the front-end main product query
	 * (shop, product taxonomies, product search) unless a query opts in with `eru_osl => 1`.
	 *
	 * @param WP_Query $query Query.
	 * @return bool
	 */
	public static function applies( $query ) {
		$s = self::settings();
		if ( 'yes' !== $s['enabled'] ) {
			return false;
		}
		$apply = false;
		if ( $query->get( 'eru_osl' ) ) {
			$apply = true;
		} elseif ( ! is_admin() && $query->is_main_query() ) {
			$is_search = $query->is_search();
			if ( $is_search ) {
				$types        = (array) $query->get( 'post_type' );
				$apply     = 'yes' === $s['search'] && in_array( 'product', $types, true );
			} else {
				$apply = $query->is_post_type_archive( 'product' ) || $query->is_tax( get_object_taxonomies( 'product' ) );
			}
		}
		/**
		 * Filters whether out-of-stock products are moved last for a query (e.g. theme AJAX "load more" queries).
		 *
		 * @param bool     $apply Current decision.
		 * @param WP_Query $query Query.
		 */
		return (bool) apply_filters( 'eru_osl_apply_to_query', $apply, $query );
	}

	/**
	 * @param array    $clauses SQL clauses.
	 * @param WP_Query $query   Query.
	 * @return array
	 */
	public static function clauses( $clauses, $query ) {
		if ( ! self::applies( $query ) ) {
			return $clauses;
		}
		global $wpdb;
		if ( false === strpos( $clauses['join'], 'eru_osl_stock' ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->prefix}wc_product_meta_lookup AS eru_osl_stock ON eru_osl_stock.product_id = {$wpdb->posts}.ID ";
		}
		$stock = "( COALESCE( eru_osl_stock.stock_status, 'instock' ) = 'outofstock' ) ASC";
		$clauses['orderby'] = '' !== trim( (string) ( $clauses['orderby'] ?? '' ) )
			? $stock . ', ' . $clauses['orderby']
			: $stock . ", {$wpdb->posts}.menu_order ASC, {$wpdb->posts}.post_title ASC";
		return $clauses;
	}
}
