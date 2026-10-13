<?php
/**
 * Uninstall: delete the option.
 *
 * @package product-status-notifications-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_psn_settings' );
