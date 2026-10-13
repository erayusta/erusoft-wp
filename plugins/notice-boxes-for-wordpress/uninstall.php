<?php
/**
 * Uninstall: delete this plugin's own options.
 *
 * @package notice-boxes-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_nb_settings' );
