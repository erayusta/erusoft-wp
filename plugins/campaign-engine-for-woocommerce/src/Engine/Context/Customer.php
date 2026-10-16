<?php
/**
 * What the engine knows about the shopper: identity flags and order history figures.
 *
 * The history (order count, total spent, first/last order time) is computed by the WooCommerce layer
 * from paid orders; the engine only compares it. A guest is id 0 with no history.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Context;

use Erusoft\CampaignEngine\Engine\Args;

final class Customer {

	/**
	 * @param string[] $roles
	 */
	public function __construct(
		public readonly int $id = 0,
		public readonly bool $logged_in = false,
		public readonly array $roles = array(),
		public readonly int $order_count = 0,
		public readonly int $total_spent_minor = 0,
		public readonly ?int $first_order_ts = null,
		public readonly ?int $last_order_ts = null
	) {}

	/** A guest without history. */
	public static function guest(): self {
		return new self();
	}

	/**
	 * @param array<string,mixed> $row Keys are the constructor's parameter names.
	 */
	public static function fromArray( array $row ): self {
		return new self(
			max( 0, (int) ( $row['id'] ?? 0 ) ),
			(bool) ( $row['logged_in'] ?? false ),
			array_map( 'strtolower', Args::strings( $row['roles'] ?? array() ) ),
			max( 0, (int) ( $row['order_count'] ?? 0 ) ),
			max( 0, (int) ( $row['total_spent_minor'] ?? 0 ) ),
			isset( $row['first_order_ts'] ) ? (int) $row['first_order_ts'] : null,
			isset( $row['last_order_ts'] ) ? (int) $row['last_order_ts'] : null
		);
	}

	/** True when this order would be the customer's first paid order. */
	public function isFirstOrder(): bool {
		return 0 === $this->order_count;
	}
}
