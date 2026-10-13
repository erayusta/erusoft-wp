<?php
/**
 * Discount products together — the combined discount never exceeds what is left to pay.
 *   wp eval-file tests/php/discount-budget.php
 * Needs Automatic Discounts, Cart Campaigns, Bought-Together Discounts and Payment Method Discounts active.
 * Each pair gets two 60 % discounts on a 100 cart: the second one may only take the remaining 40.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$near = fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.005;

foreach ( array( 'Eru_AD_Rules', 'Eru_CC_Campaigns', 'Eru_BTD_Sets', 'Eru_PMD_Rules' ) as $c ) {
	if ( ! class_exists( $c ) ) {
		echo "FAIL: class $c missing (activate the four discount plugins)\n";
		exit( 1 );
	}
}

wc_load_cart();
$opts = array( 'eru_ad_rules', 'eru_cc_settings', 'eru_btd_sets', 'eru_pmd_rules' );
$old  = array();
foreach ( $opts as $o ) {
	$old[ $o ] = get_option( $o, null );
}
$ids = array();
$mk  = function ( $name, $price ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $price );
	$p->set_manage_stock( false );
	$p->set_status( 'publish' );
	return $ids[] = $p->save();
};
$off = function () use ( $opts ) {
	foreach ( $opts as $o ) {
		delete_option( $o );
	}
	WC()->session->set( 'chosen_payment_method', '' );
};
$run = function () {
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$neg = 0.0;
	foreach ( WC()->cart->get_fees() as $f ) {
		$neg += min( 0, (float) $f->amount );
	}
	return array( (float) WC()->cart->get_cart_contents_total(), -$neg, count( WC()->cart->get_fees() ) );
};
$ad  = fn() => update_option( 'eru_ad_rules', array( array( 'type' => 'subtotal', 'min_subtotal' => 0, 'mode' => 'percent', 'value' => 60, 'label' => 'Demo AD', 'taxable' => 0 ) ) );
$cc  = fn() => update_option( 'eru_cc_settings', Eru_CC_Campaigns::sanitize( array( 'tiers' => array( array( 'threshold' => '1', 'mode' => 'percent', 'value' => '60', 'label' => 'Demo CC' ) ) ) ) );
$pmd = function () {
	update_option( 'eru_pmd_rules', array( array( 'gateway' => 'bacs', 'type' => 'discount', 'mode' => 'percent', 'value' => 60, 'label' => 'Demo PMD', 'taxable' => 0 ) ) );
	WC()->session->set( 'chosen_payment_method', 'bacs' );
};

try {
	$a   = $mk( 'Demo Ürün A', 60 );
	$b   = $mk( 'Demo Ürün B', 40 );
	$btd = fn() => update_option( 'eru_btd_sets', Eru_BTD_Sets::sanitize( array( array( 'active' => 1, 'products' => array( $a, $b ), 'mode' => 'percent', 'value' => '60', 'label' => 'Demo BTD' ) ) ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 1 );
	WC()->cart->add_to_cart( $b, 1 ); // 100.

	foreach ( array(
		'Automatic Discounts + Cart Campaigns'                  => array( $ad, $cc ),
		'Automatic Discounts + Bought-Together Discounts'       => array( $ad, $btd ),
		'Cart Campaigns + Bought-Together Discounts'            => array( $cc, $btd ),
		'Automatic Discounts + Payment Method Discounts'        => array( $ad, $pmd ),
		'Bought-Together Discounts + Payment Method Discounts'  => array( $btd, $pmd ),
	) as $label => $pair ) {
		$off();
		foreach ( $pair as $enable ) {
			$enable();
		}
		list( $contents, $disc, $n ) = $run();
		$check( "$label: two 60 % discounts on 100 -> 100 total discount, never more (got $disc)", 2 === $n && $near( $disc, 100 ) && $near( $contents, 100 ) );
	}

	// One source alone is untouched.
	$off();
	$ad();
	list( , $disc ) = $run();
	$check( 'single 60 % rule -> 60', $near( $disc, 60 ) );
} finally {
	WC()->session->set( 'chosen_payment_method', '' );
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $old as $o => $v ) {
		null === $v ? delete_option( $o ) : update_option( $o, $v );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
