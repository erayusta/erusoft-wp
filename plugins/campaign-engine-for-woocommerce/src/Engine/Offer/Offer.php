<?php
/**
 * One "add a little more" suggestion for the cart, e.g. "1 adet daha ekleyin, %30 kazanın".
 *
 * `current` and `target` are minor units for KIND_AMOUNT and units for KIND_QUANTITY; `effort_minor`
 * is always money (for quantities: missing units × the cheapest eligible unit) so offers of both kinds
 * can be ranked together. `value_minor` is the expected gain at the target; when it cannot be known in
 * advance (`value_known` false) the storefront shows the reward without an amount. `reward` describes
 * the reward for the sentence: ['type' => 'percent'|'fixed'|'free'|'price'|'free_shipping'|'reward_coupon',
 * 'value' => string]. `item_key` names the cart line to add to when the offer is about one product.
 * The engine builds no text; the presentation layer does, with translatable strings.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Offer;

final class Offer {

	public const KIND_AMOUNT   = 'amount';
	public const KIND_QUANTITY = 'quantity';

	/**
	 * @param array{type:string,value:string} $reward
	 */
	public function __construct(
		public readonly int $campaign_id,
		public readonly string $kind,
		public readonly int $current,
		public readonly int $target,
		public readonly int $effort_minor,
		public readonly int $value_minor,
		public readonly bool $value_known,
		public readonly array $reward,
		public readonly ?string $item_key,
		public readonly string $label
	) {}

	/** What is still missing: minor units or units, depending on the kind. */
	public function missing(): int {
		return max( 0, $this->target - $this->current );
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return array(
			'campaign_id'  => $this->campaign_id,
			'kind'         => $this->kind,
			'current'      => $this->current,
			'target'       => $this->target,
			'missing'      => $this->missing(),
			'effort_minor' => $this->effort_minor,
			'value_minor'  => $this->value_minor,
			'value_known'  => $this->value_known,
			'reward'       => $this->reward,
			'item_key'     => $this->item_key,
			'label'        => $this->label,
		);
	}
}
