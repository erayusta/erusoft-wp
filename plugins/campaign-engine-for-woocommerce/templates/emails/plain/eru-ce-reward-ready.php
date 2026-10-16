<?php
/**
 * "Ödülünüz hazır" e-mail (plain text). Override in your theme: campaign-engine-for-woocommerce/emails/plain/eru-ce-reward-ready.php
 *
 * @package Erusoft\CampaignEngine
 *
 * @var string   $email_heading
 * @var string   $coupon_code
 * @var string   $amount
 * @var string   $expires
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
echo esc_html__( 'Merhaba,', 'campaign-engine-for-woocommerce' ) . "\n\n";
/* translators: %s: reward amount */
echo esc_html( sprintf( __( 'Kampanyadan kazandığınız %s tutarındaki ödül artık kullanılabilir.', 'campaign-engine-for-woocommerce' ), $amount ) ) . "\n\n";
echo esc_html__( 'Kupon kodu:', 'campaign-engine-for-woocommerce' ) . ' ' . esc_html( $coupon_code ) . "\n\n";

if ( '' !== $expires ) {
	/* translators: %s: expiry date */
	echo esc_html( sprintf( __( 'Son kullanım tarihi: %s. Kupon tek kullanımlıktır ve yalnızca bu e-posta adresiyle verilen siparişlerde geçerlidir.', 'campaign-engine-for-woocommerce' ), $expires ) ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
