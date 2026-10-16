<?php
/**
 * Page-cache purge after campaign changes. Campaigns change prices on product, category and cart pages,
 * so a full-page cache must forget them when a campaign starts, stops or changes.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Cache;

interface CacheAdaptor {

	/** @param int[] $product_ids */
	public function purgeProducts( array $product_ids ): void;

	public function purgeAll(): void;
}
