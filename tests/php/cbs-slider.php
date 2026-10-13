<?php
/**
 * Category Banner Slider — shortcode renders the banners of a category in order.
 *   wp eval-file tests/php/cbs-slider.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_CBS' ) ) {
	echo "FAIL: class Eru_CBS missing (plugin not active?)\n";
	exit( 1 );
}

$att  = array();
$term = wp_insert_term( 'CBS Demo Kategori', 'product_cat', array( 'slug' => 'cbs-demo-kategori' ) );
$tid  = (int) $term['term_id'];
foreach ( array( 'cbs-demo-a.jpg', 'cbs-demo-b.jpg' ) as $f ) {
	$id = wp_insert_attachment( array( 'post_title' => $f, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ), $f );
	update_attached_file( $id, $f );
	$att[] = $id;
}
$url = fn( $id ) => wp_get_attachment_image_url( $id, 'full' );

try {
	update_term_meta(
		$tid,
		Eru_CBS::META_SLIDES,
		Eru_CBS::sanitize_slides(
			array(
				array( 'image_desktop' => $att[0], 'link_url' => 'https://example.com/a' ),
				array( 'image_desktop' => 0, 'link_url' => 'https://example.com/skip' ),
				array( 'image_desktop' => $att[1] ),
			)
		)
	);
	$html = do_shortcode( '[eru_category_slider term="cbs-demo-kategori"]' );
	$p1   = strpos( $html, $url( $att[0] ) );
	$p2   = strpos( $html, $url( $att[1] ) );
	$check( 'both banner URLs are in the output', false !== $p1 && false !== $p2 );
	$check( 'banners keep their order', false !== $p1 && false !== $p2 && $p1 < $p2 );
	$check( 'slide without a desktop image is dropped', false === strpos( $html, 'example.com/skip' ) );
	$check( 'link is rendered for the first slide', false !== strpos( $html, 'href="https://example.com/a"' ) );
	$check( 'first slide image is eager, later ones lazy', 1 === preg_match( '/<img[^>]*eru-cbs__img[^>]*loading="eager"[^>]*>.*<img[^>]*loading="lazy"/s', $html ) );
	$second = do_shortcode( '[eru_category_slider term="cbs-demo-kategori"]' );
	$check( 'a second slider on the same page has no eager / high-priority image', '' !== $second && false === strpos( $second, 'loading="eager"' ) && false === strpos( $second, 'fetchpriority' ) );
	$check( 'images use sizes for responsive loading', false !== strpos( $html, 'sizes="100vw"' ) );
	$check( 'unknown term renders nothing', '' === do_shortcode( '[eru_category_slider term="yok-boyle-bir"]' ) );
	$check( 'default position is inert (no archive hook)', '' === Eru_CBS::defaults()['position'] );
} finally {
	foreach ( $att as $id ) {
		wp_delete_attachment( $id, true );
	}
	wp_delete_term( $tid, 'product_cat' );
}
exit( $fail ? 1 : 0 );
