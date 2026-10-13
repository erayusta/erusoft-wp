<?php
/** Adds the discount / surcharge as a cart fee for the payment method chosen at checkout. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PMD_Cart {

	public static function init(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue(): void {
		if ( empty( Eru_PMD_Rules::get() ) || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'eru-pmd-checkout', ERU_PMD_URL . 'assets/checkout.js', array( 'jquery' ), ERU_PMD_VERSION, true );
	}

	/**
	 * What is left to pay: cart contents minus discounts (negative fees) other plugins already added,
	 * so stacked discounts from several plugins never push the total below zero.
	 */
	private static function budget( WC_Cart $cart ): float {
		$left = (float) $cart->get_cart_contents_total();
		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( isset( $fee->amount ) && (float) $fee->amount < 0 ) {
				$left += (float) $fee->amount;
			}
		}
		return max( 0.0, $left );
	}

	public static function apply( $cart ): void {
		if ( ! $cart instanceof WC_Cart || $cart->is_empty() ) {
			return;
		}
		$rules = Eru_PMD_Rules::get();
		if ( ! $rules || ! WC()->session ) {
			return;
		}
		$chosen = (string) WC()->session->get( 'chosen_payment_method' );
		if ( '' === $chosen ) {
			return;
		}
		$subtotal = (float) $cart->get_subtotal();

		foreach ( $rules as $rule ) {
			if ( $rule['gateway'] !== $chosen ) {
				continue;
			}
			if ( ! empty( $rule['min_cart'] ) && $subtotal < (float) $rule['min_cart'] ) {
				continue;
			}
			$base = self::matching_total( $cart, (array) ( $rule['exclude_categories'] ?? array() ) );
			if ( $base <= 0 ) {
				continue;
			}
			$amount = 'percent' === $rule['mode'] ? $base * (float) $rule['value'] / 100 : (float) $rule['value'];
			if ( 'discount' === $rule['type'] ) {
				$amount = -min( $amount, $base, self::budget( $cart ) );
			}
			$amount = round( $amount, wc_get_price_decimals() );
			if ( 0.0 === $amount ) {
				continue;
			}
			$label = '' !== $rule['label'] ? $rule['label'] : ( 'discount' === $rule['type'] ? __( 'Ödeme yöntemi indirimi', 'payment-method-discounts-for-woocommerce' ) : __( 'Ödeme yöntemi ek ücreti', 'payment-method-discounts-for-woocommerce' ) );
			/**
			 * Filters the fee amount before it is added. Return 0 to skip.
			 *
			 * @param float  $amount Signed amount (negative = discount).
			 * @param array  $rule   Matching rule.
			 * @param WC_Cart $cart  Cart.
			 */
			$amount = (float) apply_filters( 'eru_pmd_fee_amount', $amount, $rule, $cart );
			if ( 0.0 !== $amount ) {
				$cart->add_fee( $label, $amount, ! empty( $rule['taxable'] ) );
			}
			return; // First matching rule wins.
		}
	}

	/** Post-coupon, ex-tax total of lines whose product is not in an excluded category (children included). */
	private static function matching_total( WC_Cart $cart, array $excluded ): float {
		$total = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			if ( $excluded && self::in_categories( (int) $item['product_id'], $excluded ) ) {
				continue;
			}
			$total += isset( $item['line_total'] ) ? (float) $item['line_total'] : (float) $item['line_subtotal'];
		}
		return $total;
	}

	private static function in_categories( int $product_id, array $category_ids ): bool {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term_id ) {
			if ( in_array( (int) $term_id, $category_ids, true ) ) {
				return true;
			}
			foreach ( get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) as $parent ) {
				if ( in_array( (int) $parent, $category_ids, true ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
