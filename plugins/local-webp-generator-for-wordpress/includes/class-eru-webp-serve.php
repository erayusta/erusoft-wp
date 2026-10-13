<?php
/** Serving: wrap images in <picture> (default) or swap URLs, only when the .webp sibling exists. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WEBP_Serve {

	public static function init(): void {
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_attachment_image' ), 20 );
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_attachment_image' ), 20 );
	}

	/** URL -> absolute upload path, or '' when the URL is not inside uploads. */
	private static function path_for_url( string $url ): string {
		$up   = wp_get_upload_dir();
		$base = set_url_scheme( $up['baseurl'] );
		$url  = set_url_scheme( strtok( $url, '?#' ) );
		if ( 0 !== strpos( $url, $base . '/' ) ) {
			return '';
		}
		$rel = rawurldecode( substr( $url, strlen( $base ) ) );
		if ( false !== strpos( $rel, '..' ) ) {
			return '';
		}
		return $up['basedir'] . $rel;
	}

	private static function has_webp( string $url ): bool {
		if ( ! preg_match( '#\.(jpe?g|png)$#i', (string) strtok( $url, '?#' ) ) ) {
			return false;
		}
		$path = self::path_for_url( $url );
		return '' !== $path && file_exists( $path . '.webp' );
	}

	/** Append .webp to every URL of a srcset that has a sibling; keep width descriptors. */
	private static function webp_srcset( string $srcset ): string {
		$parts = array();
		foreach ( explode( ',', $srcset ) as $cand ) {
			$cand = trim( $cand );
			if ( '' === $cand ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $cand, 2 );
			$url  = $bits[0];
			if ( ! self::has_webp( $url ) ) {
				return '';
			}
			$parts[] = $url . '.webp' . ( isset( $bits[1] ) ? ' ' . $bits[1] : '' );
		}
		return implode( ', ', $parts );
	}

	/** @param mixed $html */
	public static function filter_attachment_image( $html ) {
		$mode = Eru_WEBP_Settings::get()['serve'];
		if ( 'off' === $mode || ! is_string( $html ) || 0 !== strpos( ltrim( $html ), '<img' ) || false !== strpos( $html, 'data-eru-webp' ) || is_admin() && ! wp_doing_ajax() && ! apply_filters( 'eru_webp_serve_in_admin', false ) ) {
			return $html;
		}
		if ( ! preg_match( '/\ssrc="([^"]+)"/', $html, $m ) || ! self::has_webp( html_entity_decode( $m[1] ) ) ) {
			return $html;
		}
		$src = html_entity_decode( $m[1] );
		$set = '';
		if ( preg_match( '/\ssrcset="([^"]+)"/', $html, $ms ) ) {
			$set = self::webp_srcset( html_entity_decode( $ms[1] ) );
		}
		$sizes = preg_match( '/\ssizes="([^"]*)"/', $html, $mz ) ? $mz[1] : '';

		if ( 'src' === $mode ) {
			$html = str_replace( 'src="' . $m[1] . '"', 'src="' . esc_url( $src . '.webp' ) . '"', $html );
			if ( '' !== $set && isset( $ms[1] ) ) {
				$html = str_replace( 'srcset="' . $ms[1] . '"', 'srcset="' . esc_attr( $set ) . '"', $html );
			}
			return $html;
		}
		$source = '<source type="image/webp" srcset="' . esc_attr( '' !== $set ? $set : $src . '.webp' ) . '"' . ( '' !== $set && '' !== $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
		// The marker keeps wp_content_img_tag (galleries, content images) from wrapping the same <img> twice.
		$html = preg_replace( '/^(\s*<img)\b/', '$1 data-eru-webp="1"', $html, 1 );
		return '<picture class="eru-webp">' . $source . $html . '</picture>';
	}
}
