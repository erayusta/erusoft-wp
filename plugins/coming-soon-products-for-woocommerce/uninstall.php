<?php
/**
 * Uninstall: delete this plugin's option. Per-product meta is kept with the product.
 *
 * @package coming-soon-products-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_csp_settings' );
