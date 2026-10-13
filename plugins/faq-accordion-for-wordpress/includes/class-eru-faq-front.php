<?php
/** [eru_faq] shortcode: <details> accordion plus optional FAQPage JSON-LD (once per page). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_FAQ_Front {

	/** @var bool Whether the FAQPage JSON-LD was already printed on this request. */
	private static $schema_printed = false;

	public static function init(): void {
		add_shortcode( 'eru_faq', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/** Forget per-request state (used by tests). */
	public static function reset(): void {
		self::$schema_printed = false;
	}

	public static function register_assets(): void {
		wp_register_style( 'eru-faq', ERU_FAQ_URL . 'assets/faq.css', array(), ERU_FAQ_VERSION );
	}

	/**
	 * @param array|string $atts group, limit, open (none|first|all), schema (1|0).
	 */
	public static function shortcode( $atts = array() ): string {
		$s    = Eru_FAQ_Settings::get();
		$atts = shortcode_atts(
			array(
				'group'  => '',
				'limit'  => 0,
				'open'   => $s['open'],
				'schema' => '',
			),
			$atts,
			'eru_faq'
		);

		$args = array(
			'post_type'              => Eru_FAQ_Post_Type::TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $atts['limit'] > 0 ? min( 100, (int) $atts['limit'] ) : 100,
			'orderby'                => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);
		$group = sanitize_title( (string) $atts['group'] );
		if ( '' !== $group ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => Eru_FAQ_Post_Type::TAX,
					'field'    => 'slug',
					'terms'    => $group,
				),
			);
		}
		$posts = get_posts( $args );
		if ( ! $posts ) {
			return '';
		}

		wp_enqueue_style( 'eru-faq' );
		$open      = in_array( $atts['open'], array( 'none', 'first', 'all' ), true ) ? $atts['open'] : 'none';
		$exclusive = $s['exclusive'] && 'all' !== $open;
		$name      = $exclusive ? ' name="eru-faq-' . esc_attr( '' !== $group ? $group : 'all' ) . '"' : '';
		$style     = ' style="--eru-faq-accent:' . esc_attr( $s['accent'] ) . '"';

		$html  = '<div class="eru-faq"' . $style . '>';
		$items = array();
		foreach ( $posts as $i => $post ) {
			$answer  = wp_kses_post( wpautop( $post->post_content ) );
			$is_open = 'all' === $open || ( 'first' === $open && 0 === $i );
			$html   .= '<details class="eru-faq__item"' . $name . ( $is_open ? ' open' : '' ) . '>';
			$html   .= '<summary class="eru-faq__q"><span>' . esc_html( get_the_title( $post ) ) . '</span></summary>';
			$html   .= '<div class="eru-faq__a">' . $answer . '</div></details>';
			$items[] = array(
				'q' => wp_strip_all_tags( get_the_title( $post ) ),
				'a' => trim( wp_strip_all_tags( $answer ) ),
			);
		}
		$html .= '</div>';

		$want_schema = '' === (string) $atts['schema'] ? (bool) $s['schema'] : (bool) (int) $atts['schema'];
		if ( apply_filters( 'eru_faq_print_schema', $want_schema, $items ) && ! self::$schema_printed ) {
			self::$schema_printed = true;
			$html                .= self::json_ld( $items );
		}
		return $html;
	}

	/** @param array<int,array{q:string,a:string}> $items */
	private static function json_ld( array $items ): string {
		$entities = array();
		foreach ( $items as $it ) {
			if ( '' === $it['q'] || '' === $it['a'] ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $it['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $it['a'],
				),
			);
		}
		if ( ! $entities ) {
			return '';
		}
		$data = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
		// Slashes stay escaped so a stray "</script>" in an answer cannot end the tag early.
		return '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
	}
}
