<?php
/**
 * Hide Categories — hidden categories vanish from shop loops, term lists and (optionally) direct access.
 *   wp eval-file tests/php/hc-hide.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_HC' ) ) {
	echo "FAIL: class Eru_HC missing (plugin not active?)\n";
	exit( 1 );
}

$saved = get_option( 'eru_hc_settings', null );
$ids   = array();
$terms = array();
$term  = function ( $name, $parent = 0 ) use ( &$terms ) {
	$t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	return $terms[] = (int) $t['term_id'];
};
$mk    = function ( $title, $cats ) use ( &$ids ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	$p->set_category_ids( $cats );
	$p->save();
	return $ids[] = $p->get_id();
};
$main  = function ( array $qv ) {
	$old_q = array( $GLOBALS['wp_the_query'] ?? null, $GLOBALS['wp_query'] ?? null );
	$q     = new WP_Query();
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = $q;
	$res   = $q->query( $qv + array( 'post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => 50 ) );
	$out   = array( array_map( 'intval', $res ), $q );
	list( $GLOBALS['wp_the_query'], $GLOBALS['wp_query'] ) = $old_q;
	return $out;
};

try {
	$h    = $term( 'HC Demo Gizli' );
	$hc   = $term( 'HC Demo Gizli Alt', $h );
	$k    = $term( 'HC Demo Açık' );
	$p_h  = $mk( 'HC Demo Ürün Gizli', array( $h ) );
	$p_hc = $mk( 'HC Demo Ürün Alt', array( $hc ) );
	$p_k  = $mk( 'HC Demo Ürün Açık', array( $k ) );
	$p_hk = $mk( 'HC Demo Ürün Karma', array( $h, $k ) );
	$cat  = get_term( $h, 'product_cat' );

	update_option( 'eru_hc_settings', array() );
	list( $r ) = $main( array( 'post_type' => 'product', 'orderby' => 'date' ) );
	$check( 'default (none hidden): everything listed', in_array( $p_h, $r, true ) && in_array( $p_k, $r, true ) );
	$all = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
	$check( 'default: terms untouched', in_array( $h, $all, true ) );

	update_option( 'eru_hc_settings', array( 'ids' => array( $h ), 'block' => 'no' ) );
	list( $r ) = $main( array( 'post_type' => 'product', 'orderby' => 'date' ) );
	$check( 'shop loop excludes product in hidden category', ! in_array( $p_h, $r, true ) );
	$check( 'shop loop excludes product in hidden child category', ! in_array( $p_hc, $r, true ) );
	$check( 'shop loop excludes product also in a visible category', ! in_array( $p_hk, $r, true ) );
	$check( 'shop loop keeps unrelated product', in_array( $p_k, $r, true ) );

	list( $r ) = $main( array( 's' => 'HC Demo', 'post_type' => 'product' ) );
	$check( 'search excludes hidden products', ! in_array( $p_h, $r, true ) && in_array( $p_k, $r, true ) );

	$all = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
	$check( 'get_terms (widgets/lists) excludes hidden category and its child', ! in_array( $h, $all, true ) && ! in_array( $hc, $all, true ) && in_array( $k, $all, true ) );
	$by_slug = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'slug' => $cat->slug, 'fields' => 'ids' ) );
	$check( 'get_terms by slug still resolves (lookups unaffected)', in_array( $h, $by_slug, true ) );

	// Related products, upsells and cross-sells must not leak hidden-category products.
	delete_transient( 'wc_related_' . $p_k );
	$rel = array_map( 'intval', wc_get_related_products( $p_k, 20 ) );
	$check( 'related products exclude product in a hidden category', ! in_array( $p_hk, $rel, true ) );
	$p2 = wc_get_product( $p_k );
	$p2->set_upsell_ids( array( $p_h, $p_hc ) );
	$p2->set_cross_sell_ids( array( $p_hk, $p_h ) );
	$p2->save();
	$p2 = wc_get_product( $p_k );
	$check( 'upsell ids exclude hidden-category products', array() === array_values( $p2->get_upsell_ids() ) );
	$check( 'cross-sell ids exclude hidden-category products', array() === array_values( $p2->get_cross_sell_ids() ) );
	$check( 'edit context keeps stored upsells intact', 2 === count( $p2->get_upsell_ids( 'edit' ) ) );

	list( $r, $q ) = $main( array( 'product_cat' => $cat->slug, 'post_type' => 'product' ) );
	$check( 'direct archive of hidden category still lists its products (404 option off)', in_array( $p_h, $r, true ) );
	Eru_HC::maybe_404( $q );
	$check( 'no 404 when option off', ! $q->is_404() );

	update_option( 'eru_hc_settings', array( 'ids' => array( $h ), 'block' => 'yes' ) );
	list( $r, $q ) = $main( array( 'product_cat' => $cat->slug, 'post_type' => 'product' ) );
	Eru_HC::maybe_404( $q );
	$check( '404 for hidden category archive when option on', $q->is_404() );
	$check( 'product only in hidden categories is blocked', Eru_HC::product_is_blocked( $p_h ) && Eru_HC::product_is_blocked( $p_hc ) );
	$check( 'product with a visible category is reachable', ! Eru_HC::product_is_blocked( $p_hk ) && ! Eru_HC::product_is_blocked( $p_k ) );
} finally {
	if ( null === $saved ) {
		delete_option( 'eru_hc_settings' );
	} else {
		update_option( 'eru_hc_settings', $saved );
	}
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( array_reverse( $terms ) as $t ) {
		wp_delete_term( $t, 'product_cat' );
	}
}

exit( $fail ? 1 : 0 );
