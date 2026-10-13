<?php
/**
 * Gift Product Rules — cart check.
 *   wp eval-file tests/php/gpr-gift.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_GPR_Rules' ) ) {
	echo "FAIL: class Eru_GPR_Rules missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old_rules = get_option( 'eru_gpr_rules', null );
$ids       = array();
$cat_ids   = array();

$gift_lines = function () {
	$out = array();
	foreach ( WC()->cart->get_cart() as $key => $item ) {
		if ( ! empty( $item['_eru_gpr_gift'] ) ) {
			$out[ $key ] = $item;
		}
	}
	return $out;
};

try {
	$cat    = wp_insert_term( 'GPR Demo Kategori', 'product_cat' );
	$cat_id = is_wp_error( $cat ) ? (int) $cat->get_error_data() : (int) $cat['term_id'];
	$cat_ids[] = $cat_id;

	$mk = function ( $name, $price, $cats = array() ) use ( &$ids ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( (string) $price );
		$p->set_category_ids( $cats );
		$p->set_manage_stock( false );
		$p->set_status( 'publish' );
		return $ids[] = $p->save();
	};
	$main = $mk( 'GPR Demo Ürün', 200 );
	$catp = $mk( 'GPR Demo Kategorili', 100, array( $cat_id ) );
	$gift = $mk( 'GPR Demo Hediye', 80 );

	// Variable gift (a variation id as the gift).
	$var = new WC_Product_Variable();
	$var->set_name( 'GPR Demo Varyasyonlu Hediye' );
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
	$variation->set_regular_price( '60' );
	$vid   = $variation->save();
	$ids[] = $vid;
	WC_Product_Variable::sync( $var->get_id() );

	$total = fn() => (float) WC()->cart->get_total( 'edit' );
	$near  = fn( $a, $b ) => abs( $a - $b ) < 0.001;

	// Sanitiser: variable parent and unknown ids are dropped, a variation id is kept.
	$clean = Eru_GPR_Rules::sanitize( array(
		array( 'trigger' => 'cart_total', 'min_total' => '500', 'gift_id' => $var->get_id() ),
		array( 'trigger' => 'cart_total', 'min_total' => '500', 'gift_id' => 99999999 ),
		array( 'trigger' => 'cart_total', 'min_total' => '500', 'gift_id' => $vid, 'enabled' => 1 ),
	) );
	$check( 'sanitiser keeps only the variation row', 1 === count( $clean ) && (int) reset( $clean )['gift_id'] === $vid );

	// No rules: nothing happens.
	delete_option( 'eru_gpr_rules' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $main, 3 );
	WC()->cart->calculate_totals();
	$check( 'no rules -> no gift, total 600', ! $gift_lines() && $near( $total(), 600 ) );

	// Cart total rule.
	update_option( 'eru_gpr_rules', Eru_GPR_Rules::sanitize( array( array( 'enabled' => 1, 'trigger' => 'cart_total', 'min_total' => '500', 'gift_id' => $gift, 'qty' => 1 ) ) ) );
	WC()->cart->calculate_totals();
	$g = $gift_lines();
	$check( 'cart 600 >= 500 -> one gift line', 1 === count( $g ) );
	$line = reset( $g );
	$check( 'gift line price is 0', $line && $near( (float) $line['data']->get_price(), 0 ) && (int) $line['product_id'] === $gift );
	$check( 'total stays 600 with gift', $near( $total(), 600 ) );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( 'recalculating twice -> still one gift, total 600', 1 === count( $gift_lines() ) && $near( $total(), 600 ) );

	// Customer cannot change the gift quantity.
	$gk = array_key_first( $gift_lines() );
	WC()->cart->set_quantity( $gk, 4 );
	WC()->cart->calculate_totals();
	$g = $gift_lines();
	$check( 'gift quantity forced back to 1', 1 === count( $g ) && 1 === (int) reset( $g )['quantity'] && $near( $total(), 600 ) );

	// Reduce to 300 -> gift removed.
	WC()->cart->set_quantity( array_key_first( array_diff_key( WC()->cart->get_cart(), $gift_lines() ) ), 1 );
	WC()->cart->set_quantity( array_key_first( array_diff_key( WC()->cart->get_cart(), $gift_lines() ) ), 1 );
	WC()->cart->calculate_totals();
	$check( 'cart 200 < 500 -> gift removed', ! $gift_lines() );
	WC()->cart->add_to_cart( $catp, 3 );
	WC()->cart->calculate_totals();
	$check( 'cart 500 (>= 500) -> gift back', 1 === count( $gift_lines() ) && $near( $total(), 500 ) );
	WC()->cart->add_to_cart( $catp, 1 );
	WC()->cart->set_quantity( array_key_first( array_diff_key( WC()->cart->get_cart(), $gift_lines() ) ), 0 );
	WC()->cart->calculate_totals();

	// Same product added by the customer stays paid.
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $main, 3 );
	WC()->cart->add_to_cart( $gift, 1 );
	WC()->cart->calculate_totals();
	$check( 'customer-added copy of the gift product is paid (600 + 80)', 1 === count( $gift_lines() ) && $near( $total(), 680 ) );

	// Category rule + variation gift.
	update_option( 'eru_gpr_rules', Eru_GPR_Rules::sanitize( array( array( 'enabled' => 1, 'trigger' => 'category', 'category_ids' => array( $cat_id ), 'gift_id' => $vid, 'qty' => 2 ) ) ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $main, 1 );
	WC()->cart->calculate_totals();
	$check( 'category rule: no category product -> no gift', ! $gift_lines() );
	WC()->cart->add_to_cart( $catp, 1 );
	WC()->cart->calculate_totals();
	$g = $gift_lines();
	$check( 'category rule: variation gift x2 at 0, total 300', 1 === count( $g ) && 2 === (int) reset( $g )['quantity'] && (int) reset( $g )['variation_id'] === $vid && $near( $total(), 300 ) );

	// Product rule.
	update_option( 'eru_gpr_rules', Eru_GPR_Rules::sanitize( array( array( 'enabled' => 1, 'trigger' => 'product', 'product_ids' => (string) $main, 'gift_id' => $gift ) ) ) );
	WC()->cart->calculate_totals();
	$check( 'product rule: main product in cart -> gift, total 300', 1 === count( $gift_lines() ) && $near( $total(), 300 ) );

	// Disabled rule.
	$r = get_option( 'eru_gpr_rules' );
	foreach ( $r as $k => $v ) {
		$r[ $k ]['enabled'] = 0;
	}
	update_option( 'eru_gpr_rules', $r );
	WC()->cart->calculate_totals();
	$check( 'disabled rule -> gift removed', ! $gift_lines() );
} finally {
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $cat_ids as $cid ) {
		wp_delete_term( $cid, 'product_cat' );
	}
	if ( null === $old_rules ) {
		delete_option( 'eru_gpr_rules' );
	} else {
		update_option( 'eru_gpr_rules', $old_rules );
	}
}

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
