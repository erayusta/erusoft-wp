<?php
/** Settings storage. Everything is off / neutral until the shop owner configures it. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_EIC_Settings {

	const OPTION = 'eru_eic_settings';

	public static function defaults(): array {
		return array(
			'enabled'       => 0,
			'mode'          => 'fixed', // fixed | generated.
			'code'          => '',
			'discount_type' => 'percent',
			'amount'        => 10,
			'valid_hours'   => 48,
			'min_spend'     => 0,
			'prefix'        => 'EXIT',
			'daily_limit'   => 100,
			'ip_hourly'     => 3,
			'title'         => __( 'Gitmeden önce!', 'exit-intent-coupons-for-woocommerce' ),
			'text'          => __( 'Size özel indirim kodunuz hazır:', 'exit-intent-coupons-for-woocommerce' ),
			'note'          => '',
			'button'        => __( 'Alışverişe dön', 'exit-intent-coupons-for-woocommerce' ),
			'accent'        => '#1f6feb',
			'cookie_days'   => 7,
			'min_seconds'   => 5,
			'hide_cart'     => 0,
			'hide_checkout' => 1,
			'need_cart'     => 0,
			'mobile'        => 1,
			'mobile_delay'  => 15,
			'mobile_depth'  => 300,
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** @param mixed $raw Posted values (unslashed). */
	public static function sanitize( $raw ): array {
		$d   = self::defaults();
		$raw = is_array( $raw ) ? $raw : array();
		$num = fn( $k, $min, $max, $def ) => isset( $raw[ $k ] ) && '' !== $raw[ $k ] ? max( $min, min( $max, (int) $raw[ $k ] ) ) : $def;
		$dec = fn( $k ) => isset( $raw[ $k ] ) ? max( 0.0, (float) wc_format_decimal( (string) $raw[ $k ] ) ) : 0.0;
		$txt = fn( $k ) => isset( $raw[ $k ] ) ? sanitize_text_field( (string) $raw[ $k ] ) : $d[ $k ];

		$type   = isset( $raw['discount_type'] ) && 'fixed_cart' === $raw['discount_type'] ? 'fixed_cart' : 'percent';
		$amount = $dec( 'amount' );
		if ( 'percent' === $type ) {
			$amount = min( 100.0, $amount );
		}
		$prefix = isset( $raw['prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw['prefix'] ) ) : $d['prefix'];
		$accent = isset( $raw['accent'] ) ? sanitize_hex_color( (string) $raw['accent'] ) : '';

		return array(
			'enabled'       => ! empty( $raw['enabled'] ) ? 1 : 0,
			'mode'          => isset( $raw['mode'] ) && 'generated' === $raw['mode'] ? 'generated' : 'fixed',
			'code'          => isset( $raw['code'] ) ? wc_format_coupon_code( (string) $raw['code'] ) : '',
			'discount_type' => $type,
			'amount'        => $amount,
			'valid_hours'   => $num( 'valid_hours', 1, 720, 48 ),
			'min_spend'     => $dec( 'min_spend' ),
			'prefix'        => substr( $prefix, 0, 8 ),
			'daily_limit'   => $num( 'daily_limit', 0, 100000, 100 ),
			'ip_hourly'     => $num( 'ip_hourly', 1, 100, 3 ),
			'title'         => $txt( 'title' ),
			'text'          => $txt( 'text' ),
			'note'          => $txt( 'note' ),
			'button'        => $txt( 'button' ),
			'accent'        => $accent ? $accent : $d['accent'],
			'cookie_days'   => $num( 'cookie_days', 1, 365, 7 ),
			'min_seconds'   => $num( 'min_seconds', 0, 300, 5 ),
			'hide_cart'     => ! empty( $raw['hide_cart'] ) ? 1 : 0,
			'hide_checkout' => ! empty( $raw['hide_checkout'] ) ? 1 : 0,
			'need_cart'     => ! empty( $raw['need_cart'] ) ? 1 : 0,
			'mobile'        => ! empty( $raw['mobile'] ) ? 1 : 0,
			'mobile_delay'  => $num( 'mobile_delay', 0, 300, 15 ),
			'mobile_depth'  => $num( 'mobile_depth', 0, 5000, 300 ),
		);
	}

	public static function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}
}
