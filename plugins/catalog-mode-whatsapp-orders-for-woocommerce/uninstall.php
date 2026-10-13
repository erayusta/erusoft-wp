<?php
/**
 * Uninstall: delete the option.
 *
 * @package catalog-mode-whatsapp-orders-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_cmw_settings' );
