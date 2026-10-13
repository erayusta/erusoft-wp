<?php
/**
 * Uninstall: delete this plugin's own options. User content is kept on purpose.
 *
 * @package high-value-cart-offer-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_hvco_settings' );
