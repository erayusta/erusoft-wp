<?php
/**
 * Uninstall: remove options and category meta.
 *
 * @package category-banner-slider-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_cbs_settings' );
delete_option( 'eru_cbs_global_slides' );

$term_ids = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
if ( is_array( $term_ids ) ) {
	foreach ( $term_ids as $term_id ) {
		delete_term_meta( $term_id, '_eru_cbs_enabled' );
		delete_term_meta( $term_id, '_eru_cbs_slides' );
	}
}
