<?php
/**
 * Custom adaptor: fires `eru_ce_cache_purge` so any other cache can listen.
 *
 *   add_action( 'eru_ce_cache_purge', function ( array $product_ids, bool $all ) { … }, 10, 2 );
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Cache;

final class CustomAdaptor implements CacheAdaptor {

	public function purgeProducts( array $product_ids ): void {
		do_action( 'eru_ce_cache_purge', array_map( 'intval', $product_ids ), false );
	}

	public function purgeAll(): void {
		do_action( 'eru_ce_cache_purge', array(), true );
	}
}
