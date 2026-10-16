<?php
/**
 * Thank-you page box: what the campaigns saved on this order (from the order's own snapshot) and the
 * spend-and-earn reward it earns, with the real date it is expected.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\OrderWriter;
use Erusoft\CampaignEngine\Presentation\Texts;
use WC_Order;

final class ThankYou {

	public static function hooks(): void {
		add_action( 'woocommerce_thankyou', array( self::class, 'render' ), 5 );
	}

	public static function render( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof WC_Order ) {
			echo self::html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in html().
		}
	}

	public static function html( WC_Order $order ): string {
		$snapshot = OrderWriter::snapshot( $order );
		$total    = (int) ( $snapshot['total_discount_minor'] ?? 0 );
		$lines    = array();

		if ( $total > 0 ) {
			/* translators: %s: amount */
			$lines[] = sprintf( __( 'Bu siparişte kampanyalarla %s kazandınız.', 'campaign-engine-for-woocommerce' ), Texts::money( $total ) );
		}

		if ( ! empty( $snapshot['free_shipping'] ) ) {
			$lines[] = __( 'Kargonuz kampanya kapsamında ücretsiz.', 'campaign-engine-for-woocommerce' );
		}

		$rows = RewardRepository::forOrder( $order->get_id() );

		foreach ( $rows as $row ) {
			$amount = Texts::money( (int) $row['amount_minor'] );

			if ( RewardRepository::AVAILABLE === $row['status'] && '' !== (string) $row['code'] ) {
				/* translators: 1: amount, 2: coupon code */
				$lines[] = sprintf( __( 'Ödülünüz hazır: %1$s tutarındaki kupon kodunuz %2$s.', 'campaign-engine-for-woocommerce' ), $amount, (string) $row['code'] );
			} elseif ( RewardRepository::PENDING === $row['status'] ) {
				$when = Clock::ts( $row['available_at'] ?? null );
				/* translators: 1: amount, 2: date */
				$lines[] = null === $when ? sprintf( __( '%s tutarında ödül kuponu kazandınız; e-postanıza gönderilecek.', 'campaign-engine-for-woocommerce' ), $amount ) : sprintf( __( '%1$s tutarında ödül kuponu kazandınız; %2$s tarihinde e-postanıza gönderilecek.', 'campaign-engine-for-woocommerce' ), $amount, Texts::dateTime( $when ) );
			}
		}

		if ( array() === $rows ) {
			$reward = 0;

			foreach ( (array) ( $snapshot['rewards'] ?? array() ) as $earned ) {
				$reward += (int) ( $earned['amount_minor'] ?? 0 );
			}

			if ( $reward > 0 ) {
				/* translators: %s: amount */
				$lines[] = sprintf( __( 'Siparişiniz ödendiğinde sonraki alışverişiniz için %s tutarında ödül kuponu kazanacaksınız.', 'campaign-engine-for-woocommerce' ), Texts::money( $reward ) );
			}
		}

		if ( array() === $lines ) {
			return '';
		}

		$out = '<section class="eru-ce-thankyou"><h2>' . esc_html__( 'Kampanya kazancınız', 'campaign-engine-for-woocommerce' ) . '</h2><ul>';

		foreach ( $lines as $line ) {
			$out .= '<li>' . esc_html( $line ) . '</li>';
		}

		return $out . '</ul></section>';
	}
}
