<?php
/**
 * Runs campaigns against a context and returns the resolved adjustments.
 *
 * Per campaign, in priority order, each gate either passes or drops the campaign with a trace code:
 *   1. schedule   active at the context's "now"                       (outside_schedule)
 *   2. coupons    a coupon in the cart blocks campaigns when the store says so  (coupon_in_cart)
 *   3. scope      at least one line inside the campaign's targets      (no_scope)
 *   4. usage      uses left, as given by the caller                    (usage_limit)
 *   5. conditions the rule tree, item pass then cart pass              (conditions)
 *   6. actions    every action type known, its arguments valid, at least one adjustment
 *                 (unknown_action, invalid_args, no_discount)
 * The usage gate comes after scope on purpose: campaigns unrelated to this cart are not reported as
 * "used up". The surviving candidates go to the Resolver.
 *
 * Usage example:
 *   $result = Evaluator::withDefaults( Settings::fromArray( $stored ) )->evaluate( $context, $campaigns, $limits );
 *   $result->adjustments->lineDiscount( $cart_item_key );
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

use Erusoft\CampaignEngine\Engine\Action\ActionRegistry;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Condition\ConditionRegistry;
use Erusoft\CampaignEngine\Engine\Context\Context;
use Erusoft\CampaignEngine\Engine\Context\MatchResult;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class Evaluator {

	private Matcher $matcher;

	public function __construct(
		private readonly ConditionRegistry $conditions,
		private readonly ActionRegistry $actions,
		private readonly Settings $settings
	) {
		$this->matcher = new Matcher( $conditions );
	}

	/** An evaluator with every built-in condition and action. */
	public static function withDefaults( ?Settings $settings = null ): self {
		return new self( ConditionRegistry::withDefaults(), ActionRegistry::withDefaults(), $settings ?? new Settings() );
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function conditions(): ConditionRegistry {
		return $this->conditions;
	}

	public function actions(): ActionRegistry {
		return $this->actions;
	}

	/**
	 * @param Campaign[] $campaigns
	 */
	public function evaluate( Context $context, array $campaigns, ?UsageLimits $usage = null, ?Trace $trace = null ): EvaluationResult {
		$trace      = $trace ?? new Trace();
		$usage      = $usage ?? UsageLimits::none();
		$candidates = array();

		foreach ( self::ordered( $campaigns ) as $campaign ) {
			$candidate = $this->candidate( $context, $campaign, $usage, $trace );

			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		$resolver = new Resolver( $this->settings );

		return new EvaluationResult( $resolver->resolve( $context, $candidates, $trace ), $trace, $candidates );
	}

	/** One campaign through the gates; null when it was dropped (the trace says why). */
	public function candidate( Context $context, Campaign $campaign, UsageLimits $usage, Trace $trace ): ?Candidate {
		$id = $campaign->id;

		if ( ! $campaign->schedule->isActiveAt( $context->now ) ) {
			$trace->add( $id, 'schedule', 'fail', 'outside_schedule' );

			return null;
		}

		$trace->add( $id, 'schedule', 'pass' );

		if ( ! $this->settings->with_coupons && array() !== $context->cart->coupons ) {
			$trace->add( $id, 'coupons', 'fail', 'coupon_in_cart' );

			return null;
		}

		$match = $this->matcher->match( $campaign, $context );

		if ( Matcher::NO_SCOPE === $match['status'] ) {
			$trace->add( $id, 'scope', 'fail', 'no_scope' );

			return null;
		}

		$trace->add( $id, 'scope', 'pass', 'ok', array( 'lines' => count( $match['scoped'] ) ) );

		if ( ! $usage->allows( $id ) ) {
			$trace->add( $id, 'usage', 'fail', 'usage_limit' );

			return null;
		}

		if ( Matcher::OK !== $match['status'] ) {
			$trace->add( $id, 'conditions', 'fail', 'conditions', array( 'pass' => $match['pass'] ) );

			return null;
		}

		$trace->add( $id, 'conditions', 'pass', 'ok', array( 'lines' => count( $match['eligible'] ) ) );

		$scope       = new Scope( $context, new MatchResult( $match['eligible'] ) );
		$adjustments = array();

		foreach ( $campaign->actions as $row ) {
			$type   = is_string( $row['type'] ?? null ) ? $row['type'] : '';
			$action = $this->actions->get( $type );

			if ( null === $action ) {
				$trace->add( $id, 'action', 'fail', 'unknown_action', array( 'type' => $type ) );

				return null;
			}

			try {
				$built = $action->build( $scope, $campaign, $row );
			} catch ( ArgumentError $e ) {
				$trace->add(
					$id,
					'action',
					'fail',
					'invalid_args',
					array(
						'type'   => $type,
						'field'  => $e->field,
						'reason' => $e->reason,
					)
				);

				return null;
			}

			foreach ( $built as $adjustment ) {
				$adjustments[] = $adjustment;
			}
		}

		if ( array() === $adjustments ) {
			$trace->add( $id, 'action', 'fail', 'no_discount' );

			return null;
		}

		$trace->add( $id, 'action', 'pass' );

		return new Candidate( $campaign, $adjustments );
	}

	/**
	 * Campaigns by priority (high first), then id (low first).
	 *
	 * @param Campaign[] $campaigns
	 * @return Campaign[]
	 */
	public static function ordered( array $campaigns ): array {
		$campaigns = array_values( array_filter( $campaigns, static fn( $c ): bool => $c instanceof Campaign ) );

		usort(
			$campaigns,
			static fn( Campaign $a, Campaign $b ): int => $a->priority !== $b->priority ? $b->priority <=> $a->priority : $a->id <=> $b->id
		);

		return $campaigns;
	}
}
