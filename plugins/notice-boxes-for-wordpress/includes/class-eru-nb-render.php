<?php
/** One renderer for the [eru_notice] shortcode and the eru/notice-box block. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_NB_Render {

	public static function init(): void {
		add_shortcode( 'eru_notice', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	public static function register_block(): void {
		register_block_type(
			ERU_NB_PATH . 'block',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	/**
	 * @param array|string $atts    type, title, style.
	 * @param string|null  $content Enclosed content.
	 */
	public static function shortcode( $atts = array(), $content = '' ): string {
		$d    = Eru_NB_Settings::get();
		$atts = shortcode_atts(
			array(
				'type'  => $d['type'],
				'title' => '',
				'style' => $d['style'],
			),
			$atts,
			'eru_notice'
		);
		return self::box( (string) $atts['type'], (string) $atts['title'], (string) $atts['style'], do_shortcode( (string) $content ) );
	}

	/**
	 * @param array    $attributes Block attributes (type, title, variant).
	 * @param string   $content    Inner blocks HTML.
	 */
	public static function render_block( $attributes, $content = '' ): string {
		$d = Eru_NB_Settings::get();
		return self::box(
			isset( $attributes['type'] ) ? (string) $attributes['type'] : $d['type'],
			isset( $attributes['title'] ) ? (string) $attributes['title'] : '',
			isset( $attributes['variant'] ) ? (string) $attributes['variant'] : $d['style'],
			(string) $content
		);
	}

	private static function icon( string $type ): string {
		$paths = array(
			'info'    => 'M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z',
			'warning' => 'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z',
			'success' => 'M12 2a10 10 0 100 20 10 10 0 000-20zm-2 15l-5-5 1.4-1.4L10 14.2l7.6-7.6L19 8l-9 9z',
			'error'   => 'M12 2a10 10 0 100 20 10 10 0 000-20zm5 13.6L15.6 17 12 13.4 8.4 17 7 15.6 10.6 12 7 8.4 8.4 7 12 10.6 15.6 7 17 8.4 13.4 12 17 15.6z',
		);
		return '<svg class="eru-nb__icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="' . esc_attr( $paths[ $type ] ) . '"/></svg>';
	}

	public static function box( string $type, string $title, string $style, string $content ): string {
		$s     = Eru_NB_Settings::get();
		$type  = in_array( $type, Eru_NB_Settings::TYPES, true ) ? $type : 'info';
		$style = in_array( $style, Eru_NB_Settings::STYLES, true ) ? $style : 'soft';
		wp_enqueue_style( 'eru-notice-box-style' );

		$classes = 'eru-nb eru-nb--' . $type . ' eru-nb--' . $style . ( 'off' === $s['dark'] ? ' eru-nb--nodark' : '' );
		$html    = '<div class="' . esc_attr( $classes ) . '" role="note">';
		if ( $s['icons'] ) {
			$html .= self::icon( $type );
		}
		$html .= '<div class="eru-nb__body">';
		if ( '' !== trim( $title ) ) {
			$html .= '<p class="eru-nb__title">' . esc_html( wp_strip_all_tags( $title ) ) . '</p>';
		}
		$html .= '<div class="eru-nb__content">' . wp_kses_post( $content ) . '</div></div></div>';
		return $html;
	}
}
