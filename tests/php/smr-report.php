<?php
/**
 * Shipping Method Report — two orders (flat_rate, free_shipping) → 1 row each with 1 order.
 *
 *   wp eval-file tests/php/smr-report.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_SMR_Report' ) ) {
	echo "FAIL: Eru_SMR_Report missing (plugin not active?)\n";
	exit( 1 );
}

$ids = array();
try {
	$make = function ( string $method, string $title, float $cost, string $loc = '' ) use ( &$ids ) {
		$o = wc_create_order();
		$s = new WC_Order_Item_Shipping();
		$s->set_method_id( $method );
		$s->set_method_title( $title );
		$s->set_total( (string) $cost );
		$o->add_item( $s );
		$o->set_billing_email( 'ornek@example.com' );
		if ( '' !== $loc ) {
			$o->update_meta_data( '_eru_ccl_location', $loc );
		}
		$o->set_status( 'processing' );
		$o->save();
		$ids[] = $o->get_id();
	};
	$make( 'flat_rate', 'Sabit Ücret', 30 );
	$make( 'free_shipping', 'Ücretsiz Kargo', 0 );
	$make( 'eru_ccl', 'Mağazadan teslim', 0, 'Demo Mağaza' );

	$today = wp_date( 'Y-m-d' );
	$rep   = Eru_SMR_Report::build( $today, $today, array( 'wc-processing' ) );
	$by    = array();
	foreach ( $rep['rows'] as $r ) {
		$by[ $r['method_id'] . '|' . $r['location'] ] = $r;
	}
	$check( 'flat_rate: 1 order', ( $by['flat_rate|']['orders'] ?? 0 ) === 1 );
	$check( 'free_shipping: 1 order', ( $by['free_shipping|']['orders'] ?? 0 ) === 1 );
	$check( 'flat_rate shipping total 30', abs( ( $by['flat_rate|']['shipping_total'] ?? 0 ) - 30 ) < 0.001 );
	$check( 'pickup location row from order meta', ( $by['eru_ccl|Demo Mağaza']['orders'] ?? 0 ) === 1 );
	$check( 'total orders counted', $rep['orders'] >= 3 );

	$csv = Eru_SMR_Report::to_csv( $rep['rows'] );
	$check( 'CSV has header and Turkish text', str_contains( $csv, 'method_id' ) && str_contains( $csv, 'Demo Mağaza' ) );
	$neutral = Eru_SMR_Report::to_csv( array( array( 'method_id' => 'x', 'label' => '=1+1', 'location' => '', 'orders' => 1, 'items' => 1, 'shipping_total' => 0 ) ) );
	$check( 'CSV neutralises formulas', str_contains( $neutral, "'=1+1" ) );

	$none = Eru_SMR_Report::build( '2000-01-01', '2000-01-02', array( 'wc-processing' ) );
	$check( 'empty range → no rows', array() === $none['rows'] );
} finally {
	foreach ( $ids as $id ) {
		wc_get_order( $id )->delete( true );
	}
}
exit( $fail ? 1 : 0 );
