<?php
/**
 * Classic cart box: what the campaigns already give in this cart, and at most two offers for the next
 * step ("Sepetinize 85,00 ₺ tutarında daha ürün ekleyin, kargo ücretsiz olsun.") with a progress bar
 * from the real cart figures. Offers come from the engine's OfferFinder, which only offers what the cart
 * would really get. The Cart block shows the discounted prices but not this box.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Condition\ConditionRegistry;
use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Engine\Offer\Offer;
use Erusoft\CampaignEngine\Engine\Offer\OfferFinder;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory;
use Erusoft\CampaignEngine\Presentation\Texts;
use WC_Cart;
use WC_Product;

final class SmartCart {

	public static function hooks(): void {
		add_action( 'woocommerce_before_cart', array( self::class, 'render' ) );
	}

	public static function render(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( $cart instanceof WC_Cart ) {
			echo self::html( $cart ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	public static function html( WC_Cart $cart ): string {
		if ( $cart->is_empty() || ! CampaignRepository::hasActive() ) {
			return '';
		}

		$state  = CartApplier::stateFor( $cart );
		$result = CartApplier::resultFor( $cart );

		if ( null === $state || null === $result ) {
			return '';
		}

		$campaigns = array();

		foreach ( CampaignRepository::active() as $campaign ) {
			$campaigns[ $campaign->id ] = $campaign;
		}

		$gains = array();

		foreach ( $result->adjustments->campaignTotals() as $id => $minor ) {
			if ( $minor > 0 && isset( $campaigns[ $id ] ) ) {
				/* translators: 1: campaign name, 2: amount */
				$gains[] = sprintf( __( '%1$s: %2$s indirim', 'campaign-engine-for-woocommerce' ), $campaigns[ $id ]->label, Texts::money( (int) $minor ) );
			}
		}

		foreach ( $result->adjustments->freeShippingCampaignIds() as $id ) {
			if ( isset( $campaigns[ $id ] ) ) {
				/* translators: %s: campaign name */
				$gains[] = sprintf( __( '%s: ücretsiz kargo', 'campaign-engine-for-woocommerce' ), $campaigns[ $id ]->label );
			}
		}

		foreach ( $result->adjustments->rewards() as $reward ) {
			if ( isset( $campaigns[ $reward->campaign_id ] ) && (int) ( $reward->meta['amount_minor'] ?? 0 ) > 0 ) {
				/* translators: 1: campaign name, 2: amount */
				$gains[] = sprintf( __( '%1$s: sipariş ödendiğinde sonraki alışverişiniz için %2$s kupon', 'campaign-engine-for-woocommerce' ), $campaigns[ $reward->campaign_id ]->label, Texts::money( (int) $reward->meta['amount_minor'] ) );
			}
		}

		$offers = self::offers( $cart, $state, $result->appliedCampaignIds(), array_values( $campaigns ) );

		if ( array() === $gains && array() === $offers ) {
			return '';
		}

		$out = '<div class="eru-ce-smart-cart">';

		if ( array() !== $gains ) {
			$out .= '<p class="eru-ce-smart-title">' . esc_html__( 'Bu sepette kazandıklarınız', 'campaign-engine-for-woocommerce' ) . '</p><ul class="eru-ce-gains">';

			foreach ( $gains as $gain ) {
				$out .= '<li>' . esc_html( $gain ) . '</li>';
			}

			$out .= '</ul>';
		}

		foreach ( $offers as $offer ) {
			$out .= self::offerHtml( $offer, $cart, $campaigns[ $offer->campaign_id ] ?? null );
		}

		return $out . '</div>';
	}

	/**
	 * @param array<string,mixed> $state
	 * @param int[]               $applied
	 * @param Campaign[]          $campaigns
	 * @return Offer[]
	 */
	private static function offers( WC_Cart $cart, array $state, array $applied, array $campaigns ): array {
		$lines = array();

		foreach ( $cart->get_cart() as $key => $item ) {
			$line    = $state['lines'][ (string) $key ] ?? null;
			$product = $item['data'] ?? null;

			if ( null === $line || ! $product instanceof WC_Product ) {
				continue;
			}

			$lines[ (string) $key ] = array(
				'product'    => $product,
				'item'       => $item,
				'qty'        => (int) $line['qty'],
				'base_minor' => (int) $line['base_minor'],
				'excluded'   => $line['excluded'],
			);
		}

		if ( array() === $lines ) {
			return array();
		}

		$context  = ContextFactory::fromCart( $cart, $lines );
		$shipping = Money::of( (string) $cart->get_shipping_total(), wc_get_price_decimals() );

		return ( new OfferFinder( ConditionRegistry::withDefaults() ) )->find( $context, $campaigns, $applied, $shipping, 2 );
	}

	private static function offerHtml( Offer $offer, WC_Cart $cart, ?Campaign $campaign ): string {
		$name = '';

		if ( null !== $offer->item_key ) {
			$item = $cart->get_cart_item( $offer->item_key );
			$name = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? wp_strip_all_tags( $item['data']->get_name() ) : '';
		}

		$row      = null !== $campaign ? (array) ( $campaign->actions[0] ?? array() ) : array();
		$sentence = Texts::offer( $offer, $name, $row );
		$target   = max( 1, $offer->target );
		$percent  = (int) floor( min( 100, max( 0, $offer->current * 100 / $target ) ) );
		$label    = null !== $campaign ? $campaign->label : '';

		return '<div class="eru-ce-offer"><p>' . ( '' !== $label ? '<strong>' . esc_html( $label ) . ':</strong> ' : '' ) . esc_html( $sentence ) . '</p>'
			. '<div class="eru-ce-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $percent ) . '" aria-label="' . esc_attr( $sentence ) . '"><span style="width:' . esc_attr( (string) $percent ) . '%"></span></div></div>';
	}
}
