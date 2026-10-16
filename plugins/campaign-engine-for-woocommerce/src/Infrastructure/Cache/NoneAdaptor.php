<?php
/**
 * Default adaptor: the site has no page cache to purge (or purges it some other way).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Cache;

final class NoneAdaptor implements CacheAdaptor {

	public function purgeProducts( array $product_ids ): void {}

	public function purgeAll(): void {}
}
