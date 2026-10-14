<?php
/**
 * Uninstall: delete this plugin's own options and cached files.
 *
 * @package site-manager-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_sm_settings' );
delete_option( 'eru_sm_flush_rules' );
delete_transient( 'eru_sm_llms' );
delete_transient( 'eru_sm_llms_full' );
