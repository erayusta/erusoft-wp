<?php
/**
 * Heading Pagination — long posts split by H2; short posts untouched.
 *   wp eval-file tests/php/hp-pagination.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_HP_Splitter' ) ) {
	echo "FAIL: class Eru_HP_Splitter missing (plugin not active?)\n";
	exit( 1 );
}

$ids   = array();
$saved = get_option( 'eru_hp_settings', false );
$filler = str_repeat( 'Bu bir deneme cümlesidir ve yalnızca test içindir. ', 30 );
$render = function ( $id, $page ) {
	global $wp_query;
	$wp_query = new WP_Query( array( 'p' => $id ) );
	$wp_query->set( 'page', $page );
	$wp_query->the_post();
	$html = apply_filters( 'the_content', get_the_content() );
	wp_reset_postdata();
	return $html;
};
try {
	update_option( 'eru_hp_settings', array( 'post_types' => array( 'post' ), 'level' => 2, 'min_sections' => 3, 'min_words' => 200 ), false );
	$body = '<p>Giriş paragrafı.</p>';
	foreach ( array( 'Birinci', 'İkinci', 'Üçüncü', 'Dördüncü' ) as $name ) {
		$body .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Bölüm $name</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>$name $filler</p>\n<!-- /wp:paragraph -->\n";
	}
	$long  = wp_insert_post( array( 'post_title' => 'HP Demo Uzun', 'post_status' => 'publish', 'post_content' => $body ) );
	$ids[] = $long;
	$short = wp_insert_post( array( 'post_title' => 'HP Demo Kısa', 'post_status' => 'publish', 'post_content' => '<p>Giriş.</p><h2>Tek başlık</h2><p>' . $filler . '</p>' ) );
	$ids[] = $short;

	$p2 = $render( $long, 2 );
	$check( 'page 2 has section 2 heading', false !== strpos( $p2, 'Bölüm İkinci</h2>' ) );
	$check( 'page 2 lacks section 1 and 3 body', false === strpos( $p2, 'Birinci Bu bir' ) && false === strpos( $p2, 'Üçüncü Bu bir' ) );
	$check( 'page 2 has TOC', false !== strpos( $p2, 'eru-hp-toc' ) && false !== strpos( $p2, 'Bölüm Dördüncü' ) );
	$check( 'page 2 has prev/next', false !== strpos( $p2, 'eru-hp-nav__prev' ) && false !== strpos( $p2, 'eru-hp-nav__next' ) );
	$p1 = $render( $long, 1 );
	$check( 'page 1 keeps intro + section 1', false !== strpos( $p1, 'Giriş paragrafı' ) && false !== strpos( $p1, 'Bölüm Birinci</h2>' ) && false === strpos( $p1, 'Bölüm İkinci</h2>' ) );
	$p4 = $render( $long, 4 );
	$check( 'last page has no next link', false === strpos( $p4, 'eru-hp-nav__next' ) );
	$s = $render( $short, 1 );
	$check( 'post with 1 H2 unchanged', false === strpos( $s, 'eru-hp-toc' ) && false !== strpos( $s, 'Tek başlık' ) );
	$check( 'splitter: 4 sections', 4 === count( Eru_HP_Splitter::split( $body, 2, 3, 200 ) ) );
	// Fix round 1: HTTP behaviour of section URLs.
	$get = function ( $url ) {
		$r = wp_remote_get( $url, array( 'redirection' => 0, 'timeout' => 20, 'sslverify' => false ) );
		return is_wp_error( $r ) ? array( 0, '' ) : array( (int) wp_remote_retrieve_response_code( $r ), wp_remote_retrieve_body( $r ) );
	};
	$link = get_permalink( $long );
	list( $c1 ) = $get( $link );
	list( $c2, $b2 ) = $get( trailingslashit( $link ) . '2/' );
	list( $c9 ) = $get( trailingslashit( $link ) . '9/' );
	$check( '4-section post: page 1 is 200', 200 === $c1 );
	$check( '4-section post: /2/ is 200', 200 === $c2 );
	$check( '4-section post: /9/ is 404', 404 === $c9 );
	$check( '/2/ canonical is its own URL', (bool) preg_match( '#<link rel="canonical" href="' . preg_quote( trailingslashit( $link ), '#' ) . '2/"#', $b2 ) );
	list( $cs ) = $get( trailingslashit( get_permalink( $short ) ) . '2/' );
	$check( '1-H2 post: /2/ is not served as a page (core behaviour kept)', 200 !== $cs );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	false === $saved ? delete_option( 'eru_hp_settings' ) : update_option( 'eru_hp_settings', $saved, false );
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
