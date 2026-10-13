<?php
/**
 * Uninstall: delete this plugin's own options. Order item meta is kept on purpose.
 *
 * @package gift-product-rules-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_gpr_rules' );
