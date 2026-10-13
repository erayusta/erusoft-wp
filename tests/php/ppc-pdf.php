<?php
/**
 * PDF Product Catalog — print-ready catalogue HTML for a category (Turkish glyphs, options),
 * plus the optional PDF-engine hook.
 *   wp eval-file tests/php/ppc-pdf.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_PPC' ) ) {
	echo "FAIL: class Eru_PPC missing (plugin not active?)\n";
	exit( 1 );
}

$ids  = array();
$term = wp_insert_term( 'PPC Demo Kategori', 'product_cat' );
$tid  = (int) $term['term_id'];
$mk   = function ( $title, $sku, $price ) use ( &$ids, $tid ) {
	$p = new WC_Product_Simple();
	$p->set_name( $title );
	$p->set_sku( $sku );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$p->set_category_ids( array( $tid ) );
	$p->save();
	return $ids[] = $p->get_id();
};

try {
	$mk( 'Işık Lamba', 'PPC-DEMO-1', '49.90' );
	$mk( 'Çiğdem Şapka İğne', 'PPC-DEMO-2', '19.90' );
	$html = Eru_PPC::generate( array( $tid ), array( 'footer' => 'Örnek altlık', 'subtitle' => 'Güz Koleksiyonu' ) );
	$check( 'document is a complete HTML page with an A4 @page rule', str_starts_with( $html, '<!doctype html>' ) && false !== strpos( $html, '@page{size:A4 portrait' ) );
	$check( 'declares UTF-8', false !== strpos( $html, '<meta charset="utf-8">' ) );
	$check( 'contains "Işık Lamba" and "Çiğdem Şapka İğne" unmangled', false !== strpos( $html, 'Işık Lamba' ) && false !== strpos( $html, 'Çiğdem Şapka İğne' ) );
	$check( 'contains SKU, price and category heading', false !== strpos( $html, 'PPC-DEMO-1' ) && false !== strpos( $html, '49.90' ) && false !== strpos( $html, 'PPC Demo Kategori' ) );
	$check( 'cover subtitle and footer are present', false !== strpos( $html, 'Güz Koleksiyonu' ) && false !== strpos( $html, 'Örnek altlık' ) );
	$check( 'has a print button that is hidden when printing', false !== strpos( $html, 'window.print()' ) && false !== strpos( $html, '@media print{' ) );

	$bare = Eru_PPC::generate( array( $tid ), array( 'show_sku' => 0, 'show_price' => 0 ) );
	$check( 'options: SKU and price can be switched off', false === strpos( $bare, 'PPC-DEMO-1' ) && false === strpos( $bare, '49.90' ) );
	$check( 'unknown category gives the empty notice, not an error', false !== strpos( Eru_PPC::generate( array( 999999 ) ), 'Seçilen kategorilerde ürün bulunamadı' ) );
	$check( 'HTML in names is escaped', false === strpos( Eru_PPC::build_html( array( array( 'name' => '<b>x</b>', 'products' => array() ) ), Eru_PPC::get_settings() ), '<b>x</b>' ) );

	// The public (cached) catalogue is built as a guest: a logged-in viewer's own prices never reach the cache.
	$uid  = wp_insert_user( array( 'user_login' => 'demo_ppc_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'user_email' => 'ornek+ppc' . wp_rand( 1000, 9999 ) . '@example.com', 'role' => 'customer' ) );
	$mine = fn( $price ) => is_user_logged_in() ? '12.34' : $price;
	add_filter( 'woocommerce_product_get_price', $mine, 99 );
	wp_set_current_user( $uid );
	$pub = method_exists( 'Eru_PPC', 'generate_public' ) ? Eru_PPC::generate_public( array( $tid ) ) : Eru_PPC::generate( array( $tid ) );
	$check( 'public catalogue uses guest prices, not the viewer\'s own', false !== strpos( $pub, '49.90' ) && false === strpos( $pub, '12.34' ) );
	$check( 'current user restored after building', $uid === get_current_user_id() );
	wp_set_current_user( 0 );
	remove_filter( 'woocommerce_product_get_price', $mine, 99 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );

	$check( 'no PDF engine by default', null === Eru_PPC::render_pdf( $html ) );
	add_filter( 'eru_ppc_render_pdf', fn( $pdf, $h ) => '%PDF-1.4 fake ' . strlen( $h ), 10, 2 );
	$check( 'engine filter result is used when it returns a PDF', str_starts_with( (string) Eru_PPC::render_pdf( $html ), '%PDF' ) );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	wp_delete_term( $tid, 'product_cat' );
}
exit( $fail ? 1 : 0 );
