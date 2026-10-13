<?php
/**
 * Campaign Pages — [eru_campaigns] lists only active campaigns.
 *   wp eval-file tests/php/cp-campaigns.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_CP_Campaign' ) ) {
	echo "FAIL: class Eru_CP_Campaign missing (plugin not active?)\n";
	exit( 1 );
}

$ids = array();
$mk  = function ( $title, $start, $end, $extra = array() ) use ( &$ids ) {
	$id = wp_insert_post( array( 'post_type' => Eru_CP_Campaign::TYPE, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => 'Demo içerik.' ) );
	update_post_meta( $id, Eru_CP_Campaign::START, $start );
	update_post_meta( $id, Eru_CP_Campaign::END, $end );
	foreach ( $extra as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $ids[] = $id;
};
$day = fn( $n ) => wp_date( 'Y-m-d', time() + $n * DAY_IN_SECONDS );

try {
	$mk( 'CP Demo Biten', $day( -10 ), $day( -1 ) );
	$mk( 'CP Demo Aktif', $day( -2 ), $day( 1 ) );
	$mk( 'CP Demo Süresiz', '', '' );
	$mk( 'CP Demo Yaklaşan', $day( 3 ), $day( 9 ) );
	$mk( 'CP Demo Gizli', $day( -2 ), $day( 5 ), array( Eru_CP_Campaign::OFF => '1' ) );
	$mk( 'CP Demo Bugün Biter', $day( -2 ), $day( 0 ) );

	$out = do_shortcode( '[eru_campaigns]' );
	$check( 'ending tomorrow is listed', false !== strpos( $out, 'CP Demo Aktif' ) );
	$check( 'ended yesterday is not listed', false === strpos( $out, 'CP Demo Biten' ) );
	$check( 'no dates = always listed', false !== strpos( $out, 'CP Demo Süresiz' ) );
	$check( 'ending today is still listed (end date inclusive)', false !== strpos( $out, 'CP Demo Bugün Biter' ) );
	$check( 'upcoming is not listed', false === strpos( $out, 'CP Demo Yaklaşan' ) );
	$check( 'manually hidden is not listed', false === strpos( $out, 'CP Demo Gizli' ) );
	$check( 'status=ended lists only the ended one', false !== strpos( do_shortcode( '[eru_campaigns status="ended"]' ), 'CP Demo Biten' ) && false === strpos( do_shortcode( '[eru_campaigns status="ended"]' ), 'CP Demo Aktif' ) );
	$check( 'limit="1" lists one card', 1 === substr_count( do_shortcode( '[eru_campaigns limit="1"]' ), '<article' ) );

	// Ended and upcoming campaign pages get noindex; active ones are indexable.
	$robots = function ( $id ) {
		$saved = $GLOBALS['wp_query'];
		$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $id, 'post_type' => Eru_CP_Campaign::TYPE ) );
		$r = apply_filters( 'wp_robots', array() );
		$GLOBALS['wp_query'] = $saved;
		return $r;
	};
	$check( 'ended campaign page: noindex', ! empty( $robots( $ids[0] )['noindex'] ) );
	$check( 'upcoming campaign page: noindex', ! empty( $robots( $ids[3] )['noindex'] ) );
	$check( 'active campaign page: indexable', empty( $robots( $ids[1] )['noindex'] ) );

	// Meta sanitising and CTA link.
	$check( 'invalid dates are rejected', '' === Eru_CP_Campaign::clean_date( '2026-13-40' ) && '' === Eru_CP_Campaign::clean_date( 'yarın' ) && '2026-10-12' === Eru_CP_Campaign::clean_date( '2026-10-12' ) );
	$c = $mk( 'CP Demo Bağlantı', '', '', array( Eru_CP_Campaign::LINK => 'url', Eru_CP_Campaign::REF => 'https://example.com/demo' ) );
	$check( 'custom URL CTA resolves', 'https://example.com/demo' === Eru_CP_Campaign::cta_url( $c ) );

	// Archive URL follows the settings slug and the rewrite flush happens only when the slug changes.
	$old = get_option( Eru_CP_Settings::OPTION, null );
	$check( 'default archive slug is kampanyalar', 'kampanyalar' === Eru_CP_Settings::get()['archive_slug'] && str_ends_with( untrailingslashit( (string) get_post_type_archive_link( Eru_CP_Campaign::TYPE ) ), '/kampanyalar' ) );
	delete_option( 'eru_cp_flush' );
	Eru_CP_Settings::save( Eru_CP_Settings::sanitize( array_merge( Eru_CP_Settings::get(), array( 'archive_slug' => 'kampanyalar' ) ) ) );
	$check( 'saving the same slug does not schedule a flush', false === get_option( 'eru_cp_flush', false ) );
	Eru_CP_Settings::save( Eru_CP_Settings::sanitize( array_merge( Eru_CP_Settings::get(), array( 'archive_slug' => 'firsatlar' ) ) ) );
	$check( 'changing the slug schedules one flush', '1' === get_option( 'eru_cp_flush' ) );
	delete_option( 'eru_cp_flush' );
	if ( null === $old ) {
		delete_option( Eru_CP_Settings::OPTION );
	} else {
		update_option( Eru_CP_Settings::OPTION, $old, false );
	}
} finally {
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
}

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
