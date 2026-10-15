<?php
/**
 * Short request log: the last 100 calls (method, path, HTTP code, duration, error excerpt) in one
 * non-autoloaded option. Headers and request bodies are never stored; text is redacted.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded by design: the option is trimmed on every write.
 */
class Eru_PPT_Log {

	const OPTION = 'eru_ppt_log';
	const MAX    = 100;

	/**
	 * Removes credentials from text that is stored or shown.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( $text ) {
		$text = (string) $text;
		$c    = Eru_PPT_Settings::credentials();
		foreach ( array( $c['api_key'], $c['api_secret'], base64_encode( $c['api_key'] . ':' . $c['api_secret'] ) ) as $s ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			if ( strlen( $s ) >= 3 ) {
				$text = str_replace( $s, '[gizli]', $text );
			}
		}
		$text = (string) preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/', '$1 [gizli]', $text );
		return (string) preg_replace( '/("?(?:password|secret|token|api_key|apikey|authorization)"?\s*(?:=|:)\s*"?)[^&\s",}<]+/i', '$1[gizli]', $text );
	}

	/**
	 * @param array $row method, path, code, ms, error.
	 */
	public static function add( array $row ) {
		$log   = get_option( self::OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			't'      => time(),
			'method' => substr( (string) ( $row['method'] ?? '' ), 0, 8 ),
			'path'   => self::redact( substr( (string) ( $row['path'] ?? '' ), 0, 200 ) ),
			'code'   => (int) ( $row['code'] ?? 0 ),
			'ms'     => (int) ( $row['ms'] ?? 0 ),
			'error'  => self::redact( substr( (string) ( $row['error'] ?? '' ), 0, 500 ) ),
		);
		$log = array_slice( $log, -self::MAX );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $log, '', false );
		} else {
			update_option( self::OPTION, $log, false );
		}
	}

	/** @return array Newest first. */
	public static function rows() {
		$log = get_option( self::OPTION, array() );
		return array_reverse( is_array( $log ) ? $log : array() );
	}

	/** Empties the log. */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
