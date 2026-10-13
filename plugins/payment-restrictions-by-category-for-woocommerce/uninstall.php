<?php
/**
 * Uninstall: delete this plugin's own options. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package payment-restrictions-by-category-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_prc_rules' );
