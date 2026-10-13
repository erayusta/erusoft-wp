<?php
/** Settings storage. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_NB_Settings {

	const OPTION = 'eru_nb_settings';

	const TYPES  = array( 'info', 'warning', 'success', 'error' );
	const STYLES = array( 'soft', 'solid', 'outline' );

	public static function defaults(): array {
		return array(
			'type'  => 'info',
			'style' => 'soft',
			'icons' => 1,
			'dark'  => 'auto',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** @param mixed $raw Posted values (unslashed). */
	public static function sanitize( $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$type = isset( $raw['type'] ) ? (string) $raw['type'] : 'info';
		$st   = isset( $raw['style'] ) ? (string) $raw['style'] : 'soft';
		$dark = isset( $raw['dark'] ) ? (string) $raw['dark'] : 'auto';
		return array(
			'type'  => in_array( $type, self::TYPES, true ) ? $type : 'info',
			'style' => in_array( $st, self::STYLES, true ) ? $st : 'soft',
			'icons' => ! empty( $raw['icons'] ) ? 1 : 0,
			'dark'  => in_array( $dark, array( 'auto', 'off' ), true ) ? $dark : 'auto',
		);
	}
}
