<?php
/**
 * Uninstall: delete this plugin's own options. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package store-closure-switch-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_scs_enabled', 'eru_scs_scope', 'eru_scs_message', 'eru_scs_reopen' ) as $eru_scs_option ) {
	delete_option( $eru_scs_option );
}
