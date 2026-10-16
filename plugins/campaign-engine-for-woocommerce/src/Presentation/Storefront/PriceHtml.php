<?php
/**
 * Price display: when one unit of a product already gets a campaign discount for this shopper, its price
 * shows the price before (struck through) and after. The cart charges the same amount; nothing is
 * written to the product.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Engine\Money;
use WC_Product;

final class PriceHtml {

	public static function hooks(): void {
		add_filter( 'woocommerce_get_price_html', array( self::class, 'filter' ), 100, 2 );
	}

	/**
	 * @param string     $html
	 * @param WC_Product $product
	 */
	public static function filter( $html, $product ) {
		if ( ! $product instanceof WC_Product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $html;
		}

		$view = ProductView::of( $product );

		if ( $view['discount'] <= 0 ) {
			return $html;
		}

		$decimals = wc_get_price_decimals();
		$regular  = $product->get_regular_price();
		$was      = is_numeric( $regular ) ? max( (float) $regular, (float) Money::toDecimal( $view['base'], $decimals ) ) : (float) Money::toDecimal( $view['base'], $decimals );
		$now      = (float) Money::toDecimal( $view['net'], $decimals );

		return '<span class="eru-ce-price">' . wc_format_sale_price( wc_get_price_to_display( $product, array( 'price' => $was ) ), wc_get_price_to_display( $product, array( 'price' => $now ) ) ) . $product->get_price_suffix() . '</span>';
	}
}
