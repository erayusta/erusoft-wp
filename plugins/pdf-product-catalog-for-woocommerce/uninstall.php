<?php
/**
 * Uninstall: delete the option and the cached PDF files.
 *
 * @package pdf-product-catalog-for-woocommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'eru_ppc_settings' );

$uploads = wp_upload_dir( null, false );
$dir     = trailingslashit( $uploads['basedir'] ) . 'eru-ppc';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
}
