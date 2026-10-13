<?php
/** Settings storage. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CP_Settings {

	const OPTION = 'eru_cp_settings';

	public static function defaults(): array {
		return array(
			'archive_slug'  => 'kampanyalar',
			'archive_title' => __( 'Kampanyalar', 'campaign-pages-for-wordpress' ),
			'archive_intro' => '',
			'show_upcoming' => 0,
			'show_ended'    => 0,
			'columns'       => 3,
			'accent'        => '#1f6feb',
			'cta_default'   => __( 'Kampanyayı incele', 'campaign-pages-for-wordpress' ),
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		if ( '' === $s['archive_slug'] ) {
			$s['archive_slug'] = 'kampanyalar';
		}
		return $s;
	}

	/** @param mixed $raw Posted values (unslashed). */
	public static function sanitize( $raw ): array {
		$d      = self::defaults();
		$raw    = is_array( $raw ) ? $raw : array();
		$slug   = isset( $raw['archive_slug'] ) ? sanitize_title( (string) $raw['archive_slug'] ) : '';
		$accent = isset( $raw['accent'] ) ? sanitize_hex_color( (string) $raw['accent'] ) : '';
		return array(
			'archive_slug'  => '' !== $slug ? $slug : $d['archive_slug'],
			'archive_title' => isset( $raw['archive_title'] ) ? sanitize_text_field( (string) $raw['archive_title'] ) : $d['archive_title'],
			'archive_intro' => isset( $raw['archive_intro'] ) ? sanitize_textarea_field( (string) $raw['archive_intro'] ) : '',
			'show_upcoming' => ! empty( $raw['show_upcoming'] ) ? 1 : 0,
			'show_ended'    => ! empty( $raw['show_ended'] ) ? 1 : 0,
			'columns'       => isset( $raw['columns'] ) ? max( 1, min( 4, (int) $raw['columns'] ) ) : 3,
			'accent'        => $accent ? $accent : $d['accent'],
			'cta_default'   => isset( $raw['cta_default'] ) ? sanitize_text_field( (string) $raw['cta_default'] ) : $d['cta_default'],
		);
	}

	/** Save; schedules a single rewrite flush only when the slug really changed. */
	public static function save( array $new ): void {
		$old = self::get();
		update_option( self::OPTION, $new, false );
		if ( $old['archive_slug'] !== $new['archive_slug'] ) {
			update_option( 'eru_cp_flush', '1', false );
		}
	}
}
