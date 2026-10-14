<?php
/**
 * Site Manager — llms.txt, LocalBusiness JSON-LD and robots.txt AI rules.
 *   wp eval-file tests/php/sm-modules.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_SM_Llms' ) || ! class_exists( 'Eru_SM_Schema' ) || ! class_exists( 'Eru_SM_Robots' ) ) {
	echo "FAIL: Eru_SM_* classes missing (plugin not active?)\n";
	exit( 1 );
}

$ids   = array();
$saved = get_option( 'eru_sm_settings', false );
$title = get_bloginfo( 'name' );
try {
	$mk = function ( $t, $status = 'publish', $extra = array() ) use ( &$ids ) {
		return $ids[] = wp_insert_post( array_merge( array( 'post_type' => 'page', 'post_status' => $status, 'post_title' => $t, 'post_content' => 'Demo içerik ' . $t, 'post_excerpt' => 'Kısa özet ' . $t ), $extra ) );
	};
	$p1 = $mk( 'Demo Hakkımızda' );
	$mk( 'Demo Gizli Sayfa', 'private' );
	$mk( 'Demo Şifreli Sayfa', 'publish', array( 'post_password' => 'x' ) );
	$ex = $mk( 'Demo Hariç Sayfa' );
	$ni = $mk( 'Demo Noindex Sayfa' );
	update_post_meta( $ni, '_yoast_wpseo_meta-robots-noindex', '1' );

	// --- modules off: inert ---
	update_option( 'eru_sm_settings', array(), false );
	Eru_SM_Llms::flush_cache();
	$check( 'llms off: renderer returns nothing', '' === Eru_SM_Llms::render() );
	$check( 'robots off: filter returns input unchanged', "User-agent: *\nDisallow: /wp-admin/\n" === Eru_SM_Robots::filter( "User-agent: *\nDisallow: /wp-admin/\n", '1' ) );
	ob_start();
	Eru_SM_Schema::output();
	$check( 'schema off: no JSON-LD', '' === trim( ob_get_clean() ) );

	// --- llms on ---
	update_option( 'eru_sm_settings', array(
		'llms' => array( 'enabled' => 1, 'summary' => 'Demo özet cümlesi.', 'post_types' => array( 'page' ), 'max' => 50, 'exclude' => (string) $ex, 'links' => "Demo Bağlantı | https://example.com/demo | Açıklama\nGeçersiz satır" ),
	), false );
	Eru_SM_Llms::flush_cache();
	$txt = Eru_SM_Llms::render();
	$check( 'llms starts with "# " + site title', 0 === strpos( $txt, '# ' . $title . "\n" ) );
	$check( 'llms has > summary', false !== strpos( $txt, "\n> Demo özet cümlesi.\n" ) );
	$check( 'llms lists demo page with permalink and excerpt', false !== strpos( $txt, '- [Demo Hakkımızda](' . get_permalink( $p1 ) . '): Kısa özet Demo Hakkımızda' ) );
	$check( 'llms excludes private page', false === strpos( $txt, 'Demo Gizli Sayfa' ) );
	$check( 'llms excludes password-protected page', false === strpos( $txt, 'Demo Şifreli Sayfa' ) );
	$check( 'llms excludes listed ID', false === strpos( $txt, 'Demo Hariç Sayfa' ) );
	$check( 'llms excludes noindex page', false === strpos( $txt, 'Demo Noindex Sayfa' ) );
	$check( 'llms manual links section', false !== strpos( $txt, "## Bağlantılar\n- [Demo Bağlantı](https://example.com/demo): Açıklama" ) && false === strpos( $txt, 'Geçersiz satır' ) );
	$check( 'llms-full off by default', '' === Eru_SM_Llms::render( true ) );

	$s = get_option( 'eru_sm_settings' );
	$s['llms']['max'] = 1;
	update_option( 'eru_sm_settings', $s, false );
	$txt1 = Eru_SM_Llms::render();
	$check( 'llms respects max items', 1 === preg_match_all( '/^- \[/m', preg_replace( '/## Bağlantılar.*/s', '', $txt1 ) ) );

	add_filter( 'eru_sm_llms_lines', $f = function ( $lines ) { $lines[] = 'Demo filtre satırı'; return $lines; } );
	Eru_SM_Llms::flush_cache();
	$check( 'filter eru_sm_llms_lines applies', false !== strpos( Eru_SM_Llms::render(), 'Demo filtre satırı' ) );
	remove_filter( 'eru_sm_llms_lines', $f );

	$s['llms']['max']  = 50;
	$s['llms']['full'] = 1;
	update_option( 'eru_sm_settings', $s, false );
	Eru_SM_Llms::flush_cache();
	$full = Eru_SM_Llms::render( true );
	$check( 'llms-full on: plain text content, capped', false !== strpos( $full, 'Demo içerik Demo Hakkımızda' ) && strlen( $full ) <= 1048576 );

	// cache flushed on save_post
	Eru_SM_Llms::render();
	$late = $mk( 'Demo Sonradan Eklenen' );
	$check( 'cache flushed on save_post', false !== strpos( Eru_SM_Llms::render(), 'Demo Sonradan Eklenen' ) );

	// --- fix round 1 ---
	$san = Eru_SM_Settings::sanitize( array( '_tab' => 'llms', 'enabled' => '1', 'summary' => 'Demo \\ ters bölü', 'post_types' => array( 'page', 'yok-boyle-tur' ), 'max' => '5' ) );
	$check( 'sanitize keeps backslash (no double unslash)', 'Demo \\ ters bölü' === $san['llms']['summary'] );
	$check( 'sanitize drops invalid post type', array( 'page' ) === $san['llms']['post_types'] );
	$san = Eru_SM_Settings::sanitize( array( '_tab' => 'schema', 'hours' => array( 'mon' => array( 'open' => '25:99', 'close' => 'abc' ), 'tue' => 'x' ) ) );
	$check( 'sanitize clears malformed hours row', '' === $san['schema']['hours']['mon']['open'] && '' === $san['schema']['hours']['mon']['close'] && '' === $san['schema']['hours']['tue']['open'] );
	update_option( 'eru_sm_settings', array( 'llms' => array( 'enabled' => 1, 'post_types' => array( 'page' ), 'links' => 'Demo | https://example.com/a_(b)_c | x' ), 'robots' => array( 'enabled' => 1, 'sitemap' => 1 ) ), false );
	Eru_SM_Llms::flush_cache();
	$check( 'llms encodes parentheses in link URLs', false !== strpos( Eru_SM_Llms::render(), '(https://example.com/a_%28b%29_c)' ) );
	$ni2 = $mk( 'Demo Sonradan Noindex' );
	$check( 'noindex page listed before flag', false !== strpos( Eru_SM_Llms::render(), 'Demo Sonradan Noindex' ) );
	update_post_meta( $ni2, '_yoast_wpseo_meta-robots-noindex', '1' );
	$check( 'cache flushed on noindex meta change', false === strpos( Eru_SM_Llms::render(), 'Demo Sonradan Noindex' ) );
	$prev = get_option( 'blog_public' );
	update_option( 'blog_public', '0' );
	$check( 'blog_public=0: llms renders nothing', '' === Eru_SM_Llms::render() );
	$check( 'blog_public=0: no Sitemap line', false === strpos( Eru_SM_Robots::filter( "User-agent: *\nDisallow: /\n", '0' ), 'Sitemap:' ) );
	update_option( 'blog_public', $prev );

	// --- llms: products hidden from the catalogue or search are not listed ---
	if ( class_exists( 'WooCommerce' ) ) {
		$mkp = function ( $name, $visibility ) use ( &$ids ) {
			$p = new WC_Product_Simple();
			$p->set_name( $name );
			$p->set_regular_price( '10' );
			$p->set_status( 'publish' );
			$p->set_catalog_visibility( $visibility );
			return $ids[] = $p->save();
		};
		$mkp( 'Demo Görünür Ürün', 'visible' );
		$mkp( 'Demo Katalog Dışı Ürün', 'search' );
		$mkp( 'Demo Arama Dışı Ürün', 'catalog' );
		$mkp( 'Demo Gizli Ürün', 'hidden' );
		update_option( 'eru_sm_settings', array( 'llms' => array( 'enabled' => 1, 'full' => 1, 'post_types' => array( 'product' ), 'max' => 200 ) ), false );
		Eru_SM_Llms::flush_cache();
		foreach ( array( 'llms.txt' => Eru_SM_Llms::render(), 'llms-full.txt' => Eru_SM_Llms::render( true ) ) as $file => $out ) {
			$check( "$file lists a visible product", false !== strpos( $out, 'Demo Görünür Ürün' ) );
			$check( "$file skips exclude-from-catalog / exclude-from-search / hidden products", false === strpos( $out, 'Demo Katalog Dışı Ürün' ) && false === strpos( $out, 'Demo Arama Dışı Ürün' ) && false === strpos( $out, 'Demo Gizli Ürün' ) );
		}
	} else {
		echo "SKIP: WooCommerce inactive, product visibility checks not run\n";
	}

	// --- schema ---
	update_option( 'eru_sm_settings', array(
		'schema' => array( 'enabled' => 1, 'where' => 'all', 'type' => 'Bakery', 'name' => 'Demo Fırın', 'street' => 'Demo Sokak No 1', 'district' => 'Konak', 'city' => 'İzmir', 'postal' => '35250', 'country' => 'TR', 'phone' => '', 'email' => 'ornek@example.com', 'same_as' => "https://example.com/demo\nnot a url", 'lat' => '38.4192', 'lng' => '27.1287', 'hours' => array( 'mon' => array( 'open' => '08:00', 'close' => '18:00' ), 'tue' => array( 'open' => '08:00', 'close' => '' ) ) ),
	), false );
	ob_start();
	Eru_SM_Schema::output();
	$html = ob_get_clean();
	preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
	$d = isset( $m[1] ) ? json_decode( $m[1], true ) : null;
	$check( 'schema JSON decodes', is_array( $d ) );
	$check( 'schema @type is chosen type', 'Bakery' === ( $d['@type'] ?? '' ) );
	$check( 'schema omits empty phone', is_array( $d ) && ! array_key_exists( 'telephone', $d ) );
	$check( 'schema has e-mail, address and geo', 'ornek@example.com' === ( $d['email'] ?? '' ) && 'İzmir' === ( $d['address']['addressRegion'] ?? '' ) && 'Konak' === ( $d['address']['addressLocality'] ?? '' ) && 38.4192 === ( $d['geo']['latitude'] ?? null ) );
	$check( 'schema hours: only complete rows', 1 === count( $d['openingHoursSpecification'] ?? array() ) && 'Monday' === ( $d['openingHoursSpecification'][0]['dayOfWeek'] ?? '' ) );
	$check( 'schema sameAs keeps only valid URLs', array( 'https://example.com/demo' ) === ( $d['sameAs'] ?? null ) );
	add_filter( 'eru_sm_schema_enabled', '__return_false' );
	ob_start();
	Eru_SM_Schema::output();
	$check( 'filter eru_sm_schema_enabled can disable', '' === trim( ob_get_clean() ) );
	remove_filter( 'eru_sm_schema_enabled', '__return_false' );
	// front-only: a singular query is not the front page
	$s = get_option( 'eru_sm_settings' );
	$s['schema']['where'] = 'front';
	update_option( 'eru_sm_settings', $s, false );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query( array( 'p' => $p1, 'post_type' => 'page' ) );
	ob_start();
	Eru_SM_Schema::output();
	$check( 'schema front-only: not printed on inner page', '' === trim( ob_get_clean() ) );
	wp_reset_query();

	// --- robots ---
	update_option( 'eru_sm_settings', array(
		'robots' => array( 'enabled' => 1, 'sitemap' => 0, 'bots' => array( 'GPTBot' => 'block', 'ClaudeBot' => 'allow', 'CCBot' => 'default' ) ),
	), false );
	$r = Eru_SM_Robots::filter( "User-agent: *\nDisallow: /wp-admin/\n", '1' );
	$check( 'robots: blocked bot', false !== strpos( $r, "User-agent: GPTBot\nDisallow: /" ) );
	$check( 'robots: allowed bot', false !== strpos( $r, "User-agent: ClaudeBot\nAllow: /" ) );
	$check( 'robots: default bot untouched', false === strpos( $r, 'CCBot' ) );
	$s2 = get_option( 'eru_sm_settings' );
	$s2['robots']['sitemap'] = 1;
	update_option( 'eru_sm_settings', $s2, false );
	$r2 = Eru_SM_Robots::filter( "User-agent: *\nDisallow: /wp-admin/\n", '1' );
	$check( 'robots: Sitemap line added once when none present', 1 === substr_count( $r2, 'Sitemap:' ) );
	$check( 'robots: existing Sitemap line not duplicated', 1 === substr_count( Eru_SM_Robots::filter( "Sitemap: https://example.com/s.xml\n", '1' ), 'Sitemap:' ) );
	$check( 'robots: original rules kept', 0 === strpos( $r, "User-agent: *\nDisallow: /wp-admin/" ) );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( false === $saved ) {
		delete_option( 'eru_sm_settings' );
	} else {
		update_option( 'eru_sm_settings', $saved, false );
	}
	Eru_SM_Llms::flush_cache();
}

if ( $fail ) {
	echo "$fail check(s) failed\n";
	exit( 1 );
}
echo "All checks passed\n";
