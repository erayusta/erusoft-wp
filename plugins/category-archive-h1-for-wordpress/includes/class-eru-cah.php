<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ön yüz: arşiv başlığını ve H1'i yönetir. */
class Eru_Cah {

	public const META_KEY       = 'eru_cah_heading';
	public const OPTION_TAXES   = 'eru_cah_taxonomies';
	public const OPTION_MODE    = 'eru_cah_mode';
	public const OPTION_SEO     = 'eru_cah_seo_title';
	public const MODE_FILTER    = 'filter';
	public const MODE_AUTO      = 'auto';
	public const MODE_REPLACE   = 'replace';

	public static function init(): void {
		add_filter( 'single_cat_title', array( __CLASS__, 'filter_title' ) );
		add_filter( 'single_tag_title', array( __CLASS__, 'filter_title' ) );
		add_filter( 'single_term_title', array( __CLASS__, 'filter_title' ) );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'filter_seo_title' ) );
		add_filter( 'wpseo_title', array( __CLASS__, 'filter_seo_title' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer' ), 0 );
	}

	/** Etkin sınıflandırmalar (varsayılan: category). */
	public static function taxonomies(): array {
		$taxes = get_option( self::OPTION_TAXES, array( 'category' ) );
		return is_array( $taxes ) ? array_values( array_filter( array_map( 'sanitize_key', $taxes ) ) ) : array( 'category' );
	}

	public static function mode(): string {
		$mode = (string) get_option( self::OPTION_MODE, self::MODE_AUTO );
		return in_array( $mode, array( self::MODE_FILTER, self::MODE_AUTO, self::MODE_REPLACE ), true ) ? $mode : self::MODE_AUTO;
	}

	/** Şu an sorgulanan, etkin sınıflandırmaya ait terim; yoksa null. */
	private static function current_term(): ?WP_Term {
		if ( is_admin() || ! ( is_category() || is_tag() || is_tax() ) ) {
			return null;
		}
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, self::taxonomies(), true ) ) {
			return null;
		}
		return $term;
	}

	/**
	 * Terim için özel başlık (boşsa ''). Filtre: eru_cah_heading.
	 *
	 * @param WP_Term $term Terim.
	 */
	public static function heading_for( WP_Term $term ): string {
		$heading = trim( (string) get_term_meta( $term->term_id, self::META_KEY, true ) );
		/**
		 * Arşiv başlığını değiştirir. Boş dize döndürürseniz özel başlık uygulanmaz.
		 *
		 * @param string  $heading Terim için kayıtlı başlık ('' olabilir).
		 * @param WP_Term $term    Arşivi gösterilen terim.
		 */
		return trim( (string) apply_filters( 'eru_cah_heading', $heading, $term ) );
	}

	public static function filter_title( $title ) {
		$term = self::current_term();
		if ( ! $term || $title !== $term->name ) {
			return $title;
		}
		$heading = self::heading_for( $term );
		return '' !== $heading ? $heading : $title;
	}

	/** SEO eklentisi başlığında terim adını özel başlıkla değiştirir. */
	public static function filter_seo_title( $title ) {
		if ( 'yes' !== get_option( self::OPTION_SEO, 'yes' ) || ! is_string( $title ) ) {
			return $title;
		}
		$term = self::current_term();
		if ( ! $term ) {
			return $title;
		}
		$heading = self::heading_for( $term );
		$name    = (string) $term->name;
		$pos     = '' === $heading || '' === $name ? false : strpos( $title, $name );
		return false === $pos ? $title : substr_replace( $title, $heading, $pos, strlen( $name ) );
	}

	public static function maybe_buffer(): void {
		if ( self::MODE_FILTER === self::mode() || ! self::current_term() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'process_html' ) );
	}

	/**
	 * Sayfada H1 yoksa ekler; replace kipinde tema H1'ini özel başlıkla değiştirir.
	 */
	public static function process_html( string $html ): string {
		$term = self::current_term();
		if ( ! $term || false === stripos( $html, '<body' ) ) {
			return $html;
		}
		$heading = self::heading_for( $term );
		$display = '' !== $heading ? $heading : (string) $term->name;
		if ( '' === $display ) {
			return $html;
		}

		// Betik/stil/şablon içindeki "<h1" ifadeleri sayılmasın.
		$scan = preg_replace( '#<(script|style|template|noscript)\b.*?</\1>#is', '', $html );
		$scan = is_string( $scan ) ? $scan : $html;

		$count = preg_match_all( '#<h1\b[^>]*>(.*?)</h1>#is', $scan, $found );
		if ( false === $count ) {
			return $html;
		}
		if ( 0 === $count ) {
			return self::inject( $html, $display );
		}

		if ( self::MODE_REPLACE === self::mode() && '' !== $heading ) {
			$names = array_filter( array( (string) $term->name ) );
			foreach ( $found[1] as $inner ) {
				$text = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' );
				if ( false !== mb_stripos( $text, $heading ) ) {
					return $html; // Tema başlığı zaten özel başlığı içeriyor.
				}
				foreach ( $names as $n ) {
					if ( false !== mb_stripos( $text, $n ) ) {
						$replaced = preg_replace_callback(
							'#(<h1\b[^>]*>)' . preg_quote( $inner, '#' ) . '(</h1>)#s',
							fn( $m ) => $m[1] . esc_html( $heading ) . $m[2],
							$html,
							1
						);
						return is_string( $replaced ) ? $replaced : $html;
					}
				}
			}
		}
		return $html;
	}

	private static function inject( string $html, string $heading ): string {
		$tag = '<h1 class="eru-cah-heading page-title">' . esc_html( $heading ) . '</h1>';
		// Yalnızca <body açılışından sonrasında ara (<head> içindeki "<main" benzeri ifadeleri atla).
		if ( ! preg_match( '#<body\b[^>]*>#i', $html, $b, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$from = $b[0][1] + strlen( $b[0][0] );
		$at   = $from;
		if ( preg_match( '#<main\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
		}
		return substr( $html, 0, $at ) . $tag . substr( $html, $at );
	}
}
