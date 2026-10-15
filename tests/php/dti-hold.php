<?php
/**
 * Device Trade-In — order hold until the device is approved; manual admin changes are respected.
 *
 *   wp eval-file tests/php/dti-hold.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_DTI_Hold' ) ) {
	echo "FAIL: Eru_DTI_Hold missing (plugin not active?)\n";
	exit( 1 );
}

add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_dti_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

$old_settings = get_option( Eru_DTI_Settings::OPTION, null );
$order_ids    = array();
$dids         = array();
$code         = 0;

try {
	$did    = Eru_DTI_DB::device_insert( array( 'level1' => 'Demo Marka', 'level2' => 'Model B', 'level3' => '128 GB', 'base_value' => 4000, 'active' => 1 ) );
	$dids[] = $did;

	$mk_order = function ( $status_tradein ) use ( &$order_ids, $did ) {
		$order = wc_create_order();
		$order->set_billing_email( 'ornek@example.com' );
		$order->save();
		$tid = Eru_DTI_DB::tradein_insert( array( 'order_id' => $order->get_id(), 'device_id' => $did, 'condition_key' => 'iyi', 'serial' => 'DEMO-0002', 'value' => 3400, 'bonus' => 0, 'status' => $status_tradein, 'customer_email' => 'ornek@example.com' ) );
		$order->update_meta_data( '_eru_dti_tradein_id', $tid );
		$order->save();
		$order_ids[] = $order->get_id();
		return array( $order->get_id(), $tid );
	};

	// Off by default: nothing is held.
	Eru_DTI_Settings::save_for_test( array( 'hold_enabled' => 'no' ) );
	list( $oid0 ) = $mk_order( 'beklemede' );
	wc_get_order( $oid0 )->update_status( 'processing' );
	$check( 'hold off: order stays processing', 'processing' === wc_get_order( $oid0 )->get_status() );

	// (f) hold on.
	Eru_DTI_Settings::save_for_test( array( 'hold_enabled' => 'yes', 'hold_status' => 'wc-eru-dti-hold', 'hold_triggers' => array( 'processing' ) ) );
	$check( 'hold status is registered', isset( wc_get_order_statuses()['wc-eru-dti-hold'] ) );
	list( $oid, $tid ) = $mk_order( 'beklemede' );
	wc_get_order( $oid )->update_status( 'processing' );
	$check( '(f) paid order is moved to the hold status', 'eru-dti-hold' === wc_get_order( $oid )->get_status() );

	Eru_DTI_DB::tradein_set_status( $tid, 'onaylandi' );
	$check( '(f) approval releases the order back to processing', 'processing' === wc_get_order( $oid )->get_status() );

	// Approved trade-in is never held.
	list( $oid2 ) = $mk_order( 'onaylandi' );
	wc_get_order( $oid2 )->update_status( 'processing' );
	$check( 'approved trade-in order is not held', 'processing' === wc_get_order( $oid2 )->get_status() );

	// Admin manual change is respected.
	list( $oid3 ) = $mk_order( 'beklemede' );
	add_filter( 'eru_dti_is_admin_change', '__return_true' );
	wc_get_order( $oid3 )->update_status( 'processing' );
	remove_filter( 'eru_dti_is_admin_change', '__return_true' );
	$check( '(f) manual admin change to processing is not re-held', 'processing' === wc_get_order( $oid3 )->get_status() );

	// Rejected device: the order is held again (not let through) and a note is left.
	list( $oidr, $tidr ) = $mk_order( 'beklemede' );
	wc_get_order( $oidr )->update_status( 'on-hold' );
	Eru_DTI_DB::tradein_set_status( $tidr, 'reddedildi' );
	$check( 'rejected trade-in puts the order on hold', 'eru-dti-hold' === wc_get_order( $oidr )->get_status() );
	$notes = wc_get_order_notes( array( 'order_id' => $oidr ) );
	$check( 'rejection leaves an order note', (bool) array_filter( $notes, fn( $n ) => false !== strpos( $n->content, 'reddedildi' ) ) );
	wc_get_order( $oidr )->update_status( 'processing' );
	$check( 'rejected + paid order is held (not approved)', 'eru-dti-hold' === wc_get_order( $oidr )->get_status() );
	// Auto-cancel setting.
	Eru_DTI_Settings::save_for_test( array( 'reject_cancel' => 'yes' ) );
	list( $oidc, $tidc ) = $mk_order( 'beklemede' );
	wc_get_order( $oidc )->update_status( 'on-hold' );
	Eru_DTI_DB::tradein_set_status( $tidc, 'reddedildi' );
	$check( 'reject_cancel: rejected order is cancelled', 'cancelled' === wc_get_order( $oidc )->get_status() );
	Eru_DTI_Settings::save_for_test( array( 'reject_cancel' => 'no' ) );

	// Order without a trade-in is untouched.
	$plain = wc_create_order();
	$plain->save();
	$order_ids[] = $plain->get_id();
	$plain->update_status( 'processing' );
	$check( 'order without trade-in is untouched', 'processing' === wc_get_order( $plain->get_id() )->get_status() );
} catch ( Throwable $e ) {
	echo 'ERROR: ' . $e->getMessage() . "\n";
	$code = 1;
} finally {
	remove_filter( 'eru_dti_is_admin_change', '__return_true' );
	foreach ( $order_ids as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( $dids as $d ) {
		Eru_DTI_DB::device_delete( $d );
	}
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->prefix}eru_dti_tradeins WHERE customer_email = 'ornek@example.com'" ); // phpcs:ignore
	if ( null === $old_settings ) {
		delete_option( Eru_DTI_Settings::OPTION );
	} else {
		update_option( Eru_DTI_Settings::OPTION, $old_settings );
	}
}
if ( $code ) {
	exit( 1 );
}
echo "OK dti-hold\n";
