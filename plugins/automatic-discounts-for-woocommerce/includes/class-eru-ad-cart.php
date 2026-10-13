<?php
/** Applies the matching rules as negative cart fees (recomputed on every totals calculation). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_AD_Cart {

	public static function init(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply' ), 10 );
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
		$rules = Eru_AD_Rules::get();
		if ( ! $rules ) {
			return;
		}
		/**
		 * Return false to skip all automatic discounts (e.g. when another discount source should win).
		 *
		 * @param bool    $apply Whether to apply.
		 * @param WC_Cart $cart  Cart.
		 */
		if ( ! apply_filters( 'eru_ad_apply', true, $cart ) ) {
			return;
		}

		$subtotal = (float) $cart->get_subtotal();
		$today    = current_time( 'Y-m-d' );
		$budget   = self::budget( $cart ); // Never discount more than is left to pay (other plugins' discounts included).
		$used     = array();

		usort( $rules, fn( $a, $b ) => (int) ( $a['priority'] ?? 10 ) <=> (int) ( $b['priority'] ?? 10 ) ); // Stable since PHP 8.0.

		foreach ( $rules as $rule ) {
			$rule = wp_parse_args( $rule, Eru_AD_Rules::defaults() );
			if ( '' !== $rule['start'] && $today < $rule['start'] ) {
				continue;
			}
			if ( '' !== $rule['end'] && $today > $rule['end'] ) {
				continue;
			}

			if ( 'category_qty' === $rule['type'] ) {
				$qty  = 0;
				$base = 0.0;
				foreach ( $cart->get_cart() as $item ) {
					if ( self::in_category( (int) $item['product_id'], (int) $rule['category'] ) ) {
						$qty  += (int) $item['quantity'];
						$base += (float) ( $item['line_total'] ?? $item['line_subtotal'] );
					}
				}
				if ( $qty < (int) $rule['min_qty'] ) {
					continue;
				}
			} else {
				if ( $subtotal < (float) $rule['min_subtotal'] ) {
					continue;
				}
				$base = (float) $cart->get_cart_contents_total();
			}
			if ( $base <= 0 ) {
				continue;
			}

			$amount = 'percent' === $rule['mode'] ? $base * (float) $rule['value'] / 100 : min( (float) $rule['value'], $base );
			$amount = min( $amount, $budget );
			$amount = round( $amount, wc_get_price_decimals() );
			/**
			 * Filters the discount amount (positive number) of one rule. Return 0 to skip it.
			 *
			 * @param float   $amount Discount.
			 * @param array   $rule   Rule.
			 * @param WC_Cart $cart   Cart.
			 */
			$amount = (float) apply_filters( 'eru_ad_discount_amount', $amount, $rule, $cart );
			if ( $amount <= 0 ) {
				continue;
			}

			$label = '' !== $rule['label'] ? $rule['label'] : __( 'Otomatik indirim', 'automatic-discounts-for-woocommerce' );
			$name  = $label;
			for ( $n = 2; isset( $used[ $name ] ); $n++ ) {
				$name = $label . ' (' . $n . ')';
			}
			$used[ $name ] = true;
			$cart->add_fee( $name, -$amount, ! empty( $rule['taxable'] ) );
			$budget -= $amount;

			if ( ! empty( $rule['stop'] ) ) {
				break;
			}
		}
	}

	/** True if the product is in the category or one of its descendants. */
	private static function in_category( int $product_id, int $category_id ): bool {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $term_id ) {
			if ( (int) $term_id === $category_id || in_array( $category_id, array_map( 'intval', get_ancestors( (int) $term_id, 'product_cat', 'taxonomy' ) ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
