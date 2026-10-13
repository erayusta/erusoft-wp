<?php
/**
 * Uninstall: delete this plugin's own options (lists, message, log). Order meta is kept.
 *
 * @package order-blacklist-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_ob_settings' );
delete_option( 'eru_ob_log' );
