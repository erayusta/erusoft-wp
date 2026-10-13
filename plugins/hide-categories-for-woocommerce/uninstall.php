<?php
/**
 * Uninstall: delete this plugin's option.
 *
 * @package hide-categories-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_hc_settings' );
