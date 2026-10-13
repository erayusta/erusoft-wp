<?php
/**
 * Uninstall: delete this plugin's own options. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package payment-method-discounts-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_pmd_rules' );
