<?php
/**
 * Settings access, defaults and sanitising.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the eru_cb_settings option with defaults. Every module switch defaults to "no".
 */
class Eru_CB_Settings {

	const OPTION = 'eru_cb_settings';
	const GROUP  = 'eru_cb';

	/** @return array Default settings. */
	public static function defaults() {
		return array(
			// Free-shipping bar and suggestions.
			'ship_enabled'          => 'no',
			'ship_threshold_mode'   => 'zone',
			'ship_threshold_manual' => '',
			'ship_on_cart'          => 'yes',
			'ship_on_checkout'      => 'yes',
			'ship_text'             => 'Ücretsiz kargo için sepetinize {kalan} daha ekleyin.',
			'ship_text_done'        => 'Ücretsiz kargo kazandınız.',
			'ship_gap_extra'        => '0',
			'ship_gap_count'        => '3',
			'ship_exclude_products' => array(),
			'ship_exclude_cats'     => array(),
			'ship_manual_products'  => array(),
			// Cart recommendations.
			'recs_enabled'          => 'no',
			'recs_count'            => '4',
			// Welcome coupon newsletter.
			'nl_enabled'            => 'no',
			'nl_footer'             => 'no',
			'nl_title'              => 'Bültene katılın',
			'nl_consent_text'       => 'Kampanya ve duyuru e-postaları almayı kabul ediyorum.',
			'nl_mode'               => 'generated',
			'nl_fixed_code'         => '',
			'nl_prefix'             => 'BULTEN',
			'nl_type'               => 'percent',
			'nl_amount'             => '8',
			'nl_min_cart'           => '',
			'nl_days'               => '30',
			'nl_confirm_hours'      => '48',
			'retention_days'        => '730',
			'global_hourly'         => '60',
			// Timed cart coupon.
			'tc_enabled'            => 'no',
			'tc_on_checkout'        => 'no',
			'tc_min_cart'           => '600',
			'tc_validity'           => '30',
			'tc_cooldown_hours'     => '24',
			'tc_prefix'             => 'SURELI',
			'tc_type'               => 'percent',
			'tc_amount'             => '5',
			'tc_ip_hourly'          => '5',
		);
	}

	/** @return array */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/** @param string $key Setting key. */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/** @param string $key Setting key. */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	/** Whether a module that creates coupons is on. */
	public static function coupons_on() {
		return self::is_on( 'nl_enabled' ) || self::is_on( 'tc_enabled' );
	}

	/** Test helper: merge values straight into the option. @param array $values Values. */
	public static function save_for_test( array $values ) {
		$saved = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( is_array( $saved ) ? $saved : array(), $values ), false );
	}

	/** Hooks. */
	public static function init() {
		add_filter( 'option_page_capability_' . self::GROUP, fn() => 'manage_woocommerce' );
		add_action(
			'admin_init',
			function () {
				register_setting(
					self::GROUP,
					self::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize' ),
						'default'           => array(),
					)
				);
			}
		);
		foreach ( array( 'update_option_', 'add_option_' ) as $eru_cb_prefix ) {
			add_action(
				$eru_cb_prefix . self::OPTION,
				function () {
					wp_set_option_autoload( self::OPTION, false );
					Eru_CB_Shipping::flush_cache();
					Eru_CB_Coupons::maybe_schedule( true );
				}
			);
		}
	}

	/** @param mixed $v Raw value. @return float Non-negative number, comma or dot decimal. */
	private static function num( $v ) {
		return max( 0, (float) str_replace( ',', '.', (string) $v ) );
	}

	/** @param mixed $v ID list as array or text. @return int[] */
	private static function ids( $v ) {
		$list = is_array( $v ) ? $v : preg_split( '/[\s,;]+/', (string) $v );
		return array_slice( array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) ), 0, 100 );
	}

	/** @param mixed $in Posted settings. @return array */
	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return self::all();
		}
		$in  = is_array( $in ) ? $in : array(); // options.php already unslashed.
		$def = self::defaults();
		$out = array();

		foreach ( array( 'ship_enabled', 'ship_on_cart', 'ship_on_checkout', 'recs_enabled', 'nl_enabled', 'nl_footer', 'tc_enabled', 'tc_on_checkout' ) as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] ) ? 'yes' : 'no';
		}

		$out['ship_threshold_mode']   = ( $in['ship_threshold_mode'] ?? 'zone' ) === 'manual' ? 'manual' : 'zone';
		$m                            = self::num( $in['ship_threshold_manual'] ?? '' );
		$out['ship_threshold_manual'] = $m > 0 ? (string) $m : '';
		foreach ( array( 'ship_text', 'ship_text_done', 'nl_title', 'nl_consent_text' ) as $k ) {
			$v         = sanitize_text_field( $in[ $k ] ?? '' );
			$out[ $k ] = '' !== $v ? $v : $def[ $k ];
		}
		$out['ship_gap_extra'] = (string) self::num( $in['ship_gap_extra'] ?? 0 );
		$out['ship_gap_count'] = (string) max( 1, min( 8, absint( $in['ship_gap_count'] ?? 3 ) ) );
		$out['recs_count']     = (string) max( 1, min( 8, absint( $in['recs_count'] ?? 4 ) ) );
		foreach ( array( 'ship_exclude_products', 'ship_manual_products' ) as $k ) {
			$out[ $k ] = self::ids( $in[ $k ] ?? array() );
		}
		$out['ship_exclude_cats'] = self::ids( $in['ship_exclude_cats'] ?? array() );

		$out['nl_mode']       = ( $in['nl_mode'] ?? 'generated' ) === 'fixed' ? 'fixed' : 'generated';
		$out['nl_fixed_code'] = wc_format_coupon_code( sanitize_text_field( $in['nl_fixed_code'] ?? '' ) );
		foreach ( array( 'nl_prefix', 'tc_prefix' ) as $k ) {
			$p         = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in[ $k ] ?? '' ) ) );
			$out[ $k ] = '' !== $p ? substr( $p, 0, 16 ) : $def[ $k ];
		}
		foreach ( array( 'nl_type', 'tc_type' ) as $k ) {
			$out[ $k ] = ( $in[ $k ] ?? 'percent' ) === 'fixed_cart' ? 'fixed_cart' : 'percent';
		}
		foreach ( array( 'nl_amount' => 'nl_type', 'tc_amount' => 'tc_type' ) as $k => $t ) {
			$a         = self::num( $in[ $k ] ?? 0 );
			$a         = 'percent' === $out[ $t ] ? min( 100, $a ) : $a;
			$out[ $k ] = (string) ( $a > 0 ? $a : $def[ $k ] );
		}
		$out['nl_min_cart']       = (string) ( self::num( $in['nl_min_cart'] ?? '' ) ?: '' );
		$out['nl_days']           = (string) max( 1, min( 365, absint( $in['nl_days'] ?? 30 ) ) );
		$out['retention_days']    = (string) max( 1, absint( $in['retention_days'] ?? 730 ) );
		$out['nl_confirm_hours']  = (string) max( 1, min( 720, absint( $in['nl_confirm_hours'] ?? 48 ) ) );
		$out['global_hourly']     = (string) max( 1, min( 10000, absint( $in['global_hourly'] ?? 60 ) ) );
		$out['tc_min_cart']       = (string) self::num( $in['tc_min_cart'] ?? 0 );
		$out['tc_validity']       = (string) max( 1, min( 1440, absint( $in['tc_validity'] ?? 30 ) ) );
		$out['tc_cooldown_hours'] = (string) max( 1, min( 720, absint( $in['tc_cooldown_hours'] ?? 24 ) ) );
		$out['tc_ip_hourly']      = (string) max( 1, min( 100, absint( $in['tc_ip_hourly'] ?? 5 ) ) );
		return $out;
	}
}
