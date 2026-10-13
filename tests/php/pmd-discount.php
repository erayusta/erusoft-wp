<?php
/**
 * Payment Method Discounts — cart total check.
 *
 * Run against the demo site with the plugin active:
 *   wp eval-file tests/php/pmd-discount.php
 * Creates throw-away products, restores the options it touches, prints PASS/FAIL, exits non-zero on failure.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_PMD_Rules' ) ) {
	echo "FAIL: class Eru_PMD_Rules missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old_rules = get_option( 'eru_pmd_rules', null );
$ids       = array();
$cat_ids   = array();

try {
	$cat = wp_insert_term( 'PMD Demo Kategori', 'product_cat' );
	$cat_id = is_wp_error( $cat ) ? (int) $cat->get_error_data() : (int) $cat['term_id'];
	$cat_ids[] = $cat_id;

	$simple = new WC_Product_Simple();
	$simple->set_name( 'PMD Demo Ürün' );
	$simple->set_regular_price( '1000' );
	$simple->set_status( 'publish' );
	$ids[] = $simple->save();

	$excluded = new WC_Product_Simple();
	$excluded->set_name( 'PMD Demo Hariç' );
	$excluded->set_regular_price( '500' );
	$excluded->set_category_ids( array( $cat_id ) );
	$excluded->set_status( 'publish' );
	$ids[] = $excluded->save();

	$var = new WC_Product_Variable();
	$var->set_name( 'PMD Demo Varyasyonlu' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Boyut' );
	$attr->set_options( array( 'S' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$var->set_attributes( array( $attr ) );
	$var->set_status( 'publish' );
	$ids[] = $var->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $var->get_id() );
	$variation->set_attributes( array( 'boyut' => 'S' ) );
	$variation->set_regular_price( '200' );
	$ids[] = $variation->save();
	WC_Product_Variable::sync( $var->get_id() );

	// No rules: nothing happens.
	delete_option( 'eru_pmd_rules' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[0] );
	WC()->session->set( 'chosen_payment_method', 'bacs' );
	WC()->cart->calculate_totals();
	$check( 'no rules -> total 1000', abs( (float) WC()->cart->get_total( 'edit' ) - 1000 ) < 0.001 );

	update_option(
		'eru_pmd_rules',
		array(
			array(
				'gateway'            => 'bacs',
				'type'               => 'discount',
				'mode'               => 'percent',
				'value'              => 5,
				'label'              => 'Havale indirimi',
				'min_cart'           => 0,
				'exclude_categories' => array( $cat_id ),
				'taxable'            => 1,
			),
		)
	);

	WC()->session->set( 'chosen_payment_method', 'bacs' );
	WC()->cart->calculate_totals();
	$check( 'bacs 5% on 1000 -> 950', abs( (float) WC()->cart->get_total( 'edit' ) - 950 ) < 0.001 );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( 'recalculating twice -> still 950', abs( (float) WC()->cart->get_total( 'edit' ) - 950 ) < 0.001 );

	WC()->session->set( 'chosen_payment_method', 'cod' );
	WC()->cart->calculate_totals();
	$check( 'other gateway -> 1000', abs( (float) WC()->cart->get_total( 'edit' ) - 1000 ) < 0.001 );

	WC()->session->set( 'chosen_payment_method', 'bacs' );
	WC()->cart->add_to_cart( $ids[1] );
	WC()->cart->calculate_totals();
	$check( 'excluded category line is not discounted (1500 - 50 = 1450)', abs( (float) WC()->cart->get_total( 'edit' ) - 1450 ) < 0.001 );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $ids[2], 1, $ids[3], array( 'attribute_boyut' => 'S' ) );
	WC()->cart->calculate_totals();
	$check( 'variation 200 -> 190', abs( (float) WC()->cart->get_total( 'edit' ) - 190 ) < 0.001 );

	// Minimum cart total.
	$rules                = get_option( 'eru_pmd_rules' );
	$rules[0]['min_cart'] = 500;
	update_option( 'eru_pmd_rules', $rules );
	WC()->cart->calculate_totals();
	$check( 'below min cart -> no discount (200)', abs( (float) WC()->cart->get_total( 'edit' ) - 200 ) < 0.001 );

	// Fixed fee.
	update_option(
		'eru_pmd_rules',
		array(
			array( 'gateway' => 'bacs', 'type' => 'fee', 'mode' => 'fixed', 'value' => 15, 'label' => 'Ek ücret', 'taxable' => 0 ),
		)
	);
	WC()->cart->calculate_totals();
	$check( 'fixed fee 15 on 200 -> 215', abs( (float) WC()->cart->get_total( 'edit' ) - 215 ) < 0.001 );

	// Sanitiser drops junk.
	$clean = Eru_PMD_Rules::sanitize( array( array( 'gateway' => '', 'value' => 5 ), array( 'gateway' => 'bacs', 'type' => 'x', 'mode' => 'y', 'value' => '-3' ), array( 'gateway' => 'bacs', 'value' => '7' . wc_get_price_decimal_separator() . '5', 'type' => 'discount', 'mode' => 'percent' ) ) );
	$check( 'sanitiser keeps only valid rows', 1 === count( $clean ) && 7.5 === (float) $clean[0]['value'] );
} finally {
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $cat_ids as $cid ) {
		wp_delete_term( $cid, 'product_cat' );
	}
	if ( null === $old_rules ) {
		delete_option( 'eru_pmd_rules' );
	} else {
		update_option( 'eru_pmd_rules', $old_rules );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
