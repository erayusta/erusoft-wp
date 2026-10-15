<?php
/**
 * Yurtiçi Kargo Integration + Turkish Shipping Carriers — automatic tracking numbers go through tsc's public save
 * path (customer note, `eru_tsc_tracking_saved`), never in test mode, and survive a stale order-screen save.
 *
 *   wp plugin activate yurtici-kargo-integration-for-woocommerce turkish-shipping-carriers-for-woocommerce
 *   wp eval-file tests/php/yk-tsc.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_YK_Dispatcher' ) || ! class_exists( 'Eru_TSC_Tracking' ) ) {
	echo "FAIL: activate yurtici-kargo-integration-for-woocommerce and turkish-shipping-carriers-for-woocommerce\n";
	exit( 1 );
}

add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_yk_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

$old_yk  = get_option( Eru_YK_Settings::OPTION, null );
$old_tsc = get_option( Eru_TSC_Carriers::SETTINGS_OPTION, null );
$saved   = array();
add_action(
	'eru_tsc_tracking_saved',
	function ( $order, $t ) use ( &$saved ) {
		$saved[] = $order->get_id() . ':' . $t['number'];
	},
	10,
	2
);
$was_user = get_current_user_id();
$ids      = array();
$code     = 1;
$notes    = fn( $id ) => count( array_filter( wc_get_order_notes( array( 'order_id' => $id, 'type' => 'customer' ) ), fn( $n ) => false !== strpos( $n->content, 'takip numarası' ) ) );
$mk       = function () use ( &$ids ) {
	$o = wc_create_order( array( 'created_via' => 'eru-yk-test' ) );
	$o->set_billing_email( 'ornek@example.com' );
	$o->save();
	$ids[] = $o->get_id();
	return $o;
};
$mode     = function ( $test ) use ( $old_yk ) {
	update_option( Eru_YK_Settings::OPTION, array_merge( is_array( $old_yk ) ? $old_yk : array(), array( 'test_mode' => $test ? 'yes' : 'no' ) ), false );
	Eru_YK_Settings::flush();
};

try {
	update_option( Eru_TSC_Carriers::SETTINGS_OPTION, array( 'notify_customer' => 1, 'show_in_emails' => 1 ) );
	$check( 'tsc integration is active', Eru_YK_Dispatcher::tsc_active() );

	// Live mode: the tracking number goes through tsc's save path.
	$mode( false );
	$o = $mk();
	Eru_YK_Dispatcher::set_tracking( $o, '100000000077', '' );
	$o->save();
	$f = wc_get_order( $o->get_id() );
	$check( 'tsc carrier and number are stored', 'yurtici' === $f->get_meta( '_eru_tsc_carrier' ) && '100000000077' === $f->get_meta( '_eru_tsc_tracking_number' ) );
	$check( 'eru_tsc_tracking_saved fired once for the new number', array( $o->get_id() . ':100000000077' ) === $saved );
	$check( 'tsc customer note sent (notify on)', 1 === $notes( $o->get_id() ) );
	Eru_YK_Dispatcher::set_tracking( wc_get_order( $o->get_id() ), '100000000077', '' );
	$check( 'the tracker seeing the same number again does not notify twice', 1 === count( $saved ) && 1 === $notes( $o->get_id() ) );

	// A stale order screen (opened before the number arrived) does not blank it on save.
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$_POST = array(
		'eru_tsc_tracking_nonce'  => wp_create_nonce( 'eru_tsc_tracking' ),
		'eru_tsc_carrier'         => '',
		'eru_tsc_tracking_number' => '',
		'eru_tsc_carrier_loaded'  => '',
		'eru_tsc_tracking_loaded' => '',
	);
	Eru_TSC_Tracking::save_box( $o->get_id() );
	$_POST = array();
	$f     = wc_get_order( $o->get_id() );
	$check( 'stale order-screen save keeps the Yurtiçi tracking number', '100000000077' === $f->get_meta( '_eru_tsc_tracking_number' ) && 'yurtici' === $f->get_meta( '_eru_tsc_carrier' ) );
	wp_set_current_user( $was_user );

	// Test mode: nothing is written to tsc and nobody is notified.
	$mode( true );
	$t = $mk();
	Eru_YK_Dispatcher::set_tracking( $t, '100000000078', '' );
	$t->save();
	$f = wc_get_order( $t->get_id() );
	$check( 'test mode: no tsc meta, no action, no note', '' === $f->get_meta( '_eru_tsc_tracking_number' ) && 1 === count( $saved ) && 0 === $notes( $t->get_id() ) );

	$check( 'yk no longer writes the tsc tracking meta directly', false === strpos( (string) file_get_contents( ERU_YK_PATH . 'includes/class-eru-yk-dispatcher.php' ), "update_meta_data( '_eru_tsc_tracking_number'" ) || false !== strpos( (string) file_get_contents( ERU_YK_PATH . 'includes/class-eru-yk-dispatcher.php' ), "class_exists( 'Eru_TSC_Tracking' )" ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	$_POST = array();
	wp_set_current_user( $was_user );
	foreach ( $ids as $id ) {
		if ( wc_get_order( $id ) ) {
			wc_get_order( $id )->delete( true );
		}
	}
	if ( null === $old_yk ) {
		delete_option( Eru_YK_Settings::OPTION );
	} else {
		update_option( Eru_YK_Settings::OPTION, $old_yk, false );
	}
	Eru_YK_Settings::flush();
	if ( null === $old_tsc ) {
		delete_option( Eru_TSC_Carriers::SETTINGS_OPTION );
	} else {
		update_option( Eru_TSC_Carriers::SETTINGS_OPTION, $old_tsc );
	}
}
exit( $code );
