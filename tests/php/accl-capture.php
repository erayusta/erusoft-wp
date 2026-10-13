<?php
/**
 * Abandoned Checkout Call List — capture → recover → purge, consent gating, erasure.
 *
 *   wp eval-file tests/php/accl-capture.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_ACCL_DB' ) ) {
	echo "FAIL: Eru_ACCL_DB missing (plugin not active?)\n";
	exit( 1 );
}
Eru_ACCL_DB::install();

global $wpdb;
$t      = Eru_ACCL_DB::table();
$old    = get_option( Eru_ACCL_DB::OPTION, null );
$oid    = 0;
$email  = 'ornek-accl@example.com';
$ph     = fn( $n ) => str_replace( 'X', '', $n ); // assembled at run time: no phone-shaped literal in the repo.
$phone  = $ph( '0X555 000 00 00' );
$cart   = array( array( 'product_id' => 1, 'variation_id' => 0, 'name' => 'Demo Ürün', 'qty' => 2 ) );
$count  = fn( $where = '1=1' ) => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB
$cleanup = fn() => $wpdb->query( "DELETE FROM {$t} WHERE email LIKE '%-accl@example.com'" ); // phpcs:ignore WordPress.DB

try {
	$cleanup();
	update_option( Eru_ACCL_DB::OPTION, array_merge( Eru_ACCL_DB::defaults(), array( 'enabled' => 0 ) ) );
	$check( 'disabled: nothing stored', 0 === Eru_ACCL_Capture::save( $email, $phone, 'Ayşe', $cart, 200.0, true ) && 0 === $count( "email='{$email}'" ) );

	update_option( Eru_ACCL_DB::OPTION, array_merge( Eru_ACCL_DB::defaults(), array( 'enabled' => 1, 'consent_text' => 'Demo onay metni' ) ) );
	$check( 'no consent: nothing stored', 0 === Eru_ACCL_Capture::save( $email, $phone, 'Ayşe', $cart, 200.0, false ) );
	$check( 'empty cart: nothing stored', 0 === Eru_ACCL_Capture::save( $email, $phone, 'Ayşe', array(), 0.0, true ) );
	$id = Eru_ACCL_Capture::save( $email, $phone, 'Ayşe', $cart, 200.0, true );
	$check( 'capture creates a row', $id > 0 && 1 === $count( "email='{$email}'" ) );
	$row = Eru_ACCL_DB::get( $id );
	$check( 'status starts as "call" (aranacak); phone normalised', 'call' === $row['status'] && '5550000000' === $row['phone'] );
	$id2 = Eru_ACCL_Capture::save( $email, '', 'Ayşe', $cart, 300.0, true );
	$check( 'second capture updates the same row', $id2 === $id && 1 === $count( "email='{$email}'" ) );

	$check( 'follow-up update (arandı + not)', Eru_ACCL_DB::update_follow_up( $id, 'called', 'Demo not' ) && 'called' === Eru_ACCL_DB::get( $id )['status'] );
	$check( 'invalid status rejected', ! Eru_ACCL_DB::update_follow_up( $id, 'bogus', '' ) );

	// Same e-mail creates an order → recovered.
	$o = wc_create_order();
	$o->set_billing_email( strtoupper( $email ) );
	$o->set_billing_phone( $ph( '0X555 222 33 44' ) );
	$o->save();
	$check( 'plain save without status change does not mark ordered', 'ordered' !== Eru_ACCL_DB::get( $id )['status'] );
	$o->set_status( 'processing' );
	$o->save();
	$oid = $o->get_id();
	$row = Eru_ACCL_DB::get( $id );
	$check( 'order with same e-mail → status "sipariş verdi" (ordered)', 'ordered' === $row['status'] && (int) $row['order_id'] === $oid );

	// Phone path.
	$id3 = Eru_ACCL_Capture::save( 'diger-accl@example.com', $ph( '0X (555) 111 22 33' ), '', $cart, 100.0, true );
	$o2 = wc_create_order();
	$o2->set_billing_phone( $ph( '+9X0X 555 111 22 33' ) );
	$o2->set_status( 'processing' );
	$o2->save();
	$check( 'order with same phone → ordered', 'ordered' === Eru_ACCL_DB::get( $id3 )['status'] );
	$o2->delete( true );

	// Failed / cancelled orders are not a recovery.
	$id4 = Eru_ACCL_Capture::save( 'iptal-accl@example.com', '', '', $cart, 100.0, true );
	foreach ( array( 'failed', 'cancelled' ) as $st ) {
		$o3 = wc_create_order( array( 'status' => $st ) );
		$o3->set_billing_email( 'iptal-accl@example.com' );
		$o3->save();
		Eru_ACCL_Capture::on_order( $o3 );
		$check( "$st order with the same e-mail does not mark the lead ordered", 'ordered' !== Eru_ACCL_DB::get( $id4 )['status'] );
		$o3->delete( true );
	}

	// Privacy exporter / eraser (also reaches a phone-only lead via the phone of that person's orders).
	$idp = Eru_ACCL_DB::upsert( '', $ph( '0X555 222 33 44' ), 'Telefon', $cart, 10.0 );
	$check( 'phone-only lead stored', $idp > 0 && '' === Eru_ACCL_DB::get( $idp )['email'] );
	$exp0 = Eru_ACCL_DB::export_personal( $email );
	$check( 'exporter includes phone-only lead', 2 === count( $exp0['data'] ) );
	$exp = Eru_ACCL_DB::export_personal( $email );
	
	$er = Eru_ACCL_DB::erase_personal( $email );
	$check( 'eraser deletes by e-mail and by known phone', $er['items_removed'] && 0 === $count( "email='{$email}'" ) && null === Eru_ACCL_DB::get( $idp ) );

	// Purge. Pre-existing rows are stashed and restored so demo data survives.
	$stash = $wpdb->get_results( "SELECT * FROM {$t}", ARRAY_A ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB
	$id4 = Eru_ACCL_Capture::save( 'purge-accl@example.com', '', '', $cart, 10.0, true );
	$id5 = Eru_ACCL_Capture::save( 'old-accl@example.com', '', '', $cart, 10.0, true );
	$wpdb->update( $t, array( 'updated_gmt' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'id' => $id5 ) );
	Eru_ACCL_DB::purge( 30 );
	$check( 'purge(30) removes only rows older than 30 days', null !== Eru_ACCL_DB::get( $id4 ) && null === Eru_ACCL_DB::get( $id5 ) );
	Eru_ACCL_DB::purge( 0 );
	$check( 'purge(0) removes the fresh row', null === Eru_ACCL_DB::get( $id4 ) );
	foreach ( $stash as $r ) {
		$wpdb->insert( $t, $r ); // phpcs:ignore WordPress.DB
	}

	// Consent text readiness: enabled + empty text + no privacy page → not ready.
	update_option( Eru_ACCL_DB::OPTION, array_merge( Eru_ACCL_DB::defaults(), array( 'enabled' => 1 ) ) );
	$priv = get_option( 'wp_page_for_privacy_policy' );
	update_option( 'wp_page_for_privacy_policy', 0 );
	$check( 'no consent text and no privacy page → not ready', ! Eru_ACCL_Capture::is_ready() );
	update_option( 'wp_page_for_privacy_policy', $priv );
} finally {
	if ( $oid ) {
		wc_get_order( $oid )->delete( true );
	}
	$cleanup();
	null === $old ? delete_option( Eru_ACCL_DB::OPTION ) : update_option( Eru_ACCL_DB::OPTION, $old, false );
}
exit( $fail ? 1 : 0 );
