<?php
/**
 * Uninstall: delete this plugin's option. Address parts stored on orders (`_billing_eru_taf_*`,
 * `_shipping_eru_taf_*`) and on customers belong to them and are kept.
 *
 * @package turkish-address-fields-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_taf_settings' );
