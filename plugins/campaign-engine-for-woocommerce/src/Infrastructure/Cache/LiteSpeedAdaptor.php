<?php
/**
 * LiteSpeed Cache adaptor: fires that plugin's public purge actions. Nothing happens when LiteSpeed
 * Cache is not installed, because nobody listens to the actions.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Cache;

final class LiteSpeedAdaptor implements CacheAdaptor {

	public function purgeProducts( array $product_ids ): void {
		foreach ( $product_ids as $id ) {
			do_action( 'litespeed_purge_post', (int) $id );
		}
	}

	public function purgeAll(): void {
		do_action( 'litespeed_purge_all' );
	}
}
