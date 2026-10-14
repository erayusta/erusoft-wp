<?php
/**
 * Custom order status e-mail (plain text).
 *
 * Override: copy to yourtheme/custom-order-statuses-for-woocommerce/emails/plain/eru-cos-status.php
 *
 * @package custom-order-statuses-for-woocommerce
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $body
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "=================================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================================\n\n";

if ( $body ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $body ) ) ) . "\n\n";
}

if ( $order instanceof WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	echo "\n----------------------------------------\n\n";
	do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
}

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n";
}

echo "\n----------------------------------------\n\n";
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
