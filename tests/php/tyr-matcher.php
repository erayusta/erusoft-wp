<?php
/**
 * Trendyol Reviews — Eru_TYR_Matcher priority test.
 *
 * Run against the demo site with the plugin active:
 *   wp eval-file tests/php/tyr-matcher.php
 * Prints PASS/FAIL per assertion and exits non-zero on any failure.
 * Creates two throw-away products and removes them afterwards.
 */

$eru_tyr_fail = 0;
$eru_tyr_check = function ( $label, $ok ) use ( &$eru_tyr_fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$eru_tyr_fail++;
	}
};

if ( ! class_exists( 'Eru_TYR_Matcher' ) ) {
	echo "FAIL: class Eru_TYR_Matcher missing (plugin not active?)\n";
	exit( 1 );
}

$eru_tyr_old_map = get_option( 'eru_tyr_map', null );
$eru_tyr_ids     = array();

try {
	delete_option( 'eru_tyr_map' );

	$first = new WC_Product_Simple();
	$first->set_name( 'TYR Test SKU' );
	$first->set_status( 'publish' );
	$first->set_sku( 'TY-1' );
	$eru_tyr_ids[] = $first->save();

	$second = new WC_Product_Simple();
	$second->set_name( 'TYR Test Barkod' );
	$second->set_status( 'publish' );
	$second->set_sku( 'TYR-TEST-2' );
	$eru_tyr_ids[] = $second->save();
	update_post_meta( $eru_tyr_ids[1], '_global_unique_id', '868000000001' );

	$eru_tyr_check( 'barcode meta -> second product', Eru_TYR_Matcher::match( array( 'barcode' => '868000000001' ) ) === $eru_tyr_ids[1] );
	$eru_tyr_check( 'sku TY-1 -> first product', Eru_TYR_Matcher::match( array( 'sku' => 'TY-1' ) ) === $eru_tyr_ids[0] );
	$eru_tyr_check( 'barcode wins over sku', Eru_TYR_Matcher::match( array( 'barcode' => '868000000001', 'sku' => 'TY-1' ) ) === $eru_tyr_ids[1] );

	update_option( 'eru_tyr_map', array( 990001 => $eru_tyr_ids[0] ), false );
	$eru_tyr_check(
		'mapping table overrides barcode and sku',
		Eru_TYR_Matcher::match( array( 'content_id' => 990001, 'barcode' => '868000000001', 'sku' => 'TYR-TEST-2' ) ) === $eru_tyr_ids[0]
	);

	$eru_tyr_check( 'unknown -> null', null === Eru_TYR_Matcher::match( array( 'content_id' => 990002, 'barcode' => '868000000999', 'sku' => 'NOPE-1' ) ) );
	$eru_tyr_check( 'empty -> null', null === Eru_TYR_Matcher::match( array() ) );
} finally {
	foreach ( $eru_tyr_ids as $eru_tyr_id ) {
		wp_delete_post( $eru_tyr_id, true );
	}
	if ( null === $eru_tyr_old_map ) {
		delete_option( 'eru_tyr_map' );
	} else {
		update_option( 'eru_tyr_map', $eru_tyr_old_map, false );
	}
}

echo $eru_tyr_fail ? "RESULT: FAIL ({$eru_tyr_fail})\n" : "RESULT: PASS\n";
exit( $eru_tyr_fail ? 1 : 0 );
