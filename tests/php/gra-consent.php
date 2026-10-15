<?php
/**
 * Growth Automations — consent, capture, restore, unsubscribe, suppression, double opt-in.
 *
 *   wp eval-file tests/php/gra-consent.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_GRA_Settings' ) ) {
	echo "FAIL: Eru_GRA_Settings missing (plugin not active?)\n";
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
		if ( false !== strpos( $url, 'wp-cron.php' ) || false !== strpos( $url, 'admin-ajax.php' ) ) {
			return new WP_Error( 'eru_gra_test', 'spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);
foreach ( array( 'new_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order' ) as $eru_gra_id ) {
	add_filter( 'woocommerce_email_enabled_' . $eru_gra_id, '__return_false' );
}
add_filter( 'wc_tax_enabled', '__return_false' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

global $wpdb;
$ctable = $wpdb->prefix . 'eru_gra_carts';

$to_mails = function ( $email ) use ( &$mails ) {
	return array_values( array_filter( $mails, fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
};
$drain = function () {
	for ( $i = 0; $i < 12; $i++ ) {
		ActionScheduler_QueueRunner::instance()->run();
	}
};

$purge = function () use ( $wpdb, $ctable ) {
	Eru_GRA_DB::install();
	$wpdb->query( "DELETE FROM {$ctable} WHERE email LIKE 'gra-t%'" );
	foreach ( get_posts( array( 'post_type' => Eru_GRA_Alerts::CPT, 'post_status' => 'any', 'numberposts' => -1, 's' => 'gra-t' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'gra%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE 'ERU-GRA-%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'search' => 'gra-t' ) ) as $oid ) {
		wc_get_order( $oid )->delete( true );
	}
	foreach ( array( 'gra-t-user' ) as $login ) {
		$u = get_user_by( 'login', $login );
		if ( $u ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $u->ID );
		}
	}
	delete_option( Eru_GRA_Consent::OPTION );
	delete_option( Eru_GRA_Settings::OPTION );
	as_unschedule_all_actions( '', array(), 'eru-gra' );
	delete_transient( Eru_GRA_Cart::rate_key() );
	delete_transient( Eru_GRA_Alerts::rate_key() );
	delete_transient( Eru_GRA_Alerts::global_key() );
};

$orig_filters = $GLOBALS['wp_filter']['eru_gra_has_marketing_consent'] ?? null;
unset( $GLOBALS['wp_filter']['eru_gra_has_marketing_consent'] );

$purge();
wc_load_cart();

// Fixtures: one simple product, one variable product.
$simple = new WC_Product_Simple();
$simple->set_name( 'GRA Demo Ürün' );
$simple->set_sku( 'ERU-GRA-S1' );
$simple->set_regular_price( '40' );
$simple->set_manage_stock( true );
$simple->set_stock_quantity( 50 );
$simple->save();
$simple_id = $simple->get_id();

$var = new WC_Product_Variable();
$var->set_name( 'GRA Demo Varyasyonlu' );
$var->set_sku( 'ERU-GRA-V' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Beden' );
$attr->set_options( array( 'S', 'M' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$var->set_attributes( array( $attr ) );
$var_id = $var->save();
$vars   = array();
foreach ( array( 'S', 'M' ) as $size ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var_id );
	$v->set_attributes( array( 'beden' => $size ) );
	$v->set_regular_price( '55' );
	$v->set_sku( 'ERU-GRA-V-' . $size );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 0 );
	$v->save();
	$vars[ $size ] = $v->get_id();
}
WC_Product_Variable::sync( $var_id );

$mk_order = function ( $email, $status, $created_ago = 0, $completed_days = null, $consent = false ) use ( $simple_id ) {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $simple_id ), 1 );
	$o->set_billing_email( $email );
	$o->set_billing_first_name( 'Demo' );
	$o->set_payment_method( 'bacs' );
	$o->calculate_totals();
	$o->set_status( $status );
	if ( $created_ago ) {
		$o->set_date_created( time() - $created_ago );
	}
	if ( $consent ) {
		$o->update_meta_data( '_eru_gra_marketing_consent', 'yes' );
	}
	$o->save();
	if ( null !== $completed_days ) {
		$o->set_date_completed( time() - (int) ( $completed_days * DAY_IN_SECONDS ) );
		$o->set_date_created( time() - (int) ( $completed_days * DAY_IN_SECONDS ) - HOUR_IN_SECONDS );
		$o->save();
	}
	return $o->get_id();
};

Eru_GRA_Settings::save_for_test(
	array(
		'cart' => array(
			'enabled'     => 'yes',
			'delay'       => '4',
			'subject'     => 'GRA-T cart {site_title}',
			'body'        => 'Merhaba {customer_name}, sepetiniz: {cart_link}',
			'coupon_mode' => 'none',
		),
		'bacs' => array(
			'enabled' => 'yes',
			'delay'   => '3',
			'subject' => 'GRA-T bacs {order_number}',
		),
	)
);

// (default) consent is false without a provider.
$check( 'consent defaults to false when no provider hooks the filter', false === Eru_GRA_Consent::has( 'gra-t9@example.com' ) );
add_filter( 'eru_gra_has_marketing_consent', $f_true = fn( $has, $email = '' ) => 'gra-t9@example.com' === $email ? true : $has, 10, 2 );
$check( 'consent filter can grant consent', true === Eru_GRA_Consent::has( 'gra-t9@example.com' ) );
remove_filter( 'eru_gra_has_marketing_consent', $f_true, 10 );

// (a) Guest capture needs the consent checkbox.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $simple_id, 2 );
$r = Eru_GRA_Cart::handle_capture( array( 'email' => 'gra-t1@example.com', 'consent' => '0' ) );
$check( '(a) guest capture without consent is refused', false === $r['ok'] && 'no-consent' === $r['code'] );
$check( '(a) no row stored without consent', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email LIKE 'gra-t%'" ) );
$r = Eru_GRA_Cart::handle_capture( array( 'email' => 'not-an-email', 'consent' => '1' ) );
$check( '(a) invalid e-mail refused', false === $r['ok'] && 'email' === $r['code'] );
delete_transient( Eru_GRA_Cart::rate_key() );
$r = Eru_GRA_Cart::handle_capture( array( 'email' => 'GRA-T1@example.com', 'consent' => '1' ) );
$row = $wpdb->get_row( "SELECT * FROM {$ctable} WHERE email = 'gra-t1@example.com'", ARRAY_A );
$check( '(a) consent stores a row with consent_source checkout', true === $r['ok'] && $row && 'checkout' === $row['consent_source'] && 'open' === $row['status'] );
$cart = json_decode( $row['cart'], true );
$check( '(a) cart JSON holds only product/variation ids and quantities', 1 === count( $cart ) && array( 'product_id', 'variation_id', 'qty' ) === array_keys( $cart[0] ) && 2 === (int) $cart[0]['qty'] );
// Rate limit.
$limited = false;
for ( $i = 0; $i < 40; $i++ ) {
	$x = Eru_GRA_Cart::handle_capture( array( 'email' => 'gra-t1@example.com', 'consent' => '1' ) );
	if ( 'rate' === $x['code'] ) {
		$limited = true;
		break;
	}
}
$check( '(a) capture is rate limited per IP', $limited );
delete_transient( Eru_GRA_Cart::rate_key() );
$r = Eru_GRA_Cart::handle_capture( array( 'email' => 'gra-t1@example.com', 'consent' => '0' ) );
$check( '(a) un-ticking the box removes the open row', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email = 'gra-t1@example.com'" ) );

// Untick removes only this session's own row.
$wpdb->insert( $ctable, array( 'email' => 'gra-t-other@example.com', 'user_id' => 0, 'cart' => '[]', 'token' => '', 'consent_source' => 'checkout', 'status' => 'open', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
delete_transient( Eru_GRA_Cart::rate_key() );
Eru_GRA_Cart::handle_capture( array( 'email' => 'gra-t-other@example.com', 'consent' => '0' ) );
$check( 'untick cannot delete another session\'s row', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email = 'gra-t-other@example.com'" ) );

// Global hourly cap for guest capture.
$cap_key = 'eru_gra_cgl_' . gmdate( 'YmdH' );
delete_transient( $cap_key );
add_filter( 'eru_gra_capture_hourly_cap', $capf = fn() => 2 );
$codes = array();
foreach ( array( 'gra-t-c1@example.com', 'gra-t-c2@example.com', 'gra-t-c3@example.com' ) as $em ) {
	delete_transient( Eru_GRA_Cart::rate_key() );
	$codes[] = Eru_GRA_Cart::handle_capture( array( 'email' => $em, 'consent' => '1' ) )['code'];
}
remove_filter( 'eru_gra_capture_hourly_cap', $capf );
delete_transient( $cap_key );
$check( 'guest capture has a global hourly cap', array( 'stored', 'stored', 'busy' ) === $codes );

// AJAX wrapper refuses a bad nonce.
$_REQUEST['nonce'] = 'bad';
$die = fn() => function () { throw new RuntimeException( 'die' ); };
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', $die );
$died = false;
try {
	Eru_GRA_Cart::ajax();
} catch ( RuntimeException $e ) {
	$died = true;
}
remove_filter( 'wp_die_ajax_handler', $die );
remove_filter( 'wp_doing_ajax', '__return_true' );
$check( '(a) AJAX capture refuses a bad nonce', $died );
unset( $_REQUEST['nonce'] );

// (b) Logged-in user.
$uid = wp_insert_user( array( 'user_login' => 'gra-t-user', 'user_pass' => wp_generate_password(), 'user_email' => 'gra-t-user@example.com' ) );
wp_set_current_user( $uid );
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $simple_id, 1 );
do_action( 'woocommerce_cart_updated' );
$check( '(b) logged-in user without opt-in: nothing stored', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email = 'gra-t-user@example.com'" ) );
update_user_meta( $uid, Eru_GRA_Consent::USER_META, 'yes' );
do_action( 'woocommerce_cart_updated' );
$row = $wpdb->get_row( "SELECT * FROM {$ctable} WHERE email = 'gra-t-user@example.com'", ARRAY_A );
$check( '(b) account opt-in stores the cart with consent_source account', $row && 'account' === $row['consent_source'] && (int) $row['user_id'] === (int) $uid );
Eru_GRA_Consent::suppress( 'gra-t-user@example.com' );
$wpdb->query( "DELETE FROM {$ctable} WHERE email = 'gra-t-user@example.com'" );
do_action( 'woocommerce_cart_updated' );
$check( '(b) a suppressed address is not captured even with opt-in', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email = 'gra-t-user@example.com'" ) );
delete_option( Eru_GRA_Consent::OPTION );
delete_user_meta( $uid, Eru_GRA_Consent::USER_META );
add_filter( 'eru_gra_has_marketing_consent', $f_user = fn( $has, $email = '' ) => 'gra-t-user@example.com' === $email ? true : $has, 10, 2 );
do_action( 'woocommerce_cart_updated' );
$row = $wpdb->get_row( "SELECT * FROM {$ctable} WHERE email = 'gra-t-user@example.com'", ARRAY_A );
$check( '(b) the consent filter alone also allows capture (source filter)', $row && 'filter' === $row['consent_source'] );
remove_filter( 'eru_gra_has_marketing_consent', $f_user, 10 );
$wpdb->query( "DELETE FROM {$ctable} WHERE email = 'gra-t-user@example.com'" );
wp_set_current_user( 0 );

// Unsubscribe must reach the account checkbox; an unchanged account save must not lift it.
wp_set_current_user( $uid );
update_user_meta( $uid, Eru_GRA_Consent::USER_META, 'yes' );
Eru_GRA_Consent::remove_suppression( 'gra-t-user@example.com' );
Eru_GRA_Consent::suppress( 'gra-t-user@example.com' );
$check( 'unsubscribe sets the account opt-in to no', 'no' === get_user_meta( $uid, Eru_GRA_Consent::USER_META, true ) );
ob_start();
Eru_GRA_Consent::account_field();
$field = ob_get_clean();
$check( 'account checkbox is shown unticked after unsubscribe', false !== strpos( $field, 'eru_gra_optin' ) && false === strpos( $field, 'checked' ) );
unset( $_POST['eru_gra_optin'] );
Eru_GRA_Consent::account_save( $uid );
$check( 'saving the account unchanged keeps the suppression', Eru_GRA_Consent::is_suppressed( 'gra-t-user@example.com' ) );
$_POST['eru_gra_optin'] = '1';
Eru_GRA_Consent::account_save( $uid );
$check( 'ticking the box afresh lifts the suppression', ! Eru_GRA_Consent::is_suppressed( 'gra-t-user@example.com' ) && 'yes' === get_user_meta( $uid, Eru_GRA_Consent::USER_META, true ) );
$_POST['eru_gra_optin'] = '1';
Eru_GRA_Consent::suppress( 'gra-t-user@example.com' );
update_user_meta( $uid, Eru_GRA_Consent::USER_META, 'yes' );
unset( $_POST['eru_gra_optin'] );
wp_set_current_user( 0 );
delete_option( Eru_GRA_Consent::OPTION );

// (e) Suppressed address: no marketing mail, still gets the transfer reminder.
Eru_GRA_Consent::suppress( 'gra-t5@example.com' );
$check( 'suppression list stores hashes, not plain addresses', false === strpos( wp_json_encode( get_option( Eru_GRA_Consent::OPTION ) ), 'gra-t5' ) );
$mk_order( 'gra-t5@example.com', 'on-hold', 5 * HOUR_IN_SECONDS );
$wpdb->insert(
	$ctable,
	array(
		'email'          => 'gra-t5@example.com',
		'user_id'        => 0,
		'cart'           => wp_json_encode( array( array( 'product_id' => $simple_id, 'variation_id' => 0, 'qty' => 1 ) ) ),
		'token'          => '',
		'consent_source' => 'checkout',
		'status'         => 'open',
		'created_at'     => gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS ),
		'updated_at'     => gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS ),
	)
);
$mails = array();
Eru_GRA_Runner::run( 'cart' );
Eru_GRA_Runner::run( 'bacs' );
$got = $to_mails( 'gra-t5@example.com' );
$subjects = array_map( fn( $m ) => $m['subject'], $got );
$check( '(e) suppressed e-mail gets no marketing mail', 0 === count( array_filter( $subjects, fn( $s ) => 0 === strpos( $s, 'GRA-T cart' ) ) ) );
$check( '(e) suppressed e-mail still gets the BACS reminder', 1 === count( array_filter( $subjects, fn( $s ) => 0 === strpos( $s, 'GRA-T bacs' ) ) ) );
$check( '(e) the suppressed cart row is closed, not left due', 'open' !== $wpdb->get_var( "SELECT status FROM {$ctable} WHERE email = 'gra-t5@example.com'" ) );

// Cart mail: unsubscribe link, header, restore link (f).
Eru_GRA_Consent::remove_suppression( 'gra-t6@example.com' );
$wpdb->insert(
	$ctable,
	array(
		'email'          => 'gra-t6@example.com',
		'user_id'        => 0,
		'cart'           => wp_json_encode( array( array( 'product_id' => $var_id, 'variation_id' => $vars['S'], 'qty' => 2 ) ) ),
		'token'          => '',
		'consent_source' => 'checkout',
		'status'         => 'open',
		'created_at'     => gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS ),
		'updated_at'     => gmdate( 'Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS ),
	)
);
// The variation has no stock; allow backorders so the restore can add it.
$vobj = wc_get_product( $vars['S'] );
$vobj->set_stock_quantity( 10 );
$vobj->save();
$orig_base = get_option( 'woocommerce_email_base_color', false );
update_option( 'woocommerce_email_base_color', '#ab12cd' );
$mails = array();
Eru_GRA_Runner::run( 'cart' );
false === $orig_base ? delete_option( 'woocommerce_email_base_color' ) : update_option( 'woocommerce_email_base_color', $orig_base );
$got = $to_mails( 'gra-t6@example.com' );
$check( '(f) one cart mail was sent', 1 === count( $got ) );
$body = $got[0]['message'];
$check( 'marketing mail carries a working unsubscribe link', 1 === preg_match( '/eru_gra_unsub=([A-Za-z0-9_\-\.]+)/', $body, $um ) );
$hdr = is_array( $got[0]['headers'] ) ? implode( "\n", $got[0]['headers'] ) : (string) $got[0]['headers'];
$check( 'List-Unsubscribe header present', false !== stripos( $hdr, 'List-Unsubscribe:' ) );
$check( 'mail uses the WooCommerce e-mail colour setting (no hard-coded palette)', false !== stripos( $body, '#ab12cd' ) );
$check( '(f) restore link in the mail', 1 === preg_match( '/eru_gra_restore=([A-Za-z0-9]+)/', $body, $rm ) );
$token = $rm[1];
$check( 'raw restore token is not stored', 64 === strlen( (string) $wpdb->get_var( "SELECT token FROM {$ctable} WHERE email = 'gra-t6@example.com'" ) ) && $token !== $wpdb->get_var( "SELECT token FROM {$ctable} WHERE email = 'gra-t6@example.com'" ) );
$check( 'cart row is sent, not open', 'sent' === $wpdb->get_var( "SELECT status FROM {$ctable} WHERE email = 'gra-t6@example.com'" ) );
$mails = array();
Eru_GRA_Runner::run( 'cart' );
$check( 'a second run sends no second cart mail', 0 === count( $to_mails( 'gra-t6@example.com' ) ) );

// Restore: GET only shows a button.
WC()->cart->empty_cart();
WC()->cart->add_to_cart( $simple_id, 1 );
$page = Eru_GRA_Cart::handle_restore( 'GET', $token );
$check( '(f) GET restore shows a button and changes nothing', 200 === $page['status'] && false !== strpos( $page['html'], '<form' ) && 1 === count( WC()->cart->get_cart() ) );
$before_user = get_current_user_id();
$res = Eru_GRA_Cart::handle_restore( 'POST', $token );
$qty = 0;
foreach ( WC()->cart->get_cart() as $line ) {
	if ( (int) $line['variation_id'] === $vars['S'] ) {
		$qty = $line['quantity'];
	}
}
$check( '(f) restore adds the lines to a non-empty cart', 200 === $res['status'] && 2 === count( WC()->cart->get_cart() ) && 2 === (int) $qty );
$check( '(f) restore does not log anybody in', get_current_user_id() === $before_user );
$res2 = Eru_GRA_Cart::handle_restore( 'POST', $token );
$check( '(f) the restore token cannot be used twice', 400 === $res2['status'] && 2 === count( WC()->cart->get_cart() ) );
$check( '(f) a wrong token is rejected', 400 === Eru_GRA_Cart::handle_restore( 'POST', 'nonsense' )['status'] );
$check( 'restored page does not expose cart contents of the owner', false === strpos( $page['html'], 'GRA Demo' ) );

$check( 'confirm pages keep their form after output filtering', false !== strpos( Eru_GRA_Consent::kses( $page['html'] ), '<form' ) && false !== strpos( Eru_GRA_Consent::kses( $page['html'] ), '<button' ) );

// Recovery on order with the same e-mail.
$wpdb->insert(
	$ctable,
	array(
		'email' => 'gra-t7@example.com', 'user_id' => 0, 'cart' => '[]', 'token' => '', 'consent_source' => 'checkout', 'status' => 'sent',
		'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	)
);
$mk_order( 'gra-t7@example.com', 'processing' );
$check( 'an order with the same e-mail marks the cart recovered', 'recovered' === $wpdb->get_var( "SELECT status FROM {$ctable} WHERE email = 'gra-t7@example.com'" ) );

// Unsubscribe: GET only shows a button; POST with a valid signature suppresses.
$raw = $um[1];
$page = Eru_GRA_Consent::handle_unsub( 'GET', $raw );
$check( 'unsubscribe GET shows a button and changes no state', 200 === $page['status'] && false !== strpos( $page['html'], '<form' ) && ! Eru_GRA_Consent::is_suppressed( 'gra-t6@example.com' ) );
$bad = Eru_GRA_Consent::handle_unsub( 'POST', substr( $raw, 0, -2 ) . 'xx' );
$check( 'unsubscribe with a forged signature is rejected', 400 === $bad['status'] && ! Eru_GRA_Consent::is_suppressed( 'gra-t6@example.com' ) );
$ok = Eru_GRA_Consent::handle_unsub( 'POST', $raw );
$check( 'signed POST adds the address to the suppression list', 200 === $ok['status'] && Eru_GRA_Consent::is_suppressed( 'gra-t6@example.com' ) && ! Eru_GRA_Consent::has( 'gra-t6@example.com' ) );

// (h) Double opt-in for stock alerts.
Eru_GRA_Settings::save_for_test( array( 'alerts' => array( 'enabled' => 'yes', 'subject' => 'GRA-T notify', 'confirm_subject' => 'GRA-T confirm' ) ) );
$mails = array();
$vS = $vars['S'];
$vzero = wc_get_product( $vS );
$vzero->set_stock_quantity( 0 );
$vzero->save();
$r  = Eru_GRA_Alerts::subscribe( array( 'email' => 'gra-t8@example.com', 'product_id' => $var_id, 'variation_id' => $vS ) );
$check( '(h) alert request is accepted as pending', $r['ok'] && 'pending' === get_post_meta( $r['id'], '_eru_gra_state', true ) );
$conf = $to_mails( 'gra-t8@example.com' );
$check( '(h) a confirmation e-mail is sent first', 1 === count( $conf ) && 1 === preg_match( '/eru_gra_confirm=(\d+)\.([A-Za-z0-9]+)/', $conf[0]['message'], $cm ) );
$again = Eru_GRA_Alerts::subscribe( array( 'email' => 'gra-t8@example.com', 'product_id' => $var_id, 'variation_id' => $vS ) );
$check( '(h) the same request is not stored twice', ! $again['ok'] );
$vobj = wc_get_product( $vS );
$vobj->set_stock_quantity( 0 );
$vobj->save();
$mails = array();
$vobj->set_stock_quantity( 5 );
$vobj->save();
$drain();
$check( '(h) an unconfirmed alert is not notified', 0 === count( $to_mails( 'gra-t8@example.com' ) ) );
$page = Eru_GRA_Alerts::handle_confirm( 'GET', $cm[1] . '.' . $cm[2] );
$check( '(h) confirm link GET only shows a button', false !== strpos( $page['html'], '<form' ) && 'pending' === get_post_meta( $r['id'], '_eru_gra_state', true ) );
$fail = Eru_GRA_Alerts::handle_confirm( 'POST', $cm[1] . '.wrongtoken' );
$check( '(h) wrong token does not confirm', 400 === $fail['status'] && 'pending' === get_post_meta( $r['id'], '_eru_gra_state', true ) );
$okc = Eru_GRA_Alerts::handle_confirm( 'POST', $cm[1] . '.' . $cm[2] );
$check( '(h) POST with the token activates the alert', 200 === $okc['status'] && 'active' === get_post_meta( $r['id'], '_eru_gra_state', true ) );
$vobj->set_stock_quantity( 0 );
$vobj->save();
$mails = array();
$vobj->set_stock_quantity( 5 );
$vobj->save();
$drain();
$check( '(h) after confirmation the stock change notifies once', 1 === count( $to_mails( 'gra-t8@example.com' ) ) );

// Cleanup.
$wpdb->query( "DELETE FROM {$ctable} WHERE email LIKE 'gra-t%'" );
foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'billing_email' => 'gra-t5@example.com' ) ) as $oid ) {
	wc_get_order( $oid )->delete( true );
}
foreach ( array( 'gra-t7@example.com' ) as $em ) {
	foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'billing_email' => $em ) ) as $oid ) {
		wc_get_order( $oid )->delete( true );
	}
}
$purge();
wc_empty_cart();
wp_delete_post( $var_id, true );
foreach ( $vars as $vid ) {
	wp_delete_post( $vid, true );
}
wp_delete_post( $simple_id, true );
echo "DONE gra-consent\n";
