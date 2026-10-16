<?php
/**
 * Works out which lines of a cart a campaign may act on: targets first, then the rule tree in two
 * passes (see RuleTree). Shared by the evaluator and the offer finder.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Condition\ConditionRegistry;
use Erusoft\CampaignEngine\Engine\Condition\RuleTree;
use Erusoft\CampaignEngine\Engine\Context\Context;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\Context\MatchResult;
use Erusoft\CampaignEngine\Engine\Context\Scope;

final class Matcher {

	public const OK         = 'ok';
	public const NO_SCOPE   = 'no_scope';
	public const CONDITIONS = 'conditions';

	public function __construct( private readonly ConditionRegistry $conditions ) {}

	public function conditions(): ConditionRegistry {
		return $this->conditions;
	}

	/**
	 * Lines inside the campaign's targets.
	 *
	 * @return Item[]
	 */
	public function scoped( Campaign $campaign, Context $context ): array {
		return array_values( array_filter( $context->items, static fn( Item $item ): bool => $campaign->targets->matches( $item ) ) );
	}

	/**
	 * Matches with the campaign's own rules, or with $rules when given (the offer finder passes a
	 * copy with one threshold relaxed).
	 *
	 * @param array<string,mixed>|null $rules
	 * @return array{status:string,pass:string,scoped:Item[],eligible:Item[]} `pass` is 'item' or 'cart' on a
	 *         conditions failure, '' otherwise.
	 */
	public function match( Campaign $campaign, Context $context, ?array $rules = null, bool $override = false ): array {
		$rules  = $override ? $rules : $campaign->rules;
		$scoped = $this->scoped( $campaign, $context );
		$out    = array(
			'status'   => self::OK,
			'pass'     => '',
			'scoped'   => $scoped,
			'eligible' => array(),
		);

		if ( array() === $scoped ) {
			$out['status'] = self::NO_SCOPE;

			return $out;
		}

		$first    = new Scope( $context, new MatchResult( $scoped ) );
		$eligible = array();

		foreach ( $scoped as $item ) {
			if ( RuleTree::evaluate( $rules, $this->conditions, $first, $item ) ) {
				$eligible[] = $item;
			}
		}

		if ( array() === $eligible ) {
			$out['status'] = self::CONDITIONS;
			$out['pass']   = 'item';

			return $out;
		}

		if ( ! RuleTree::evaluateCart( $rules, $this->conditions, new Scope( $context, new MatchResult( $eligible ) ) ) ) {
			$out['status'] = self::CONDITIONS;
			$out['pass']   = 'cart';

			return $out;
		}

		$out['eligible'] = $eligible;

		return $out;
	}
}
