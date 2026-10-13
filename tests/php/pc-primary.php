<?php
/**
 * Primary Category — picker value, SEO-plugin fallback (read-only), permalink and breadcrumb term.
 *   wp eval-file tests/php/pc-primary.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! function_exists( 'eru_pc_get_primary' ) ) {
	echo "FAIL: eru_pc_get_primary() missing (plugin not active?)\n";
	exit( 1 );
}

$ids   = array();
$terms = array();
$term  = function ( $name, $parent = 0 ) use ( &$terms ) {
	$t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	return $terms[] = (int) $t['term_id'];
};

try {
	$x = $term( 'PC Demo X' );
	$y = $term( 'PC Demo Y' );
	$z = $term( 'PC Demo Z' );
	$p = new WC_Product_Simple();
	$p->set_name( 'PC Demo Ürün' );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	$p->set_category_ids( array( $x, $y ) );
	$p->save();
	$ids[] = $pid = $p->get_id();

	$check( 'no primary chosen -> null', null === eru_pc_get_primary( $pid ) );

	update_post_meta( $pid, Eru_PC::META, $y );
	$t = eru_pc_get_primary( $pid );
	$check( 'primary Y returned', $t instanceof WP_Term && $t->term_id === $y );

	$link = wc_product_post_type_link( 'http://example.test/product/%product_cat%/pc-demo-urun/', get_post( $pid ) );
	$ysl  = get_term( $y )->slug;
	$xsl  = get_term( $x )->slug;
	$check( '%product_cat% permalink contains Y slug', false !== strpos( $link, '/' . $ysl . '/' ) && false === strpos( $link, '/' . $xsl . '/' ) );

	$GLOBALS['post'] = get_post( $pid );
	$trail = apply_filters( 'woocommerce_breadcrumb_main_term', get_term( $x ), array( get_term( $x ), get_term( $y ) ) );
	$check( 'breadcrumb main term is Y', $trail->term_id === $y );

	// A stored primary that is no longer assigned is ignored.
	update_post_meta( $pid, Eru_PC::META, $z );
	$check( 'unassigned primary ignored', null === eru_pc_get_primary( $pid ) );

	// Read-only SEO plugin fallback.
	delete_post_meta( $pid, Eru_PC::META );
	update_post_meta( $pid, '_yoast_wpseo_primary_product_cat', $x );
	$t = eru_pc_get_primary( $pid );
	$check( 'Yoast primary used as fallback', $t && $t->term_id === $x );
	update_post_meta( $pid, 'rank_math_primary_product_cat', $y );
	update_post_meta( $pid, Eru_PC::META, $y );
	$t = eru_pc_get_primary( $pid );
	$check( 'own value wins over Yoast/Rank Math', $t && $t->term_id === $y );
	$check( 'SEO plugin meta not modified', (int) get_post_meta( $pid, '_yoast_wpseo_primary_product_cat', true ) === $x && (int) get_post_meta( $pid, 'rank_math_primary_product_cat', true ) === $y );

	// Save handler validates the posted value.
	wp_set_current_user( 1 );
	$_POST = array( 'eru_pc_nonce' => wp_create_nonce( 'eru_pc_save' ), 'eru_pc_primary' => (string) $z );
	$p2 = wc_get_product( $pid );
	Eru_PC::save( $p2 );
	$check( 'save rejects a category the product is not in', (int) get_post_meta( $pid, Eru_PC::META, true ) === $y );
	$_POST['eru_pc_primary'] = (string) $x;
	Eru_PC::save( $p2 );
	$check( 'save stores a valid primary', (int) get_post_meta( $pid, Eru_PC::META, true ) === $x );
	$_POST['eru_pc_primary'] = '0';
	Eru_PC::save( $p2 );
	$check( 'save clears the primary', '' === (string) get_post_meta( $pid, Eru_PC::META, true ) );
} finally {
	$_POST = array();
	wp_set_current_user( 0 );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $terms as $t ) {
		wp_delete_term( $t, 'product_cat' );
	}
}

exit( $fail ? 1 : 0 );
