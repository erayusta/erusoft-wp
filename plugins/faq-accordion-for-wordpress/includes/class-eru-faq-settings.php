<?php
/** Settings storage. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_FAQ_Settings {

	const OPTION = 'eru_faq_settings';

	public static function defaults(): array {
		return array(
			'schema'    => 1,
			'exclusive' => 0,
			'open'      => 'none',
			'accent'    => '#1f6feb',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** @param mixed $raw Posted values (unslashed). */
	public static function sanitize( $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$d      = self::defaults();
		$open   = isset( $raw['open'] ) ? (string) $raw['open'] : 'none';
		$accent = isset( $raw['accent'] ) ? sanitize_hex_color( (string) $raw['accent'] ) : '';
		return array(
			'schema'    => ! empty( $raw['schema'] ) ? 1 : 0,
			'exclusive' => ! empty( $raw['exclusive'] ) ? 1 : 0,
			'open'      => in_array( $open, array( 'none', 'first', 'all' ), true ) ? $open : 'none',
			'accent'    => $accent ? $accent : $d['accent'],
		);
	}
}
