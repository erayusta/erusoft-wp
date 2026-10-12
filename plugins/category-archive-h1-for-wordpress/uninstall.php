<?php
/**
 * Uninstall: delete this plugin's own options. User content
 * (posts, term/product/order meta, user roles) is kept on purpose.
 *
 * @package category-archive-h1-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'eru_cah_taxonomies', 'eru_cah_mode', 'eru_cah_seo_title' ) as $eru_cah_option ) {
	delete_option( $eru_cah_option );
}
