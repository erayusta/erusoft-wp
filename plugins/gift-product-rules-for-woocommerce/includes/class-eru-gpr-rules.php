<?php
/** Rule storage, sanitising and matching. Rules live in the `eru_gpr_rules` option (default: none). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_GPR_Rules {

	const OPTION = 'eru_gpr_rules';

	/** @return array<int,array<string,mixed>> Rules keyed by a stable rule id. */
	public static function get(): array {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	public static function save( array $rules ): void {
		update_option( self::OPTION, $rules, false );
	}

	/**
	 * Validate raw (already unslashed) rows. Rows without a valid gift product are dropped.
	 *
	 * @param mixed $raw Posted rows.
	 * @return array<string,array<string,mixed>>
	 */
	public static function sanitize( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		$n = 0;
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$gift_id = isset( $row['gift_id'] ) ? absint( $row['gift_id'] ) : 0;
			$gift    = $gift_id ? wc_get_product( $gift_id ) : false;
			if ( ! $gift || $gift->is_type( 'variable' ) || $gift->is_type( 'grouped' ) || $gift->is_type( 'external' ) ) {
				continue;
			}
			$trigger = isset( $row['trigger'] ) && in_array( $row['trigger'], array( 'product', 'category' ), true ) ? $row['trigger'] : 'cart_total';
			$ids     = isset( $row['product_ids'] ) ? preg_split( '/[\s,]+/', (string) $row['product_ids'], -1, PREG_SPLIT_NO_EMPTY ) : array();
			$id      = isset( $row['id'] ) && preg_match( '/^[a-z0-9]{4,16}$/', (string) $row['id'] ) ? (string) $row['id'] : 'r' . substr( md5( uniqid( '', true ) ), 0, 8 );
			$out[ $id ] = array(
				'enabled'      => ! empty( $row['enabled'] ) ? 1 : 0,
				'label'        => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'trigger'      => $trigger,
				'min_total'    => isset( $row['min_total'] ) ? max( 0.0, (float) wc_format_decimal( (string) $row['min_total'] ) ) : 0.0,
				'product_ids'  => array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ),
				'category_ids' => isset( $row['category_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $row['category_ids'] ) ) ) : array(),
				'gift_id'      => $gift_id,
				'qty'          => isset( $row['qty'] ) ? max( 1, min( 99, absint( $row['qty'] ) ) ) : 1,
			);
			if ( ++$n >= 50 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Does a rule match the given non-gift cart lines?
	 *
	 * @param array $rule  Rule.
	 * @param array $lines Cart items (without gift lines).
	 * @param float $total Sum of the lines' price x quantity.
	 */
	public static function matches( array $rule, array $lines, float $total ): bool {
		if ( empty( $rule['enabled'] ) || ! $lines ) {
			return false;
		}
		if ( $total + 0.00001 < (float) ( $rule['min_total'] ?? 0 ) ) {
			return false;
		}
		switch ( $rule['trigger'] ?? 'cart_total' ) {
			case 'product':
				$ids = (array) ( $rule['product_ids'] ?? array() );
				if ( ! $ids ) {
					return false;
				}
				foreach ( $lines as $item ) {
					if ( in_array( (int) $item['product_id'], $ids, true ) || ( ! empty( $item['variation_id'] ) && in_array( (int) $item['variation_id'], $ids, true ) ) ) {
						return true;
					}
				}
				return false;
			case 'category':
				$cats = array_map( 'intval', (array) ( $rule['category_ids'] ?? array() ) );
				if ( ! $cats ) {
					return false;
				}
				foreach ( $lines as $item ) {
					$terms = wc_get_product_term_ids( (int) $item['product_id'], 'product_cat' );
					foreach ( $terms as $term_id ) {
						$chain = array_merge( array( (int) $term_id ), array_map( 'intval', get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ) );
						if ( array_intersect( $chain, $cats ) ) {
							return true;
						}
					}
				}
				return false;
			default:
				// Cart total rule: needs a positive threshold, otherwise it would match any cart.
				return (float) ( $rule['min_total'] ?? 0 ) > 0;
		}
	}
}
