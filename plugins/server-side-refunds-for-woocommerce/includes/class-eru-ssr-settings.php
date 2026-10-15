<?php
/**
 * Settings access and the WooCommerce integration screen.
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the integration option (woocommerce_eru_ssr_settings) with defaults.
 */
class Eru_SSR_Settings {

	const OPTION = 'woocommerce_eru_ssr_settings';

	const DEFAULTS = array(
		'ga4_enabled'        => 'no',
		'ga4_measurement_id' => '',
		'ga4_api_secret'     => '',
		'matomo_enabled'     => 'no',
		'matomo_url'         => '',
		'matomo_site_id'     => '',
		'matomo_token'       => '',
		'matomo_mode'        => 'a',
		'ev_partial'         => 'yes',
		'ev_full'            => 'yes',
		'ev_cancelled'       => 'yes',
		'ev_failed'          => 'yes',
		'send_user_id'       => 'no',
		'fallback_client_id' => 'yes',
		'retention_days'     => '30',
	);

	/** @return array */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
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

	/** Matomo accepts https, or http only for a local host. */
	public static function valid_matomo_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( empty( $p['host'] ) || empty( $p['scheme'] ) ) {
			return false;
		}
		if ( 'https' === $p['scheme'] ) {
			return true;
		}
		$host = strtolower( $p['host'] );
		return 'http' === $p['scheme'] && ( 'localhost' === $host || '127.0.0.1' === $host || '[::1]' === $host || str_ends_with( $host, '.localhost' ) );
	}

	/** Registers the integration. */
	public static function register() {
		add_filter(
			'woocommerce_integrations',
			function ( $integrations ) {
				require_once ERU_SSR_PATH . 'includes/class-eru-ssr-integration.php';
				$integrations[] = 'Eru_SSR_Integration';
				return $integrations;
			}
		);
	}
}
