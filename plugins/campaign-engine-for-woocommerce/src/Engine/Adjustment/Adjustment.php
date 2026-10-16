<?php
/**
 * One campaign's effect on one cart line, or a non-line effect.
 *
 * Levels:
 *  - 'item'     a discount on a line; `discount_minor` is for the WHOLE line (all units);
 *  - 'shipping' free shipping (meta['free_shipping'] = true), no amount, `item_key` empty;
 *  - 'reward'   a future reward earned by this cart (see FutureRewardAction), no amount.
 *
 * Cart-level actions (cart percent/fixed) are spread onto lines and arrive here as 'item' adjustments,
 * so tax, refunds and per-product reports always know which product carried which discount.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Adjustment;

final class Adjustment {

	public const LEVEL_ITEM     = 'item';
	public const LEVEL_SHIPPING = 'shipping';
	public const LEVEL_REWARD   = 'reward';

	/**
	 * @param array<string,mixed> $meta Action details (per-unit figure, rewarded units, reward terms…).
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly string $item_key,
		public readonly int $discount_minor,
		public readonly string $level = self::LEVEL_ITEM,
		public readonly array $meta = array(),
		public readonly string $label = '',
		public readonly int $campaign_version = 1,
		public readonly int $priority = 0
	) {}

	/** The same adjustment with another amount. */
	public function withDiscount( int $discount_minor ): self {
		return new self( $this->campaign_id, $this->item_key, max( 0, $discount_minor ), $this->level, $this->meta, $this->label, $this->campaign_version, $this->priority );
	}

	/** @return array<string,mixed> A plain array for order snapshots and JSON. */
	public function toArray(): array {
		return array(
			'campaign_id'      => $this->campaign_id,
			'campaign_version' => $this->campaign_version,
			'item_key'         => $this->item_key,
			'discount_minor'   => $this->discount_minor,
			'level'            => $this->level,
			'label'            => $this->label,
			'meta'             => $this->meta,
		);
	}
}
