<?php
/**
 * A row in the classic cart totals and the checkout order review: what the campaigns saved in this cart,
 * by campaign. The amounts are already inside the line prices; the row only shows them.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;
use Erusoft\CampaignEngine\Presentation\Texts;
use WC_Cart;

final class CheckoutSummary {

	public static function hooks(): void {
		add_action( 'woocommerce_cart_totals_before_order_total', array( self::class, 'render' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( self::class, 'render' ) );
	}

	public static function render(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( $cart instanceof WC_Cart ) {
			echo self::html( $cart ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	public static function html( WC_Cart $cart ): string {
		if ( ! CampaignRepository::hasActive() ) {
			return '';
		}

		$result = CartApplier::resultFor( $cart );

		if ( null === $result ) {
			return '';
		}

		$labels = array();

		foreach ( CampaignRepository::active() as $campaign ) {
			$labels[ $campaign->id ] = $campaign->label;
		}

		$parts = array();

		foreach ( $result->adjustments->campaignTotals() as $id => $minor ) {
			if ( $minor > 0 ) {
				$parts[] = ( $labels[ $id ] ?? '#' . $id ) . ': ' . Texts::money( (int) $minor );
			}
		}

		$total = $result->adjustments->totalDiscount();

		if ( $total <= 0 && ! $result->adjustments->freeShipping() ) {
			return '';
		}

		$label = __( 'Kampanyalarla kazancınız', 'campaign-engine-for-woocommerce' );
		$out   = '<tr class="eru-ce-summary"><th>' . esc_html( $label ) . '</th><td data-title="' . esc_attr( $label ) . '">';

		if ( $total > 0 ) {
			$out .= '<strong>' . esc_html( Texts::money( $total ) ) . '</strong>';
		}

		if ( $result->adjustments->freeShipping() ) {
			$out .= ( $total > 0 ? '<br>' : '' ) . esc_html__( 'Ücretsiz kargo', 'campaign-engine-for-woocommerce' );
		}

		if ( array() !== $parts ) {
			$out .= '<br><small>' . esc_html( implode( ' · ', $parts ) ) . '</small>';
		}

		$out .= '<br><small>' . esc_html__( 'İndirim ürün fiyatlarına yansıtıldı.', 'campaign-engine-for-woocommerce' ) . '</small>';

		return $out . '</td></tr>';
	}
}
