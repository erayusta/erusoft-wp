<?php
/**
 * Everything one evaluation looks at: the lines, the customer, the cart facts and "now".
 *
 * "Now" is always given by the caller, already in the store's time zone. The engine never reads the
 * clock, which keeps evaluations repeatable and lets the admin preview a cart at any moment.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Context;

use DateTimeImmutable;

final class Context {

	/**
	 * @param Item[] $items
	 */
	public function __construct(
		public readonly array $items,
		public readonly Customer $customer,
		public readonly CartInfo $cart,
		public readonly DateTimeImmutable $now
	) {}

	/** The line with this key, or null. */
	public function item( string $key ): ?Item {
		foreach ( $this->items as $item ) {
			if ( $item->key === $key ) {
				return $item;
			}
		}

		return null;
	}

	/** Units in the cart. */
	public function totalQuantity(): int {
		$total = 0;

		foreach ( $this->items as $item ) {
			$total += $item->qty;
		}

		return $total;
	}
}
