<?php
/**
 * Uninstall: delete this plugin's own options. Campaign posts are content and are kept.
 *
 * @package campaign-pages-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_cp_settings' );
delete_option( 'eru_cp_flush' );
