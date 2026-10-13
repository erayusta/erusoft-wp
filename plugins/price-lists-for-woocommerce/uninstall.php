<?php
/**
 * Uninstall: delete the price lists (own post type) and the plugin's version option.
 *
 * @package price-lists-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$lists = get_posts(
	array(
		'post_type'   => 'eru_plst_list',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $lists as $list_id ) {
	wp_delete_post( (int) $list_id, true );
}
delete_option( 'eru_plst_version' );
