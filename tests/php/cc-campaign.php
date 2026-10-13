<?php
/**
 * Cart Campaigns — cart total / progress message check.
 *   wp eval-file tests/php/cc-campaign.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$near = fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.001;

if ( ! class_exists( 'Eru_CC_Campaigns' ) ) {
	echo "FAIL: class Eru_CC_Campaigns missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old = get_option( 'eru_cc_settings', null );
$ids = array();

try {
	$mk = function ( $name, $price ) use ( &$ids ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( (string) $price );
		$p->set_status( 'publish' );
		return $ids[] = $p->save();
	};
	$a = $mk( 'CC Demo Ürün', 400 );

	$var = new WC_Product_Variable();
	$var->set_name( 'CC Demo Varyasyonlu' );
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
	$variation->set_regular_price( '1000' );
	$vid   = $variation->save();
	$ids[] = $vid;
	WC_Product_Variable::sync( $var->get_id() );

	$total = function () {
		WC()->cart->calculate_totals();
		return (float) WC()->cart->get_total( 'edit' );
	};

	delete_option( 'eru_cc_settings' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 2 );
	$check( 'no settings -> 800, no message', $near( $total(), 800 ) && '' === Eru_CC_Campaigns::message( WC()->cart ) );

	update_option( 'eru_cc_settings', Eru_CC_Campaigns::sanitize( array( 'tiers' => array( array( 'threshold' => '1000', 'mode' => 'percent', 'value' => '5', 'label' => 'Demo kampanya' ) ) ) ) );
	$check( 'cart 800, tier 1000 -> total 800', $near( $total(), 800 ) );
	$msg = Eru_CC_Campaigns::message( WC()->cart );
	$check( 'progress text mentions 200 and %5', false !== strpos( $msg, '200' ) && false !== strpos( $msg, '%5' ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 3 );
	$check( 'cart 1200 -> 5% = 60 -> 1140', $near( $total(), 1140 ) );
	$total();
	$check( 'recalculating twice -> 1140, one fee', $near( $total(), 1140 ) && 1 === count( WC()->cart->get_fees() ) );
	$check( 'success message shown at top tier', false !== strpos( Eru_CC_Campaigns::message( WC()->cart ), 'kazandınız' ) );

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $var->get_id(), 1, $vid, array( 'attribute_boyut' => 'S' ) );
	$check( 'variation 1000 -> 950', $near( $total(), 950 ) );

	// Two tiers: highest reached applies once.
	update_option(
		'eru_cc_settings',
		Eru_CC_Campaigns::sanitize(
			array( 'tiers' => array(
				array( 'threshold' => '2000', 'mode' => 'fixed', 'value' => '300' ),
				array( 'threshold' => '1000', 'mode' => 'percent', 'value' => '5' ),
			) )
		)
	);
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 3 );
	$check( 'tiers: 1200 -> only 5% tier -> 1140', $near( $total(), 1140 ) );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $a, 5 );
	$check( 'tiers: 2000 -> fixed 300 -> 1700', $near( $total(), 1700 ) );

	$off = '__return_false';
	add_filter( 'eru_cc_apply', $off );
	$check( 'eru_cc_apply false -> 2000', $near( $total(), 2000 ) );
	remove_filter( 'eru_cc_apply', $off );

	$clean = Eru_CC_Campaigns::sanitize( array( 'tiers' => array( array( 'threshold' => 0, 'value' => 5 ), array( 'threshold' => 100, 'value' => 150 ) ) ) );
	$check( 'sanitiser drops bad row, caps 100%', 1 === count( $clean['tiers'] ) && 100.0 === (float) $clean['tiers'][0]['value'] );
} finally {
	WC()->cart->empty_cart();
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	if ( null === $old ) {
		delete_option( 'eru_cc_settings' );
	} else {
		update_option( 'eru_cc_settings', $old );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
