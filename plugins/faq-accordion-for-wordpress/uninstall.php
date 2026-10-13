<?php
/**
 * Uninstall: delete this plugin's own options. FAQ items are content and are kept.
 *
 * @package faq-accordion-for-wordpress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_faq_settings' );
