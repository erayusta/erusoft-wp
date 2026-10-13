<?php
/**
 * High-Value Cart Offer — output check.
 *   wp eval-file tests/php/hvco-offer.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_HVCO_Offer' ) ) {
	echo "FAIL: class Eru_HVCO_Offer missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$old = get_option( 'eru_hvco_settings', null );
$ids = array();
$num = implode( '', array( '90', '555', '000', '0000' ) ); // Fictional number, built so it is not a literal phone pattern.

// Number normalisation: same behaviour as Catalog Mode & WhatsApp Orders.
$loc   = '0' . implode( ' ', array( '555', '000', '00', '00' ) );
$cases = array(
	'digits are kept'                              => array( $num, $num ),
	'formatting is stripped'                       => array( '+' . $num, $num ),
	'national 0XXXXXXXXXX becomes 90XXXXXXXXXX'    => array( $loc, $num ),
	'bare 10-digit mobile 5XXXXXXXXX becomes 90…'  => array( substr( $num, 2 ), $num ),
	'00 prefix is dropped'                         => array( '00' . $num, $num ),
	'garbage is rejected'                          => array( 'abc', '' ),
	'too short is rejected'                        => array( '123', '' ),
);
foreach ( $cases as $label => $c ) {
	$check( "number: $label", $c[1] === Eru_HVCO_Offer::clean_number( $c[0] ) );
	if ( class_exists( 'Eru_CMW' ) ) {
		$check( "number: $label (same as Catalog Mode WhatsApp)", Eru_CMW::normalize_number( $c[0] ) === Eru_HVCO_Offer::clean_number( $c[0] ) );
	}
}

try {
	$p = new WC_Product_Simple();
	$p->set_name( 'HVCO Demo Ürün' );
	$p->set_regular_price( '100' );
	$p->set_status( 'publish' );
	$ids[] = $p->save();

	$box = function ( $qty ) use ( $ids ) {
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $ids[0], $qty );
		WC()->cart->calculate_totals();
		return Eru_HVCO_Offer::box( WC()->cart );
	};

	delete_option( 'eru_hvco_settings' );
	$check( 'defaults (no number) -> no output at 1200', '' === $box( 12 ) );

	update_option( 'eru_hvco_settings', Eru_HVCO_Offer::sanitize( array( 'number' => '', 'threshold' => '1000' ) ) );
	$check( 'empty number -> no output', '' === $box( 12 ) );

	update_option( 'eru_hvco_settings', Eru_HVCO_Offer::sanitize( array( 'number' => '+' . $num, 'threshold' => '1000' ) ) );
	$html = $box( 12 );
	$check( 'cart 1200 -> link https://wa.me/<number>?text=', false !== strpos( $html, 'href="https://wa.me/' . $num . '?text=' ) );
	$check( 'message carries product name and quantity (url-encoded)', false !== strpos( $html, rawurlencode( 'HVCO Demo' ) ) && false !== strpos( $html, rawurlencode( 'x 12' ) ) );
	$check( 'cart 500 -> no link', '' === $box( 5 ) );
	$check( 'cart exactly 1000 -> link', false !== strpos( $box( 10 ), 'wa.me' ) );

	$s = Eru_HVCO_Offer::sanitize( array( 'number' => '12345', 'threshold' => '-5' ) );
	$check( 'sanitiser rejects short numbers and negative thresholds', '' === $s['number'] && 0.0 === (float) $s['threshold'] );
} finally {
	WC()->cart->empty_cart();
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( null === $old ) {
		delete_option( 'eru_hvco_settings' );
	} else {
		update_option( 'eru_hvco_settings', $old );
	}
}

echo $fail ? "RESULT: FAIL ({$fail})\n" : "RESULT: PASS\n";
exit( $fail ? 1 : 0 );
