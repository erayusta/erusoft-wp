<?php
/**
 * Uninstall: delete this plugin's own options, transients and scheduled events.
 * Product meta (`_eru_usd_*`) and the stored prices are kept on purpose.
 *
 * @package usd-pricing-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_usd_source', 'eru_usd_manual_rate', 'eru_usd_markup', 'eru_usd_show_usd', 'eru_usd_tcmb_last' ) as $eru_usd_option ) {
	delete_option( $eru_usd_option );
}
delete_transient( 'eru_usd_tcmb_rate' );
delete_transient( 'eru_usd_tcmb_backoff' );
wp_clear_scheduled_hook( 'eru_usd_resync' );
wp_clear_scheduled_hook( 'eru_usd_refresh_rate' );
