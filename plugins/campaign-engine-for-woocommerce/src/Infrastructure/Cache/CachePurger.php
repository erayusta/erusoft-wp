<?php
/**
 * Picks the adaptor from the `cache_adaptor` setting (none | litespeed | custom); the filter
 * `eru_ce_cache_adaptor` may return any CacheAdaptor instead. An empty product list purges everything,
 * as does a list longer than MAX_PRODUCTS.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Cache;

use Erusoft\CampaignEngine\Infrastructure\Settings\Options;

final class CachePurger {

	private const MAX_PRODUCTS = 200;

	public static function adaptor(): CacheAdaptor {
		switch ( Options::get( 'cache_adaptor' ) ) {
			case 'litespeed':
				$adaptor = new LiteSpeedAdaptor();
				break;
			case 'custom':
				$adaptor = new CustomAdaptor();
				break;
			default:
				$adaptor = new NoneAdaptor();
		}

		/**
		 * Filters the cache adaptor.
		 *
		 * @param CacheAdaptor $adaptor Adaptor chosen by the setting.
		 */
		$filtered = apply_filters( 'eru_ce_cache_adaptor', $adaptor );

		return $filtered instanceof CacheAdaptor ? $filtered : $adaptor;
	}

	/** @param int[] $product_ids empty = everything */
	public static function purge( array $product_ids ): void {
		$adaptor = self::adaptor();

		if ( array() === $product_ids || count( $product_ids ) > self::MAX_PRODUCTS ) {
			$adaptor->purgeAll();

			return;
		}

		$adaptor->purgeProducts( $product_ids );
	}
}
