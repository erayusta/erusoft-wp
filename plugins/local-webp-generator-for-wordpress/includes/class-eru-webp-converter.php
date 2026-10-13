<?php
/** Creates and removes <file>.webp siblings next to uploads. Originals are never modified. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WEBP_Converter {

	public static function init(): void {
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_metadata' ), 999, 2 );
		add_filter( 'wp_delete_file', array( __CLASS__, 'on_delete_file' ) );
	}

	/** 'imagick', 'gd' or '' when neither can write WebP. */
	public static function engine(): string {
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				if ( in_array( 'WEBP', Imagick::queryFormats( 'WEBP' ), true ) ) {
					return 'imagick';
				}
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to GD.
			}
		}
		if ( function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromjpeg' ) ) {
			return 'gd';
		}
		return '';
	}

	/**
	 * Absolute paths of every existing JPEG/PNG file (original + sizes) described by attachment metadata.
	 * Paths are resolved with realpath() and must stay inside the uploads directory.
	 *
	 * @return string[]
	 */
	public static function files_for_meta( $meta, string $basedir ): array {
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return array();
		}
		$ok       = array( 'jpg', 'jpeg', 'png' );
		$root     = realpath( $basedir );
		if ( false === $root ) {
			return array();
		}
		$relative = ltrim( (string) $meta['file'], '/' );
		$sub      = dirname( $relative );
		$sub      = '.' === $sub ? '' : $sub . '/';
		$cands    = array();
		if ( in_array( strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ), $ok, true ) ) {
			$cands[] = $relative;
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) && in_array( strtolower( pathinfo( (string) $size['file'], PATHINFO_EXTENSION ) ), $ok, true ) ) {
				$cands[] = $sub . $size['file'];
			}
		}
		$paths = array();
		foreach ( $cands as $rel ) {
			$real = realpath( rtrim( $basedir, '/' ) . '/' . $rel );
			if ( false !== $real && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
				$paths[] = $real;
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/** @param mixed $meta */
	public static function on_metadata( $meta, $attachment_id = 0 ) {
		if ( '' === self::engine() ) {
			return $meta;
		}
		$basedir = wp_get_upload_dir()['basedir'];
		foreach ( self::files_for_meta( $meta, $basedir ) as $path ) {
			self::convert( $path );
		}
		return $meta;
	}

	/**
	 * Create <path>.webp. Returns 'created', 'exists', 'skipped' (too small / unsupported / larger than the
	 * original) or 'error'.
	 */
	public static function convert( string $path, bool $force = false ): string {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) || ! is_readable( $path ) ) {
			return 'skipped';
		}
		$target = $path . '.webp';
		if ( file_exists( $target ) && ! $force ) {
			return 'exists';
		}
		$s = Eru_WEBP_Settings::get();
		if ( (int) $s['min_bytes'] > 0 && filesize( $path ) < (int) $s['min_bytes'] ) {
			return 'skipped';
		}
		$engine = self::engine();
		if ( '' === $engine ) {
			return 'error';
		}
		// Decoding allocates width x height x 4 bytes: look at the header first.
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
			return 'skipped';
		}
		$limit_mp = (float) apply_filters( 'eru_webp_max_megapixels', 40.0, $path );
		if ( $limit_mp > 0 && ( $info[0] * $info[1] ) > $limit_mp * 1000000 ) {
			return 'skipped';
		}
		wp_raise_memory_limit( 'image' );
		$tmp = $target . '.tmp';
		try {
			$result = 'imagick' === $engine ? self::write_imagick( $path, $tmp, (int) $s['quality'] ) : self::write_gd( $path, $tmp, $ext, (int) $s['quality'] );
		} catch ( Throwable $e ) {
			error_log( 'local-webp-generator: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$result = false;
		}
		if ( ! $result || ! file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
			return 'error';
		}
		// A WebP that is not smaller only makes pages heavier.
		if ( filesize( $tmp ) >= filesize( $path ) ) {
			wp_delete_file( $tmp );
			return 'skipped';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap inside uploads.
		return rename( $tmp, $target ) ? 'created' : 'error';
	}

	private static function write_imagick( string $src, string $dst, int $quality ): bool {
		try {
			$im = new Imagick( $src );
			$im->setImageFormat( 'webp' );
			$im->setImageCompressionQuality( $quality );
			$im->stripImage();
			$ok = $im->writeImage( 'webp:' . $dst );
			$im->clear();
			return (bool) $ok;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	private static function write_gd( string $src, string $dst, string $ext, int $quality ): bool {
		$img = 'png' === $ext ? @imagecreatefrompng( $src ) : @imagecreatefromjpeg( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $img ) {
			return false;
		}
		if ( 'png' === $ext ) {
			imagepalettetotruecolor( $img );
			imagealphablending( $img, false );
			imagesavealpha( $img, true );
		}
		$ok = imagewebp( $img, $dst, $quality );
		imagedestroy( $img );
		return (bool) $ok;
	}

	/** Remove the .webp sibling whenever WordPress deletes an image file. @param mixed $file */
	public static function on_delete_file( $file ) {
		if ( is_string( $file ) && '' !== $file && '.webp' !== substr( $file, -5 ) && file_exists( $file . '.webp' ) ) {
			wp_delete_file( $file . '.webp' );
		}
		return $file;
	}
}
