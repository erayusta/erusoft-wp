<?php
/**
 * Settings (single option).
 *
 * @package turkish-address-fields-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TAF_Settings {

	const OPTION = 'eru_taf_settings';

	/** Defaults: switched off — the checkout is unchanged until the merchant enables the fields. */
	public static function defaults(): array {
		return array(
			'enabled'           => 0,
			'billing'           => 1,
			'shipping'          => 1,
			'account'           => 1,
			'show_neighborhood' => 1,
			'show_street'       => 1,
			'req_district'      => 1,
			'req_neighborhood'  => 1,
			'req_street'        => 0,
			'door_check'        => 1,
			'normalize'         => 1,
			'write_address'     => 1,
			'hide_postcode'     => 0,
			'lock_country'      => 0,
			'phone_check'       => 0,
		);
	}

	public static function all(): array {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? array_intersect_key( $s, self::defaults() ) : array(), self::defaults() );
	}

	public static function on( string $key ): bool {
		return ! empty( self::all()[ $key ] );
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array_keys( self::defaults() ) as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		return $out;
	}

	/** Address groups the fields apply to. */
	public static function groups(): array {
		$s = self::all();
		return array_values( array_filter( array( 'billing', 'shipping' ), fn( $g ) => ! empty( $s[ $g ] ) ) );
	}

	/** Levels shown below İl: district always, neighbourhood/street optional (street needs neighbourhood). */
	public static function levels(): array {
		$s      = self::all();
		$levels = array( 'district' );
		if ( $s['show_neighborhood'] ) {
			$levels[] = 'neighborhood';
			if ( $s['show_street'] ) {
				$levels[] = 'street';
			}
		}
		return $levels;
	}

	public static function required( string $level ): bool {
		return ! empty( self::all()[ 'req_' . $level ] );
	}
}
