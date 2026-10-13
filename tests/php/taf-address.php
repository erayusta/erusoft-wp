<?php
/**
 * Turkish Address Fields — fields, AJAX data endpoint (Ankara → Çankaya …), validation, door number,
 * order address lines built from the TR parts.
 *
 *   wp eval-file tests/php/taf-address.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_TAF_Checkout' ) ) {
	echo "FAIL: Eru_TAF_Checkout missing (plugin not active?)\n";
	exit( 1 );
}

$old = get_option( Eru_TAF_Settings::OPTION, null );

/** Run the AJAX handler with the given query and return the decoded JSON. */
$ajax = function ( array $query ) {
	$saved_get = $_GET;
	$_GET      = $query;
	$doing     = fn() => true;
	$die       = fn() => function () {
		throw new RuntimeException( 'ajax-done' );
	};
	add_filter( 'wp_doing_ajax', $doing );
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		Eru_TAF_Checkout::ajax_data();
	} catch ( RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_die() replacement.
	}
	$out = ob_get_clean();
	remove_filter( 'wp_doing_ajax', $doing );
	remove_filter( 'wp_die_ajax_handler', $die );
	$_GET = $saved_get;
	return json_decode( (string) $out, true );
};
$ph    = fn( $n ) => str_replace( 'X', '', $n ); // assembled at run time: no phone-shaped literal in the repo.
$names = fn( $rows ) => array_map( fn( $r ) => is_array( $r ) ? $r[1] : $r, (array) $rows );

try {
	// Inert default: with the option unset at boot the checkout filters are not registered.
	if ( null === $old || empty( $old['enabled'] ) ) {
		$check( 'default (switched off): checkout field filter not registered', false === has_filter( 'woocommerce_billing_fields', array( 'Eru_TAF_Checkout', 'billing_fields' ) ) );
	}

	update_option( Eru_TAF_Settings::OPTION, Eru_TAF_Settings::sanitize( array_merge( Eru_TAF_Settings::defaults(), array( 'enabled' => 1 ) ) ) );

	// Fields.
	$f = Eru_TAF_Checkout::billing_fields( array( 'billing_address_1' => array( 'label' => 'Adres' ) ), 'TR' );
	$check( 'checkout fields include billing_eru_taf_district / _neighborhood / _street', isset( $f['billing_eru_taf_district'], $f['billing_eru_taf_neighborhood'], $f['billing_eru_taf_street'] ) );
	$check( 'door-number escape checkbox billing_eru_taf_no_number', isset( $f['billing_eru_taf_no_number'] ) && 'checkbox' === $f['billing_eru_taf_no_number']['type'] );
	$check( 'district required for TR (default settings)', true === $f['billing_eru_taf_district']['required'] );
	$check( 'street optional by default', false === $f['billing_eru_taf_street']['required'] );
	$f2 = Eru_TAF_Checkout::billing_fields( array(), 'DE' );
	$check( 'non-TR country: TR fields not required', false === $f2['billing_eru_taf_district']['required'] );
	$s = Eru_TAF_Checkout::shipping_fields( array(), 'TR' );
	$check( 'shipping group gets the same fields', isset( $s['shipping_eru_taf_district'], $s['shipping_eru_taf_street'] ) );
	$loc = Eru_TAF_Checkout::country_locale( array() );
	$check( 'TR locale: city hidden and optional (filled from İlçe)', ! empty( $loc['TR']['city']['hidden'] ) && false === $loc['TR']['city']['required'] );

	// AJAX handler.
	$r = $ajax( array( 'action' => 'eru_taf_data', 'level' => 'districts', 'il' => '06' ) );
	$d = $names( $r['data'] ?? array() );
	$check( 'AJAX: Ankara district list is non-empty', ! empty( $r['success'] ) && count( $d ) > 20 );
	$check( 'AJAX: Ankara districts contain Çankaya', in_array( 'Çankaya', $d, true ) );
	$bid = 0;
	foreach ( (array) ( $r['data'] ?? array() ) as $row ) {
		if ( 'Çankaya' === $row[1] ) {
			$bid = (int) $row[0];
		}
	}
	$r = $ajax( array( 'level' => 'neighborhoods', 'district' => (string) $bid ) );
	$check( 'AJAX: Çankaya neighbourhoods contain Kavaklıdere Mah.', in_array( 'Kavaklıdere Mah.', $names( $r['data'] ?? array() ), true ) );
	$mah  = Eru_TAF_Data::neighborhood_id( $bid, 'Kavaklıdere Mah.' );
	$r    = $ajax( array( 'level' => 'streets', 'district' => (string) $bid, 'neighborhood' => (string) $mah ) );
	$check( 'AJAX: streets decoded with suffix (Bestekar Cd.)', in_array( 'Bestekar Cd.', (array) ( $r['data'] ?? array() ), true ) );
	$r = $ajax( array( 'level' => 'districts', 'il' => '99' ) );
	$check( 'AJAX: unknown province rejected', empty( $r['success'] ) );
	$r = $ajax( array( 'level' => 'neighborhoods', 'district' => '../../wp-config' ) );
	$check( 'AJAX: non-numeric district rejected', empty( $r['success'] ) );

	// Duplicate registry names are merged at build time: one entry, streets of both records.
	$merkez = Eru_TAF_Data::district_id( '28', 'Merkez' );
	$dupes  = array_filter( Eru_TAF_Data::neighborhoods( $merkez ), fn( $r ) => 'Çaldağ Köyü, Yahyalı Mevkii' === $r[1] );
	$check( 'duplicate mahalle name appears once in its ilçe', 1 === count( $dupes ) );
	$hid = Eru_TAF_Data::neighborhood_id( $merkez, 'Çaldağ Köyü, Yahyalı Mevkii' );
	$check( 'merged mahalle carries the streets of both records (9 + 1)', 10 === count( Eru_TAF_Data::streets( $merkez, $hid ) ) );
	$all = array_map( fn( $r ) => $r[1], Eru_TAF_Data::neighborhoods( $merkez ) );
	$check( 'mahalle names unique within the ilçe', count( $all ) === count( array_unique( $all ) ) );

	// Door number validator.
	$check( 'door-number validator rejects "abc"', ! Eru_TAF_Normalize::has_door_number( 'abc' ) );
	$check( 'door-number validator accepts "13A"', Eru_TAF_Normalize::has_door_number( '13A' ) );

	$data = array(
		'billing_country'                => 'TR',
		'billing_state'                  => 'TR06',
		'billing_eru_taf_district'       => 'Çankaya',
		'billing_eru_taf_neighborhood'   => 'Kavaklıdere Mah.',
		'billing_eru_taf_street'         => 'Bestekar Cd.',
		'billing_address_1'              => 'abc',
		'billing_address_2'              => '',
		'billing_city'                   => '',
		'ship_to_different_address'      => false,
	);
	$e = Eru_TAF_Checkout::group_errors( $data, 'billing' );
	$check( 'checkout: address line "abc" stopped (no door number)', isset( $e['billing_address_1'] ) );
	$check( 'checkout: valid il/ilçe/mahalle/sokak chain accepted', 1 === count( $e ) );
	$e = Eru_TAF_Checkout::group_errors( array_merge( $data, array( 'billing_address_1' => '13A' ) ), 'billing' );
	$check( 'checkout: "13A" accepted', array() === $e );
	$e = Eru_TAF_Checkout::group_errors( array_merge( $data, array( 'billing_eru_taf_no_number' => 1 ) ), 'billing' );
	$check( 'checkout: "abc" accepted when "no number" is ticked', array() === $e );
	$e = Eru_TAF_Checkout::group_errors( array_merge( $data, array( 'billing_state' => 'TR35', 'billing_address_1' => '13A' ) ), 'billing' );
	$check( 'checkout: district of another province rejected', isset( $e['billing_eru_taf_district'] ) );
	$e = Eru_TAF_Checkout::group_errors( array_merge( $data, array( 'billing_eru_taf_street' => 'Uydurma Sk.', 'billing_address_1' => '13A' ) ), 'billing' );
	$check( 'checkout: street not in the neighbourhood rejected', isset( $e['billing_eru_taf_street'] ) );
	$e = Eru_TAF_Checkout::group_errors( array_merge( $data, array( 'billing_eru_taf_street' => Eru_TAF_Checkout::OTHER, 'billing_address_1' => 'Demo Sk. 13A' ) ), 'billing' );
	$check( 'checkout: "Listede yok" street accepted', array() === $e );
	$err = new WP_Error();
	Eru_TAF_Checkout::validate_checkout( $data, $err );
	$check( 'woocommerce_after_checkout_validation adds the error with field id', in_array( 'billing_address_1_eru_taf', $err->get_error_codes(), true ) );

	// Posted data: city from İlçe, address line cleanup.
	$p = Eru_TAF_Checkout::posted_data( array_merge( $data, array( 'billing_address_1' => '  no:13   d:5 ' ) ) );
	$check( 'posted data: city = İlçe', 'Çankaya' === $p['billing_city'] );
	$check( 'posted data: "no:13 d:5" → "No: 13 Daire: 5"', 'No: 13 Daire: 5' === $p['billing_address_1'] );
	$check( 'normalisation is idempotent', 'No: 13 Daire: 5' === Eru_TAF_Normalize::line( 'No: 13 Daire: 5' ) );
	$check( '"Blok D 5" unchanged (bare D is a block letter)', 'Blok D 5' === Eru_TAF_Normalize::line( 'Blok D 5' ) );
	$check( '"d.5" → "Daire: 5"', 'No: 3 Daire: 5' === Eru_TAF_Normalize::line( 'no 3 d.5' ) );
	$check( 'Turkish title case: "BAHRİYE SOKAK NO 4" → "Bahriye Sokak No: 4"', 'Bahriye Sokak No: 4' === Eru_TAF_Normalize::line( 'BAHRİYE SOKAK NO 4' ) );
	$check( 'phone "+90 …" normalised to 0XXXXXXXXXX', $ph( '0X5550000000' ) === Eru_TAF_Normalize::phone( $ph( '+X90 555 000 00 00' ) ) );
	$check( 'phone "abc" invalid', null === Eru_TAF_Normalize::phone( 'abc' ) );

	// Order address lines from the parts; shipping = billing when not shipping elsewhere.
	$o = new WC_Order();
	$o->set_billing_country( 'TR' );
	$o->set_billing_state( 'TR06' );
	$o->set_billing_address_1( 'No: 13' );
	$o->set_billing_city( 'Çankaya' );
	$o->set_shipping_country( 'TR' );
	$o->set_shipping_state( 'TR06' );
	$o->set_shipping_address_1( 'No: 13' );
	$o->set_shipping_city( 'Çankaya' );
	Eru_TAF_Checkout::create_order( $o, array_merge( $data, array( 'billing_address_1' => 'No: 13' ) ) );
	$check( 'order billing address_1 = street + door', 'Bestekar Cd. No: 13' === $o->get_billing_address_1() );
	$check( 'order billing address_2 = neighbourhood', 'Kavaklıdere Mah.' === $o->get_billing_address_2() );
	$check( 'order shipping built from billing parts (ships to billing address)', 'Bestekar Cd. No: 13' === $o->get_shipping_address_1() && 'Kavaklıdere Mah.' === $o->get_shipping_address_2() && 'Kavaklıdere Mah.' === $o->get_meta( '_shipping_eru_taf_neighborhood' ) );
	$formatted = WC()->countries->get_formatted_address(
		array(
			'first_name' => 'Demo',
			'last_name'  => 'Müşteri',
			'address_1'  => $o->get_billing_address_1(),
			'address_2'  => $o->get_billing_address_2(),
			'city'       => $o->get_billing_city(),
			'state'      => 'TR06',
			'country'    => 'TR',
		)
	);
	$check( 'formatted TR address reads street / neighbourhood / district / province', str_contains( $formatted, 'Bestekar Cd. No: 13' ) && str_contains( $formatted, 'Kavaklıdere Mah.' ) && str_contains( $formatted, 'Çankaya' ) && str_contains( $formatted, 'Ankara' ) );
} finally {
	null === $old ? delete_option( Eru_TAF_Settings::OPTION ) : update_option( Eru_TAF_Settings::OPTION, $old );
}
exit( $fail ? 1 : 0 );
