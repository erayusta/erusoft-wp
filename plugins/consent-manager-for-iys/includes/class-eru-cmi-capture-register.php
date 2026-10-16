<?php
/**
 * Registration form consent box (WooCommerce My Account registration).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Same rule as the checkout box, for the e-mail channel only (the form has no phone): unticked by default,
 * ticked → EPOSTA ONAY with source "register" (not verified, with the consent-text hash as evidence),
 * unticked → nothing. An address that withdrew earlier is never flipped by a new registration; a
 * confirmation e-mail goes to it instead. Accounts created during checkout
 * are left to the checkout box.
 */
class Eru_CMI_Capture_Register {

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_register_form', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'capture' ), 20, 3 );
	}

	/** The box. */
	public static function render() {
		if ( Eru_CMI_Settings::front()['register'] && in_array( 'EPOSTA', Eru_CMI_Settings::channels(), true ) ) {
			echo Eru_CMI_Capture_Checkout::box_html( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in box_html().
		}
	}

	/**
	 * @param int   $customer_id New customer.
	 * @param array $data        New customer data.
	 * @param bool  $generated   Password generated.
	 */
	public static function capture( $customer_id, $data = array(), $generated = false ) {
		if ( ! Eru_CMI_Settings::front()['register'] || ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the registration nonce.
		if ( empty( $_POST['eru_cmi_optin'] ) || ! in_array( 'EPOSTA', Eru_CMI_Settings::channels(), true ) ) {
			return;
		}
		$user = get_userdata( (int) $customer_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$row = Eru_CMI_Repository::get( $user->user_email, 'EPOSTA' );
		if ( $row && 'RET' === $row->status ) {
			Eru_CMI_Links::send_confirm( $user->user_email );
			return;
		}
		Eru_CMI_Repository::set(
			$user->user_email,
			'EPOSTA',
			'ONAY',
			array(
				'actor'     => 'user',
				'source'    => 'register',
				'actor_id'  => (int) $customer_id,
				'user_id'   => (int) $customer_id,
				'verified'  => false,
				'text_hash' => Eru_CMI_Capture_Checkout::consent_text()['hash'],
			) + Eru_CMI_Links::client()
		);
	}
}
