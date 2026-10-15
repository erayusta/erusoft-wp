<?php
/**
 * Turkish Shipping Carriers — public save path for tracking numbers (save + customer note + action), and an order
 * screen opened before a background write must not blank that tracking number on save.
 *
 *   wp eval-file tests/php/tsc-tracking.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_TSC_Tracking' ) ) {
	echo "FAIL: Eru_TSC_Tracking missing (plugin not active?)\n";
	exit( 1 );
}

$mails = array();
add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_tsc_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

$old_set = get_option( Eru_TSC_Carriers::SETTINGS_OPTION, null );
$saved   = array();
$spy     = function ( $order, $t ) use ( &$saved ) {
	$saved[] = $t['number'];
};
add_action( 'eru_tsc_tracking_saved', $spy, 10, 2 );
$was_user = get_current_user_id();
$oid      = 0;
$code     = 1;
$notes    = function ( $id ) {
	return array_values( array_filter( wc_get_order_notes( array( 'order_id' => $id, 'type' => 'customer' ) ), fn( $n ) => false !== strpos( $n->content, 'takip numarası' ) ) );
};

try {
	update_option( Eru_TSC_Carriers::SETTINGS_OPTION, array( 'notify_customer' => 1, 'show_in_emails' => 1 ) );
	$o = wc_create_order();
	$o->set_billing_email( 'ornek@example.com' );
	$o->set_billing_first_name( 'Demo' );
	$o->save();
	$oid = $o->get_id();

	// 1. Public save path.
	$check( 'Eru_TSC_Tracking::save_tracking() exists', method_exists( 'Eru_TSC_Tracking', 'save_tracking' ) );
	$check( 'eru_tsc_set_tracking() exists', function_exists( 'eru_tsc_set_tracking' ) );
	$changed = Eru_TSC_Tracking::save_tracking( wc_get_order( $oid ), 'yurtici', 'DEMO100' );
	$fresh   = wc_get_order( $oid );
	$check( 'save_tracking() stores carrier and number on the order (saved)', true === $changed && 'yurtici' === $fresh->get_meta( '_eru_tsc_carrier' ) && 'DEMO100' === $fresh->get_meta( '_eru_tsc_tracking_number' ) );
	$check( 'a new number fires eru_tsc_tracking_saved once', array( 'DEMO100' ) === $saved );
	$check( 'a new number adds the customer note (notify on)', 1 === count( $notes( $oid ) ) );
	$check( 'the same number again: no second action or note', false === eru_tsc_set_tracking( wc_get_order( $oid ), 'yurtici', 'DEMO100' ) && 1 === count( $saved ) && 1 === count( $notes( $oid ) ) );
	eru_tsc_set_tracking( wc_get_order( $oid ), 'aras' );
	$fresh = wc_get_order( $oid );
	$check( 'carrier only (number null) keeps the stored number', 'aras' === $fresh->get_meta( '_eru_tsc_carrier' ) && 'DEMO100' === $fresh->get_meta( '_eru_tsc_tracking_number' ) && 1 === count( $saved ) );

	// 2. Order screen loaded before a background write.
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$fresh = wc_get_order( $oid );
	$fresh->delete_meta_data( '_eru_tsc_carrier' );
	$fresh->delete_meta_data( '_eru_tsc_tracking_number' );
	$fresh->save();
	ob_start();
	Eru_TSC_Tracking::render_box( wc_get_order( $oid ) );
	$form = ob_get_clean();
	$check( 'the box carries the values it was rendered with', false !== strpos( $form, 'name="eru_tsc_tracking_loaded"' ) && false !== strpos( $form, 'name="eru_tsc_carrier_loaded"' ) );
	Eru_TSC_Tracking::save_tracking( wc_get_order( $oid ), 'yurtici', 'BG200' ); // Background writer.
	$post = function ( array $fields ) use ( $oid ) {
		$_POST = array_merge( array( 'eru_tsc_tracking_nonce' => wp_create_nonce( 'eru_tsc_tracking' ) ), $fields );
		Eru_TSC_Tracking::save_box( $oid );
		$_POST = array();
		return wc_get_order( $oid );
	};
	$r = $post( array( 'eru_tsc_carrier' => '', 'eru_tsc_tracking_number' => '', 'eru_tsc_carrier_loaded' => '', 'eru_tsc_tracking_loaded' => '' ) );
	$check( 'saving a stale, untouched box keeps the background number and carrier', 'BG200' === $r->get_meta( '_eru_tsc_tracking_number' ) && 'yurtici' === $r->get_meta( '_eru_tsc_carrier' ) );
	$r = $post( array( 'eru_tsc_carrier' => 'yurtici', 'eru_tsc_tracking_number' => 'NEW300', 'eru_tsc_carrier_loaded' => 'yurtici', 'eru_tsc_tracking_loaded' => 'BG200' ) );
	$check( 'a typed new number is saved and notified', 'NEW300' === $r->get_meta( '_eru_tsc_tracking_number' ) && 'NEW300' === end( $saved ) );
	$r = $post( array( 'eru_tsc_carrier' => 'yurtici', 'eru_tsc_tracking_number' => '', 'eru_tsc_carrier_loaded' => 'yurtici', 'eru_tsc_tracking_loaded' => 'NEW300' ) );
	$check( 'clearing the field on purpose still clears the number', '' === $r->get_meta( '_eru_tsc_tracking_number' ) );
	$r = $post( array( 'eru_tsc_carrier' => 'mng', 'eru_tsc_tracking_number' => 'OLD400' ) );
	$check( 'a form without the loaded fields (older page) saves as before', 'OLD400' === $r->get_meta( '_eru_tsc_tracking_number' ) && 'mng' === $r->get_meta( '_eru_tsc_carrier' ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	wp_set_current_user( $was_user );
	if ( $oid && wc_get_order( $oid ) ) {
		wc_get_order( $oid )->delete( true );
	}
	if ( null === $old_set ) {
		delete_option( Eru_TSC_Carriers::SETTINGS_OPTION );
	} else {
		update_option( Eru_TSC_Carriers::SETTINGS_OPTION, $old_set );
	}
}
exit( $code );
