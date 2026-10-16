<?php
/**
 * Re-checks campaigns when the classic checkout form is posted.
 *
 * What the customer saw was calculated on an earlier request; since then a campaign may have ended,
 * used up its last use or stopped matching the posted payment or shipping method. Every cart pass
 * stores a signature of its campaign outcome (applied campaigns, discount, free shipping) in the
 * session. The guard reads that signature when the form arrives (`woocommerce_checkout_process`,
 * before WooCommerce applies the posted data), evaluates the cart again from scratch after the posted
 * data is in (`woocommerce_after_checkout_validation`) and stops the checkout with an error when the
 * campaigns changed, so the customer confirms the new amount. Address-only changes to shipping or tax
 * do not trigger it.
 *
 * The block checkout calculates its totals on the order request itself; its usage check is in
 * ReservationGate. Carts that never had a campaign are not re-checked (inert without campaigns).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use WP_Error;

final class CheckoutGuard {

	private static ?string $seen = null;
	private static bool $armed   = false;

	public static function hooks(): void {
		add_action( 'woocommerce_checkout_process', array( self::class, 'capture' ), 5 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'verify' ), 20, 2 );
	}

	public static function capture(): void {
		$session     = function_exists( 'WC' ) ? WC()->session : null;
		$seen        = $session ? (string) $session->get( CartApplier::SIGNATURE_KEY, '' ) : '';
		self::$armed = '' !== $seen || CampaignRepository::hasActive();
		self::$seen  = $seen;
	}

	public static function verify( $data, $errors ): void {
		$cart    = function_exists( 'WC' ) ? WC()->cart : null;
		$session = function_exists( 'WC' ) ? WC()->session : null;

		if ( ! self::$armed || ! $cart || ! $session || ! $errors instanceof WP_Error || $cart->is_empty() ) {
			return;
		}

		self::$armed = false;

		CartApplier::forget( $cart );
		$cart->calculate_totals();

		$now = (string) $session->get( CartApplier::SIGNATURE_KEY, '' );

		if ( $now !== self::$seen ) {
			$errors->add( 'eru_ce_campaigns_changed', __( 'Sepetinizdeki kampanyalar değişti ve toplam güncellendi. Lütfen yeni tutarı kontrol edip siparişi yeniden verin.', 'campaign-engine-for-woocommerce' ) );
		}
	}
}
