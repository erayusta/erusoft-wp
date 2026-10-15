<?php
/**
 * E-mail body used by every automation. Override in your theme at woocommerce/emails/eru-gra-message.php.
 *
 * @var string $body_html       Message text as HTML.
 * @var string $extra_html      Extra block (bank details, product list), already safe HTML.
 * @var bool   $marketing       Commercial message: shows the unsubscribe link.
 * @var string $unsubscribe_url Signed unsubscribe URL.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo wp_kses_post( $body_html );
if ( ! empty( $extra_html ) ) {
	echo wp_kses_post( $extra_html );
}
if ( ! empty( $marketing ) && ! empty( $unsubscribe_url ) ) {
	echo '<p style="font-size:12px;margin-top:24px">' . esc_html__( 'Bu e-postayı almak istemiyorsanız', 'growth-automations-for-woocommerce' ) . ' <a href="' . esc_url( $unsubscribe_url ) . '">' . esc_html__( 'buradan vazgeçebilirsiniz', 'growth-automations-for-woocommerce' ) . '</a>.</p>';
}
