<?php
/**
 * Product page box. "Bu üründe geçerli kampanyalar": campaigns without visitor conditions that give one
 * unit of this product something now, with the gain. "Koşullara bağlı kampanyalar": campaigns that need
 * more in the cart or a visitor condition, each with its condition note ("İlk siparişinize özel: …").
 * The box is the same for every visitor (ProductView), and campaigns with a real end time get a
 * countdown to it. Nothing else: no stock counts, no visitor counts, no invented deadlines.
 *
 * Classic themes: `woocommerce_single_product_summary`. Block themes: before the add-to-cart form
 * block. Printed once per product.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Presentation\Texts;
use WC_Product;

final class GainBox {

	/** @var array<int,bool> */
	private static array $printed = array();

	/** Allows the box to be printed again (tests). */
	public static function reset(): void {
		self::$printed = array();
	}

	public static function hooks(): void {
		add_action( 'woocommerce_single_product_summary', array( self::class, 'classic' ), 25 );
		add_filter( 'render_block_woocommerce/add-to-cart-form', array( self::class, 'block' ), 10, 3 );
	}

	public static function html( WC_Product $product ): string {
		$id = $product->get_id();

		if ( isset( self::$printed[ $id ] ) ) {
			return '';
		}

		$view = ProductView::of( $product );

		if ( array() === $view['applied'] && array() === $view['others'] ) {
			return '';
		}

		self::$printed[ $id ] = true;
		$out                  = '<div class="eru-ce-gain" role="note">';

		if ( array() !== $view['applied'] ) {
			$out .= '<p class="eru-ce-gain-title">' . esc_html__( 'Bu üründe geçerli kampanyalar', 'campaign-engine-for-woocommerce' ) . '</p><ul>';

			foreach ( $view['applied'] as $campaign ) {
				$out .= '<li><strong>' . esc_html( $campaign->label ) . '</strong> — ' . esc_html( Texts::badge( $campaign ) );

				if ( ( $view['gains'][ $campaign->id ] ?? 0 ) > 0 ) {
					/* translators: %s: amount */
					$out .= '<br><span class="eru-ce-gain-amount">' . esc_html( sprintf( __( 'Bir adette kazancınız: %s', 'campaign-engine-for-woocommerce' ), Texts::money( (int) $view['gains'][ $campaign->id ] ) ) ) . '</span>';
				}

				$out .= self::countdown( $view, $campaign->id ) . '</li>';
			}

			$out .= '</ul>';
		}

		if ( array() !== $view['others'] ) {
			$out .= '<p class="eru-ce-gain-title">' . esc_html__( 'Koşullara bağlı kampanyalar', 'campaign-engine-for-woocommerce' ) . '</p><ul>';

			foreach ( $view['others'] as $other ) {
				$campaign = $other['campaign'];
				$gives    = Texts::action( $campaign->actions[0] ?? array() );
				$gives    = $other['note'] . ': ' . Texts::lcfirst( $gives );
				$out     .= '<li><strong>' . esc_html( $campaign->label ) . '</strong> — ' . esc_html( $gives ) . self::countdown( $view, $campaign->id ) . '</li>';
			}

			$out .= '</ul><p class="eru-ce-gain-note">' . esc_html__( 'Koşullar sağlandığında indirim sepette uygulanır.', 'campaign-engine-for-woocommerce' ) . '</p>';
		}

		return $out . '</div>';
	}

	/** @param array<string,mixed> $view */
	private static function countdown( array $view, int $campaign_id ): string {
		return isset( $view['ends'][ $campaign_id ] ) ? '<br>' . Storefront::countdown( $campaign_id, (int) $view['ends'][ $campaign_id ] ) : '';
	}

	public static function classic(): void {
		global $product;

		if ( $product instanceof WC_Product ) {
			echo self::html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	/**
	 * @param string    $content
	 * @param array     $block
	 * @param \WP_Block $instance
	 */
	public static function block( $content, $block, $instance = null ): string {
		$id = is_object( $instance ) && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : get_the_ID();

		if ( ! is_product() || (int) $id !== get_queried_object_id() ) {
			return (string) $content;
		}

		$product = wc_get_product( (int) $id );

		return ( $product instanceof WC_Product ? self::html( $product ) : '' ) . (string) $content;
	}
}
