<?php
/**
 * Uninstall: delete this plugin's option.
 *
 * @package out-of-stock-last-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_osl_settings' );
