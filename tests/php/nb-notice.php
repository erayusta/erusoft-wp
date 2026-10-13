<?php
/**
 * Notice Boxes — shortcode classes and block registration.
 *   wp eval-file tests/php/nb-notice.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_NB_Render' ) ) {
	echo "FAIL: class Eru_NB_Render missing (plugin not active?)\n";
	exit( 1 );
}

$out = do_shortcode( '[eru_notice type="warning" title="Dikkat"]Metin[/eru_notice]' );
$check( 'class eru-nb--warning', false !== strpos( $out, 'eru-nb--warning' ) );
$check( 'title rendered', false !== strpos( $out, 'Dikkat' ) );
$check( 'content rendered', false !== strpos( $out, 'Metin' ) );
$check( 'default preset class', false !== strpos( $out, 'eru-nb--soft' ) );
foreach ( array( 'info', 'warning', 'success', 'error' ) as $t ) {
	foreach ( array( 'soft', 'solid', 'outline' ) as $p ) {
		$o = do_shortcode( "[eru_notice type=\"$t\" style=\"$p\"]x[/eru_notice]" );
		$check( "$t/$p class pair", false !== strpos( $o, "eru-nb--$t" ) && false !== strpos( $o, "eru-nb--$p" ) );
	}
}
$bad = do_shortcode( '[eru_notice type="nonsense" style="zzz"]x[/eru_notice]' );
$check( 'unknown type/style fall back', false !== strpos( $bad, 'eru-nb--info' ) && false !== strpos( $bad, 'eru-nb--soft' ) );
$xss = do_shortcode( '[eru_notice title="<script>alert(1)</script>"]<img src=x onerror=alert(1)>ok[/eru_notice]' );
$check( 'title and content are escaped/sanitised', false === strpos( $xss, '<script>' ) && false === strpos( $xss, 'onerror' ) );
$check( 'block registered', WP_Block_Type_Registry::get_instance()->is_registered( 'eru/notice-box' ) );
$blk = render_block( array( 'blockName' => 'eru/notice-box', 'attrs' => array( 'type' => 'success', 'title' => 'Tamam', 'variant' => 'solid' ), 'innerBlocks' => array(), 'innerHTML' => '<p>Blok metni</p>', 'innerContent' => array( '<p>Blok metni</p>' ) ) );
$check( 'block renders server-side with shared markup', false !== strpos( $blk, 'eru-nb--success' ) && false !== strpos( $blk, 'eru-nb--solid' ) && false !== strpos( $blk, 'Tamam' ) && false !== strpos( $blk, 'Blok metni' ) );
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
