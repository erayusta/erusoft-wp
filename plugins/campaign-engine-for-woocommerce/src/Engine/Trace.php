<?php
/**
 * Step-by-step record of an evaluation, for previews, the status screen and the CLI.
 *
 * Entries hold codes, not sentences: the engine has no translation layer, so the admin side turns
 * `code` (and `data`) into Turkish text. Steps and codes:
 *   schedule/outside_schedule, coupons/coupon_in_cart, scope/no_scope, usage/usage_limit,
 *   conditions/conditions, action/unknown_action, action/invalid_args (data: type, field, reason),
 *   action/no_discount, exclusive/exclusive (data: winner) on each excluded campaign,
 *   exclusive/excluded_others (info, data: excluded ids) on the winner, stacking/stacking,
 *   stacking/lost_all_lines (best/first: no line kept, so shipping and rewards dropped too),
 *   campaign_cap/campaign_cap, cart_cap/cart_cap, min_price/min_price, min_price/line_missing;
 *   a passing step is recorded with status 'pass' and code 'ok'.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

final class Trace {

	public const STATUSES = array( 'pass', 'fail', 'info' );

	/** @var array<int,array{campaign_id:int,step:string,status:string,code:string,data:array<string,mixed>}> */
	private array $entries = array();

	/**
	 * @param array<string,mixed> $data
	 */
	public function add( int $campaign_id, string $step, string $status, string $code = 'ok', array $data = array() ): void {
		$this->entries[] = array(
			'campaign_id' => $campaign_id,
			'step'        => $step,
			'status'      => in_array( $status, self::STATUSES, true ) ? $status : 'info',
			'code'        => $code,
			'data'        => $data,
		);
	}

	/** @return array<int,array{campaign_id:int,step:string,status:string,code:string,data:array<string,mixed>}> */
	public function entries(): array {
		return $this->entries;
	}

	/** @return array<int,array{campaign_id:int,step:string,status:string,code:string,data:array<string,mixed>}> */
	public function forCampaign( int $campaign_id ): array {
		return array_values( array_filter( $this->entries, static fn( array $e ): bool => $e['campaign_id'] === $campaign_id ) );
	}

	/**
	 * Campaigns removed by an exclusive campaign: excluded id => winning id.
	 *
	 * @return array<int,int>
	 */
	public function excludedBy(): array {
		$out = array();

		foreach ( $this->entries as $entry ) {
			if ( 'exclusive' === $entry['code'] && 'fail' === $entry['status'] ) {
				$out[ $entry['campaign_id'] ] = (int) ( $entry['data']['winner'] ?? 0 );
			}
		}

		return $out;
	}

	/**
	 * The first failing entry of a campaign, or null when it was never dropped.
	 *
	 * @return array{campaign_id:int,step:string,status:string,code:string,data:array<string,mixed>}|null
	 */
	public function failureFor( int $campaign_id ): ?array {
		foreach ( $this->forCampaign( $campaign_id ) as $entry ) {
			if ( 'fail' === $entry['status'] ) {
				return $entry;
			}
		}

		return null;
	}
}
