<?php
/**
 * Uninstall: delete this plugin's settings. Shipping-method instances in WooCommerce zones are managed by WooCommerce.
 *
 * @package same-day-delivery-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_sdd_settings' );
