<?php
/**
 * Uninstall: delete this plugin's own options.
 *
 * @package heading-pagination-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_hp_settings' );
