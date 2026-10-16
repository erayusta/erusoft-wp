<?php
/**
 * A badge on product cards (classic loops and the product image block) naming the highest-priority
 * campaign that targets the product and runs now: its own badge text, or what it gives ("%12 indirim",
 * "3 al 2 öde"), with the cart threshold it needs. Campaigns with a visitor condition (first order, role,
 * weekday, payment method…) never get a card badge, so every visitor sees the same cards.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Presentation\Texts;
use WC_Product;

final class ListBadge {

	/** Product whose card the image block just badged; block templates fire the classic loop hook too. */
	private static int $last_block = 0;

	public static function hooks(): void {
		add_action( 'woocommerce_before_shop_loop_item_title', array( self::class, 'loop' ), 9 );
		add_filter( 'render_block_woocommerce/product-image', array( self::class, 'block' ), 10, 3 );
	}

	public static function html( WC_Product $product ): string {
		// Storewide campaigns would put the same badge on every card; they show one only when the
		// merchant wrote a badge text for them.
		$view      = ProductView::of( $product );
		$campaigns = $view['applied'];

		// Visitor-conditional campaigns never get a card badge; the product page names their condition.
		foreach ( $view['others'] as $other ) {
			if ( ! $other['conditional'] ) {
				$campaigns[] = $other['campaign'];
			}
		}

		foreach ( $campaigns as $campaign ) {
			if ( $campaign->targets->matchesEverything() && '' === trim( (string) ( $campaign->meta['badge'] ?? '' ) ) ) {
				continue;
			}

			$text = Texts::badge( $campaign );

			if ( '' !== $text ) {
				return '<span class="eru-ce-badge">' . esc_html( $text ) . '</span>';
			}
		}

		return '';
	}

	public static function loop(): void {
		global $product;

		if ( $product instanceof WC_Product && $product->get_id() !== self::$last_block ) {
			echo self::html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	/**
	 * @param string    $content
	 * @param array     $block
	 * @param \WP_Block $instance
	 */
	public static function block( $content, $block, $instance = null ): string {
		$content = (string) $content;
		$id      = is_object( $instance ) && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;
		$product = $id > 0 ? wc_get_product( $id ) : null;

		if ( ! $product instanceof WC_Product || is_product() && get_queried_object_id() === $id ) {
			return $content;
		}

		$badge            = self::html( $product );
		self::$last_block = $id;

		if ( '' === $badge ) {
			return $content;
		}

		// Inside the image wrapper, so the badge sits on the image.
		$at = strpos( $content, '>' );

		return false === $at ? $badge . $content : substr( $content, 0, $at + 1 ) . $badge . substr( $content, $at + 1 );
	}
}
