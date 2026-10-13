<?php
/**
 * Settings pages saved through options.php must accept the capability their menu uses (manage_woocommerce),
 * so a shop manager can save them. Posts to wp-admin/options.php over HTTP as a temporary shop manager.
 *   wp eval-file tests/php/settings-capability.php   (the plugins below active; restores every eru_* option)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

// option group => plugin slug.
$groups = array(
	'eru_accl' => 'abandoned-checkout-call-list-for-woocommerce',
	'eru_cmw'  => 'catalog-mode-whatsapp-orders-for-woocommerce',
	'eru_cbs'  => 'category-banner-slider-for-woocommerce',
	'eru_ccl'  => 'click-collect-for-woocommerce',
	'eru_csp'  => 'coming-soon-products-for-woocommerce',
	'eru_hc'   => 'hide-categories-for-woocommerce',
	'eru_ob'   => 'order-blacklist-for-woocommerce',
	'eru_oje'  => 'order-json-export-for-woocommerce',
	'eru_osl'  => 'out-of-stock-last-for-woocommerce',
	'eru_ppc'  => 'pdf-product-catalog-for-woocommerce',
	'eru_psn'  => 'product-status-notifications-for-woocommerce',
	'eru_sdd'  => 'same-day-delivery-for-woocommerce',
	'eru_sps'  => 'smart-product-search-for-woocommerce',
	'eru_tsc'  => 'turkish-shipping-carriers-for-woocommerce',
	'eru_taf'  => 'turkish-address-fields-for-woocommerce',
	'eru_bss'  => 'bundle-stock-sync-for-woocommerce',
);

global $wpdb;
$snapshot = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_%' AND option_name NOT LIKE '%\\_transient\\_%'", OBJECT_K );
$uid      = 0;

try {
	$active = (array) get_option( 'active_plugins', array() );
	$uid    = wp_insert_user( array( 'user_login' => 'demo_shop_mgr_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'ornek+' . wp_generate_password( 6, false ) . '@example.com', 'role' => 'shop_manager' ) );
	$exp    = time() + HOUR_IN_SECONDS;
	$token  = WP_Session_Tokens::get_instance( $uid )->create( $exp );
	$auth   = wp_generate_auth_cookie( $uid, $exp, 'auth', $token );
	$logged = wp_generate_auth_cookie( $uid, $exp, 'logged_in', $token );
	wp_set_current_user( $uid );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $logged;
	$cookies = array(
		new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => $auth ) ),
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $logged ) ),
	);

	foreach ( $groups as $group => $slug ) {
		if ( ! in_array( "$slug/$slug.php", $active, true ) ) {
			$check( "$group: plugin $slug active", false );
			continue;
		}
		$res  = wp_remote_post(
			admin_url( 'options.php' ),
			array(
				'cookies'     => $cookies,
				'redirection' => 0,
				'timeout'     => 30,
				'body'        => array(
					'option_page'      => $group,
					'action'           => 'update',
					'_wpnonce'         => wp_create_nonce( "$group-options" ),
					'_wp_http_referer' => '/wp-admin/admin.php?page=x',
				),
			)
		);
		$code = is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res );
		$check( "$group: shop manager can save settings (got $code)", 302 === $code );
	}
} finally {
	if ( $uid ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $uid );
	}
	$now = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_%' AND option_name NOT LIKE '%\\_transient\\_%'" );
	foreach ( $now as $name ) {
		if ( ! isset( $snapshot[ $name ] ) ) {
			delete_option( $name );
		}
	}
	foreach ( $snapshot as $name => $row ) {
		$wpdb->update( $wpdb->options, array( 'option_value' => $row->option_value, 'autoload' => $row->autoload ), array( 'option_name' => $name ) );
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
}

exit( $fail ? 1 : 0 );
