<?php
/**
 * Custom order status e-mail (HTML).
 *
 * Override: copy to yourtheme/custom-order-statuses-for-woocommerce/emails/eru-cos-status.php
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

do_action( 'woocommerce_email_header', $email_heading, $email );

if ( $body ) {
	echo wp_kses_post( wpautop( wptexturize( $body ) ) );
}

if ( $order instanceof WC_Order ) {
	do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
	do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
