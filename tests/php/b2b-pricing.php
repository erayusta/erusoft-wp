<?php
/**
 * B2B Customer Pricing — customer and group rules in product, variation and cart.
 *
 *   wp eval-file tests/php/b2b-pricing.php
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

if ( ! class_exists( 'Eru_B2B_Pricing' ) ) {
	echo "FAIL: class Eru_B2B_Pricing missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old = array();
foreach ( array( 'eru_b2b_rules', 'eru_b2b_groups', 'eru_b2b_label', 'eru_b2b_version' ) as $o ) {
	$old[ $o ] = get_option( $o, null );
	delete_option( $o );
}
$ids   = array();
$users = array();
$mk    = function ( $name ) use ( &$users ) {
	$uid = wp_create_user( $name . wp_rand( 1000, 9999 ), wp_generate_password(), $name . wp_rand( 1000, 9999 ) . '@example.com' );
	( new WP_User( $uid ) )->set_role( 'customer' );
	$users[] = $uid;
	return $uid;
};

try {
	$simple = new WC_Product_Simple();
	$simple->set_name( 'B2B Demo Ürün' );
	$simple->set_regular_price( '100' );
	$simple->set_status( 'publish' );
	$ids[] = $simple->save();

	$var = new WC_Product_Variable();
	$var->set_name( 'B2B Demo Varyasyonlu' );
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

	$alice = $mk( 'b2b_alice_' );
	$bob   = $mk( 'b2b_bob_' );

	// Inert by default.
	wp_set_current_user( $alice );
	$check( 'no rules -> 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );

	// Customer-specific price 70.
	$rid = Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $alice, 'type' => 'product', 'object' => $ids[0], 'mode' => 'price', 'value' => '70' ) );
	$check( 'rule accepted', '' !== $rid );
	$check( 'invalid rule rejected (negative)', '' === Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $alice, 'type' => 'all', 'mode' => 'price', 'value' => '-5' ) ) );
	$check( 'invalid rule rejected (percent > 100)', '' === Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $alice, 'type' => 'all', 'mode' => 'percent', 'value' => '150' ) ) );
	$check( 'invalid rule rejected (unknown user)', '' === Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => 999999, 'type' => 'all', 'mode' => 'percent', 'value' => '10' ) ) );

	wp_set_current_user( $alice );
	$check( 'alice product price 70', $near( wc_get_product( $ids[0] )->get_price(), 70 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0] );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( 'alice cart total 70 (twice)', $near( WC()->cart->get_total( 'edit' ), 70 ) );
	$check( 'price html carries the label', false !== strpos( wc_get_product( $ids[0] )->get_price_html(), 'Size özel fiyat' ) );

	wp_set_current_user( $bob );
	$check( 'bob product price 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0] );
	WC()->cart->calculate_totals();
	$check( 'bob cart total 100', $near( WC()->cart->get_total( 'edit' ), 100 ) );
	wp_set_current_user( 0 );
	$check( 'guest price 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );

	// Group rule: 10 % on everything for the group; bob joins it.
	$slug = Eru_B2B_Store::add_group( 'Demo Bayi' );
	update_user_meta( $bob, 'eru_b2b_group', $slug );
	Eru_B2B_Store::add_rule( array( 'scope' => 'group', 'target' => $slug, 'type' => 'all', 'mode' => 'percent', 'value' => '10' ) );
	wp_set_current_user( $bob );
	$check( 'group member product 90', $near( wc_get_product( $ids[0] )->get_price(), 90 ) );
	$check( 'group member variation 180', $near( wc_get_product( $ids[2] )->get_price(), 180 ) );
	$prices = wc_get_product( $ids[1] )->get_variation_prices( true );
	$check( 'group member variable range 180', $near( min( $prices['price'] ), 180 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[1], 1, $ids[2], array( 'attribute_boyut' => 'S' ) );
	WC()->cart->calculate_totals();
	$check( 'group member variation cart 180', $near( WC()->cart->get_total( 'edit' ), 180 ) );

	// Customer rule beats group rule even when the group price is lower.
	wp_set_current_user( $alice );
	update_user_meta( $alice, 'eru_b2b_group', $slug );
	Eru_B2B_Pricing::flush();
	$check( 'customer rule (70) beats group 10 % (90) on the product', $near( wc_get_product( $ids[0] )->get_price(), 70 ) );
	$check( 'alice variation falls back to group rule (180)', $near( wc_get_product( $ids[2] )->get_price(), 180 ) );

	// A fixed price on a variation.
	Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $alice, 'type' => 'product', 'object' => $ids[2], 'mode' => 'price', 'value' => '150' ) );
	$check( 'variation-specific fixed price 150', $near( wc_get_product( $ids[2] )->get_price(), 150 ) );

	// Deleting the rule restores the normal behaviour.
	Eru_B2B_Store::delete_rule( $rid );
	$check( 'deleted rule -> alice back to group price 90', $near( wc_get_product( $ids[0] )->get_price(), 90 ) );

	// Deleting a group removes its rules and memberships.
	Eru_B2B_Store::delete_group( $slug );
	wp_set_current_user( $bob );
	$check( 'deleted group -> bob 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );
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
	foreach ( $old as $o => $v ) {
		null === $v ? delete_option( $o ) : update_option( $o, $v, false );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
