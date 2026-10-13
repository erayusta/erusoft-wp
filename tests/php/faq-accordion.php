<?php
/**
 * FAQ Accordion — shortcode renders <details> items and one FAQPage JSON-LD.
 *   wp eval-file tests/php/faq-accordion.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_FAQ_Front' ) ) {
	echo "FAIL: class Eru_FAQ_Front missing (plugin not active?)\n";
	exit( 1 );
}

$ids   = array();
$saved = get_option( 'eru_faq_settings', false );
try {
	update_option( 'eru_faq_settings', array( 'schema' => 1 ), false );
	$mk = function ( $q, $a, $order, $group ) use ( &$ids ) {
		$id = wp_insert_post( array( 'post_type' => 'eru_faq', 'post_status' => 'publish', 'post_title' => $q, 'post_content' => $a, 'menu_order' => $order ) );
		wp_set_object_terms( $id, $group, 'eru_faq_group' );
		return $ids[] = $id;
	};
	$mk( 'Demo soru ikinci?', 'İkinci <strong>cevap</strong>.<script>alert(1)</script>', 2, 'genel' );
	$mk( 'Demo soru birinci?', 'Birinci cevap.', 1, 'genel' );
	$mk( 'Demo başka grup?', 'Başka cevap.', 1, 'diger' );

	Eru_FAQ_Front::reset();
	$out = do_shortcode( '[eru_faq group="genel"]' );
	$check( 'two <details> in group genel', 2 === substr_count( $out, '<details' ) );
	$check( 'ordered by menu_order', strpos( $out, 'birinci' ) < strpos( $out, 'ikinci' ) );
	$check( 'other group excluded', false === strpos( $out, 'başka grup' ) );
	$check( 'script stripped from answer', false === strpos( $out, '<script>alert' ) );
	preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $m );
	$data = isset( $m[1][0] ) ? json_decode( $m[1][0], true ) : null;
	$check( 'JSON-LD FAQPage present', is_array( $data ) && 'FAQPage' === ( $data['@type'] ?? '' ) );
	$check( 'JSON-LD has 2 Questions', is_array( $data ) && 2 === count( array_filter( $data['mainEntity'] ?? array(), fn( $q ) => 'Question' === ( $q['@type'] ?? '' ) ) ) );
	$out2 = do_shortcode( '[eru_faq group="diger"]' );
	$check( 'JSON-LD printed once per page', false === strpos( $out2, 'application/ld+json' ) && 1 === substr_count( $out2, '<details' ) );
	Eru_FAQ_Front::reset();
	update_option( 'eru_faq_settings', array( 'schema' => 0 ), false );
	$check( 'schema can be switched off', false === strpos( do_shortcode( '[eru_faq group="genel"]' ), 'ld+json' ) );
	$check( 'empty group renders nothing', '' === do_shortcode( '[eru_faq group="yok-boyle-grup"]' ) );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	false === $saved ? delete_option( 'eru_faq_settings' ) : update_option( 'eru_faq_settings', $saved, false );
	Eru_FAQ_Front::reset();
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
