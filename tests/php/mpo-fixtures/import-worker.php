<?php
/**
 * Worker for the multi-process race in tests/php/mpo-importer.php: waits for MPO_START, then imports the
 * packages in MPO_REFS through the fake adapter and prints "<ref> <result>" per package. Invented data only.
 */

require dirname( __DIR__ ) . '/mpo-helpers.php';
mpo_no_http();
$fake = mpo_fake_adapter();
add_action(
	'eru_mpo_order_before_save',
	function () {
		usleep( 300000 ); // Widen the window between claim and save.
	}
);
$start = (float) getenv( 'MPO_START' );
while ( microtime( true ) < $start ) {
	usleep( 2000 );
}
foreach ( array_filter( explode( ',', (string) getenv( 'MPO_REFS' ) ) ) as $ref ) {
	$r = Eru_MPO_Importer::import( $fake, mpo_dto( 'order-basic.json', array( '{{REF}}' => $ref, '{{SKU}}' => 'MPO-TEST-NONE', '{{STATUS}}' => 'new', '{{TCKN}}' => '' ) ) );
	echo $ref . ' ' . $r['result'] . "\n";
}
