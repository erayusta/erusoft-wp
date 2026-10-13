<?php
/** Settings storage. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WN_Settings {

	const OPTION = 'eru_wn_settings';

	/** Empty text = the plugin does nothing. */
	public static function defaults(): array {
		return array(
			'text'          => '',
			'link_url'      => '',
			'link_label'    => '',
			'style'         => 'info',
			'admin_enabled' => 1,
			'roles'         => array( 'administrator' ),
			'front_enabled' => 0,
			'front_pos'     => 'top',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** @param mixed $raw Posted values (unslashed). */
	public static function sanitize( $raw ): array {
		$raw   = is_array( $raw ) ? $raw : array();
		$roles = isset( $raw['roles'] ) ? array_map( 'sanitize_key', (array) $raw['roles'] ) : array();
		$roles = array_values( array_intersect( $roles, array_keys( wp_roles()->roles ) ) );
		$style = isset( $raw['style'] ) ? (string) $raw['style'] : 'info';
		$pos   = isset( $raw['front_pos'] ) ? (string) $raw['front_pos'] : 'top';
		return array(
			'text'          => isset( $raw['text'] ) ? sanitize_textarea_field( (string) $raw['text'] ) : '',
			'link_url'      => isset( $raw['link_url'] ) ? esc_url_raw( (string) $raw['link_url'], array( 'http', 'https' ) ) : '',
			'link_label'    => isset( $raw['link_label'] ) ? sanitize_text_field( (string) $raw['link_label'] ) : '',
			'style'         => in_array( $style, array( 'info', 'success', 'warning' ), true ) ? $style : 'info',
			'admin_enabled' => ! empty( $raw['admin_enabled'] ) ? 1 : 0,
			'roles'         => $roles,
			'front_enabled' => ! empty( $raw['front_enabled'] ) ? 1 : 0,
			'front_pos'     => in_array( $pos, array( 'top', 'bottom' ), true ) ? $pos : 'top',
		);
	}
}
