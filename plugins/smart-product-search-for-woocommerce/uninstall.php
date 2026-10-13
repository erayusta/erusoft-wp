<?php
/**
 * Uninstall: delete the option and the search index meta.
 *
 * @package smart-product-search-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_sps_settings' );
delete_transient( 'eru_sps_cats' );
delete_post_meta_by_key( '_eru_sps_index' );
delete_post_meta_by_key( '_eru_sps_title' );
