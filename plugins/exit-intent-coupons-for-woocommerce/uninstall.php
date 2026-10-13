<?php
/**
 * Uninstall: delete this plugin's own options and scheduled event. Coupons already issued are kept
 * (they expire on their own).
 *
 * @package exit-intent-coupons-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_eic_settings' );
delete_option( 'eru_eic_stats' );
wp_clear_scheduled_hook( 'eru_eic_cleanup' );
