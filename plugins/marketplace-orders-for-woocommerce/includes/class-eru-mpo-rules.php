<?php
/**
 * Stock bridge rules per marketplace (eru_mpo_mp_<key>[rules]): exclusions, stock floor and ceiling, quantity for
 * products without stock tracking, price multiplier, fixed amount and rounding.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keys (all sanitised by sanitize()):
 *
 * - excluded_cats     int[]    product categories; a product in one of them or in a child category is not pushed
 * - excluded_brands   int[]    brand terms (product_brand, or the taxonomy from the eru_mpo_brand_taxonomy filter)
 * - excluded_skus     string[] one SKU per line; "ABC*" matches every SKU that starts with ABC (case-insensitive);
 *                              a parent's SKU excludes its variations
 * - stock_floor       int      below this quantity 0 is sent (0 = off)
 * - stock_ceiling     int      at most this quantity is sent (0 = off)
 * - untracked_qty     int      quantity sent for an in-stock product without stock tracking (default 5)
 * - price_multiplier  string   decimal, 0.0001–100 (default 1)
 * - price_add         string   fixed amount added after the multiplier, may be negative (default 0)
 * - rounding          none | 90 | 99   price rounded to the nearest ,90 / ,99 ending
 *
 * Money is handled in minor units (kuruş) and rounded once: multiplier (half up), then the fixed amount, then the
 * rounding. An unpublished product (or a variation of one) is pushed with stock 0.
 */
class Eru_MPO_Rules {

	const ROUNDING = array( 'none', '90', '99' );

	/** @return array Defaults. */
	public static function defaults() {
		return array(
			'excluded_cats'    => array(),
			'excluded_brands'  => array(),
			'excluded_skus'    => array(),
			'stock_floor'      => 0,
			'stock_ceiling'    => 0,
			'untracked_qty'    => 5,
			'price_multiplier' => '1',
			'price_add'        => '0',
			'rounding'         => 'none',
		);
	}

	/**
	 * @param string $s Decimal typed by a person ("1,15", " 5 ").
	 * @return string|null Normalised decimal ("1.15") or null when not a number.
	 */
	private static function decimal( $s, $places ) {
		$s = str_replace( ',', '.', trim( (string) $s ) );
		if ( '' === $s || ! preg_match( '/^-?\d+(\.\d+)?$/', $s ) ) {
			return null;
		}
		$v = number_format( (float) $s, $places, '.', '' );
		return rtrim( rtrim( $v, '0' ), '.' ) ?: '0';
	}

	/** @param mixed $v List or text. @return int[] Positive unique ids. */
	private static function ids( $v ) {
		$v = is_array( $v ) ? $v : array_filter( explode( ',', (string) $v ) );
		return array_values( array_unique( array_filter( array_map( 'absint', $v ) ) ) );
	}

	/** @param mixed $v Lines or list. @return string[] */
	public static function parse_skus( $v ) {
		$lines = is_array( $v ) ? $v : preg_split( '/[\r\n,]+/', (string) $v );
		$out   = array();
		foreach ( (array) $lines as $l ) {
			$l = trim( sanitize_text_field( (string) $l ) );
			if ( '' !== $l && ! in_array( $l, $out, true ) ) {
				$out[] = substr( $l, 0, 100 );
			}
			if ( count( $out ) >= 1000 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param array $in Raw values (unknown keys dropped, missing keys take the defaults).
	 * @return array Clean rules.
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array(
			'excluded_cats'   => self::ids( $in['excluded_cats'] ?? array() ),
			'excluded_brands' => self::ids( $in['excluded_brands'] ?? array() ),
			'excluded_skus'   => self::parse_skus( $in['excluded_skus'] ?? array() ),
		);
		foreach ( array( 'stock_floor', 'stock_ceiling', 'untracked_qty' ) as $k ) {
			$v         = isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? (int) $in[ $k ] : $d[ $k ];
			$out[ $k ] = max( 0, min( 1000000, $v ) );
		}
		$m                       = self::decimal( $in['price_multiplier'] ?? '', 4 );
		$out['price_multiplier'] = ( null !== $m && (float) $m > 0 && (float) $m <= 100 ) ? $m : $d['price_multiplier'];
		$a                       = self::decimal( $in['price_add'] ?? '', 2 );
		$out['price_add']        = ( null !== $a && abs( (float) $a ) <= 1000000 ) ? $a : $d['price_add'];
		$r                       = (string) ( $in['rounding'] ?? '' );
		$out['rounding']         = in_array( $r, self::ROUNDING, true ) ? $r : $d['rounding'];
		return $out;
	}

	/** @param string $mp Marketplace. @return array Its rules. */
	public static function get( $mp ) {
		$r = Eru_MPO_Settings::mp_get( $mp, 'rules', array() );
		return self::sanitize( is_array( $r ) ? $r : array() );
	}

	/**
	 * Merges and saves the rules of a marketplace.
	 *
	 * @param string $mp Marketplace.
	 * @param array  $in Values.
	 */
	public static function update( $mp, array $in ) {
		Eru_MPO_Settings::mp_update( $mp, array( 'rules' => self::sanitize( array_merge( self::get( $mp ), $in ) ) ) );
	}

	/** @param int $qty Quantity. @param array $rules Rules. @return int Quantity to send (floor → 0, ceiling caps). */
	public static function stock_qty( $qty, array $rules ) {
		$qty = max( 0, (int) $qty );
		if ( (int) $rules['stock_floor'] > 0 && $qty < (int) $rules['stock_floor'] ) {
			return 0;
		}
		if ( (int) $rules['stock_ceiling'] > 0 && $qty > (int) $rules['stock_ceiling'] ) {
			return (int) $rules['stock_ceiling'];
		}
		return $qty;
	}

	/**
	 * Rounds to the nearest price ending in ,90 or ,99 (a tie goes up).
	 *
	 * @param int    $minor Minor units.
	 * @param string $mode  none | 90 | 99.
	 * @return int
	 */
	public static function round_minor( $minor, $mode ) {
		$minor = (int) $minor;
		if ( ! in_array( (string) $mode, array( '90', '99' ), true ) || $minor <= 0 ) {
			return $minor;
		}
		$end  = (int) $mode;
		$base = intdiv( $minor, 100 ) * 100 + $end;
		$best = null;
		foreach ( array( $base - 100, $base, $base + 100 ) as $c ) {
			if ( $c <= 0 ) {
				continue;
			}
			if ( null === $best || abs( $c - $minor ) < abs( $best - $minor ) || ( abs( $c - $minor ) === abs( $best - $minor ) && $c > $best ) ) {
				$best = $c;
			}
		}
		return (int) $best;
	}

	/**
	 * Price after the rules, in minor units: multiplier (half up), fixed amount, rounding.
	 *
	 * @param int   $minor Price in minor units.
	 * @param array $rules Rules.
	 * @return int (≤ 0 = do not send)
	 */
	public static function price_minor( $minor, array $rules ) {
		$minor = (int) $minor;
		if ( $minor <= 0 ) {
			return 0;
		}
		$scaled = (int) round( (float) $rules['price_multiplier'] * 10000 );
		$v      = intdiv( $minor * $scaled + 5000, 10000 );
		$v     += Eru_MPO_Builder::minor( $rules['price_add'] );
		return $v > 0 ? self::round_minor( $v, (string) $rules['rounding'] ) : 0;
	}

	/** @return string Brand taxonomy ('' = none on this site). */
	public static function brand_taxonomy() {
		$tax = '';
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $t ) {
			if ( taxonomy_exists( $t ) ) {
				$tax = $t;
				break;
			}
		}
		$tax = (string) apply_filters( 'eru_mpo_brand_taxonomy', $tax );
		return '' !== $tax && taxonomy_exists( $tax ) ? $tax : '';
	}

	/** @param int $id Product. @param string $tax Taxonomy. @return int[] Its terms and their ancestors. */
	private static function terms_with_parents( $id, $tax ) {
		$terms = wp_get_post_terms( $id, $tax, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$all = array_map( 'intval', $terms );
		foreach ( $terms as $tid ) {
			$all = array_merge( $all, array_map( 'intval', get_ancestors( (int) $tid, $tax, 'taxonomy' ) ) );
		}
		return array_values( array_unique( $all ) );
	}

	/**
	 * @param WC_Product $product Product or variation.
	 * @param array      $rules   Rules.
	 * @return bool Not pushed to this marketplace.
	 */
	public static function is_excluded( $product, array $rules ) {
		// A variation is also excluded by its parent's SKU.
		$skus = array_filter( array( (string) $product->get_sku(), $product->get_parent_id() ? (string) get_post_meta( $product->get_parent_id(), '_sku', true ) : '' ) );
		foreach ( (array) $rules['excluded_skus'] as $pat ) {
			$pat = strtolower( (string) $pat );
			foreach ( $skus as $s ) {
				$s = strtolower( $s );
				if ( str_ends_with( $pat, '*' ) ? 0 === strpos( $s, substr( $pat, 0, -1 ) ) : $s === $pat ) {
					return true;
				}
			}
		}
		$id = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
		if ( $rules['excluded_cats'] && array_intersect( $rules['excluded_cats'], self::terms_with_parents( $id, 'product_cat' ) ) ) {
			return true;
		}
		$brand = $rules['excluded_brands'] ? self::brand_taxonomy() : '';
		return '' !== $brand && (bool) array_intersect( $rules['excluded_brands'], self::terms_with_parents( $id, $brand ) );
	}

	/** @param WC_Product $product Product. @return bool Published (a variation: it and its parent). */
	private static function published( $product ) {
		if ( 'publish' !== $product->get_status() ) {
			return false;
		}
		if ( $product->get_parent_id() ) {
			return 'publish' === get_post_status( $product->get_parent_id() );
		}
		return true;
	}

	/**
	 * Quantity to send for a product.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param array      $rules   Rules.
	 * @return int
	 */
	public static function stock_for( $product, array $rules ) {
		if ( ! self::published( $product ) ) {
			return 0;
		}
		if ( $product->managing_stock() ) {
			$qty = (int) $product->get_stock_quantity();
		} else {
			$qty = 'outofstock' === $product->get_stock_status() ? 0 : (int) $rules['untracked_qty'];
		}
		return self::stock_qty( $qty, $rules );
	}

	/**
	 * Prices to send: the active price and the list price (regular price, never below the active one).
	 *
	 * @param WC_Product $product Product or variation.
	 * @param array      $rules   Rules.
	 * @return array{price: float, list_price: float}|null Null when the product has no price.
	 */
	public static function prices_for( $product, array $rules ) {
		$active = (string) $product->get_price();
		if ( '' === $active ) {
			return null;
		}
		$regular = (string) $product->get_regular_price();
		$price   = self::price_minor( Eru_MPO_Builder::minor( $active ), $rules );
		if ( $price <= 0 ) {
			return null;
		}
		$list = '' !== $regular ? self::price_minor( Eru_MPO_Builder::minor( $regular ), $rules ) : $price;
		return array(
			'price'      => round( $price / 100, 2 ),
			'list_price' => round( max( $price, $list ) / 100, 2 ),
		);
	}
}
