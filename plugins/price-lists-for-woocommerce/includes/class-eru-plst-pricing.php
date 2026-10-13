<?php
/**
 * Price list price resolution and price filters.
 *
 * Priority 1000: runs after Role-Based Prices (999) and before B2B Customer Pricing (1001),
 * so a price list beats a role price and a customer-specific rule beats a price list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PLST_Pricing {

	const PRIORITY = 1000;

	private static array $cache = array();

	public static function init(): void {
		foreach ( array( 'woocommerce_product_get_', 'woocommerce_product_variation_get_' ) as $prefix ) {
			add_filter( $prefix . 'price', array( __CLASS__, 'price' ), self::PRIORITY, 2 );
			add_filter( $prefix . 'regular_price', array( __CLASS__, 'price' ), self::PRIORITY, 2 );
			add_filter( $prefix . 'sale_price', array( __CLASS__, 'sale_price' ), self::PRIORITY, 2 );
		}
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'sale_price' ), self::PRIORITY, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'prices_hash' ) );
	}

	public static function flush(): void {
		self::$cache = array();
	}

	/**
	 * Whose prices apply. Storefront: the shopper. wp-admin: nobody's (an admin's own rules never
	 * price products there), except WooCommerce order-item AJAX, which prices for the order's customer.
	 * Admin-ajax calls coming from the storefront keep the shopper.
	 */
	public static function pricing_user_id(): int {
		$uid = get_current_user_id();
		if ( is_admin() ) {
			// phpcs:disable WordPress.Security.NonceVerification -- context detection only.
			$action   = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
			// phpcs:enable
			if ( wp_doing_ajax() && $order_id && str_starts_with( $action, 'woocommerce_' ) ) {
				$order = wc_get_order( $order_id );
				$uid   = $order ? (int) $order->get_customer_id() : 0;
			} elseif ( ! wp_doing_ajax() || str_starts_with( (string) wp_get_referer(), admin_url() ) ) {
				$uid = 0;
			}
		}
		/**
		 * Filters the user whose PLST prices apply to this request (0 = none).
		 *
		 * @param int $uid User ID.
		 */
		return (int) apply_filters( 'eru_plst_pricing_user_id', $uid );
	}

	/** A price set at runtime (gift line, bundle child…) wins over every price rule. */
	private static function has_runtime_price( $product ): bool {
		return $product instanceof WC_Product && array_key_exists( 'price', $product->get_changes() );
	}

	private static function category_ids( int $product_id ): array {
		$ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return array();
		}
		$all = array_map( 'intval', $ids );
		foreach ( $ids as $id ) {
			$all = array_merge( $all, array_map( 'intval', get_ancestors( (int) $id, 'product_cat', 'taxonomy' ) ) );
		}
		return array_unique( $all );
	}

	/** Price of one product from one list, or null when the list says nothing about it. */
	private static function list_price( array $list, WC_Product $product ): ?float {
		$id        = $product->get_id();
		$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $id;
		$decimals  = wc_get_price_decimals();

		// 1. Fixed price (variation first, then the parent product).
		foreach ( array( $id, $parent_id ) as $key ) {
			if ( isset( $list['prices'][ $key ] ) && is_numeric( $list['prices'][ $key ] ) ) {
				return round( max( 0, (float) $list['prices'][ $key ] ), $decimals );
			}
		}

		$base = $product->get_regular_price( 'edit' ); // 'edit' context: ignores other price filters, so the result is stable.
		if ( '' === $base || ! is_numeric( $base ) ) {
			return null;
		}

		// 2. Category percentage (the highest discount among the product's categories).
		$percent = null;
		if ( $list['cats'] ) {
			foreach ( self::category_ids( $parent_id ) as $cat ) {
				if ( isset( $list['cats'][ $cat ] ) && is_numeric( $list['cats'][ $cat ] ) ) {
					$percent = null === $percent ? (float) $list['cats'][ $cat ] : max( $percent, (float) $list['cats'][ $cat ] );
				}
			}
		}
		// 3. Catalogue-wide percentage.
		if ( null === $percent && $list['percent'] > 0 ) {
			$percent = $list['percent'];
		}
		if ( null === $percent ) {
			return null;
		}
		$percent = min( 100, max( 0, $percent ) );
		$price   = (float) $base * ( 1 - $percent / 100 );
		// A percentage never makes the price higher than an active sale price.
		if ( $product->is_on_sale( 'edit' ) && is_numeric( $product->get_sale_price( 'edit' ) ) ) {
			$price = min( $price, (float) $product->get_sale_price( 'edit' ) );
		}
		return round( max( 0, $price ), $decimals );
	}

	/** Price-list price for the current user, or null when no list applies. */
	public static function list_price_for_current_user( $product ): ?float {
		$uid = self::pricing_user_id();
		if ( ! $product instanceof WC_Product || $uid < 1 ) {
			return null;
		}
		$key = $uid . ':' . $product->get_id();
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}
		$price = null;
		foreach ( Eru_PLST_Lists::for_user( $uid ) as $list ) {
			$price = self::list_price( $list, $product );
			if ( null !== $price ) {
				break;
			}
		}
		/**
		 * Filters the price-list price.
		 *
		 * @param float|null $price   Price or null when no list applies.
		 * @param WC_Product $product Product.
		 * @param int        $user_id Current user.
		 */
		$price = apply_filters( 'eru_plst_price', $price, $product, $uid );
		self::$cache[ $key ] = null === $price ? null : (float) $price;
		return self::$cache[ $key ];
	}

	public static function price( $value, $product ) {
		if ( self::has_runtime_price( $product ) ) {
			return $value;
		}
		$p = self::list_price_for_current_user( $product );
		return null === $p ? $value : (string) $p;
	}

	public static function sale_price( $value, $product ) {
		return null === self::list_price_for_current_user( $product ) ? $value : '';
	}

	public static function prices_hash( $hash ) {
		$hash = is_array( $hash ) ? $hash : array();
		$uid  = self::pricing_user_id();
		$ids  = $uid > 0 ? wp_list_pluck( Eru_PLST_Lists::for_user( $uid ), 'id' ) : array();
		$hash[] = 'eru_plst:' . implode( ',', $ids ) . ':' . Eru_PLST_Lists::version();
		return $hash;
	}
}
