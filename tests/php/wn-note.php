<?php
/**
 * Welcome Note — role-targeted admin notice with per-user dismissal.
 *   wp eval-file tests/php/wn-note.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_WN_Note' ) ) {
	echo "FAIL: class Eru_WN_Note missing (plugin not active?)\n";
	exit( 1 );
}

$saved = get_option( 'eru_wn_settings', false );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$prev  = get_current_user_id();
$uids  = array();
$check( 'hooked to admin_notices', false !== has_action( 'admin_notices', array( 'Eru_WN_Note', 'admin_notice' ) ) );
$out   = function () {
	ob_start();
	Eru_WN_Note::admin_notice();
	return ob_get_clean();
};
try {
	delete_user_meta( $admin->ID, 'eru_wn_dismissed' );
	update_option( 'eru_wn_settings', array( 'text' => 'Merhaba {name}, demo not metni.', 'link_url' => 'https://example.com/', 'link_label' => 'Detay', 'roles' => array( 'administrator' ), 'admin_enabled' => 1 ), false );
	wp_set_current_user( $admin->ID );
	$html = $out();
	$check( 'notice shows text with name placeholder replaced', false !== strpos( $html, 'demo not metni' ) && false !== strpos( $html, esc_html( $admin->display_name ) ) );
	$check( 'notice has link', false !== strpos( $html, 'https://example.com/' ) );

	$sub = wp_insert_user( array( 'user_login' => 'eru_wn_demo', 'user_pass' => wp_generate_password(), 'user_email' => 'ornek-wn@example.com', 'role' => 'editor' ) );
	$uids[] = $sub;
	wp_set_current_user( $sub );
	$check( 'editor does not see it (role-targeted)', false === strpos( $out(), 'demo not metni' ) );
	wp_set_current_user( $admin->ID );

	$_POST = array( 'nonce' => wp_create_nonce( 'eru_wn_dismiss' ), 'key' => Eru_WN_Note::key() );
	$r = Eru_WN_Note::dismiss_for( $admin->ID, Eru_WN_Note::key() );
	$check( 'dismissal stored in user meta', true === $r && '' !== (string) get_user_meta( $admin->ID, 'eru_wn_dismissed', true ) );
	$check( 'notice gone after dismissal', false === strpos( $out(), 'demo not metni' ) );

	update_option( 'eru_wn_settings', array( 'text' => 'Yeni metin değişti.', 'roles' => array( 'administrator' ), 'admin_enabled' => 1 ), false );
	$check( 'changed text shows again', false !== strpos( $out(), 'Yeni metin değişti' ) );

	update_option( 'eru_wn_settings', array( 'text' => '', 'roles' => array( 'administrator' ), 'admin_enabled' => 1 ), false );
	$check( 'empty text = inert', '' === trim( $out() ) );
} finally {
	$_POST = array();
	delete_user_meta( $admin->ID, 'eru_wn_dismissed' );
	foreach ( $uids as $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u );
	}
	wp_set_current_user( $prev );
	false === $saved ? delete_option( 'eru_wn_settings' ) : update_option( 'eru_wn_settings', $saved, false );
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
