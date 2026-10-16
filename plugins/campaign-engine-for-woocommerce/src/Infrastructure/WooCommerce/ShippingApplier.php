<?php
/**
 * Free-shipping campaigns: every rate of a package costs 0 while a free-shipping campaign applies.
 *
 * The ids of those campaigns are written into each shipping package, so WooCommerce's cached package
 * rates (keyed by a hash of the package) are recalculated when the cart crosses a threshold in either
 * direction. With no such campaign nothing is added and the package hash is untouched.
 *
 * The filter `eru_ce_free_shipping_rate` (bool, WC_Shipping_Rate, package) can keep a rate paid,
 * e.g. an express option.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use WC_Shipping_Rate;

final class ShippingApplier {

	public const PACKAGE_KEY = 'eru_ce_free_shipping';

	public static function hooks(): void {
		add_filter( 'woocommerce_cart_shipping_packages', array( self::class, 'packages' ), 20 );
		add_filter( 'woocommerce_package_rates', array( self::class, 'rates' ), CartApplier::pricePriority(), 2 );
	}

	/**
	 * @param array<int,array<string,mixed>> $packages
	 * @return array<int,array<string,mixed>>
	 */
	public static function packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		$result = CartApplier::resultFor( CartApplier::current() );
		$ids    = $result ? $result->adjustments->freeShippingCampaignIds() : array();

		if ( array() === $ids ) {
			return $packages;
		}

		sort( $ids );

		foreach ( $packages as $i => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $i ][ self::PACKAGE_KEY ] = $ids;
			}
		}

		return $packages;
	}

	/**
	 * @param array<string,WC_Shipping_Rate> $rates
	 * @param array<string,mixed>            $package
	 * @return array<string,WC_Shipping_Rate>
	 */
	public static function rates( $rates, $package ) {
		if ( ! is_array( $rates ) || ! is_array( $package ) || empty( $package[ self::PACKAGE_KEY ] ) ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate || ! apply_filters( 'eru_ce_free_shipping_rate', true, $rate, $package ) ) {
				continue;
			}

			$rate->set_cost( '0' );
			$rate->set_taxes( array() );
			$rate->add_meta_data( '_eru_ce_free_shipping', implode( ',', array_map( 'intval', (array) $package[ self::PACKAGE_KEY ] ) ) );
		}

		return $rates;
	}
}
