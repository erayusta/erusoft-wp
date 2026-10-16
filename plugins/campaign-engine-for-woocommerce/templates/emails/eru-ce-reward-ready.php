<?php
/**
 * "Ödülünüz hazır" e-mail (HTML). Override in your theme: campaign-engine-for-woocommerce/emails/eru-ce-reward-ready.php
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php esc_html_e( 'Merhaba,', 'campaign-engine-for-woocommerce' ); ?></p>
<p>
	<?php
	/* translators: %s: reward amount */
	echo esc_html( sprintf( __( 'Kampanyadan kazandığınız %s tutarındaki ödül artık kullanılabilir.', 'campaign-engine-for-woocommerce' ), $amount ) );
	?>
</p>
<p style="font-size:20px;font-weight:bold;letter-spacing:2px;text-align:center;border:1px dashed #999;padding:12px;"><?php echo esc_html( $coupon_code ); ?></p>
<?php if ( '' !== $expires ) : ?>
	<p>
		<?php
		/* translators: %s: expiry date */
		echo esc_html( sprintf( __( 'Son kullanım tarihi: %s. Kupon tek kullanımlıktır ve yalnızca bu e-posta adresiyle verilen siparişlerde geçerlidir.', 'campaign-engine-for-woocommerce' ), $expires ) );
		?>
	</p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
