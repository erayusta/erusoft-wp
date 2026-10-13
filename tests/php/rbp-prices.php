<?php
/**
 * Role-Based Prices — role price in product, variation and cart.
 *
 *   wp eval-file tests/php/rbp-prices.php
 * Creates and removes its own products and users; restores options.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$near = function ( $a, $b ) {
	return abs( (float) $a - (float) $b ) < 0.005;
};

if ( ! class_exists( 'Eru_RBP_Prices' ) ) {
	echo "FAIL: class Eru_RBP_Prices missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old_roles = get_option( 'eru_rbp_roles', null );
$ids       = array();
$users     = array();

try {
	delete_option( 'eru_rbp_roles' );

	$simple = new WC_Product_Simple();
	$simple->set_name( 'RBP Demo Ürün' );
	$simple->set_regular_price( '100' );
	$simple->set_status( 'publish' );
	$ids[] = $simple->save();
	update_post_meta( $ids[0], '_eru_rbp_price_customer', '80' );

	$var = new WC_Product_Variable();
	$var->set_name( 'RBP Demo Varyasyonlu' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Boyut' );
	$attr->set_options( array( 'S' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$var->set_attributes( array( $attr ) );
	$var->set_status( 'publish' );
	$ids[]     = $var->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $var->get_id() );
	$variation->set_attributes( array( 'boyut' => 'S' ) );
	$variation->set_regular_price( '200' );
	$ids[] = $variation->save();
	WC_Product_Variable::sync( $ids[1] );
	update_post_meta( $ids[2], '_eru_rbp_price_customer', '150' );

	$uid = wp_create_user( 'rbp_demo_' . wp_rand( 1000, 9999 ), wp_generate_password(), 'rbp-demo-' . wp_rand( 1000, 9999 ) . '@example.com' );
	( new WP_User( $uid ) )->set_role( 'customer' );
	$users[] = $uid;

	// Inert by default: no role selected -> nobody gets a role price.
	wp_set_current_user( $uid );
	$check( 'no roles selected -> customer sees 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );

	update_option( 'eru_rbp_roles', array( 'customer' ) );

	wp_set_current_user( 0 );
	$check( 'guest sees 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );

	wp_set_current_user( $uid );
	$check( 'customer product price 80', $near( wc_get_product( $ids[0] )->get_price(), 80 ) );
	$check( 'customer variation price 150', $near( wc_get_product( $ids[2] )->get_price(), 150 ) );
	$check( 'price html shows 80', false !== strpos( wc_get_product( $ids[0] )->get_price_html(), '80' ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0] );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( 'customer cart total 80 (twice)', $near( WC()->cart->get_total( 'edit' ), 80 ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[1], 1, $ids[2], array( 'attribute_boyut' => 'S' ) );
	WC()->cart->calculate_totals();
	$check( 'customer variation cart total 150', $near( WC()->cart->get_total( 'edit' ), 150 ) );

	$prices = wc_get_product( $ids[1] )->get_variation_prices( true );
	$check( 'variable parent range uses role price (150)', $near( min( $prices['price'] ), 150 ) );

	wp_set_current_user( 0 );
	$prices = wc_get_product( $ids[1] )->get_variation_prices( true );
	$check( 'variable parent range for guest (200), not served from the customer cache', $near( min( $prices['price'] ), 200 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0] );
	WC()->cart->calculate_totals();
	$check( 'guest cart total 100', $near( WC()->cart->get_total( 'edit' ), 100 ) );

	// A user whose role is not selected is unaffected.
	$uid2 = wp_create_user( 'rbp_demo2_' . wp_rand( 1000, 9999 ), wp_generate_password(), 'rbp-demo2-' . wp_rand( 1000, 9999 ) . '@example.com' );
	( new WP_User( $uid2 ) )->set_role( 'subscriber' );
	$users[] = $uid2;
	wp_set_current_user( $uid2 );
	$check( 'subscriber (role not selected) sees 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );

	// Empty role price -> falls back to the normal price.
	delete_post_meta( $ids[0], '_eru_rbp_price_customer' );
	wp_set_current_user( $uid );
	$check( 'empty role price -> normal price 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );

	$check( 'sanitiser keeps only existing roles', array( 'customer' ) === Eru_RBP_Prices::sanitize_roles( array( 'customer', 'no_such_role', '' ) ) );
} finally {
	wp_set_current_user( 0 );
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $u ) {
		wp_delete_user( $u );
	}
	null === $old_roles ? delete_option( 'eru_rbp_roles' ) : update_option( 'eru_rbp_roles', $old_roles );
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
