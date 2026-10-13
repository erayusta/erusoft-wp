<?php
/**
 * Click & Collect — shipping method available with a location, location saved on the order and shown in the order e-mail.
 *
 *   wp eval-file tests/php/ccl-pickup.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_CCL_Locations' ) ) {
	echo "FAIL: Eru_CCL_Locations missing (plugin not active?)\n";
	exit( 1 );
}

$old_loc = get_option( Eru_CCL_Locations::OPTION, null );
$old_set = get_option( 'eru_ccl_settings', null );
$oid     = 0;
try {
	require_once ERU_CCL_PATH . 'includes/class-eru-ccl-shipping-method.php';
	$pkg = array( 'contents' => array(), 'contents_cost' => 0, 'applied_coupons' => array(), 'destination' => array( 'country' => 'TR' ) );

	delete_option( Eru_CCL_Locations::OPTION );
	$m = new Eru_CCL_Shipping_Method( 0 );
	$m->calculate_shipping( $pkg );
	$check( 'no locations → method not offered (inert default)', 0 === count( $m->rates ) );

	update_option( Eru_CCL_Locations::OPTION, array( array( 'id' => 'ldemo1', 'name' => 'Demo Mağaza', 'address' => 'Örnek Mah. 1, Ankara', 'hours' => 'Hafta içi 10:00-18:00' ) ) );
	$m = new Eru_CCL_Shipping_Method( 0 );
	$m->calculate_shipping( $pkg );
	$check( 'location configured → free rate offered', 1 === count( $m->rates ) && (float) reset( $m->rates )->get_cost() === 0.0 );
	$check( 'registered as a WooCommerce shipping method', isset( WC()->shipping()->get_shipping_method_class_names()['eru_ccl'] ) );
	$check( 'supports shipping zones', in_array( 'shipping-zones', $m->supports, true ) );

	// Server-side validation of the chosen location.
	$e = new WP_Error();
	Eru_CCL_Checkout::validate( array( 'eru_ccl:3' ), 'bogus', $e );
	$check( 'unknown location rejected', $e->has_errors() );
	$e = new WP_Error();
	Eru_CCL_Checkout::validate( array( 'eru_ccl:3' ), 'ldemo1', $e );
	$check( 'valid location accepted', ! $e->has_errors() );
	$e = new WP_Error();
	Eru_CCL_Checkout::validate( array( 'flat_rate:1' ), '', $e );
	$check( 'other shipping methods unaffected', ! $e->has_errors() );
	update_option( Eru_CCL_Locations::OPTION, array_merge( (array) get_option( Eru_CCL_Locations::OPTION ), array( array( 'id' => 'ldemo2', 'name' => 'Demo Şube', 'address' => '', 'hours' => '' ) ) ) );
	$e = new WP_Error();
	Eru_CCL_Checkout::validate( array( 'eru_ccl:3' ), '', $e );
	$check( 'two locations: empty choice rejected', $e->has_errors() );
	update_option( Eru_CCL_Locations::OPTION, array( array( 'id' => 'ldemo1', 'name' => 'Demo Mağaza', 'address' => 'Örnek Mah. 1, Ankara', 'hours' => 'Hafta içi 10:00-18:00' ) ) );

	// Order with the method → location meta → e-mail HTML.
	$o = wc_create_order();
	$s = new WC_Order_Item_Shipping();
	$s->set_method_id( 'eru_ccl' );
	$s->set_method_title( 'Mağazadan teslim' );
	$s->set_total( '0' );
	$o->add_item( $s );
	$o->set_billing_email( 'ornek@example.com' );
	$check( 'invalid location not stored', false === Eru_CCL_Locations::save_to_order( $o, 'bogus' ) );
	$check( 'valid location stored', Eru_CCL_Locations::save_to_order( $o, 'ldemo1' ) );
	$o->save();
	$oid = $o->get_id();
	$o   = wc_get_order( $oid );
	$check( 'meta _eru_ccl_location persisted', 'Demo Mağaza' === $o->get_meta( '_eru_ccl_location' ) );
	$check( 'order is a pickup order', Eru_CCL_Locations::is_pickup_order( $o ) );

	$emails = WC()->mailer()->get_emails();
	$email  = $emails['WC_Email_Customer_Processing_Order'];
	$email->object = $o;
	$html = $email->get_content_html();
	$check( 'order e-mail HTML shows the location name', str_contains( $html, 'Demo Mağaza' ) );
	$check( 'order e-mail HTML shows the address', str_contains( $html, 'Örnek Mah. 1, Ankara' ) );
	ob_start();
	Eru_CCL_Checkout::admin_box( $o );
	$check( 'admin order screen shows the location', str_contains( ob_get_clean(), 'Demo Mağaza' ) );
	ob_start();
	Eru_CCL_Checkout::frontend_info( $o );
	$check( 'My Account / thank-you shows the location', str_contains( ob_get_clean(), 'Demo Mağaza' ) );

	// Block (Store API) checkout: with 2 locations the method is hidden in REST requests and a pickup order without a location is refused.
	update_option( Eru_CCL_Locations::OPTION, array( array( 'id' => 'ldemo1', 'name' => 'Demo Mağaza', 'address' => '', 'hours' => '' ), array( 'id' => 'ldemo2', 'name' => 'Demo Şube', 'address' => '', 'hours' => '' ) ) );
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/wc/store/v1/cart';
	$m = new Eru_CCL_Shipping_Method( 0 );
	$m->calculate_shipping( $pkg );
	$check( 'two locations: method hidden for REST/block requests', 0 === count( $m->rates ) );
	$_SERVER['REQUEST_URI'] = $uri;
	$o3 = wc_create_order();
	$s3 = new WC_Order_Item_Shipping();
	$s3->set_method_id( 'eru_ccl' );
	$o3->add_item( $s3 );
	$o3->save();
	$threw = false;
	try {
		Eru_CCL_Checkout::on_store_api_order( $o3 );
	} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e ) {
		$threw = 'eru_ccl_location' === $e->getErrorCode();
	}
	$check( 'two locations: pickup order without location → RouteException', $threw );
	update_option( Eru_CCL_Locations::OPTION, array( array( 'id' => 'ldemo1', 'name' => 'Demo Mağaza', 'address' => '', 'hours' => '' ) ) );
	Eru_CCL_Checkout::on_store_api_order( $o3 );
	$check( 'single location: auto-assigned on block checkout', 'Demo Mağaza' === wc_get_order( $o3->get_id() )->get_meta( '_eru_ccl_location' ) );
	$o3->delete( true );
	update_option( Eru_CCL_Locations::OPTION, array( array( 'id' => 'ldemo1', 'name' => 'Demo Mağaza', 'address' => 'Örnek Mah. 1, Ankara', 'hours' => 'Hafta içi 10:00-18:00' ) ) );

	// Optional status.
	update_option( 'eru_ccl_settings', array( 'ready_status' => 1, 'ready_email' => 0 ) );
	$check( 'ready status offered when enabled', isset( Eru_CCL_Checkout::add_status( array() )[ Eru_CCL_Checkout::READY ] ) );
	update_option( 'eru_ccl_settings', array( 'ready_status' => 0 ) );
	$check( 'ready status absent by default', ! isset( Eru_CCL_Checkout::add_status( array() )[ Eru_CCL_Checkout::READY ] ) );
} finally {
	if ( $oid ) {
		wc_get_order( $oid )->delete( true );
	}
	null === $old_loc ? delete_option( Eru_CCL_Locations::OPTION ) : update_option( Eru_CCL_Locations::OPTION, $old_loc );
	null === $old_set ? delete_option( 'eru_ccl_settings' ) : update_option( 'eru_ccl_settings', $old_set );
}
exit( $fail ? 1 : 0 );
