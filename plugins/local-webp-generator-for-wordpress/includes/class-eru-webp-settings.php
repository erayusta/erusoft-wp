<?php
/** Settings storage. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WEBP_Settings {

	const OPTION = 'eru_webp_settings';

	public static function defaults(): array {
		return array(
			'quality'   => 82,
			'min_bytes' => 10000,
			'serve'     => 'picture',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** @param mixed $raw Posted values (unslashed). */
	public static function sanitize( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$serve = isset( $raw['serve'] ) ? (string) $raw['serve'] : 'picture';
		return array(
			'quality'   => isset( $raw['quality'] ) ? max( 30, min( 100, (int) $raw['quality'] ) ) : 82,
			'min_bytes' => isset( $raw['min_bytes'] ) ? max( 0, min( 5000000, (int) $raw['min_bytes'] ) ) : 10000,
			'serve'     => in_array( $serve, array( 'picture', 'src', 'off' ), true ) ? $serve : 'picture',
		);
	}
}
