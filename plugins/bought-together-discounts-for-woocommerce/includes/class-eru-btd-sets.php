<?php
/** Set storage, sanitising and cart matching. Sets live in the `eru_btd_sets` option (default: none). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BTD_Sets {

	const OPTION = 'eru_btd_sets';
	const TD     = 'bought-together-discounts-for-woocommerce';

	/** @return array<int,array<string,mixed>> */
	public static function get( bool $only_active = false ): array {
		$sets = get_option( self::OPTION, array() );
		$sets = is_array( $sets ) ? array_values( $sets ) : array();
		foreach ( $sets as $i => $set ) {
			$sets[ $i ] = wp_parse_args( $set, self::defaults() );
			// Stable id saved with the set (sets saved before it existed fall back to their position).
			$sets[ $i ]['id'] = '' !== (string) $sets[ $i ]['uid'] ? (string) $sets[ $i ]['uid'] : 's' . $i;
		}
		return $only_active ? array_values( array_filter( $sets, fn( $s ) => ! empty( $s['active'] ) && count( (array) $s['products'] ) > 1 ) ) : $sets;
	}

	public static function defaults(): array {
		return array(
			'uid'      => '',
			'name'     => '',
			'products' => array(),
			'mode'     => 'percent',
			'value'    => '',
			'label'    => '',
			'heading'  => '',
			'active'   => 1,
		);
	}

	/** @param mixed $raw Posted (unslashed) rows. */
	public static function sanitize( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$value = isset( $row['value'] ) ? (float) wc_format_decimal( (string) $row['value'] ) : 0.0;
			$ids   = array();
			foreach ( (array) ( $row['products'] ?? array() ) as $pid ) {
				$product = wc_get_product( absint( $pid ) );
				// A variable parent cannot be bought without choosing a variation: only simple products and variations.
				if ( $product && ! $product->is_type( 'variable' ) ) {
					$ids[] = $product->get_id();
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( $value <= 0 || count( $ids ) < 2 ) {
				continue;
			}
			$mode  = isset( $row['mode'] ) && 'fixed' === $row['mode'] ? 'fixed' : 'percent';
			$uid   = isset( $row['uid'] ) ? strtolower( (string) $row['uid'] ) : '';
			$out[] = array(
				'uid'      => preg_match( '/^[a-f0-9]{12}$/', $uid ) ? $uid : substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 ),
				'name'     => isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '',
				'products' => $ids,
				'mode'     => $mode,
				'value'    => 'percent' === $mode ? min( 100.0, $value ) : $value,
				'label'    => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'heading'  => isset( $row['heading'] ) ? sanitize_text_field( (string) $row['heading'] ) : '',
				'active'   => ! empty( $row['active'] ) ? 1 : 0,
			);
		}
		return $out;
	}

	public static function reward_text( array $set ): string {
		return 'percent' === $set['mode']
			? '%' . wc_format_localized_decimal( (string) $set['value'] )
			: wp_strip_all_tags( wc_price( (float) $set['value'] ) );
	}

	/**
	 * How many complete copies of each active set the cart holds, with the discount for each.
	 * Cart units are consumed per set, so one unit never earns two set discounts.
	 *
	 * @return array<int,array{set:array,count:int,base:float,discount:float}>
	 */
	public static function match( WC_Cart $cart ): array {
		$avail  = array();
		$prices = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			$qty = (int) $item['quantity'];
			if ( $qty < 1 ) {
				continue;
			}
			$avail[ $key ]  = $qty;
			$prices[ $key ] = (float) $item['line_subtotal'] / $qty;
		}
		$results = array();
		foreach ( self::get( true ) as $set ) {
			$lines = array(); // product id => cart keys.
			$count = PHP_INT_MAX;
			foreach ( $set['products'] as $pid ) {
				$keys = array();
				$have = 0;
				foreach ( $cart->get_cart() as $key => $item ) {
					$match = (int) $item['variation_id'] ? (int) $item['variation_id'] === (int) $pid : (int) $item['product_id'] === (int) $pid;
					if ( $match && ! empty( $avail[ $key ] ) ) {
						$keys[] = $key;
						$have  += $avail[ $key ];
					}
				}
				$lines[ $pid ] = $keys;
				$count         = min( $count, $have );
				if ( 0 === $count ) {
					break;
				}
			}
			if ( $count < 1 || PHP_INT_MAX === $count ) {
				continue;
			}
			$base = 0.0;
			foreach ( $lines as $keys ) {
				$need = $count;
				foreach ( $keys as $key ) {
					$take            = min( $need, $avail[ $key ] );
					$avail[ $key ]  -= $take;
					$base           += $take * $prices[ $key ];
					$need           -= $take;
					if ( 0 === $need ) {
						break;
					}
				}
			}
			$discount = 'percent' === $set['mode'] ? $base * (float) $set['value'] / 100 : min( $base, (float) $set['value'] * $count );
			$results[] = array(
				'set'      => $set,
				'count'    => $count,
				'base'     => $base,
				'discount' => round( $discount, wc_get_price_decimals() ),
			);
		}
		return $results;
	}
}
