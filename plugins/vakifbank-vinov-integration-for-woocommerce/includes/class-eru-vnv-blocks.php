<?php
/**
 * Checkout block: registers the method as unavailable in 1.0.0.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The OTP step needs the classic checkout. The method is registered with the block payment registry so
 * WooCommerce knows about it, but it reports itself inactive (no script, never listed); the gateway settings
 * and WooCommerce admin screens show a notice while the checkout page is the block. The gateway also refuses
 * Store API requests in is_available().
 */
class Eru_VNV_Blocks {

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register' ) );
	}

	/** @param object $registry Payment method registry. */
	public static function register( $registry ) {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) || ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}
		require_once ERU_VNV_PATH . 'includes/class-eru-vnv-blocks-method.php';
		$registry->register( new Eru_VNV_Blocks_Method() );
	}
}
