<?php
/**
 * Local WebP Generator — webp sibling on upload, served via filter, removed on delete.
 *   wp eval-file tests/php/webp-generate.php   (plugin active; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_WEBP_Converter' ) ) {
	echo "FAIL: class Eru_WEBP_Converter missing (plugin not active?)\n";
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$saved = get_option( 'eru_webp_settings', false );
$att   = 0;
try {
	update_option( 'eru_webp_settings', array( 'quality' => 80, 'min_bytes' => 1000, 'serve' => 'picture' ), false );
	$check( 'engine available', Eru_WEBP_Converter::engine() !== '' );

	// Locally generated noisy JPEG (fictional).
	$tmp = wp_tempnam( 'eru-webp-demo.jpg' );
	$im  = imagecreatetruecolor( 1400, 900 );
	for ( $i = 0; $i < 400; $i++ ) {
		imagefilledellipse( $im, wp_rand( 0, 1400 ), wp_rand( 0, 900 ), wp_rand( 20, 200 ), wp_rand( 20, 200 ), imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	}
	imagejpeg( $im, $tmp, 90 );
	imagedestroy( $im );
	$sideload = array( 'name' => 'eru-webp-demo.jpg', 'tmp_name' => $tmp );
	$att      = media_handle_sideload( $sideload, 0 );
	$check( 'attachment imported', is_int( $att ) && $att > 0 );

	$file = get_attached_file( $att );
	$check( 'webp sibling of original exists', file_exists( $file . '.webp' ) );
	$meta = wp_get_attachment_metadata( $att );
	$sizes_ok = ! empty( $meta['sizes'] );
	$dir = dirname( $file );
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		$sizes_ok = $sizes_ok && file_exists( $dir . '/' . $s['file'] . '.webp' );
	}
	$check( 'webp sibling for every size', $sizes_ok );
	$check( 'original untouched (still JPEG)', 'image/jpeg' === mime_content_type( $file ) );
	$check( 'webp sibling is real WebP', 'image/webp' === mime_content_type( $file . '.webp' ) );

	$html = wp_get_attachment_image( $att, 'medium' );
	$check( 'picture filter outputs <picture> with webp source', false !== strpos( $html, '<picture' ) && false !== strpos( $html, 'type="image/webp"' ) && false !== strpos( $html, '.webp' ) );
	$check( 'img fallback keeps original url', (bool) preg_match( '#<img[^>]+src="[^"]+\.jpg"#', $html ) );
	update_option( 'eru_webp_settings', array( 'quality' => 80, 'min_bytes' => 1000, 'serve' => 'off' ), false );
	$check( 'serve=off leaves markup alone', false === strpos( wp_get_attachment_image( $att, 'medium' ), '.webp' ) );
	update_option( 'eru_webp_settings', array( 'quality' => 80, 'min_bytes' => 1000, 'serve' => 'src' ), false );
	$h2 = wp_get_attachment_image( $att, 'medium' );
	$check( 'serve=src swaps urls to webp', false === strpos( $h2, '<picture' ) && (bool) preg_match( '#<img[^>]+src="[^"]+\.jpg\.webp"#', $h2 ) );

	// Bulk path: remove siblings, regenerate through the batch API.
	foreach ( glob( $dir . '/eru-webp-demo*.webp' ) as $w ) {
		unlink( $w );
	}
	$res = Eru_WEBP_Bulk::run_batch( array( $att ), false );
	$check( 'bulk batch recreates siblings', file_exists( $file . '.webp' ) && $res['created'] >= 1 );
	$mtime = filemtime( $file . '.webp' );
	$res2  = Eru_WEBP_Bulk::run_batch( array( $att ), false );
	$check( 'bulk batch skips existing', 0 === $res2['created'] && filemtime( $file . '.webp' ) === $mtime );

	// Fix round 1: no double <picture> when markup passes wp_content_img_tag.
	update_option( 'eru_webp_settings', array( 'quality' => 80, 'min_bytes' => 1000, 'serve' => 'picture' ), false );
	$once = wp_get_attachment_image( $att, 'medium' );
	$twice = wp_filter_content_tags( $once, 'the_content' );
	$check( 'already-wrapped image is not wrapped again (one <picture>)', 1 === substr_count( $twice, '<picture' ) && 1 === substr_count( $twice, '</picture>' ) );
	$gallery = wp_filter_content_tags( '<p>' . wp_get_attachment_image( $att, 'thumbnail' ) . '</p><p>' . wp_get_attachment_image( $att, 'medium' ) . '</p>', 'the_content' );
	$check( 'two images in content -> two pictures', 2 === substr_count( $gallery, '<picture' ) );
	$raw = wp_filter_content_tags( '<img class="wp-image-' . $att . '" src="' . wp_get_attachment_image_url( $att, 'medium' ) . '" alt="">', 'the_content' );
	$check( 'plain content image gets exactly one picture', 1 === substr_count( $raw, '<picture' ) );

	// Fix round 1: dimension guard.
	$big = wp_tempnam( 'eru-webp-guard.jpg' );
	$im  = imagecreatetruecolor( 1400, 900 );
	for ( $i = 0; $i < 200; $i++ ) {
		imagefilledellipse( $im, wp_rand( 0, 1400 ), wp_rand( 0, 900 ), 150, 150, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	}
	imagejpeg( $im, $big, 90 );
	imagedestroy( $im );
	rename( $big, $big . '.jpg' );
	$big .= '.jpg';
	$low = function () { return 0.5; };
	add_filter( 'eru_webp_max_megapixels', $low );
	$r = Eru_WEBP_Converter::convert( $big );
	$check( 'image above megapixel limit is skipped, no webp written', 'skipped' === $r && ! file_exists( $big . '.webp' ) );
	remove_filter( 'eru_webp_max_megapixels', $low );
	$r = Eru_WEBP_Converter::convert( $big );
	$check( 'default limit (40 MP) converts it', 'created' === $r && file_exists( $big . '.webp' ) );
	@unlink( $big . '.webp' );
	@unlink( $big );
	$check( 'corrupt image never fatals', in_array( Eru_WEBP_Converter::convert( __FILE__ ), array( 'skipped', 'error' ), true ) );
	$junk = wp_tempnam( 'eru-webp-junk.jpg' );
	rename( $junk, $junk . '.jpg' );
	file_put_contents( $junk . '.jpg', str_repeat( 'x', 20000 ) );
	$check( 'garbage file named .jpg -> skipped/error, no exception', in_array( Eru_WEBP_Converter::convert( $junk . '.jpg' ), array( 'skipped', 'error' ), true ) );
	@unlink( $junk . '.jpg' );

	// Fix round 1: realpath containment.
	$basedir = wp_get_upload_dir()['basedir'];
	$evil    = Eru_WEBP_Converter::files_for_meta( array( 'file' => '../../../../etc/hosts.jpg', 'sizes' => array( 'x' => array( 'file' => '../../outside.jpg' ) ) ), $basedir );
	$check( 'files_for_meta ignores paths outside uploads', array() === $evil );
	$mine = Eru_WEBP_Converter::files_for_meta( $meta, $basedir );
	$check( 'files_for_meta keeps real upload files', count( $mine ) === 1 + count( $meta['sizes'] ) );

	$all = array_merge( array( $file ), array_map( fn( $s ) => $dir . '/' . $s['file'], $meta['sizes'] ) );
	wp_delete_attachment( $att, true );
	$left = array_filter( $all, fn( $p ) => file_exists( $p ) || file_exists( $p . '.webp' ) );
	$check( 'files and webp siblings deleted with attachment', 0 === count( $left ) );
	$att = 0;
} finally {
	if ( $att ) {
		wp_delete_attachment( $att, true );
	}
	false === $saved ? delete_option( 'eru_webp_settings' ) : update_option( 'eru_webp_settings', $saved, false );
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
