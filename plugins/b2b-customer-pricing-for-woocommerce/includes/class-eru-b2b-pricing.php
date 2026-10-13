<?php
/**
 * B2B price resolution and price filters.
 *
 * Priority 1001 on purpose: it runs after Role-Based Prices (999) and Price Lists (1000),
 * so a customer-specific rule wins over a price list, which wins over a role price.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_B2B_Pricing {

	const PRIORITY = 1001;

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
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
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
		 * Filters the user whose B2B prices apply to this request (0 = none).
		 *
		 * @param int $uid User ID.
		 */
		return (int) apply_filters( 'eru_b2b_pricing_user_id', $uid );
	}

	/** A price set at runtime (gift line, bundle child…) wins over every price rule. */
	private static function has_runtime_price( $product ): bool {
		return $product instanceof WC_Product && array_key_exists( 'price', $product->get_changes() );
	}

	/** Rules that apply to a user: [ [scope_rank, rule], ... ]. */
	private static function user_rules( int $user_id ): array {
		$group = Eru_B2B_Store::user_group( $user_id );
		$out   = array();
		foreach ( Eru_B2B_Store::rules() as $rule ) {
			if ( 'user' === $rule['scope'] && (int) $rule['target'] === $user_id ) {
				$out[] = $rule;
			} elseif ( 'group' === $rule['scope'] && '' !== $group && (string) $rule['target'] === $group ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/** Category ids of a product including all ancestors. */
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

	/** Customer price for the product, or null when no rule applies to the current user. */
	public static function customer_price( $product ): ?float {
		$uid = self::pricing_user_id();
		if ( ! $product instanceof WC_Product || $uid < 1 ) {
			return null;
		}
		$key = $uid . ':' . $product->get_id();
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		$rules = self::user_rules( $uid );
		$price = null;
		if ( $rules ) {
			$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
			$cats      = null;
			$base      = $product->get_regular_price( 'edit' ); // 'edit' context: ignores other price filters, so the result is stable.
			$best      = null; // [rank, price].
			// A percentage never makes the price higher than an active sale price.
			$on_sale   = $product->is_on_sale( 'edit' ) && is_numeric( $product->get_sale_price( 'edit' ) ) ? (float) $product->get_sale_price( 'edit' ) : null;
			foreach ( $rules as $rule ) {
				$type = $rule['type'];
				if ( 'product' === $type ) {
					if ( (int) $rule['object'] !== $product->get_id() && (int) $rule['object'] !== $parent_id ) {
						continue;
					}
				} elseif ( 'category' === $type ) {
					$cats ??= self::category_ids( $parent_id );
					if ( ! in_array( (int) $rule['object'], $cats, true ) ) {
						continue;
					}
				}
				if ( 'price' === $rule['mode'] ) {
					$candidate = (float) $rule['value'];
				} else {
					if ( '' === $base || ! is_numeric( $base ) ) {
						continue;
					}
					$candidate = (float) $base * ( 1 - (float) $rule['value'] / 100 );
					if ( null !== $on_sale ) {
						$candidate = min( $candidate, $on_sale );
					}
				}
				$candidate = round( max( 0, $candidate ), wc_get_price_decimals() );
				// Specificity: a customer rule beats a group rule; product beats category beats all.
				$rank = ( 'user' === $rule['scope'] ? 10 : 0 ) + array( 'all' => 1, 'category' => 2, 'product' => 3 )[ $type ];
				if ( null === $best || $rank > $best[0] || ( $rank === $best[0] && $candidate < $best[1] ) ) {
					$best = array( $rank, $candidate );
				}
			}
			$price = $best ? $best[1] : null;
		}

		/**
		 * Filters the customer price.
		 *
		 * @param float|null $price   Price or null when no rule applies.
		 * @param WC_Product $product Product.
		 * @param int        $user_id Current user.
		 */
		$price = apply_filters( 'eru_b2b_price', $price, $product, $uid );
		self::$cache[ $key ] = null === $price ? null : (float) $price;
		return self::$cache[ $key ];
	}

	public static function price( $value, $product ) {
		if ( self::has_runtime_price( $product ) ) {
			return $value;
		}
		$p = self::customer_price( $product );
		return null === $p ? $value : (string) $p;
	}

	public static function sale_price( $value, $product ) {
		return null === self::customer_price( $product ) ? $value : '';
	}

	public static function prices_hash( $hash ) {
		$hash   = is_array( $hash ) ? $hash : array();
		$uid    = self::pricing_user_id();
		$hash[] = 'eru_b2b:' . $uid . ':' . Eru_B2B_Store::version();
		return $hash;
	}

	public static function price_html( $html, $product ) {
		if ( ! $product instanceof WC_Product || self::pricing_user_id() < 1 ) {
			return $html;
		}
		$label = Eru_B2B_Store::label();
		if ( '' === $label ) {
			return $html;
		}
		$probes = array( $product );
		if ( $product->is_type( 'variable' ) ) {
			// Any variation with a customer price gets the label (a product rule may name one variation only).
			$probes = array_filter( array_map( 'wc_get_product', array_slice( $product->get_children(), 0, (int) apply_filters( 'eru_b2b_label_probe_limit', 50 ) ) ) );
		}
		$hit = false;
		foreach ( $probes as $probe ) {
			if ( null !== self::customer_price( $probe ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return $html;
		}
		return $html . ' <small class="eru-b2b-label">' . esc_html( $label ) . '</small>';
	}
}
