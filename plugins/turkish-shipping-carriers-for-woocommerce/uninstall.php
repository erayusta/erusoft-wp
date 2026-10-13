<?php
/**
 * Uninstall: delete this plugin's options. Order meta (`_eru_tsc_*`) belongs to the orders and is kept;
 * shipping zone instances are managed by WooCommerce.
 *
 * @package turkish-shipping-carriers-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_tsc_carriers' );
delete_option( 'eru_tsc_settings' );
