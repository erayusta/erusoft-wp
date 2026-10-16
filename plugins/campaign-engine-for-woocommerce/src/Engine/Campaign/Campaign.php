<?php
/**
 * A campaign as the engine consumes it: read-only, built once from the stored row.
 *
 * Fields:
 *  - `rules`    AND/OR condition tree (see RuleTree), null = no conditions;
 *  - `actions`  list of action rows, each ['type' => 'product_percent', …args];
 *  - `targets`  include/exclude lists (see Targets);
 *  - `schedule` season and recurrence (see Schedule);
 *  - `priority` higher runs first and wins ties; equal priorities fall back to the lower id;
 *  - `exclusive` when it applies, no other campaign applies to the same cart ("tek başına");
 *  - `basis`    which price discounts are computed from: 'current' (what the customer pays now),
 *               'regular' (the catalogue price; the result is a target price that only counts when it
 *               beats the current sale price) or 'exclude_sale' (lines on sale are left alone);
 *  - `max_discount_minor` cap on what this campaign gives per cart, null = none;
 *  - `version`  bumped by the store on every save, copied into adjustments for order snapshots;
 *  - `meta`     anything the WooCommerce or presentation layer wants to carry along (badge text…).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine\Campaign;

use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;

final class Campaign {

	public const BASES = array( 'current', 'regular', 'exclude_sale' );

	public readonly Targets $targets;
	public readonly Schedule $schedule;

	/**
	 * @param array<int,array<string,mixed>> $actions
	 * @param array<string,mixed>|null       $rules
	 * @param array<string,mixed>            $meta
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $label,
		public readonly array $actions,
		public readonly ?array $rules = null,
		?Targets $targets = null,
		?Schedule $schedule = null,
		public readonly int $priority = 10,
		public readonly bool $exclusive = false,
		public readonly string $basis = 'current',
		public readonly ?int $max_discount_minor = null,
		public readonly int $version = 1,
		public readonly array $meta = array()
	) {
		$this->targets  = $targets ?? Targets::all();
		$this->schedule = $schedule ?? Schedule::always();
	}

	/**
	 * Builds a campaign from a stored row. Unknown basis values fall back to 'current' (the narrowest
	 * reading); action rows that are not arrays are ignored. A malformed discount cap or target id
	 * throws ArgumentError instead of being dropped, because dropping either would widen the campaign
	 * (no cap, or storewide). The store validates on save and skips rows that still throw.
	 *
	 * @param array<string,mixed> $row
	 * @throws ArgumentError
	 */
	public static function fromArray( array $row ): self {
		$basis   = is_string( $row['basis'] ?? null ) ? $row['basis'] : 'current';
		$raw_cap = $row['max_discount_minor'] ?? null;
		$cap     = null === $raw_cap || '' === $raw_cap ? null : Args::minor( $raw_cap, 'max_discount_minor' );
		$actions = array();

		foreach ( (array) ( $row['actions'] ?? array() ) as $action ) {
			if ( is_array( $action ) ) {
				$actions[] = $action;
			}
		}

		$targets  = $row['targets'] ?? null;
		$schedule = $row['schedule'] ?? null;
		$id       = max( 0, (int) ( $row['id'] ?? 0 ) );

		return new self(
			$id,
			is_string( $row['label'] ?? null ) && '' !== $row['label'] ? $row['label'] : '#' . $id,
			$actions,
			is_array( $row['rules'] ?? null ) ? $row['rules'] : null,
			$targets instanceof Targets ? $targets : ( is_array( $targets ) ? Targets::fromArray( $targets ) : null ),
			$schedule instanceof Schedule ? $schedule : ( is_array( $schedule ) ? Schedule::fromArray( $schedule ) : null ),
			(int) ( $row['priority'] ?? 10 ),
			Args::flag( $row['exclusive'] ?? null ),
			in_array( $basis, self::BASES, true ) ? $basis : 'current',
			$cap,
			max( 1, (int) ( $row['version'] ?? 1 ) ),
			is_array( $row['meta'] ?? null ) ? $row['meta'] : array()
		);
	}

	/**
	 * The action rows of one type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function actionsOfType( string $type ): array {
		return array_values( array_filter( $this->actions, static fn( array $a ): bool => ( $a['type'] ?? '' ) === $type ) );
	}
}
