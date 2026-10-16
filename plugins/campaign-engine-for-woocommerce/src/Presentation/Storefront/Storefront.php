<?php
/**
 * Shop-front modules, each a setting and off by default: list badge, price display, product page gain
 * box, smart cart, cart and checkout summary row, thank-you box. With every module off nothing is
 * hooked and nothing is printed or enqueued. With a module on, nothing is printed while no campaign is
 * active.
 *
 * The campaign pages and Hesabım → Ödüllerim have their own classes (CampaignPages, RewardsEndpoint).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;

final class Storefront {

	public const STYLE  = 'eru-ce-storefront';
	public const SCRIPT = 'eru-ce-countdown';

	public static function hooks(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$on = false;

		foreach ( array(
			'badge'            => ListBadge::class,
			'price_html'       => PriceHtml::class,
			'gain_box'         => GainBox::class,
			'smart_cart'       => SmartCart::class,
			'checkout_summary' => CheckoutSummary::class,
			'thank_you'        => ThankYou::class,
		) as $module => $class ) {
			if ( StorefrontOptions::on( $module ) ) {
				$class::hooks();
				$on = true;
			}
		}

		if ( $on || StorefrontOptions::on( 'pages' ) || StorefrontOptions::on( 'account' ) ) {
			add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		}
	}

	public static function assets(): void {
		wp_register_style( self::STYLE, plugins_url( 'assets/css/storefront.css', ERU_CE_FILE ), array(), ERU_CE_VERSION );
		wp_register_script( self::SCRIPT, plugins_url( 'assets/js/countdown.js', ERU_CE_FILE ), array(), ERU_CE_VERSION, true );
		wp_localize_script(
			self::SCRIPT,
			'eruCeClock',
			array(
				'url'   => esc_url_raw( rest_url( 'eru-ce/v1/clock' ) ),
				'ended' => __( 'Kampanya sona erdi', 'campaign-engine-for-woocommerce' ),
				'day'   => __( 'gün', 'campaign-engine-for-woocommerce' ),
				'left'  => __( 'Kampanyanın bitmesine', 'campaign-engine-for-woocommerce' ),
			)
		);

		if ( CampaignRepository::hasActive() || is_account_page() ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/** Countdown markup from a campaign's real end time; the static date stays when scripts are off. */
	public static function countdown( int $campaign_id, int $ends ): string {
		wp_enqueue_script( self::SCRIPT );

		return '<span class="eru-ce-countdown" data-eru-ce-campaign="' . esc_attr( (string) $campaign_id ) . '" data-eru-ce-end="' . esc_attr( (string) $ends ) . '">'
			/* translators: %s: end date and time */
			. esc_html( sprintf( __( 'Bitiş: %s', 'campaign-engine-for-woocommerce' ), \Erusoft\CampaignEngine\Presentation\Texts::dateTime( $ends ) ) )
			. '</span>';
	}
}
