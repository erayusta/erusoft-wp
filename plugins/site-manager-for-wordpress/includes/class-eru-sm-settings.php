<?php
/** Settings: one option, one section per module; saving a tab only replaces that tab's section. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SM_Settings {

	const OPTION = 'eru_sm_settings';

	const DAYS = array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);

	/** Crawler => owner. */
	const BOTS = array(
		'GPTBot'              => 'OpenAI',
		'OAI-SearchBot'       => 'OpenAI',
		'ChatGPT-User'        => 'OpenAI',
		'ClaudeBot'           => 'Anthropic',
		'Claude-SearchBot'    => 'Anthropic',
		'Claude-User'         => 'Anthropic',
		'PerplexityBot'       => 'Perplexity',
		'Google-Extended'     => 'Google',
		'Applebot-Extended'   => 'Apple',
		'CCBot'               => 'Common Crawl',
		'Bytespider'          => 'ByteDance',
		'Amazonbot'           => 'Amazon',
		'meta-externalagent'  => 'Meta',
	);

	const TYPES = array(
		'LocalBusiness', 'Store', 'Restaurant', 'CafeOrCoffeeShop', 'Bakery', 'ProfessionalService', 'AutoRepair',
		'BeautySalon', 'HairSalon', 'HealthAndBeautyBusiness', 'Dentist', 'LegalService', 'RealEstateAgent',
		'HomeAndConstructionBusiness', 'SportsActivityLocation', 'LodgingBusiness',
	);

	public static function defaults(): array {
		return array(
			'llms'   => array(
				'enabled'    => 0,
				'summary'    => '',
				'intro'      => '',
				'post_types' => array( 'page', 'post' ),
				'max'        => 50,
				'exclude'    => '',
				'links'      => '',
				'full'       => 0,
			),
			'schema' => array(
				'enabled'    => 0,
				'where'      => 'front',
				'type'       => 'LocalBusiness',
				'name'       => '',
				'legal_name' => '',
				'street'     => '',
				'district'   => '',
				'city'       => '',
				'postal'     => '',
				'country'    => 'TR',
				'phone'      => '',
				'email'      => '',
				'price'      => '',
				'same_as'    => '',
				'logo'       => '',
				'lat'        => '',
				'lng'        => '',
				'hours'      => array(),
			),
			'robots' => array(
				'enabled' => 0,
				'sitemap' => 1,
				'bots'    => array(),
			),
		);
	}

	/** Saved settings merged over defaults, section by section. */
	public static function get( ?string $section = null ): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::defaults() as $key => $def ) {
			$out[ $key ] = array_merge( $def, isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array() );
		}
		return null === $section ? $out : $out[ $section ];
	}

	public static function sanitize( $input ): array {
		$old   = self::get();
		$input = is_array( $input ) ? $input : array(); // options.php has already unslashed it.
		$tab   = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		$new   = $old;
		if ( 'llms' === $tab ) {
			$new['llms'] = self::sanitize_llms( $input );
		} elseif ( 'schema' === $tab ) {
			$new['schema'] = self::sanitize_schema( $input );
		} elseif ( 'robots' === $tab ) {
			$new['robots'] = self::sanitize_robots( $input );
		}
		Eru_SM_Llms::flush_cache();
		return $new;
	}

	private static function text( $in, $key ): string {
		return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? sanitize_text_field( (string) $in[ $key ] ) : '';
	}

	private static function sanitize_llms( array $in ): array {
		$types = array_keys( Eru_SM_Llms::available_post_types() );
		$ptype    = isset( $in['post_types'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $in['post_types'] ), $types ) ) : array();
		$ids   = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', self::text( $in, 'exclude' ) ) ) );
		return array(
			'enabled'    => empty( $in['enabled'] ) ? 0 : 1,
			'summary'    => self::text( $in, 'summary' ),
			'intro'      => isset( $in['intro'] ) && is_scalar( $in['intro'] ) ? sanitize_textarea_field( (string) $in['intro'] ) : '',
			'post_types' => $ptype,
			'max'        => max( 1, min( 500, absint( $in['max'] ?? 50 ) ) ),
			'exclude'    => implode( ',', $ids ),
			'links'      => isset( $in['links'] ) && is_scalar( $in['links'] ) ? sanitize_textarea_field( (string) $in['links'] ) : '',
			'full'       => empty( $in['full'] ) ? 0 : 1,
		);
	}

	private static function sanitize_schema( array $in ): array {
		$type  = self::text( $in, 'type' );
		$hours = array();
		foreach ( array_keys( self::DAYS ) as $d ) {
			foreach ( array( 'open', 'close' ) as $k ) {
				$v = isset( $in['hours'][ $d ][ $k ] ) && is_scalar( $in['hours'][ $d ][ $k ] ) ? trim( (string) $in['hours'][ $d ][ $k ] ) : '';
				$hours[ $d ][ $k ] = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : '';
			}
		}
		$num = function ( $k ) use ( $in ) {
			$v = isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? str_replace( ',', '.', trim( (string) $in[ $k ] ) ) : '';
			return is_numeric( $v ) ? (string) (float) $v : '';
		};
		$urls = array();
		foreach ( preg_split( '/\R/', isset( $in['same_as'] ) && is_scalar( $in['same_as'] ) ? (string) $in['same_as'] : '' ) as $line ) {
			$u = preg_match( '#^https?://#i', trim( $line ) ) ? esc_url_raw( trim( $line ), array( 'http', 'https' ) ) : '';
			if ( $u ) {
				$urls[] = $u;
			}
		}
		return array(
			'enabled'    => empty( $in['enabled'] ) ? 0 : 1,
			'where'      => ( $in['where'] ?? '' ) === 'all' ? 'all' : 'front',
			'type'       => in_array( $type, self::TYPES, true ) ? $type : 'LocalBusiness',
			'name'       => self::text( $in, 'name' ),
			'legal_name' => self::text( $in, 'legal_name' ),
			'street'     => self::text( $in, 'street' ),
			'district'   => self::text( $in, 'district' ),
			'city'       => self::text( $in, 'city' ),
			'postal'     => self::text( $in, 'postal' ),
			'country'    => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', self::text( $in, 'country' ) ), 0, 2 ) ),
			'phone'      => preg_replace( '/[^0-9+()\-\s]/', '', self::text( $in, 'phone' ) ),
			'email'      => sanitize_email( self::text( $in, 'email' ) ),
			'price'      => self::text( $in, 'price' ),
			'same_as'    => implode( "\n", $urls ),
			'logo'       => preg_match( '#^https?://#i', self::text( $in, 'logo' ) ) ? esc_url_raw( self::text( $in, 'logo' ), array( 'http', 'https' ) ) : '',
			'lat'        => $num( 'lat' ),
			'lng'        => $num( 'lng' ),
			'hours'      => $hours,
		);
	}

	private static function sanitize_robots( array $in ): array {
		$bots = array();
		foreach ( array_keys( self::BOTS ) as $bot ) {
			$v = isset( $in['bots'][ $bot ] ) ? sanitize_key( (string) $in['bots'][ $bot ] ) : 'default';
			if ( in_array( $v, array( 'allow', 'block' ), true ) ) {
				$bots[ $bot ] = $v;
			}
		}
		return array(
			'enabled' => empty( $in['enabled'] ) ? 0 : 1,
			'sitemap' => empty( $in['sitemap'] ) ? 0 : 1,
			'bots'    => $bots,
		);
	}
}
