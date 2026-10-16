<?php
/**
 * Product Push for Trendyol bridge: lends this plugin's Trendyol credentials to Product Push for Trendyol.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Push for Trendyol reads its credentials through its own eru_ppt_credentials filter
 * (seller_id, api_key, api_secret, integration_ref). When the Trendyol setting "Trendyol bilgilerini Product Push
 * ile paylaş" (eru_mpo_mp_trendyol[share_ppt]) is on and Product Push is active, this plugin fills the keys Product
 * Push left empty, and only those:
 *
 * - a key Product Push has set is never changed;
 * - nothing is filled unless every key Product Push has set (seller ID, API key, API secret, integration reference,
 *   the key's owner) equals this plugin's value: a value of another account means the two must not be mixed;
 * - the integration reference is also filled when Product Push still has its placeholder "SelfIntegration" and the
 *   seller ID itself came from here (same account, so the same User-Agent).
 *
 * The values never leave the filter: nothing is stored on the Product Push side, nothing is logged.
 */
class Eru_MPO_PPT_Bridge {

	const KEYS = array( 'seller_id', 'api_key', 'api_secret', 'integration_ref' );

	/** Hooks. */
	public static function init() {
		add_filter( 'eru_ppt_credentials', array( __CLASS__, 'fill' ), 20 );
	}

	/** @return bool The share setting is on. */
	public static function enabled() {
		return 'yes' === (string) Eru_MPO_Settings::mp_get( Eru_MPO_Adapter_Trendyol::KEY, 'share_ppt', 'no' );
	}

	/** @return bool Product Push for Trendyol is active (filter eru_mpo_ppt_active). */
	public static function ppt_active() {
		return (bool) apply_filters( 'eru_mpo_ppt_active', defined( 'ERU_PPT_VERSION' ) || class_exists( 'Eru_PPT_Settings', false ) );
	}

	/**
	 * eru_ppt_credentials.
	 *
	 * @param mixed $c Product Push credentials.
	 * @return mixed The same array with its empty keys filled, or the input unchanged.
	 */
	public static function fill( $c ) {
		if ( ! is_array( $c ) || ! self::enabled() || ! self::ppt_active() ) {
			return $c;
		}
		$own = Eru_MPO_Adapter_Trendyol::credentials();
		foreach ( self::KEYS as $k ) {
			$theirs = trim( (string) ( $c[ $k ] ?? '' ) );
			if ( 'integration_ref' === $k && Eru_MPO_Adapter_Trendyol::DEFAULT_REF === $theirs ) {
				continue; // Product Push's placeholder, not a value of its own.
			}
			if ( '' !== $theirs && $theirs !== $own[ $k ] ) {
				return $c; // Product Push holds a credential of another account: never mix the two.
			}
		}
		$filled_seller = false;
		foreach ( self::KEYS as $k ) {
			if ( '' === trim( (string) ( $c[ $k ] ?? '' ) ) && '' !== $own[ $k ] ) {
				$c[ $k ]       = $own[ $k ];
				$filled_seller = $filled_seller || 'seller_id' === $k;
			}
		}
		if ( $filled_seller && '' !== $own['integration_ref'] && Eru_MPO_Adapter_Trendyol::DEFAULT_REF === (string) ( $c['integration_ref'] ?? '' ) ) {
			$c['integration_ref'] = $own['integration_ref'];
		}
		return $c;
	}
}
