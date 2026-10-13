<?php
/** Role price lookup and price filters. Option `eru_rbp_roles` (default: none = inert). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_RBP_Prices {

	const OPTION = 'eru_rbp_roles';
	const META   = '_eru_rbp_price_';

	public static function init(): void {
		foreach ( array( 'woocommerce_product_get_', 'woocommerce_product_variation_get_' ) as $prefix ) {
			add_filter( $prefix . 'price', array( __CLASS__, 'price' ), 999, 2 );
			add_filter( $prefix . 'regular_price', array( __CLASS__, 'price' ), 999, 2 );
			add_filter( $prefix . 'sale_price', array( __CLASS__, 'sale_price' ), 999, 2 );
		}
		add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'price' ), 999, 2 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'price' ), 999, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( __CLASS__, 'sale_price' ), 999, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'prices_hash' ) );
	}

	/** @return string[] Role slugs that may get a custom price. */
	public static function roles(): array {
		$roles = get_option( self::OPTION, array() );
		return is_array( $roles ) ? array_values( array_filter( array_map( 'sanitize_key', $roles ) ) ) : array();
	}

	/** Keep only roles that exist. */
	public static function sanitize_roles( $raw ): array {
		$valid = array_keys( wp_roles()->get_names() );
		$out   = array();
		foreach ( (array) $raw as $role ) {
			$role = sanitize_key( (string) $role );
			if ( '' !== $role && in_array( $role, $valid, true ) && ! in_array( $role, $out, true ) ) {
				$out[] = $role;
			}
		}
		return $out;
	}

	/** Selected roles the pricing user (see pricing_user_id()) holds. */
	public static function user_roles(): array {
		$uid  = self::pricing_user_id();
		$user = $uid > 0 ? get_userdata( $uid ) : false;
		if ( ! $user ) {
			return array();
		}
		return array_values( array_intersect( self::roles(), (array) $user->roles ) );
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
		 * Filters the user whose RBP prices apply to this request (0 = none).
		 *
		 * @param int $uid User ID.
		 */
		return (int) apply_filters( 'eru_rbp_pricing_user_id', $uid );
	}

	/** A price set at runtime (gift line, bundle child…) wins over every price rule. */
	private static function has_runtime_price( $product ): bool {
		return $product instanceof WC_Product && array_key_exists( 'price', $product->get_changes() );
	}


	/** Role price for the product, or null when there is none for the current user. */
	public static function role_price( $product ): ?float {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$best = null;
		foreach ( self::user_roles() as $role ) {
			$raw = $product->get_meta( self::META . $role, true, 'edit' );
			if ( '' === $raw || ! is_numeric( $raw ) || (float) $raw < 0 ) {
				continue;
			}
			// Several matching roles: the lowest price wins.
			$best = null === $best ? (float) $raw : min( $best, (float) $raw );
		}
		/**
		 * Filters the role price.
		 *
		 * @param float|null $best    Role price or null.
		 * @param WC_Product $product Product.
		 * @param string[]   $roles   Matching roles of the current user.
		 */
		return apply_filters( 'eru_rbp_price', $best, $product, self::user_roles() );
	}

	public static function price( $value, $product ) {
		if ( self::has_runtime_price( $product ) ) {
			return $value;
		}
		$role_price = self::role_price( $product );
		return null === $role_price ? $value : (string) $role_price;
	}

	/** A role price replaces the whole price, so no sale price is shown for it. */
	public static function sale_price( $value, $product ) {
		return null === self::role_price( $product ) ? $value : '';
	}

	public static function prices_hash( $hash ) {
		$hash   = is_array( $hash ) ? $hash : array();
		$roles  = self::user_roles();
		$hash[] = 'eru_rbp:' . implode( ',', $roles );
		return $hash;
	}
}
