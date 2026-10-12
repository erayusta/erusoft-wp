<?php
/**
 * Uninstall: delete this plugin's own options. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package email-domain-roles-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_edr_mappings', 'eru_edr_fallback_role' ) as $eru_edr_option ) {
	delete_option( $eru_edr_option );
}
