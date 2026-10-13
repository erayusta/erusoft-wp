<?php
/**
 * Uninstall: delete this plugin's options and the per-user dismissal meta.
 *
 * @package welcome-note-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_wn_settings' );
delete_metadata( 'user', 0, 'eru_wn_dismissed', '', true );
