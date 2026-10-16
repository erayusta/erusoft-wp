<?php
/**
 * Adapter registry (filter eru_mpo_adapters).
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The filter receives and returns an array of key => Eru_MPO_Adapter. Entries that are not adapters, or whose
 * key() differs from the array key or is not [a-z0-9_]{2,20}, are dropped.
 */
class Eru_MPO_Registry {

	/** @var array<string,Eru_MPO_Adapter>|null */
	private static $adapters = null;

	/** @return array<string,Eru_MPO_Adapter> */
	public static function all() {
		if ( null === self::$adapters ) {
			$in             = apply_filters( 'eru_mpo_adapters', array() );
			self::$adapters = array();
			foreach ( is_array( $in ) ? $in : array() as $key => $a ) {
				if ( $a instanceof Eru_MPO_Adapter && is_string( $key ) && $a->key() === $key && preg_match( '/^[a-z0-9_]{2,20}$/', $key ) ) {
					self::$adapters[ $key ] = $a;
				}
			}
		}
		return self::$adapters;
	}

	/** @param string $key Key. @return Eru_MPO_Adapter|null */
	public static function get( $key ) {
		return self::all()[ (string) $key ] ?? null;
	}

	/** Forgets the resolved list (after adding a filter late, and in tests). */
	public static function reset() {
		self::$adapters = null;
	}
}
