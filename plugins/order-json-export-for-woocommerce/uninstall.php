<?php
/**
 * Uninstall: delete this plugin's own options. Orders are never touched.
 *
 * @package order-json-export-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_oje_meta_allowlist' );
delete_option( 'eru_oje_include_note' );
