<?php
/**
 * Bulk Product Visibility — dry-run preview, then batched apply by category / stock / price.
 *   wp eval-file tests/php/bpv-bulk.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_BPV_Engine' ) ) {
	echo "FAIL: class Eru_BPV_Engine missing (plugin not active?)\n";
	exit( 1 );
}

$ids   = array();
$terms = array();
$old_user = get_current_user_id();
$term  = function ( $name ) use ( &$terms ) {
	$t = wp_insert_term( $name, 'product_cat' );
	return $terms[] = (int) $t['term_id'];
};
$mk    = function ( $title, $cat, $price, $stock = 'instock' ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_regular_price( $price );
	$p->set_stock_status( $stock );
	$p->set_status( 'publish' );
	$p->set_category_ids( array( $cat ) );
	$p->save();
	return $ids[] = $p->get_id();
};
$vis   = fn( $id ) => wc_get_product( $id )->get_catalog_visibility();
$run   = function ( $filters, $changes ) {
	$pv = Eru_BPV_Engine::preview( $filters, $changes );
	if ( is_wp_error( $pv ) ) {
		return $pv;
	}
	$off = 0;
	for ( $i = 0; $i < 50; $i++ ) {
		$r = Eru_BPV_Engine::run_batch( $pv['token'], $off );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r['done'] ) {
			return $pv;
		}
		$off = $r['next'];
	}
	return new WP_Error( 'loop' );
};

try {
	wp_set_current_user( 1 );
	add_filter( 'eru_bpv_batch_size', fn() => 1 );

	$x   = $term( 'BPV Demo X' );
	$y   = $term( 'BPV Demo Y' );
	$x1  = $mk( 'BPV Demo X1', $x, '10' );
	$x2  = $mk( 'BPV Demo X2', $x, '100', 'outofstock' );
	$x3  = $mk( 'BPV Demo X3', $x, '30' );
	$y1  = $mk( 'BPV Demo Y1', $y, '10' );

	$filters = array( 'category' => $x );
	$pv      = Eru_BPV_Engine::preview( $filters, array( 'visibility' => 'hidden' ) );
	$check( 'preview counts the products in the category', ! is_wp_error( $pv ) && 3 === $pv['count'] );
	$check( 'preview (dry run) changes nothing', 'visible' === $vis( $x1 ) && 'visible' === $vis( $x2 ) );
	$check( 'apply without a preview token is refused', is_wp_error( Eru_BPV_Engine::run_batch( 'nope', 0 ) ) );

	$r1 = Eru_BPV_Engine::run_batch( $pv['token'], 0 );
	$check( 'first batch is partial (batch size 1)', ! is_wp_error( $r1 ) && ! $r1['done'] && 1 === $r1['next'] );
	$done_pv = $run( $filters, array( 'visibility' => 'hidden' ) );
	$check( 'batched apply: category X products hidden', ! is_wp_error( $done_pv ) && 'hidden' === $vis( $x1 ) && 'hidden' === $vis( $x2 ) && 'hidden' === $vis( $x3 ) );
	$check( 'products outside the category unchanged', 'visible' === $vis( $y1 ) );
	$check( 'token cannot be replayed after completion', is_wp_error( Eru_BPV_Engine::run_batch( $done_pv['token'], 0 ) ) );

	$run( array( 'category' => $x ), array( 'visibility' => 'visible' ) );
	$run( array( 'category' => $x, 'stock' => 'outofstock' ), array( 'visibility' => 'catalog' ) );
	$check( 'stock filter: only out-of-stock changed', 'catalog' === $vis( $x2 ) && 'visible' === $vis( $x1 ) && 'visible' === $vis( $x3 ) );

	$run( array( 'category' => $x, 'price_max' => '50' ), array( 'visibility' => 'search' ) );
	$check( 'price filter: only products up to the maximum changed', 'search' === $vis( $x1 ) && 'search' === $vis( $x3 ) && 'catalog' === $vis( $x2 ) );

	$run( array( 'category' => $x, 'price_min' => '90' ), array( 'status' => 'draft' ) );
	$check( 'status change via the same flow', 'draft' === get_post_status( $x2 ) && 'publish' === get_post_status( $x1 ) );

	$check( 'a change is required', is_wp_error( Eru_BPV_Engine::preview( $filters, array() ) ) );
	$check( 'invalid visibility is rejected', is_wp_error( Eru_BPV_Engine::preview( $filters, array( 'visibility' => 'bogus' ) ) ) );

	wp_set_current_user( 0 );
	$check( 'preview needs manage_woocommerce', is_wp_error( Eru_BPV_Engine::preview( $filters, array( 'visibility' => 'hidden' ), true ) ) );
} finally {
	wp_set_current_user( $old_user );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $terms as $t ) {
		wp_delete_term( $t, 'product_cat' );
	}
}

exit( $fail ? 1 : 0 );
