<?php
/**
 * Automatic Discounts — cart total check.
 *   wp eval-file tests/php/ad-discount.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$near = fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.001;

if ( ! class_exists( 'Eru_AD_Rules' ) ) {
	echo "FAIL: class Eru_AD_Rules missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old_rules = get_option( 'eru_ad_rules', null );
$ids       = array();
$cat_ids   = array();

try {
	$cat       = wp_insert_term( 'AD Demo Kategori', 'product_cat' );
	$cat_id    = is_wp_error( $cat ) ? (int) $cat->get_error_data() : (int) $cat['term_id'];
	$cat_ids[] = $cat_id;

	$mk = function ( $name, $price, $cats = array() ) use ( &$ids ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( (string) $price );
		$p->set_category_ids( $cats );
		$p->set_status( 'publish' );
		return $ids[] = $p->save();
	};
	$a   = $mk( 'AD Demo Ürün', 200 );
	$cp  = $mk( 'AD Demo Kategorili', 100, array( $cat_id ) );

	$var = new WC_Product_Variable();
	$var->set_name( 'AD Demo Varyasyonlu' );
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
	$variation->set_regular_price( '600' );
	$vid   = $variation->save();
	$ids[] = $vid;
	WC_Product_Variable::sync( $var->get_id() );

	$total = function () {
		WC()->cart->calculate_totals();
		return (float) WC()->cart->get_total( 'edit' );
	};

	delete_option( 'eru_ad_rules' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 3 );
	$check( 'no rules -> 600', $near( $total(), 600 ) );

	update_option( 'eru_ad_rules', array( array( 'type' => 'subtotal', 'min_subtotal' => 450, 'mode' => 'percent', 'value' => 15, 'label' => 'Demo indirim', 'taxable' => 0 ) ) );
	$check( 'subtotal 600 >= 450, 15% -> 510', $near( $total(), 510 ) );
	$total();
	$check( 'recalculating twice -> 510', $near( $total(), 510 ) );
	$check( 'exactly one fee line', 1 === count( WC()->cart->get_fees() ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 2 );
	$check( 'subtotal 400 < 450 -> no discount', $near( $total(), 400 ) );

	// Variable product variation.
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $var->get_id(), 1, $vid, array( 'attribute_boyut' => 'S' ) );
	$check( 'variation 600 -> 510', $near( $total(), 510 ) );

	// Date range in the past -> inactive.
	update_option( 'eru_ad_rules', array( array( 'type' => 'subtotal', 'min_subtotal' => 450, 'mode' => 'percent', 'value' => 15, 'end' => '2020-01-01' ) ) );
	$check( 'expired rule -> 600', $near( $total(), 600 ) );

	// Category quantity rule + priority + stop.
	update_option(
		'eru_ad_rules',
		array(
			array( 'type' => 'subtotal', 'min_subtotal' => 100, 'mode' => 'fixed', 'value' => 50, 'priority' => 20, 'label' => 'Ikinci' ),
			array( 'type' => 'category_qty', 'category' => $cat_id, 'min_qty' => 2, 'mode' => 'percent', 'value' => 10, 'priority' => 5, 'stop' => 1, 'label' => 'Birinci' ),
		)
	);
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $cp, 2 );
	$check( 'category 2x100, 10% with stop -> 180 (second rule skipped)', $near( $total(), 180 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $cp, 1 );
	$check( 'category qty 1 < 2 -> only fixed 50 -> 50', $near( $total(), 50 ) );

	// Same label twice -> both fees present, still idempotent.
	update_option(
		'eru_ad_rules',
		array(
			array( 'type' => 'subtotal', 'min_subtotal' => 100, 'mode' => 'fixed', 'value' => 10 ),
			array( 'type' => 'subtotal', 'min_subtotal' => 100, 'mode' => 'fixed', 'value' => 20 ),
		)
	);
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 1 );
	$total();
	$check( 'two rules stack (200-30=170), twice the same', $near( $total(), 170 ) );

	// Opt-out filter.
	$off = '__return_false';
	add_filter( 'eru_ad_apply', $off );
	$check( 'eru_ad_apply false -> 200', $near( $total(), 200 ) );
	remove_filter( 'eru_ad_apply', $off );

	// Coexistence with Payment Method Discounts (only when that plugin is active).
	if ( class_exists( 'Eru_PMD_Rules' ) ) {
		$old_pmd = get_option( 'eru_pmd_rules', null );
		update_option( 'eru_ad_rules', array( array( 'type' => 'subtotal', 'min_subtotal' => 100, 'mode' => 'percent', 'value' => 10, 'taxable' => 0 ) ) );
		update_option( 'eru_pmd_rules', array( array( 'gateway' => 'bacs', 'type' => 'discount', 'mode' => 'percent', 'value' => 5, 'label' => 'PMD demo', 'taxable' => 0 ) ) );
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $a, 5 ); // 1000.
		WC()->session->set( 'chosen_payment_method', 'bacs' );
		$total();
		$check( 'with Payment Method Discounts: 1000 - 100 (AD) - 50 (5% of the 1000 line total) = 850, stable on recalculation', $near( $total(), 850 ) && 2 === count( WC()->cart->get_fees() ) );
		WC()->session->set( 'chosen_payment_method', '' );
		if ( null === $old_pmd ) {
			delete_option( 'eru_pmd_rules' );
		} else {
			update_option( 'eru_pmd_rules', $old_pmd );
		}
	}

	// Sanitiser.
	$clean = Eru_AD_Rules::sanitize( array( array( 'value' => 0 ), array( 'type' => 'category_qty', 'value' => 5 ), array( 'value' => '150', 'mode' => 'percent', 'start' => 'junk' ) ) );
	$check( 'sanitiser keeps valid row, caps 100%, drops bad date', 1 === count( $clean ) && 100.0 === (float) $clean[0]['value'] && '' === $clean[0]['start'] );
} finally {
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $cat_ids as $cid ) {
		wp_delete_term( $cid, 'product_cat' );
	}
	if ( null === $old_rules ) {
		delete_option( 'eru_ad_rules' );
	} else {
		update_option( 'eru_ad_rules', $old_rules );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
