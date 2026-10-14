<?php
/**
 * Uninstall: delete the service groups, the settings and the per-product switch.
 * Order data (line-item meta "Ek hizmet" and _eru_aos_choices, fee lines) is kept on purpose.
 *
 * @package product-add-on-services-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$eru_aos_ids = get_posts(
	array(
		'post_type'   => 'eru_aos_group',
		'post_status' => array_keys( get_post_stati() ), // 'any' skips trash and auto-draft.
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $eru_aos_ids as $eru_aos_id ) {
	wp_delete_post( $eru_aos_id, true );
}
foreach ( array( 'eru_aos_show_prices', 'eru_aos_live_total', 'eru_aos_percent_base', 'eru_aos_position' ) as $eru_aos_opt ) {
	delete_option( $eru_aos_opt );
}
delete_post_meta_by_key( '_eru_aos_disabled' );
