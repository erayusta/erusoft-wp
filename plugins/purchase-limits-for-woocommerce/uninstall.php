<?php
/**
 * Uninstall: delete this plugin's own options. Product meta (`_eru_pl_*`) and
 * other user content is kept on purpose.
 *
 * @package purchase-limits-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_pl_settings' );
