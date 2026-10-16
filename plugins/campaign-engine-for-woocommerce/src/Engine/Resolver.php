<?php
/**
 * Turns candidates into the final adjustment set.
 *
 * Steps, in order:
 *   1. exclusive  when an exclusive campaign is among the candidates, the highest-priority exclusive
 *                 one (lower id on a tie) is the only campaign left — its free shipping and rewards
 *                 included. The shop manager's priority is respected as set: an exclusive campaign
 *                 that only gives free shipping or a future reward still excludes every discount,
 *                 and so does one with a lower priority than a non-exclusive campaign. The admin
 *                 screens warn about this; the trace records `exclusive` on each excluded campaign
 *                 and `excluded_others` on the winner;
 *   2. stacking   per line, by the store's policy (see Settings): best / first / stack;
 *   3. campaign cap  a campaign's `max_discount_minor` is shared back over its lines;
 *   4. cart cap   `max_cart_discount_percent` of the cart subtotal (rounded down), shared back over
 *                 every line;
 *   5. line floor no line goes below `min_unit_price_minor` × quantity, nor below zero;
 *   6. losers     under best/first, a campaign that proposed line discounts but kept none after
 *                 steps 2–5 also loses its free shipping and rewards (trace `lost_all_lines`) and is
 *                 not among the applied campaigns. Pure shipping/reward campaigns are unaffected;
 *                 under `stack` nothing is dropped here.
 * Every reduction is shared with Money::distribute, so amounts stay exact to the minor unit. Shipping
 * and reward adjustments take part only in steps 1 and 6.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Engine;

use Erusoft\CampaignEngine\Engine\Adjustment\Adjustment;
use Erusoft\CampaignEngine\Engine\Adjustment\AdjustmentSet;
use Erusoft\CampaignEngine\Engine\Context\Context;

final class Resolver {

	public function __construct( private readonly Settings $settings ) {}

	/**
	 * @param Candidate[] $candidates
	 */
	public function resolve( Context $context, array $candidates, Trace $trace ): AdjustmentSet {
		$candidates = array_values( array_filter( $candidates, static fn( Candidate $c ): bool => array() !== $c->adjustments ) );
		usort( $candidates, array( Candidate::class, 'compare' ) );

		$candidates = $this->exclusive( $candidates, $trace );

		$lines  = array();
		$others = array();

		foreach ( $candidates as $candidate ) {
			foreach ( $candidate->adjustments as $adjustment ) {
				if ( Adjustment::LEVEL_ITEM === $adjustment->level ) {
					$lines[] = $adjustment;
				} else {
					$others[] = $adjustment;
				}
			}
		}

		$lines = $this->stack( $lines, $trace );
		$lines = $this->campaignCaps( $lines, $candidates, $trace );
		$lines = $this->cartCap( $lines, $context, $trace );
		$lines = $this->floor( $lines, $context, $trace );

		if ( 'stack' !== $this->settings->stacking ) {
			$others = $this->dropPerksOfLosers( $candidates, $lines, $others, $trace );
		}

		return new AdjustmentSet( array_merge( $lines, $others ) );
	}

	/**
	 * Under best/first, a campaign that proposed line discounts but kept none (it lost every line,
	 * or caps and the floor reduced it to zero) did not apply, so its free shipping and rewards go
	 * too: "one campaign per line" must not let a losing campaign hand out perks. Campaigns that
	 * only ever give shipping or rewards are not affected.
	 *
	 * @param Candidate[]  $candidates
	 * @param Adjustment[] $lines  Final line adjustments.
	 * @param Adjustment[] $others Shipping and reward adjustments.
	 * @return Adjustment[] The others that stay.
	 */
	private function dropPerksOfLosers( array $candidates, array $lines, array $others, Trace $trace ): array {
		$kept = array();

		foreach ( $lines as $adjustment ) {
			$kept[ $adjustment->campaign_id ] = true;
		}

		$lost = array();

		foreach ( $candidates as $candidate ) {
			$id = $candidate->campaign->id;

			if ( isset( $kept[ $id ] ) ) {
				continue;
			}

			foreach ( $candidate->adjustments as $adjustment ) {
				if ( Adjustment::LEVEL_ITEM === $adjustment->level ) {
					$lost[ $id ] = true;
					$trace->add( $id, 'stacking', 'fail', 'lost_all_lines' );
					break;
				}
			}
		}

		return array_values( array_filter( $others, static fn( Adjustment $a ): bool => ! isset( $lost[ $a->campaign_id ] ) ) );
	}

	/**
	 * @param Candidate[] $candidates Already ordered.
	 * @return Candidate[]
	 */
	private function exclusive( array $candidates, Trace $trace ): array {
		$winner = null;

		foreach ( $candidates as $candidate ) {
			if ( $candidate->campaign->exclusive ) {
				$winner = $candidate;
				break;
			}
		}

		if ( null === $winner ) {
			return $candidates;
		}

		$excluded = array();

		foreach ( $candidates as $candidate ) {
			if ( $candidate !== $winner ) {
				$excluded[] = $candidate->campaign->id;
				$trace->add( $candidate->campaign->id, 'exclusive', 'fail', 'exclusive', array( 'winner' => $winner->campaign->id ) );
			}
		}

		if ( array() !== $excluded ) {
			$trace->add( $winner->campaign->id, 'exclusive', 'info', 'excluded_others', array( 'excluded' => $excluded ) );
		}

		return array( $winner );
	}

	/**
	 * Applies the stacking policy line by line. A campaign's several adjustments on one line count as
	 * one bid.
	 *
	 * @param Adjustment[] $lines
	 * @return Adjustment[]
	 */
	private function stack( array $lines, Trace $trace ): array {
		if ( 'stack' === $this->settings->stacking ) {
			return $lines;
		}

		/** @var array<string,array<int,array{sum:int,priority:int,list:Adjustment[]}>> $bids */
		$bids = array();

		foreach ( $lines as $adjustment ) {
			$bid = &$bids[ $adjustment->item_key ][ $adjustment->campaign_id ];

			if ( null === $bid ) {
				$bid = array(
					'sum'      => 0,
					'priority' => $adjustment->priority,
					'list'     => array(),
				);
			}

			$bid['sum']   += $adjustment->discount_minor;
			$bid['list'][] = $adjustment;
			unset( $bid );
		}

		$kept    = array();
		$winners = array();
		$losers  = array();

		foreach ( $bids as $item_key => $by_campaign ) {
			$best_id = null;

			foreach ( $by_campaign as $campaign_id => $bid ) {
				if ( null === $best_id || $this->better( $campaign_id, $bid, $best_id, $by_campaign[ $best_id ] ) ) {
					$best_id = $campaign_id;
				}
			}

			foreach ( $by_campaign as $campaign_id => $bid ) {
				if ( $campaign_id === $best_id ) {
					$winners[ $campaign_id ] = true;

					foreach ( $bid['list'] as $adjustment ) {
						$kept[] = $adjustment;
					}

					continue;
				}

				$losers[ $campaign_id ] = true;
				$trace->add( (int) $campaign_id, 'stacking', 'info', 'stacking', array( 'line' => (string) $item_key, 'winner' => $best_id ) );
			}
		}

		foreach ( array_keys( array_diff_key( $losers, $winners ) ) as $campaign_id ) {
			$trace->add( (int) $campaign_id, 'stacking', 'fail', 'stacking' );
		}

		return $kept;
	}

	/**
	 * Whether bid A beats bid B on one line under the 'best' or 'first' policy.
	 *
	 * @param array{sum:int,priority:int} $a
	 * @param array{sum:int,priority:int} $b
	 */
	private function better( int $a_id, array $a, int $b_id, array $b ): bool {
		if ( 'best' === $this->settings->stacking && $a['sum'] !== $b['sum'] ) {
			return $a['sum'] > $b['sum'];
		}

		if ( $a['priority'] !== $b['priority'] ) {
			return $a['priority'] > $b['priority'];
		}

		return $a_id < $b_id;
	}

	/**
	 * @param Adjustment[] $lines
	 * @param Candidate[]  $candidates
	 * @return Adjustment[]
	 */
	private function campaignCaps( array $lines, array $candidates, Trace $trace ): array {
		foreach ( $candidates as $candidate ) {
			$cap = $candidate->campaign->max_discount_minor;

			if ( null === $cap ) {
				continue;
			}

			$weights = array();

			foreach ( $lines as $index => $adjustment ) {
				if ( $adjustment->campaign_id === $candidate->campaign->id ) {
					$weights[ $index ] = $adjustment->discount_minor;
				}
			}

			if ( array_sum( $weights ) <= $cap ) {
				continue;
			}

			foreach ( Money::distribute( $cap, $weights ) as $index => $amount ) {
				$lines[ $index ] = $lines[ $index ]->withDiscount( $amount );
			}

			$trace->add( $candidate->campaign->id, 'campaign_cap', 'info', 'campaign_cap', array( 'cap' => $cap ) );
		}

		return $lines;
	}

	/**
	 * @param Adjustment[] $lines
	 * @return Adjustment[]
	 */
	private function cartCap( array $lines, Context $context, Trace $trace ): array {
		if ( null === $this->settings->max_cart_discount_percent ) {
			return $lines;
		}

		$cap     = Money::percentFloor( $context->cart->subtotal_minor, $this->settings->max_cart_discount_percent );
		$weights = array_map( static fn( Adjustment $a ): int => $a->discount_minor, $lines );

		if ( array_sum( $weights ) <= $cap ) {
			return $lines;
		}

		$noted = array();

		foreach ( Money::distribute( $cap, $weights ) as $index => $amount ) {
			$lines[ $index ] = $lines[ $index ]->withDiscount( $amount );

			if ( ! isset( $noted[ $lines[ $index ]->campaign_id ] ) ) {
				$noted[ $lines[ $index ]->campaign_id ] = true;
				$trace->add( $lines[ $index ]->campaign_id, 'cart_cap', 'info', 'cart_cap', array( 'cap' => $cap ) );
			}
		}

		return $lines;
	}

	/**
	 * Keeps every line at or above its floor and drops adjustments that end at zero. Lines missing
	 * from the context are dropped.
	 *
	 * @param Adjustment[] $lines
	 * @return Adjustment[]
	 */
	private function floor( array $lines, Context $context, Trace $trace ): array {
		$by_line = array();

		foreach ( $lines as $index => $adjustment ) {
			$by_line[ $adjustment->item_key ][ $index ] = $adjustment->discount_minor;
		}

		foreach ( $by_line as $item_key => $weights ) {
			$item = $context->item( (string) $item_key );
			$room = null === $item ? 0 : max( 0, $item->lineSubtotalMinor() - $this->settings->min_unit_price_minor * $item->qty );

			if ( array_sum( $weights ) <= $room ) {
				continue;
			}

			foreach ( Money::distribute( $room, $weights ) as $index => $amount ) {
				$lines[ $index ] = $lines[ $index ]->withDiscount( $amount );
			}

			$trace->add( $lines[ array_key_first( $weights ) ]->campaign_id, 'min_price', 'info', null === $item ? 'line_missing' : 'min_price', array( 'line' => (string) $item_key ) );
		}

		return array_values( array_filter( $lines, static fn( Adjustment $a ): bool => $a->discount_minor > 0 ) );
	}
}
