<?php
/**
 * Bought-Together Discounts — cart total + PDP block check.
 *   wp eval-file tests/php/btd-sets.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$near = fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.001;

if ( ! class_exists( 'Eru_BTD_Sets' ) ) {
	echo "FAIL: class Eru_BTD_Sets missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old = get_option( 'eru_btd_sets', null );
$ids = array();

try {
	$mk = function ( $name, $price ) use ( &$ids ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( (string) $price );
		$p->set_status( 'publish' );
		return $ids[] = $p->save();
	};
	$a = $mk( 'BTD Demo A', 100 );
	$b = $mk( 'BTD Demo B', 50 );
	$c = $mk( 'BTD Demo C', 30 );

	$var = new WC_Product_Variable();
	$var->set_name( 'BTD Demo Varyasyonlu' );
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
	$vid   = $variation->save();
	$ids[] = $vid;
	WC_Product_Variable::sync( $var->get_id() );

	$total = function () {
		WC()->cart->calculate_totals();
		return (float) WC()->cart->get_total( 'edit' );
	};

	delete_option( 'eru_btd_sets' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a );
	WC()->cart->add_to_cart( $b );
	$check( 'no sets -> 150', $near( $total(), 150 ) );

	$sets = Eru_BTD_Sets::sanitize( array( array( 'active' => 1, 'name' => 'Demo', 'products' => array( $a, $b, $var->get_id() ), 'mode' => 'percent', 'value' => '20', 'label' => 'Set indirimi' ) ) );
	$check( 'sanitiser drops variable parent id', array( $a, $b ) === $sets[0]['products'] );
	update_option( 'eru_btd_sets', $sets );

	$check( 'A(100)+B(50) 20% -> 150-30 = 120', $near( $total(), 120 ) );
	$total();
	$check( 'recalculating twice -> 120, one fee', $near( $total(), 120 ) && 1 === count( WC()->cart->get_fees() ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a );
	$check( 'only A -> 100', $near( $total(), 100 ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 2 );
	WC()->cart->add_to_cart( $b, 1 );
	$check( '2xA + 1xB -> one set only: 250-30 = 220', $near( $total(), 220 ) );

	// Fixed amount per set, two complete sets.
	update_option( 'eru_btd_sets', Eru_BTD_Sets::sanitize( array( array( 'active' => 1, 'products' => array( $a, $b ), 'mode' => 'fixed', 'value' => '25' ) ) ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 2 );
	WC()->cart->add_to_cart( $b, 2 );
	$check( 'fixed 25 x 2 sets -> 300-50 = 250', $near( $total(), 250 ) );

	// A unit in two sets earns the discount only once.
	update_option(
		'eru_btd_sets',
		Eru_BTD_Sets::sanitize(
			array(
				array( 'active' => 1, 'products' => array( $a, $b ), 'mode' => 'percent', 'value' => '20' ),
				array( 'active' => 1, 'products' => array( $a, $c ), 'mode' => 'percent', 'value' => '50' ),
			)
		)
	);
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a );
	WC()->cart->add_to_cart( $b );
	WC()->cart->add_to_cart( $c );
	$check( 'A in two sets, one unit -> only first set: 180-30 = 150', $near( $total(), 150 ) );

	// Variation in a set.
	update_option( 'eru_btd_sets', Eru_BTD_Sets::sanitize( array( array( 'active' => 1, 'products' => array( $vid, $b ), 'mode' => 'percent', 'value' => '10' ) ) ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $var->get_id(), 1, $vid, array( 'attribute_boyut' => 'S' ) );
	WC()->cart->add_to_cart( $b );
	$check( 'variation 200 + B 50, 10% -> 225', $near( $total(), 225 ) );

	// PDP block.
	$html = Eru_BTD_Render::block( wc_get_product( $b ) );
	$check( 'PDP block renders heading with %10 and the add-all button', false !== strpos( $html, 'Birlikte alın, %10 kazanın' ) && false !== strpos( $html, 'eru_btd_set' ) );
	$check( 'PDP block absent for unrelated product', '' === Eru_BTD_Render::block( wc_get_product( $c ) ) );
	$vblock = Eru_BTD_Render::block( wc_get_product( $var->get_id() ) );
	$check( 'PDP block shown on the variable parent of a set variation', '' !== $vblock );

	// Stable set ids: deleting the first set does not shift the id of the next one.
	$two = Eru_BTD_Sets::sanitize( array( array( 'active' => 1, 'products' => array( $a, $b ), 'value' => '10' ), array( 'active' => 1, 'products' => array( $b, $c ), 'value' => '10' ) ) );
	update_option( 'eru_btd_sets', $two );
	$second = Eru_BTD_Sets::get()[1]['id'];
	update_option( 'eru_btd_sets', Eru_BTD_Sets::sanitize( array( $two[1] ) ) );
	$check( 'set id is stable when an earlier set is removed', $second === Eru_BTD_Sets::get()[0]['id'] && preg_match( '/^[a-f0-9]{12}$/', $second ) );

	// Add-all is all or nothing.
	$stock = wc_get_product( $c );
	$stock->set_stock_status( 'outofstock' );
	$stock->save();
	WC()->cart->empty_cart();
	wc_clear_notices();
	$res = Eru_BTD_Render::add_set( Eru_BTD_Sets::get()[0] );
	$check( 'add-all with an out-of-stock item adds nothing and shows an error', false === $res && WC()->cart->is_empty() && wc_notice_count( 'error' ) > 0 );
	$stock->set_stock_status( 'instock' );
	$stock->save();
	wc_clear_notices();
	$check( 'add-all with every item buyable adds the set', true === Eru_BTD_Render::add_set( Eru_BTD_Sets::get()[0] ) && 2 === count( WC()->cart->get_cart() ) );
	wc_clear_notices();
	update_option( 'eru_btd_sets', Eru_BTD_Sets::sanitize( array( array( 'active' => 1, 'products' => array( $vid, $b ), 'mode' => 'percent', 'value' => '10' ) ) ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $var->get_id(), 1, $vid, array( 'attribute_boyut' => 'S' ) );
	WC()->cart->add_to_cart( $b );

	$off = '__return_false';
	add_filter( 'eru_btd_apply', $off );
	$check( 'eru_btd_apply false -> 250', $near( $total(), 250 ) );
	remove_filter( 'eru_btd_apply', $off );
} finally {
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( null === $old ) {
		delete_option( 'eru_btd_sets' );
	} else {
		update_option( 'eru_btd_sets', $old );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
