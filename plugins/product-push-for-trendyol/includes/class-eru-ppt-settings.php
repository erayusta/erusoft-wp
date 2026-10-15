<?php
/**
 * Settings: one non-autoloaded option. The screen lives in Eru_PPT_Admin.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything starts switched off: no outbound request and no scheduled job until the master switch is on
 * and seller ID, API key and API secret are set. Key and secret are write-only.
 */
class Eru_PPT_Settings {

	const OPTION = 'eru_ppt_settings';

	/** Trendyol's documented integration API hosts (filter eru_ppt_base_url; editable on the screen). */
	const BASE_LIVE  = 'https://apigw.trendyol.com/integration';
	const BASE_STAGE = 'https://stageapigw.trendyol.com/integration';

	/** @var array|null Request cache. */
	private static $cache = null;

	/** @return array Defaults. */
	public static function defaults() {
		return array(
			'enabled'              => 'no',
			'environment'          => 'live',
			'base_live'            => self::BASE_LIVE,
			'base_stage'           => self::BASE_STAGE,
			'seller_id'            => '',
			'api_key'              => '',
			'api_secret'           => '',
			'integration_ref'      => 'SelfIntegration',
			'brand_id'             => 0,
			'brand_name'           => '',
			'cargo_company_id'     => 0,
			'shipment_address_id'  => 0,
			'returning_address_id' => 0,
			'vat_rate'             => 20,
			'delivery_duration'    => 2,
			'dimensional_weight'   => '1',
			'list_multiplier'      => '1',
			'title_template'       => '{name}',
			'description_prefix'   => '',
			'barcode_source'       => 'sku',
			'barcode_meta_key'     => '',
			'barcode_prefix'       => '',
			'push_quantity'        => 'stock',
			'category_map'         => array(),
			'attribute_map'        => array(),
		);
	}

	/** @return array Saved settings over defaults. */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/** @param string $key Key. @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/** @return bool Master switch. */
	public static function is_on() {
		return 'yes' === self::get( 'enabled' );
	}

	/**
	 * Credentials after the eru_ppt_credentials filter (another plugin may supply them).
	 *
	 * @return array{seller_id:string,api_key:string,api_secret:string,integration_ref:string}
	 */
	public static function credentials() {
		$c = array(
			'seller_id'       => (string) self::get( 'seller_id' ),
			'api_key'         => (string) self::get( 'api_key' ),
			'api_secret'      => (string) self::get( 'api_secret' ),
			'integration_ref' => (string) self::get( 'integration_ref' ),
		);
		$f = apply_filters( 'eru_ppt_credentials', $c );
		$f = is_array( $f ) ? $f : array();
		foreach ( $c as $k => $v ) {
			$c[ $k ] = trim( (string) ( $f[ $k ] ?? '' ) );
		}
		if ( '' === $c['integration_ref'] ) {
			$c['integration_ref'] = 'SelfIntegration';
		}
		return $c;
	}

	/** @return bool Seller ID, key and secret present. */
	public static function configured() {
		$c = self::credentials();
		return '' !== $c['seller_id'] && '' !== $c['api_key'] && '' !== $c['api_secret'];
	}

	/** @return bool Switch on and credentials present. */
	public static function is_enabled() {
		return self::is_on() && self::configured();
	}

	/** @return string Environment: live|stage. */
	public static function environment() {
		return 'stage' === self::get( 'environment' ) ? 'stage' : 'live';
	}

	/** @return string API base URL without trailing slash. */
	public static function base_url() {
		$env = self::environment();
		$url = (string) self::get( 'stage' === $env ? 'base_stage' : 'base_live' );
		if ( '' === $url ) {
			$url = 'stage' === $env ? self::BASE_STAGE : self::BASE_LIVE;
		}
		return untrailingslashit( (string) apply_filters( 'eru_ppt_base_url', $url, $env ) );
	}

	/**
	 * Internal write (screen, tests, CLI): merges values, keeps autoload off, re-syncs the schedule.
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$new   = array_merge( $saved, $values );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $new, '', false );
		} else {
			update_option( self::OPTION, $new, false );
		}
		self::$cache = null;
		if ( class_exists( 'Eru_PPT_Cron' ) ) {
			Eru_PPT_Cron::sync();
		}
	}

	/** Drops the request cache. */
	public static function flush() {
		self::$cache = null;
	}

	/** Hooks. */
	public static function init() {
		foreach ( array( 'update_option_', 'add_option_', 'delete_option_' ) as $prefix ) {
			add_action(
				$prefix . self::OPTION,
				function () {
					self::$cache = null;
				}
			);
		}
	}

	/** @param mixed $v Raw. @return string Decimal string or ''. */
	public static function decimal( $v ) {
		$v = str_replace( ',', '.', trim( (string) $v ) );
		return is_numeric( $v ) && (float) $v >= 0 ? (string) wc_format_decimal( $v ) : '';
	}

	/**
	 * Sanitises posted values (unslashed). Key and secret left blank keep the stored value.
	 *
	 * @param array $in Posted.
	 * @return array{values:array,errors:string[]}
	 */
	public static function sanitize( array $in ) {
		$old    = self::all();
		$out    = array();
		$errors = array();


		$out['enabled']     = isset( $in['enabled'] ) && 'yes' === $in['enabled'] ? 'yes' : 'no';
		$out['environment'] = 'stage' === ( $in['environment'] ?? '' ) ? 'stage' : 'live';
		// Credentials go to this host: only https on trendyol.com (other hosts only via the eru_ppt_base_url filter).
		foreach ( array(
			'base_live'  => self::BASE_LIVE,
			'base_stage' => self::BASE_STAGE,
		) as $k => $def ) {
			$url  = esc_url_raw( trim( (string) ( $in[ $k ] ?? '' ) ), array( 'https' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $url && ! ( 'trendyol.com' === $host || str_ends_with( $host, '.trendyol.com' ) ) ) {
				$errors[] = __( 'API adresi yalnızca https ve trendyol.com alan adında olabilir; varsayılan adres kullanıldı. Başka bir adres için eru_ppt_base_url filtresini kullanın.', 'product-push-for-trendyol' );
				$url      = '';
			}
			$out[ $k ] = '' !== $url ? untrailingslashit( $url ) : $def;
		}
		$out['seller_id'] = preg_replace( '/\D+/', '', (string) ( $in['seller_id'] ?? '' ) );
		foreach ( array( 'api_key', 'api_secret' ) as $k ) {
			$v         = trim( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ) );
			$out[ $k ] = '' !== $v ? $v : (string) $old[ $k ];
			if ( ! empty( $in[ 'clear_' . $k ] ) ) {
				$out[ $k ] = '';
			}
		}
		$ref                    = preg_replace( '/[^A-Za-z0-9 ._-]/', '', (string) ( $in['integration_ref'] ?? '' ) );
		$out['integration_ref'] = '' !== trim( $ref ) ? trim( $ref ) : 'SelfIntegration';

		$out['brand_id']             = absint( $in['brand_id'] ?? 0 );
		$out['brand_name']           = sanitize_text_field( (string) ( $in['brand_name'] ?? '' ) );
		$out['cargo_company_id']     = absint( $in['cargo_company_id'] ?? 0 );
		$out['shipment_address_id']  = absint( $in['shipment_address_id'] ?? 0 );
		$out['returning_address_id'] = absint( $in['returning_address_id'] ?? 0 );
		$vat                         = absint( $in['vat_rate'] ?? 20 );
		$out['vat_rate']             = in_array( $vat, array( 0, 1, 10, 20 ), true ) ? $vat : 20;
		$out['delivery_duration']    = max( 1, min( 30, absint( $in['delivery_duration'] ?? 2 ) ) );
		$dw                          = self::decimal( $in['dimensional_weight'] ?? '1' );
		$out['dimensional_weight']   = '' !== $dw ? $dw : '1';
		$mul                         = self::decimal( $in['list_multiplier'] ?? '1' );
		if ( '' === $mul || (float) $mul < 1 || (float) $mul > 10 ) {
			$errors[] = __( 'Liste fiyatı çarpanı 1 ile 10 arasında olmalı; 1 kullanıldı.', 'product-push-for-trendyol' );
			$mul      = '1';
		}
		$out['list_multiplier']    = $mul;
		$tpl                       = sanitize_text_field( (string) ( $in['title_template'] ?? '' ) );
		$out['title_template']     = '' !== trim( $tpl ) ? $tpl : '{name}';
		$out['description_prefix'] = wp_kses_post( (string) ( $in['description_prefix'] ?? '' ) );
		$src                       = (string) ( $in['barcode_source'] ?? 'sku' );
		$out['barcode_source']     = in_array( $src, array( 'sku', 'gtin', 'meta' ), true ) ? $src : 'sku';
		$out['barcode_meta_key']   = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $in['barcode_meta_key'] ?? '' ) );
		if ( 'meta' === $out['barcode_source'] && '' === $out['barcode_meta_key'] ) {
			$errors[] = __( 'Barkod kaynağı "meta alanı" için bir meta anahtarı girin.', 'product-push-for-trendyol' );
		}
		$out['barcode_prefix'] = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) ( $in['barcode_prefix'] ?? '' ) );
		$out['barcode_prefix'] = substr( $out['barcode_prefix'], 0, 20 );
		$out['push_quantity']  = 'zero' === ( $in['push_quantity'] ?? '' ) ? 'zero' : 'stock';

		// Category map: one row per WC category.
		$map = array();
		foreach ( array_values( (array) ( $in['category_map'] ?? array() ) ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$wc = absint( $row['wc'] ?? 0 );
			$ty = absint( $row['ty'] ?? 0 );
			if ( ! $wc || ! $ty || ! term_exists( $wc, 'product_cat' ) ) {
				continue;
			}
			$map[ $wc ] = array(
				'wc'   => $wc,
				'ty'   => $ty,
				'name' => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
			);
		}
		$out['category_map'] = array_values( $map );

		// Attribute map: WC attribute → Trendyol attribute (per Trendyol category, 0 = all), value lines "wc=id".
		$amap = array();
		foreach ( array_values( (array) ( $in['attribute_map'] ?? array() ) ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$wc = sanitize_title( (string) ( $row['wc'] ?? '' ) );
			$ty = absint( $row['ty'] ?? 0 );
			if ( '' === $wc || ! $ty ) {
				continue;
			}
			$values = array();
			$raw    = is_array( $row['values'] ?? null ) ? $row['values'] : preg_split( '/\r\n|\r|\n/', (string) ( $row['values'] ?? '' ) );
			foreach ( $raw as $k => $line ) {
				if ( is_string( $k ) ) {
					$from = $k;
					$to   = $line;
				} else {
					$parts = explode( '=', (string) $line, 2 );
					if ( 2 !== count( $parts ) ) {
						continue;
					}
					list( $from, $to ) = $parts;
				}
				$from = sanitize_title( trim( (string) $from ) );
				$to   = absint( trim( (string) $to ) );
				if ( '' !== $from && $to ) {
					$values[ $from ] = $to;
				}
			}
			$amap[] = array(
				'cat'    => absint( $row['cat'] ?? 0 ),
				'wc'     => $wc,
				'ty'     => $ty,
				'values' => array_slice( $values, 0, 500, true ),
			);
		}
		$out['attribute_map'] = array_slice( $amap, 0, 200 );

		return array(
			'values' => $out,
			'errors' => $errors,
		);
	}
}
