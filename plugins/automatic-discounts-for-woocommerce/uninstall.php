<?php
/**
 * Uninstall: delete this plugin's own options. User content is kept on purpose.
 *
 * @package automatic-discounts-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_ad_rules' );
