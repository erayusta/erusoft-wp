<?php
/** Settings storage. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HP_Settings {

	const OPTION = 'eru_hp_settings';

	public static function defaults(): array {
		return array(
			'post_types'   => array( 'post' ),
			'level'        => 2,
			'min_sections' => 3,
			'min_words'    => 600,
			'show_toc'     => 1,
			'show_nav'     => 1,
			'toc_title'    => '',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		if ( '' === (string) $s['toc_title'] ) {
			$s['toc_title'] = __( 'Bu yazıda', 'heading-pagination-for-wordpress' );
		}
		return $s;
	}

	/** @param mixed $raw Posted values (unslashed). */
	public static function sanitize( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$types = isset( $raw['post_types'] ) ? array_map( 'sanitize_key', (array) $raw['post_types'] ) : array();
		$types = array_values( array_intersect( $types, get_post_types( array( 'public' => true ) ) ) );
		return array(
			'post_types'   => $types,
			'level'        => isset( $raw['level'] ) ? max( 2, min( 4, (int) $raw['level'] ) ) : 2,
			'min_sections' => isset( $raw['min_sections'] ) ? max( 2, min( 50, (int) $raw['min_sections'] ) ) : 3,
			'min_words'    => isset( $raw['min_words'] ) ? max( 0, min( 100000, (int) $raw['min_words'] ) ) : 600,
			'show_toc'     => ! empty( $raw['show_toc'] ) ? 1 : 0,
			'show_nav'     => ! empty( $raw['show_nav'] ) ? 1 : 0,
			'toc_title'    => isset( $raw['toc_title'] ) ? sanitize_text_field( (string) $raw['toc_title'] ) : '',
		);
	}
}
