<?php
/**
 * Uninstall: delete this plugin's own options. Generated .webp copies stay in uploads
 * (they sit next to the originals as <file>.jpg.webp and can be removed by hand).
 *
 * @package local-webp-generator-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_webp_settings' );
