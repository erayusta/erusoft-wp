<?php
/**
 * Price Lists — create list -> assign -> cart uses the list price.
 *
 *   wp eval-file tests/php/plst-pricing.php
 * When Role-Based Prices and/or B2B Customer Pricing are active as well, the precedence
 * (customer rule > price list > role price) is asserted too.
 * Creates and removes its own lists, products and users.
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

if ( ! class_exists( 'Eru_PLST_Pricing' ) ) {
	echo "FAIL: class Eru_PLST_Pricing missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old_version = get_option( 'eru_plst_version', null );
$old_b2b     = array();
$ids         = array();
$lists       = array();
$users       = array();
$mk          = function ( $name, $role ) use ( &$users ) {
	$uid = wp_create_user( $name . wp_rand( 1000, 9999 ), wp_generate_password(), $name . wp_rand( 1000, 9999 ) . '@example.com' );
	( new WP_User( $uid ) )->set_role( $role );
	$users[] = $uid;
	return $uid;
};
$mklist = function ( $title, $meta, $status = 'publish' ) use ( &$lists ) {
	$id = wp_insert_post( array( 'post_type' => 'eru_plst_list', 'post_title' => $title, 'post_status' => $status, 'meta_input' => $meta ) );
	Eru_PLST_Lists::on_change();
	$lists[] = $id;
	return $id;
};

try {
	$simple = new WC_Product_Simple();
	$simple->set_name( 'PLST Demo Ürün' );
	$simple->set_regular_price( '100' );
	$simple->set_sku( 'plst-demo-' . wp_rand( 1000, 9999 ) );
	$simple->set_status( 'publish' );
	$ids[] = $simple->save();

	$var = new WC_Product_Variable();
	$var->set_name( 'PLST Demo Varyasyonlu' );
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
	$variation->set_regular_price( '50' );
	$ids[] = $variation->save();
	WC_Product_Variable::sync( $ids[1] );

	$customer = $mk( 'plst_cust_', 'customer' );
	$other    = $mk( 'plst_sub_', 'subscriber' );

	wp_set_current_user( $customer );
	$check( 'no list -> 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );

	// List "Bayi": -10 % on everything, assigned to the customer role.
	$bayi = $mklist( 'Bayi', array( '_eru_plst_roles' => array( 'customer' ), '_eru_plst_percent' => 10 ) );
	wp_set_current_user( $customer );
	$check( 'customer product 90', $near( wc_get_product( $ids[0] )->get_price(), 90 ) );
	$check( 'customer variation 45', $near( wc_get_product( $ids[2] )->get_price(), 45 ) );
	$prices = wc_get_product( $ids[1] )->get_variation_prices( true );
	$check( 'variable range 45', $near( min( $prices['price'] ), 45 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0] );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( 'customer cart 90 (twice)', $near( WC()->cart->get_total( 'edit' ), 90 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[1], 1, $ids[2], array( 'attribute_boyut' => 'S' ) );
	WC()->cart->calculate_totals();
	$check( 'customer variation cart 45', $near( WC()->cart->get_total( 'edit' ), 45 ) );

	wp_set_current_user( $other );
	$check( 'role without a list sees 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );
	wp_set_current_user( 0 );
	$check( 'guest sees 100', $near( wc_get_product( $ids[0] )->get_price(), 100 ) );
	$prices = wc_get_product( $ids[1] )->get_variation_prices( true );
	$check( 'guest variable range 50 (not served from the customer cache)', $near( min( $prices['price'] ), 50 ) );

	// A list assigned to a person beats the role list; fixed price beats percentages.
	$sku = wc_get_product( $ids[0] )->get_sku();
	list( $parsed, $bad ) = Eru_PLST_Lists::parse_lines( $sku . " = 79,90\nyok-boyle-bir-sku = 5\n" . $ids[2] . ' = 40' );
	$check( 'parse_lines resolves SKU and ID, reports unknown', $near( $parsed[ $ids[0] ] ?? 0, 79.9 ) && $near( $parsed[ $ids[2] ] ?? 0, 40 ) && 1 === count( $bad ) );
	$vip = $mklist( 'VIP', array( '_eru_plst_users' => array( $customer ), '_eru_plst_prices' => $parsed ) );
	wp_set_current_user( $customer );
	$check( 'personal list fixed price 79.90', $near( wc_get_product( $ids[0] )->get_price(), 79.9 ) );
	$check( 'personal list fixed variation price 40', $near( wc_get_product( $ids[2] )->get_price(), 40 ) );

	// A list that says nothing about the product falls through to the next list.
	wp_update_post( array( 'ID' => $vip, 'post_status' => 'draft' ) );
	Eru_PLST_Lists::on_change();
	$check( 'draft list ignored -> back to 90', $near( wc_get_product( $ids[0] )->get_price(), 90 ) );
	update_post_meta( $vip, '_eru_plst_prices', array( $ids[0] => 60 ) );
	wp_update_post( array( 'ID' => $vip, 'post_status' => 'publish' ) );
	Eru_PLST_Lists::on_change();
	$check( 'product with fixed price -> 60', $near( wc_get_product( $ids[0] )->get_price(), 60 ) );
	$check( 'variation not in the personal list falls through to role list 45', $near( wc_get_product( $ids[2] )->get_price(), 45 ) );

	// Category percentage beats the catalogue-wide percentage.
	$term = wp_insert_term( 'PLST Demo Kategori ' . wp_rand( 1000, 9999 ), 'product_cat' );
	wp_set_object_terms( $ids[0], (int) $term['term_id'], 'product_cat' );
	wp_delete_post( $vip, true );
	Eru_PLST_Lists::on_change();
	update_post_meta( $bayi, '_eru_plst_cats', array( (int) $term['term_id'] => 25 ) );
	Eru_PLST_Lists::on_change();
	$check( 'category -25 % beats catalogue -10 % -> 75', $near( wc_get_product( $ids[0] )->get_price(), 75 ) );
	wp_delete_term( (int) $term['term_id'], 'product_cat' );

	// Precedence with the other two pricing products, when they are active.
	if ( class_exists( 'Eru_RBP_Prices' ) ) {
		$old_roles = get_option( 'eru_rbp_roles', null );
		update_option( 'eru_rbp_roles', array( 'customer' ) );
		update_post_meta( $ids[0], '_eru_rbp_price_customer', '95' );
		update_post_meta( $bayi, '_eru_plst_cats', array() );
		Eru_PLST_Lists::on_change();
		$check( 'price list (90) beats role price (95)', $near( wc_get_product( $ids[0] )->get_price(), 90 ) );
		delete_post_meta( $ids[0], '_eru_rbp_price_customer' );
		null === $old_roles ? delete_option( 'eru_rbp_roles' ) : update_option( 'eru_rbp_roles', $old_roles );
	}
	if ( class_exists( 'Eru_B2B_Store' ) ) {
		foreach ( array( 'eru_b2b_rules', 'eru_b2b_groups', 'eru_b2b_label', 'eru_b2b_version' ) as $o ) {
			$old_b2b[ $o ] = get_option( $o, null );
		}
		update_post_meta( $bayi, '_eru_plst_cats', array() );
		Eru_PLST_Lists::on_change();
		Eru_B2B_Store::add_rule( array( 'scope' => 'user', 'target' => $customer, 'type' => 'product', 'object' => $ids[0], 'mode' => 'price', 'value' => '70' ) );
		$check( 'customer rule (70) beats price list (90)', $near( wc_get_product( $ids[0] )->get_price(), 70 ) );
		$check( 'product without a customer rule keeps the list price (45)', $near( wc_get_product( $ids[2] )->get_price(), 45 ) );
	}
} finally {
	wp_set_current_user( 0 );
	WC()->cart->empty_cart();
	foreach ( $lists as $l ) {
		wp_delete_post( $l, true );
	}
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $u ) {
		wp_delete_user( $u );
	}
	foreach ( $old_b2b as $o => $v ) {
		null === $v ? delete_option( $o ) : update_option( $o, $v, false );
	}
	null === $old_version ? delete_option( 'eru_plst_version' ) : update_option( 'eru_plst_version', $old_version, false );
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
