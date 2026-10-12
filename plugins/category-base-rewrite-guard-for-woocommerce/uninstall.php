<?php
/**
 * Uninstall: delete this plugin's own transient. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package category-base-rewrite-guard-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'eru_cbrg_checked' );
