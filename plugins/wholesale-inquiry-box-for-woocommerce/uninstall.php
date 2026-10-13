<?php
/**
 * Uninstall: delete this plugin's settings option.
 *
 * @package wholesale-inquiry-box-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_wib_settings' );
