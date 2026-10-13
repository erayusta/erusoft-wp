<?php
/** Rule storage, sanitising and product/cart matching. Option `eru_prc_rules` (default: none). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PRC_Rules {

	const OPTION = 'eru_prc_rules';

	/** @return array<int,array<string,mixed>> */
	public static function get(): array {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	public static function save( array $rules ): void {
		update_option( self::OPTION, $rules );
	}

	/**
	 * Taxonomies a rule may target: those attached to products that have an admin UI.
	 *
	 * @return array<string,string> taxonomy => label
	 */
	public static function taxonomies(): array {
		$out = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
			if ( $tax->show_ui && ! in_array( $tax->name, array( 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				$out[ $tax->name ] = $tax->labels->name;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $raw Posted rows (unslashed).
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$allowed = self::taxonomies();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$terms = array();
			foreach ( (array) ( $row['terms'] ?? array() ) as $term ) {
				if ( is_string( $term ) && preg_match( '/^([a-z0-9_\-]+):(\d+)$/', $term, $m ) && ( isset( $allowed[ $m[1] ] ) || 'product_cat' === $m[1] || 'product_tag' === $m[1] ) ) {
					$terms[] = $m[1] . ':' . (int) $m[2];
				}
			}
			$gateways = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $row['gateways'] ?? array() ) ) ) ) );
			if ( ! $terms || ! $gateways ) {
				continue;
			}
			$out[] = array(
				'terms'          => array_values( array_unique( $terms ) ),
				'gateways'       => $gateways,
				'notice'         => wp_kses_post( (string) ( $row['notice'] ?? '' ) ),
				'product_notice' => wp_kses_post( (string) ( $row['product_notice'] ?? '' ) ),
			);
		}
		return $out;
	}

	/** Does the product (or its ancestors in hierarchical taxonomies) hold one of the rule's terms? */
	public static function product_matches( int $product_id, array $rule ): bool {
		$by_tax = array();
		foreach ( $rule['terms'] as $pair ) {
			list( $tax, $id )  = explode( ':', $pair );
			$by_tax[ $tax ][] = (int) $id;
		}
		foreach ( $by_tax as $tax => $wanted ) {
			$terms = wp_get_post_terms( $product_id, $tax, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_id ) {
				$chain = array( (int) $term_id );
				if ( is_taxonomy_hierarchical( $tax ) ) {
					$chain = array_merge( $chain, array_map( 'intval', get_ancestors( (int) $term_id, $tax, 'taxonomy' ) ) );
				}
				if ( array_intersect( $chain, $wanted ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Rules triggered by the current cart. */
	public static function triggered_by_cart(): array {
		$rules = self::get();
		if ( ! $rules || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}
		$hit = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			foreach ( $rules as $i => $rule ) {
				if ( ! isset( $hit[ $i ] ) && self::product_matches( (int) $item['product_id'], $rule ) ) {
					$hit[ $i ] = $rule;
				}
			}
		}
		return array_values( $hit );
	}
}
