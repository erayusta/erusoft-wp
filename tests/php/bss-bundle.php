<?php
/**
 * Bundle Stock Sync — stock maths, order reduce, cancel/refund restore, nesting + recursion guards.
 *   wp eval-file tests/php/bss-bundle.php   (plugin + WooCommerce active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_BSS_Bundles' ) ) {
	echo "FAIL: class Eru_BSS_Bundles missing (plugin not active?)\n";
	exit( 1 );
}

$ids    = array();
$orders = array();
update_option( 'woocommerce_manage_stock', 'yes' );
$mk   = function ( $name, $sku, $stock = null ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	if ( null !== $stock ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
		$p->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
	}
	$p->save();
	return $ids[] = $p->get_id();
};
$qty  = fn( $id ) => wc_get_product( $id )->get_stock_quantity();
$stat = fn( $id ) => wc_get_product( $id )->get_stock_status();

$a = $mk( 'Demo Bileşen A', 'DEMO-BSS-A', 10 );
$b = $mk( 'Demo Bileşen B', 'DEMO-BSS-B', 3 );
$k = $mk( 'Demo Paket', 'DEMO-BSS-KIT' );

$res = Eru_BSS_Bundles::set_components( $k, array( array( 'sku' => 'DEMO-BSS-A', 'qty' => 2 ), array( 'sku' => 'DEMO-BSS-B', 'qty' => 1 ) ) );
$check( 'definition accepted', true === $res );
$check( 'bundle stock = min(10/2, 3/1) = 3', 3 === $qty( $k ) && 'instock' === $stat( $k ) );

// Component stock change recalculates the bundle (and does not loop).
wc_update_product_stock( wc_get_product( $a ), 4, 'set' );
$check( 'component A set to 4 -> bundle 2', 2 === $qty( $k ) );
wc_update_product_stock( wc_get_product( $a ), 10, 'set' );
$check( 'component A back to 10 -> bundle 3', 3 === $qty( $k ) );

// Order: 1 bundle -> A 8, B 2, bundle 2.
$order = wc_create_order();
$order->add_product( wc_get_product( $k ), 1 );
$order->calculate_totals();
$order->save();
$orders[] = $order->get_id();
wc_reduce_stock_levels( $order->get_id() );
$check( 'order of 1 bundle: A 8', 8 === $qty( $a ) );
$check( 'order of 1 bundle: B 2', 2 === $qty( $b ) );
$check( 'order of 1 bundle: bundle 2', 2 === $qty( $k ) );
wc_reduce_stock_levels( $order->get_id() );
$check( 'reducing twice is idempotent (A still 8)', 8 === $qty( $a ) );

// Cancel restores.
wc_increase_stock_levels( $order->get_id() );
$check( 'cancel restores A 10 / B 3 / bundle 3', 10 === $qty( $a ) && 3 === $qty( $b ) && 3 === $qty( $k ) );
wc_increase_stock_levels( $order->get_id() );
$check( 'restoring twice is idempotent', 10 === $qty( $a ) && 3 === $qty( $b ) );

// Order of 2, refund 1 with restock.
$order2 = wc_create_order();
$order2->add_product( wc_get_product( $k ), 2 );
$order2->calculate_totals();
$order2->set_status( 'processing' );
$order2->save();
$orders[] = $order2->get_id();
wc_reduce_stock_levels( $order2->get_id() );
$check( 'order of 2: A 6, B 1, bundle 1', 6 === $qty( $a ) && 1 === $qty( $b ) && 1 === $qty( $k ) );
$item = array_values( $order2->get_items() )[0];
wc_create_refund(
	array(
		'order_id'       => $order2->get_id(),
		'amount'         => '10',
		'line_items'     => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 10 ) ),
		'restock_items'  => true,
	)
);
$check( 'refund 1 with restock: A 8, B 2, bundle 2', 8 === $qty( $a ) && 2 === $qty( $b ) && 2 === $qty( $k ) );

// Unpublished component -> bundle out of stock; republish -> back.
wp_update_post( array( 'ID' => $b, 'post_status' => 'draft' ) );
$check( 'draft component -> bundle out of stock', 0 === $qty( $k ) && 'outofstock' === $stat( $k ) );
wp_update_post( array( 'ID' => $b, 'post_status' => 'publish' ) );
$check( 'republished component -> bundle back in stock', $qty( $k ) > 0 && 'instock' === $stat( $k ) );

// Nesting / self rejected.
$k2 = $mk( 'Demo Paket 2', 'DEMO-BSS-KIT2' );
$r  = Eru_BSS_Bundles::set_components( $k2, array( array( 'sku' => 'DEMO-BSS-KIT', 'qty' => 1 ) ) );
$check( 'bundle containing a bundle is rejected', is_wp_error( $r ) && ! Eru_BSS_Bundles::is_bundle( $k2 ) );
$r = Eru_BSS_Bundles::set_components( $k, array( array( 'sku' => 'DEMO-BSS-KIT', 'qty' => 1 ) ) );
$check( 'bundle containing itself is rejected', is_wp_error( $r ) );
$r = Eru_BSS_Bundles::set_components( $a, array( array( 'sku' => 'DEMO-BSS-B', 'qty' => 1 ) ) );
$check( 'a component cannot become a bundle', is_wp_error( $r ) );
$check( 'rejected edits left the original definition', 2 === count( Eru_BSS_Bundles::get_components( $k ) ) );

// Unknown SKU -> bundle cannot be sold.
$k3 = $mk( 'Demo Paket 3', 'DEMO-BSS-KIT3' );
Eru_BSS_Bundles::set_components( $k3, array( array( 'sku' => 'DEMO-NO-SUCH', 'qty' => 1 ) ) );
$check( 'unknown component SKU -> bundle out of stock', 0 === $qty( $k3 ) );

// Component without stock management does not limit the bundle.
$c = $mk( 'Demo Bileşen C', 'DEMO-BSS-C' );
$k4 = $mk( 'Demo Paket 4', 'DEMO-BSS-KIT4' );
Eru_BSS_Bundles::set_components( $k4, array( array( 'sku' => 'DEMO-BSS-C', 'qty' => 1 ), array( 'sku' => 'DEMO-BSS-A', 'qty' => 4 ) ) );
$check( 'unmanaged component ignored: bundle = A/4 = 2', 2 === $qty( $k4 ) );

// Clean up.
foreach ( $orders as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o ) {
		$o->delete( true );
	}
}
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
