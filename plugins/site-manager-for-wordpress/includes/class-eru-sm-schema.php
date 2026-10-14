<?php
/** LocalBusiness JSON-LD. Empty fields are omitted, nothing is invented. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SM_Schema {

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 20 );
	}

	/** Name of the SEO plugin whose local-business module is active, or ''. */
	public static function conflict(): string {
		if ( defined( 'WPSEO_LOCAL_VERSION' ) || class_exists( 'WPSEO_Local_Core' ) ) {
			return 'Yoast Local SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$mods = get_option( 'rank_math_modules', array() );
			if ( is_array( $mods ) && in_array( 'local-seo', $mods, true ) ) {
				return 'Rank Math (Local SEO)';
			}
		}
		if ( defined( 'SEOPRESS_PRO_VERSION' ) ) {
			$t = get_option( 'seopress_toggle', array() );
			if ( is_array( $t ) && ! empty( $t['toggle-local-business'] ) ) {
				return 'SEOPress Local Business';
			}
		}
		return '';
	}

	public static function output(): void {
		$s = Eru_SM_Settings::get( 'schema' );
		if ( empty( $s['enabled'] ) || ( 'front' === $s['where'] && ! is_front_page() ) ) {
			return;
		}
		/**
		 * Whether the LocalBusiness block may be printed (false when an SEO plugin already does it).
		 *
		 * @param bool $enabled
		 */
		if ( ! apply_filters( 'eru_sm_schema_enabled', '' === self::conflict() ) ) {
			return;
		}
		$data = self::build( $s );
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON, tags hex-escaped.
	}

	/** The schema array for the saved (or given) settings. */
	public static function build( ?array $s = null ): array {
		$s    = $s ?? Eru_SM_Settings::get( 'schema' );
		$name = '' !== $s['name'] ? $s['name'] : get_bloginfo( 'name' );
		$type = in_array( $s['type'], Eru_SM_Settings::TYPES, true ) ? $s['type'] : 'LocalBusiness';
		$d    = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => $name,
			'url'      => home_url( '/' ),
		);
		$map = array(
			'legal_name' => 'legalName',
			'phone'      => 'telephone',
			'email'      => 'email',
			'price'      => 'priceRange',
		);
		foreach ( $map as $k => $prop ) {
			if ( '' !== trim( (string) $s[ $k ] ) ) {
				$d[ $prop ] = trim( (string) $s[ $k ] );
			}
		}
		$logo = '' !== $s['logo'] ? $s['logo'] : self::default_logo();
		if ( '' !== $logo ) {
			$d['logo'] = $logo;
		}
		// Turkish convention: ilçe = addressLocality, il = addressRegion.
		$addr = array_filter(
			array(
				'streetAddress'   => $s['street'],
				'addressLocality' => $s['district'],
				'addressRegion'   => $s['city'],
				'postalCode'      => $s['postal'],
				'addressCountry'  => $s['country'],
			),
			static fn( $v ) => '' !== trim( (string) $v )
		);
		if ( array_diff_key( $addr, array( 'addressCountry' => 1 ) ) ) {
			$d['address'] = array( '@type' => 'PostalAddress' ) + $addr;
		}
		if ( is_numeric( $s['lat'] ) && is_numeric( $s['lng'] ) && '' !== $s['lat'] && '' !== $s['lng'] ) {
			$d['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $s['lat'], 'longitude' => (float) $s['lng'] );
		}
		$hours = array();
		foreach ( Eru_SM_Settings::DAYS as $key => $day ) {
			$o = $s['hours'][ $key ]['open'] ?? '';
			$c = $s['hours'][ $key ]['close'] ?? '';
			if ( '' !== $o && '' !== $c ) {
				$hours[] = array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $day, 'opens' => $o, 'closes' => $c );
			}
		}
		if ( $hours ) {
			$d['openingHoursSpecification'] = $hours;
		}
		$same = array_values( array_filter( array_map( static fn( $u ) => preg_match( '#^https?://#i', trim( $u ) ) ? esc_url_raw( trim( $u ), array( 'http', 'https' ) ) : '', preg_split( '/\R/', (string) $s['same_as'] ) ) ) );
		if ( $same ) {
			$d['sameAs'] = $same;
		}
		/**
		 * Filter the LocalBusiness array before it is printed.
		 *
		 * @param array $d
		 */
		return (array) apply_filters( 'eru_sm_schema', $d );
	}

	private static function default_logo(): string {
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( $id ) {
			$u = wp_get_attachment_image_url( $id, 'full' );
			if ( $u ) {
				return $u;
			}
		}
		return (string) get_site_icon_url( 512 );
	}
}
