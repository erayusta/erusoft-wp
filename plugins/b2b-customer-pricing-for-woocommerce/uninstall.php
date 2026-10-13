<?php
/**
 * Uninstall: delete this plugin's options and the customer-group user meta.
 *
 * @package b2b-customer-pricing-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_b2b_rules', 'eru_b2b_groups', 'eru_b2b_label', 'eru_b2b_version' ) as $option ) {
	delete_option( $option );
}
delete_metadata( 'user', 0, 'eru_b2b_group', '', true );
