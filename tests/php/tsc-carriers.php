<?php
/**
 * Turkish Shipping Carriers — per-carrier flat / free-over rates, desi rates, tracking link in the order e-mail.
 *
 *   wp eval-file tests/php/tsc-carriers.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_TSC_Carriers' ) ) {
	echo "FAIL: Eru_TSC_Carriers missing (plugin not active?)\n";
	exit( 1 );
}

$old_car = get_option( Eru_TSC_Carriers::OPTION, null );
$old_set = get_option( Eru_TSC_Carriers::SETTINGS_OPTION, null );
$oid     = 0;
$pid     = 0;

/** Package with one line of the given product and quantity; contents_cost = price × qty. */
$package = function ( WC_Product $p, int $qty, float $amount ) {
	return array(
		'contents'        => array( 'k1' => array( 'data' => $p, 'quantity' => $qty, 'line_total' => $amount ) ),
		'contents_cost'   => $amount,
		'applied_coupons' => array(),
		'destination'     => array( 'country' => 'TR', 'state' => 'TR06', 'postcode' => '', 'city' => '', 'address' => '' ),
	);
};
$rate_costs = function ( Eru_TSC_Shipping_Method $m, array $pkg ) {
	$m->rates = array();
	$m->calculate_shipping( $pkg );
	$out = array();
	foreach ( $m->rates as $r ) {
		$out[ $r->get_meta_data()['_eru_tsc_carrier'] ?? '' ] = (float) $r->get_cost();
	}
	return $out;
};

// Fixed units for the desi arithmetic, whatever the demo store uses.
$units = array(
	'pre_option_woocommerce_dimension_unit' => fn() => 'cm',
	'pre_option_woocommerce_weight_unit'    => fn() => 'kg',
);
foreach ( $units as $hook => $cb ) {
	add_filter( $hook, $cb );
}

try {
	require_once ERU_TSC_PATH . 'includes/class-eru-tsc-shipping-method.php';

	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Ürün' );
	$p->set_regular_price( '1000' );
	$p->set_weight( '2' );
	$p->set_length( '30' );
	$p->set_width( '20' );
	$p->set_height( '20' );
	$p->set_status( 'private' );
	$pid = $p->save();
	$p   = wc_get_product( $pid );

	delete_option( Eru_TSC_Carriers::OPTION );
	$m = new Eru_TSC_Shipping_Method( 0 );
	$check( 'no carrier enabled → method not offered (inert default)', ! $m->is_available( $package( $p, 1, 1000 ) ) && array() === $rate_costs( $m, $package( $p, 1, 1000 ) ) );

	$check( 'registered as a WooCommerce shipping method', isset( WC()->shipping()->get_shipping_method_class_names()['eru_tsc'] ) );

	update_option(
		Eru_TSC_Carriers::OPTION,
		Eru_TSC_Carriers::sanitize_carriers(
			array(
				'yurtici' => array( 'enabled' => 1, 'name' => 'Yurtiçi Kargo', 'mode' => 'flat', 'cost' => '95', 'free_over' => '1250', 'tracking_url' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code={takip_no}' ),
				'aras'    => array( 'enabled' => 1, 'name' => 'Aras Kargo', 'mode' => 'desi', 'cost' => '50', 'per_desi' => '10,5', 'free_over' => '0' ),
			)
		)
	);
	$m = new Eru_TSC_Shipping_Method( 0 );

	$c = $rate_costs( $m, $package( $p, 1, 1000 ) );
	$check( 'Yurtiçi flat 95: 1000 TL cart → 95', isset( $c['yurtici'] ) && 95.0 === $c['yurtici'] );
	$c2 = $rate_costs( $m, $package( $p, 1, 1600 ) );
	$check( 'Yurtiçi free over 1250: 1600 TL cart → 0', isset( $c2['yurtici'] ) && 0.0 === $c2['yurtici'] );
	$check( 'threshold is inclusive: 1250 TL cart → 0', 0.0 === ( $rate_costs( $m, $package( $p, 1, 1250 ) )['yurtici'] ?? -1 ) );
	$check( 'disabled carriers are not offered', 2 === count( $c ) && ! isset( $c['mng'] ) );

	// Desi: 30×20×20/3000 = 4 desi > 2 kg → 4 per item; 2 items = 8 desi → 50 + 8 × 10.5 = 134.
	$check( 'desi of one item = max(kg, volumetric)', abs( Eru_TSC_Carriers::package_desi( $package( $p, 1, 1000 ) ) - 4.0 ) < 0.001 );
	$check( 'Aras base 50 + 10.5/desi, 2 items (8 desi) → 134', 134.0 === ( $rate_costs( $m, $package( $p, 2, 2000 ) )['aras'] ?? -1 ) );
	$check( 'Turkish decimal comma in settings stored as 10.5', '10.5' === Eru_TSC_Carriers::get( 'aras' )['per_desi'] );

	// Instance restricted to one carrier.
	$m2                    = new Eru_TSC_Shipping_Method( 999999 ); // instance options are only read for a non-zero instance id
	$m2->instance_settings = array( 'carriers' => array( 'aras' ), 'tax_status' => 'taxable' );
	$c3                    = $rate_costs( $m2, $package( $p, 1, 1000 ) );
	$check( 'instance carrier filter offers only the picked carrier', array( 'aras' ) === array_keys( $c3 ) );

	// Template sanitising keeps the placeholder, rejects other schemes.
	$check( 'template keeps {takip_no}', str_contains( Eru_TSC_Carriers::sanitize_template( 'https://ornek.example.com/t?no={takip_no}' ), '{takip_no}' ) );
	$check( 'javascript: template rejected', '' === Eru_TSC_Carriers::sanitize_template( 'javascript:alert(1)//{takip_no}' ) );

	// Order with a tracking number → customer e-mail HTML has the carrier tracking URL with the number.
	$o = wc_create_order();
	$s = new WC_Order_Item_Shipping();
	$s->set_method_id( 'eru_tsc' );
	$s->set_method_title( 'Yurtiçi Kargo' );
	$s->add_meta_data( '_eru_tsc_carrier', 'yurtici', true );
	$s->set_total( '95' );
	$o->add_item( $s );
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_email( 'ornek@example.com' );
	$o->save();
	$oid = $o->get_id();
	$check( 'carrier taken from the checkout rate', 'yurtici' === Eru_TSC_Tracking::get( $o )['carrier'] );
	$check( 'no tracking number → no block', '' === Eru_TSC_Tracking::html( $o ) );

	$check( 'new tracking number reported as changed', Eru_TSC_Tracking::set( $o, 'yurtici', '123' ) );
	$o->save();
	$o = wc_get_order( $oid );
	$check( 'meta _eru_tsc_tracking_number persisted', '123' === $o->get_meta( '_eru_tsc_tracking_number' ) );
	$check( 'same number again is not a change', ! Eru_TSC_Tracking::set( $o, 'yurtici', '123' ) );

	$expected = 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=123';
	$emails   = WC()->mailer()->get_emails();
	$email    = $emails['WC_Email_Customer_Completed_Order'];
	$email->object = $o;
	$html     = $email->get_content_html();
	$check( 'order e-mail HTML contains the tracking URL with 123', str_contains( $html, esc_url( $expected ) ) );
	$check( 'order e-mail HTML names the carrier', str_contains( $html, 'Yurtiçi Kargo' ) );
	ob_start();
	Eru_TSC_Tracking::email_info( $o, false, true );
	$check( 'plain-text e-mail contains the URL', str_contains( ob_get_clean(), $expected ) );
	$cs_old = get_option( Eru_TSC_Carriers::OPTION, null );
	$cs     = Eru_TSC_Carriers::sanitize_carriers( array( 'yurtici' => array( 'enabled' => 1, 'name' => 'Demo & "Kargo"', 'cost' => '95' ) ) );
	update_option( Eru_TSC_Carriers::OPTION, $cs );
	ob_start();
	Eru_TSC_Tracking::email_info( $o, false, true );
	$plain = ob_get_clean();
	$check( 'plain-text e-mail is plain text (no HTML entities)', str_contains( $plain, 'Demo & "Kargo" 123' ) && ! str_contains( $plain, '&amp;' ) && ! str_contains( $plain, '&quot;' ) );
	null === $cs_old ? delete_option( Eru_TSC_Carriers::OPTION ) : update_option( Eru_TSC_Carriers::OPTION, $cs_old );
	ob_start();
	Eru_TSC_Tracking::frontend_info( $o );
	$check( 'My Account / thank-you shows the tracking link', str_contains( ob_get_clean(), esc_url( $expected ) ) );
	$check( 'helper eru_tsc_get_tracking()', $expected === eru_tsc_get_tracking( $oid )['url'] );

	update_option( Eru_TSC_Carriers::SETTINGS_OPTION, array( 'show_in_emails' => 0, 'notify_customer' => 0 ) );
	ob_start();
	Eru_TSC_Tracking::email_info( $o, false, false );
	$check( 'e-mail block can be switched off', '' === ob_get_clean() );

	// Optional customer note.
	update_option( Eru_TSC_Carriers::SETTINGS_OPTION, array( 'show_in_emails' => 1, 'notify_customer' => 1 ) );
	add_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );
	$fired = 0;
	add_action( 'eru_tsc_tracking_saved', function () use ( &$fired ) { $fired++; } );
	Eru_TSC_Tracking::set( $o, 'yurtici', '456' );
	$o->save();
	Eru_TSC_Tracking::after_new_number( $o );
	$notes = wc_get_order_notes( array( 'order_id' => $oid, 'type' => 'customer' ) );
	$check( 'customer note with tracking link added when enabled', $notes && str_contains( $notes[0]->content, 'code=456' ) );
	$check( 'eru_tsc_tracking_saved action fired', 1 === $fired );
} finally {
	foreach ( $units as $hook => $cb ) {
		remove_filter( $hook, $cb );
	}
	if ( $oid ) {
		wc_get_order( $oid )->delete( true );
	}
	if ( $pid ) {
		wc_get_product( $pid )->delete( true );
	}
	null === $old_car ? delete_option( Eru_TSC_Carriers::OPTION ) : update_option( Eru_TSC_Carriers::OPTION, $old_car );
	null === $old_set ? delete_option( Eru_TSC_Carriers::SETTINGS_OPTION ) : update_option( Eru_TSC_Carriers::SETTINGS_OPTION, $old_set );
}
exit( $fail ? 1 : 0 );
