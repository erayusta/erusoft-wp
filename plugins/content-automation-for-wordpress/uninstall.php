<?php
/**
 * Uninstall: unschedule the pipeline and delete this plugin's options (including stored API keys
 * and the topic queue). Generated posts are content and are kept.
 *
 * @package content-automation-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'eru_ca_tick' );
foreach ( array( 'eru_ca_settings', 'eru_ca_keys', 'eru_ca_queue', 'eru_ca_usage', 'eru_ca_lock' ) as $option ) {
	delete_option( $option );
}
