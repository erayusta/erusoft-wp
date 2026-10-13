<?php
/**
 * Uninstall: delete this plugin's options (including stored API keys). Generated posts are content and are kept.
 *
 * @package ai-post-generator-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_apg_settings' );
delete_option( 'eru_apg_keys' );
delete_option( 'eru_apg_usage' );
