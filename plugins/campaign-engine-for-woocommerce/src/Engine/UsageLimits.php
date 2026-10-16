<?php
/**
 * Remaining uses per campaign, worked out by the store before evaluating.
 *
 * The engine does not count anything: the WooCommerce layer reads its usage records (overall and per
 * customer, reservations included), takes the smaller remainder and passes it here. A campaign with no
 * entry is unlimited; an entry of 0 or less withdraws the campaign from this evaluation.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

final class UsageLimits {

	/** @var array<int,int> */
	private array $remaining = array();

	/**
	 * @param array<int|string,int|string> $remaining campaign id => uses left.
	 */
	public function __construct( array $remaining = array() ) {
		foreach ( $remaining as $id => $left ) {
			$this->remaining[ (int) $id ] = (int) $left;
		}
	}

	/** No limits at all. */
	public static function none(): self {
		return new self();
	}

	public function allows( int $campaign_id ): bool {
		return ! isset( $this->remaining[ $campaign_id ] ) || $this->remaining[ $campaign_id ] > 0;
	}

	/** Uses left, or null when unlimited. */
	public function remaining( int $campaign_id ): ?int {
		return $this->remaining[ $campaign_id ] ?? null;
	}
}
