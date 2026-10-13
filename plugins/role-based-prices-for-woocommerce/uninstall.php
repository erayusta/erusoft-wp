<?php
/**
 * Uninstall: delete this plugin's own option. Product/variation meta
 * (`_eru_rbp_price_*`) is kept on purpose.
 *
 * @package role-based-prices-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_rbp_roles' );
