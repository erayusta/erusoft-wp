<?php
/** Applies the set discounts as negative cart fees (recomputed on every totals calculation). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BTD_Cart {

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
		if ( ! $cart instanceof WC_Cart || $cart->is_empty() || ! get_option( Eru_BTD_Sets::OPTION ) ) {
			return;
		}
		/** Return false to skip bought-together discounts (e.g. when another discount source should win). */
		if ( ! apply_filters( 'eru_btd_apply', true, $cart ) ) {
			return;
		}
		$budget = self::budget( $cart );
		$used   = array();
		foreach ( Eru_BTD_Sets::match( $cart ) as $hit ) {
			$amount = min( (float) $hit['discount'], $budget );
			/** Filters the set discount (positive). Return 0 to skip. */
			$amount = (float) apply_filters( 'eru_btd_discount_amount', $amount, $hit, $cart );
			if ( $amount <= 0 ) {
				continue;
			}
			$label = '' !== $hit['set']['label'] ? $hit['set']['label'] : __( 'Birlikte alma indirimi', 'bought-together-discounts-for-woocommerce' );
			$name  = $label;
			for ( $n = 2; isset( $used[ $name ] ); $n++ ) {
				$name = $label . ' (' . $n . ')';
			}
			$used[ $name ] = true;
			$cart->add_fee( $name, -$amount, false );
			$budget -= $amount;
		}
	}
}
