<?php
/**
 * Pricing plugins together — runtime prices, sale prices, admin context and precedence.
 *   wp eval-file tests/php/pricing-context.php
 * Needs USD Pricing, Role-Based Prices, Price Lists, B2B Customer Pricing and Gift Product Rules active.
 * Creates and removes its own products, users, lists, rules and orders; restores options.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$near = fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.005;

foreach ( array( 'Eru_USD_Rate', 'Eru_RBP_Prices', 'Eru_PLST_Pricing', 'Eru_B2B_Pricing', 'Eru_GPR_Rules' ) as $c ) {
	if ( ! class_exists( $c ) ) {
		echo "FAIL: class $c missing (activate the five pricing plugins)\n";
		exit( 1 );
	}
}
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

wc_load_cart();
$opts = array( 'eru_usd_source', 'eru_usd_manual_rate', 'eru_usd_markup', 'eru_rbp_roles', 'eru_gpr_rules', 'eru_b2b_rules', 'eru_b2b_groups', 'eru_b2b_label', 'eru_b2b_version', 'eru_plst_version' );
$old  = array();
foreach ( $opts as $o ) {
	$old[ $o ] = get_option( $o, null );
}
$ids    = array();
$users  = array();
$lists  = array();
$orders = array();
$http   = fn() => new WP_Error( 'eru_test_blocked', 'blocked' );
add_filter( 'pre_http_request', $http );

$mkp = function ( $name, $regular, $sale = '' ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $regular );
	if ( '' !== $sale ) {
		$p->set_sale_price( (string) $sale );
	}
	$p->set_manage_stock( false );
	$p->set_status( 'publish' );
	return $ids[] = $p->save();
};
$mku = function ( $role ) use ( &$users ) {
	$n   = 'demo_' . $role . '_' . wp_rand( 1000, 9999 );
	$uid = wp_create_user( $n, wp_generate_password(), $n . '@example.com' );
	( new WP_User( $uid ) )->set_role( $role );
	return $users[] = $uid;
};
$mklist = function ( $meta ) use ( &$lists ) {
	$id = wp_insert_post( array( 'post_type' => 'eru_plst_list', 'post_title' => 'Demo Liste', 'post_status' => 'publish', 'meta_input' => $meta ) );
	Eru_PLST_Lists::on_change();
	return $lists[] = $id;
};
$flush = function () {
	Eru_PLST_Pricing::flush();
	Eru_B2B_Pricing::flush();
	Eru_PLST_Lists::on_change();
};
$reset = function () use ( &$lists, $flush ) {
	foreach ( $lists as $l ) {
		wp_delete_post( $l, true );
	}
	$lists = array();
	foreach ( Eru_B2B_Store::rules() as $r ) {
		Eru_B2B_Store::delete_rule( (string) $r['id'] );
	}
	delete_option( 'eru_rbp_roles' );
	delete_option( 'eru_usd_manual_rate' );
	$flush();
};
$price = fn( $id ) => (float) wc_get_product( $id )->get_price();
$cart_total = function ( $main, $qty ) {
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $main, $qty );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	return (float) WC()->cart->get_total( 'edit' );
};
$gift_line_price = function () {
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! empty( $item['_eru_gpr_gift'] ) ) {
			return (float) $item['data']->get_price();
		}
	}
	return null;
};

try {
	update_option( 'eru_usd_source', 'manual' );
	update_option( 'eru_usd_markup', '0' );
	$cust  = $mku( 'customer' );
	$adm   = $mku( 'administrator' );
	$main  = $mkp( 'Demo Ürün Ana', 200 );
	$gift  = $mkp( 'Demo Ürün Hediye', 80 );
	$sale  = $mkp( 'Demo Ürün İndirimli', 100, 70 );
	$q     = $mkp( 'Demo Ürün Yönetim', 100 );
	update_option( 'eru_gpr_rules', Eru_GPR_Rules::sanitize( array( array( 'enabled' => 1, 'trigger' => 'cart_total', 'min_total' => '500', 'gift_id' => $gift, 'qty' => 1 ) ) ) );

	/* ---- I1: a runtime set_price() (gift line = 0) beats every price filter ---- */
	wp_set_current_user( $cust );
	update_option( 'eru_usd_manual_rate', '40' );
	update_post_meta( $gift, '_eru_usd_price', '10' );
	$t = $cart_total( $main, 3 );
	$check( 'GPR + USD: gift line stays 0, total 600', $near( $gift_line_price(), 0 ) && $near( $t, 600 ) );
	delete_post_meta( $gift, '_eru_usd_price' );
	$reset();

	update_option( 'eru_rbp_roles', array( 'customer' ) );
	update_post_meta( $gift, '_eru_rbp_price_customer', '50' );
	$t = $cart_total( $main, 3 );
	$check( 'GPR + RBP: gift line stays 0, total 600', $near( $gift_line_price(), 0 ) && $near( $t, 600 ) );
	delete_post_meta( $gift, '_eru_rbp_price_customer' );
	$reset();

	$mklist( array( '_eru_plst_users' => array( $cust ), '_eru_plst_prices' => array( $gift => 30 ) ) );
	$t = $cart_total( $main, 3 );
	$check( 'GPR + Price Lists: gift line stays 0, total 600', $near( $gift_line_price(), 0 ) && $near( $t, 600 ) );
	$reset();

	Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $cust, 'type' => 'product', 'object' => $gift, 'mode' => 'price', 'value' => '20' ) );
	$flush();
	$t = $cart_total( $main, 3 );
	$check( 'GPR + B2B: gift line stays 0, total 600', $near( $gift_line_price(), 0 ) && $near( $t, 600 ) );
	$reset();
	WC()->cart->empty_cart();

	/* ---- I4: percentage rules never beat an active sale price ---- */
	$mklist( array( '_eru_plst_users' => array( $cust ), '_eru_plst_percent' => 10 ) );
	$check( 'Price Lists: regular 100 / sale 70 / list -10 % -> 70', $near( $price( $sale ), 70 ) );
	$check( 'Price Lists: no sale, 200 with list -10 % -> 180', $near( $price( $main ), 180 ) );
	$reset();
	Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $cust, 'type' => 'all', 'mode' => 'percent', 'value' => '10' ) );
	$flush();
	$check( 'B2B: regular 100 / sale 70 / rule -10 % -> 70', $near( $price( $sale ), 70 ) );
	$reset();
	Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $cust, 'type' => 'product', 'object' => $sale, 'mode' => 'price', 'value' => '80' ) );
	$flush();
	$check( 'B2B: fixed price 80 unchanged on a sale product', $near( $price( $sale ), 80 ) );
	$reset();

	/* ---- USD (998) converts first, Role-Based Prices (999) overrides ---- */
	update_option( 'eru_usd_manual_rate', '40' );
	update_option( 'eru_rbp_roles', array( 'customer' ) );
	update_post_meta( $q, '_eru_usd_price', '10' );
	update_post_meta( $q, '_eru_rbp_price_customer', '300' );
	$check( 'USD 10 x 40 = 400, role price 300 wins', $near( $price( $q ), 300 ) );
	wp_set_current_user( 0 );
	$check( 'guest gets the USD price 400', $near( $price( $q ), 400 ) );
	delete_post_meta( $q, '_eru_usd_price' );
	delete_post_meta( $q, '_eru_rbp_price_customer' );
	$reset();

	/* ---- A5: an admin's own rules never price products in wp-admin ---- */
	update_option( 'eru_rbp_roles', array( 'administrator' ) );
	update_post_meta( $q, '_eru_rbp_price_administrator', '11' );
	$mklist( array( '_eru_plst_users' => array( $adm ), '_eru_plst_prices' => array( $q => 22 ) ) );
	Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $adm, 'type' => 'product', 'object' => $q, 'mode' => 'price', 'value' => '33' ) );
	Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $cust, 'type' => 'product', 'object' => $q, 'mode' => 'price', 'value' => '44' ) );
	$flush();
	wp_set_current_user( $adm );
	$check( 'front end: the admin shopping as a customer gets own price (33)', $near( $price( $q ), 33 ) );

	$GLOBALS['current_screen'] = WP_Screen::get( 'edit-product' );
	$flush();
	$check( 'wp-admin screen: admin does not get own RBP/list/B2B price (100)', $near( $price( $q ), 100 ) );
	$as_admin = fn() => $adm;
	add_filter( 'eru_b2b_pricing_user_id', $as_admin );
	$flush();
	$check( 'eru_b2b_pricing_user_id filter can override (33)', $near( $price( $q ), 33 ) );
	remove_filter( 'eru_b2b_pricing_user_id', $as_admin );

	$o = wc_create_order( array( 'customer_id' => $cust ) );
	$orders[] = $o->get_id();
	$g = wc_create_order();
	$orders[] = $g->get_id();
	add_filter( 'wp_doing_ajax', '__return_true' );
	$_REQUEST['action']   = 'woocommerce_add_order_item';
	$_REQUEST['order_id'] = (string) $o->get_id();
	$flush();
	$check( 'order-item AJAX: priced for the order customer (44), not the admin', $near( $price( $q ), 44 ) );
	$_REQUEST['order_id'] = (string) $g->get_id();
	$flush();
	$check( 'order-item AJAX for a guest order: base price (100)', $near( $price( $q ), 100 ) );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	unset( $_REQUEST['action'], $_REQUEST['order_id'], $GLOBALS['current_screen'] );
	$flush();
	$check( 'back on the front end: admin price again (33)', $near( $price( $q ), 33 ) );
} finally {
	remove_filter( 'wp_doing_ajax', '__return_true' );
	unset( $_REQUEST['action'], $_REQUEST['order_id'], $GLOBALS['current_screen'] );
	remove_filter( 'pre_http_request', $http );
	wp_set_current_user( 0 );
	WC()->cart->empty_cart();
	foreach ( $orders as $oid ) {
		$ord = wc_get_order( $oid );
		if ( $ord ) {
			$ord->delete( true );
		}
	}
	foreach ( $lists as $l ) {
		wp_delete_post( $l, true );
	}
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $users as $u ) {
		wp_delete_user( $u );
	}
	foreach ( $old as $o => $v ) {
		null === $v ? delete_option( $o ) : update_option( $o, $v );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
