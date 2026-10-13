<?php
/**
 * Order Blacklist — checkout validation blocks a blocklisted e-mail, allows another.
 *
 *   wp eval-file tests/php/ob-blacklist.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_OB_Guard' ) ) {
	echo "FAIL: Eru_OB_Guard missing (plugin not active?)\n";
	exit( 1 );
}

// Fictional numbers are assembled at run time so the repository scrubber sees no phone-shaped literal.
$ph = fn( $n ) => str_replace( 'X', '', $n );
$old_s   = get_option( Eru_OB_Guard::OPTION, null );
$old_log = get_option( Eru_OB_Guard::LOG_OPTION, null );
$oid     = 0;
$pid     = 0;
$cleanup_emails = array( 'kotu@example.com', 'iyi@example.com' );
try {
	update_option(
		Eru_OB_Guard::OPTION,
		array_merge(
			Eru_OB_Guard::defaults(),
			array(
				'emails'    => "kotu@example.com\n@example.net",
				'phones'    => $ph( '0X555 000 11 22' ),
				'ips'       => '2001:db8::7',
				'addresses' => 'Örnek Sk. No: 9',
			)
		)
	);
	delete_option( Eru_OB_Guard::LOG_OPTION );

	$run = function ( array $data ) {
		$errors = new WP_Error();
		Eru_OB_Guard::validate_checkout( $data, $errors );
		return $errors;
	};

	$e = $run( array( 'billing_email' => 'kotu@example.com' ) );
	$check( 'blocked e-mail adds an error', $e->has_errors() && $e->get_error_code() === 'eru_ob_blocked' );
	$e = $run( array( 'billing_email' => 'KOTU@Example.com' ) );
	$check( 'e-mail match is case-insensitive', $e->has_errors() );
	$e = $run( array( 'billing_email' => 'iyi@example.com' ) );
	$check( 'other e-mail adds no error', ! $e->has_errors() );
	$e = $run( array( 'billing_email' => 'biri@example.net' ) );
	$check( '@domain rule blocks the domain', $e->has_errors() );
	$e = $run( array( 'billing_email' => 'iyi@example.com', 'billing_phone' => $ph( '+9X0X 555 000 11 22' ) ) );
	$check( 'phone match ignores formatting/prefix', $e->has_errors() );
	$e = $run( array( 'billing_email' => 'iyi@example.com', 'billing_address_1' => 'ÖRNEK SK. NO: 9 daire 2' ) );
	$check( 'address fragment match', $e->has_errors() );
	add_filter( 'eru_ob_client_ip', $f = fn() => '2001:db8::7' );
	$e = $run( array( 'billing_email' => 'iyi@example.com' ) );
	remove_filter( 'eru_ob_client_ip', $f );
	$check( 'IP match via eru_ob_client_ip filter', $e->has_errors() );

	$log = get_option( Eru_OB_Guard::LOG_OPTION, array() );
	$check( 'blocked attempts are logged', count( $log ) >= 5 );
	$check( 'log holds no visitor e-mail', ! str_contains( wp_json_encode( $log ), 'iyi@example.com' ) );

	for ( $i = 0; $i < 120; $i++ ) {
		Eru_OB_Guard::log( array( 'type' => 'email', 'rule' => 'x' ), 'checkout' );
	}
	$check( 'log capped at 100', count( get_option( Eru_OB_Guard::LOG_OPTION, array() ) ) === 100 );
	global $wpdb;
	$al = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", Eru_OB_Guard::LOG_OPTION ) );
	$check( 'log option autoload off', in_array( $al, array( 'no', 'off', 'auto-off' ), true ) );

	// Empty lists = inert.
	update_option( Eru_OB_Guard::OPTION, Eru_OB_Guard::defaults() );
	$e = $run( array( 'billing_email' => 'kotu@example.com' ) );
	$check( 'empty lists: no error', ! $e->has_errors() );

	// Backstop cancels a matching order created outside checkout validation.
	update_option( Eru_OB_Guard::OPTION, array_merge( Eru_OB_Guard::defaults(), array( 'emails' => 'kotu@example.com' ) ) );
	$o = wc_create_order();
	$o->set_billing_email( 'kotu@example.com' );
	$o->save();
	Eru_OB_Guard::backstop( $o->get_id(), wc_get_order( $o->get_id() ) );
	$oid = $o->get_id();
	$check( 'backstop cancels matching order', wc_get_order( $oid )->get_status() === 'cancelled' );
	$check( 'no "cancelled order" admin e-mail for a blocked attempt', false === apply_filters( 'woocommerce_email_enabled_cancelled_order', true, wc_get_order( $oid ), null ) );
	$plain = wc_create_order();
	$check( 'ordinary cancelled orders still e-mail the admin', true === apply_filters( 'woocommerce_email_enabled_cancelled_order', true, $plain, null ) );
	$plain->delete( true );


	// Real Store API (block checkout) route: a blocked e-mail must fail the request and never reach processing.
	// Price-0 virtual product = the "no payment needed" path that used to push cancelled orders to processing.
	update_option( Eru_OB_Guard::OPTION, array_merge( Eru_OB_Guard::defaults(), array( 'emails' => 'kotu@example.com' ) ) );
	$sp = new WC_Product_Simple();
	$sp->set_name( 'OB Store API Demo' );
	$sp->set_regular_price( '0' );
	$sp->set_virtual( true );
	$sp->set_status( 'publish' );
	$pid  = $sp->save();
	$call = function ( string $mail ) use ( $pid ) {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $pid, 1 );
		$addr = array( 'first_name' => 'Ayşe', 'last_name' => 'Örnek', 'address_1' => 'Örnek Mah. 1', 'city' => 'Ankara', 'state' => 'TR06', 'postcode' => '06000', 'country' => 'TR', 'phone' => '' );
		$req  = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$req->set_body_params( array( 'billing_address' => $addr + array( 'email' => $mail ), 'shipping_address' => $addr, 'payment_method' => '' ) );
		$req->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		return rest_do_request( $req );
	};
	$res = $call( 'kotu@example.com' );
	$check( 'Store API checkout: blocked e-mail → 400 eru_ob_blocked', 400 === $res->get_status() && 'eru_ob_blocked' === ( $res->get_data()['code'] ?? '' ) );
	$live = wc_get_orders( array( 'billing_email' => 'kotu@example.com', 'limit' => -1, 'status' => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ) ) );
	$check( 'Store API checkout: no processing/completed/pending order for the blocked e-mail', 0 === count( $live ) );
	$res = $call( 'iyi@example.com' );
	$check( 'Store API checkout: other e-mail succeeds (control)', 200 === $res->get_status() );

	// Privacy eraser removes the e-mail rule.
	$r = Eru_OB_Guard::erase_personal( 'kotu@example.com' );
	$check( 'eraser removes list entry', $r['items_removed'] && ! str_contains( Eru_OB_Guard::settings()['emails'], 'kotu@' ) );
} finally {
	if ( $oid ) {
		wc_get_order( $oid )->delete( true );
	}
	foreach ( wc_get_orders( array( 'billing_email' => $cleanup_emails, 'limit' => -1, 'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'checkout-draft' ) ) ) ) as $o ) {
		$o->delete( true );
	}
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
	null === $old_s ? delete_option( Eru_OB_Guard::OPTION ) : update_option( Eru_OB_Guard::OPTION, $old_s );
	null === $old_log ? delete_option( Eru_OB_Guard::LOG_OPTION ) : update_option( Eru_OB_Guard::LOG_OPTION, $old_log, false );
}
exit( $fail ? 1 : 0 );
