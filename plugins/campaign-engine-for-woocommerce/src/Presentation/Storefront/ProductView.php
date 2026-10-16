<?php
/**
 * What the active campaigns mean for one product on the shop front. The result is the same for every
 * visitor, so a page cache can serve it to anyone:
 *
 *  - `applied`: campaigns without visitor conditions (Audience) that give one unit of this product
 *    something now: a line discount, free shipping or a reward. Only these change the shown price.
 *    They are evaluated together, as a guest, with the store's settings and total usage limits.
 *  - `others`: campaigns that target the product but need more: a cart threshold, a complete set, a
 *    tier, or a visitor condition (first order, role, weekday, payment method…). Each comes with its
 *    condition note. A visitor-conditional campaign is listed only if it could apply to this product
 *    at all (its rules checked with the visitor conditions treated as met); it never changes a price.
 *  - `ends`: the real end of each listed campaign's season, for countdowns.
 *
 * The cart is the authority: it evaluates every campaign for the real customer.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\Context;
use Erusoft\CampaignEngine\Engine\Context\Customer;
use Erusoft\CampaignEngine\Engine\EvaluationResult;
use Erusoft\CampaignEngine\Engine\Evaluator;
use Erusoft\CampaignEngine\Engine\UsageLimits;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory;
use WC_Product;

final class ProductView {

	/** @var array<int,array<string,mixed>> */
	private static array $cache = array();
	private static ?UsageLimits $usage = null;

	/**
	 * @return array{base:int,discount:int,net:int,applied:Campaign[],gains:array<int,int>,others:array<int,array{campaign:Campaign,note:string,conditional:bool}>,ends:array<int,int>}
	 */
	public static function of( WC_Product $product ): array {
		$id = $product->get_id();

		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}

		$view = array(
			'base'     => 0,
			'discount' => 0,
			'net'      => 0,
			'applied'  => array(),
			'gains'    => array(),
			'others'   => array(),
			'ends'     => array(),
		);

		if ( ! CampaignRepository::hasActive() || $product->is_type( array( 'grouped', 'external' ) ) ) {
			self::$cache[ $id ] = $view;

			return $view;
		}

		// Always a guest: nothing here may depend on who is looking.
		$context = ContextFactory::fromProducts( array( array( $product, 1 ) ), Customer::guest() );
		$item    = $context->items[0] ?? null;

		if ( null === $item ) {
			self::$cache[ $id ] = $view;

			return $view;
		}

		$view['base'] = $item->unit_minor;
		$view['net']  = $item->unit_minor;
		$now          = Clock::now();
		$limits       = CampaignRepository::limits();
		$plain        = array();
		$conditional  = array();

		foreach ( Evaluator::ordered( CampaignRepository::active() ) as $campaign ) {
			$per_customer = $limits[ $campaign->id ]['per_customer'] ?? null;

			if ( ! $campaign->targets->matches( $item ) ) {
				continue;
			}

			if ( Audience::isConditional( $campaign, $per_customer ) ) {
				$relaxed = Audience::relaxed( $campaign );

				if ( $relaxed->schedule->isActiveAt( $now ) ) {
					$conditional[] = array( $campaign, $relaxed, $per_customer );
				}
			} elseif ( $campaign->schedule->isActiveAt( $now ) ) {
				$plain[] = $campaign;
			}
		}

		$evaluator = Evaluator::withDefaults( Options::engineSettings() );

		if ( array() !== $plain ) {
			$result  = $evaluator->evaluate( $context, $plain, self::usage() );
			$applied = $result->appliedCampaignIds();

			foreach ( $result->adjustments->forItem( $item->key ) as $adjustment ) {
				$view['gains'][ $adjustment->campaign_id ] = ( $view['gains'][ $adjustment->campaign_id ] ?? 0 ) + $adjustment->discount_minor;
			}

			$view['discount'] = min( $item->unit_minor, $result->adjustments->lineDiscount( $item->key ) );
			$view['net']      = $item->unit_minor - $view['discount'];

			foreach ( $plain as $campaign ) {
				if ( in_array( $campaign->id, $applied, true ) ) {
					$view['applied'][] = $campaign;
				} elseif ( ! self::excludedByOthers( $result, $campaign->id ) && self::couldApply( $evaluator, $context, Audience::relaxed( $campaign ) ) ) {
					$view['others'][] = array(
						'campaign'    => $campaign,
						'note'        => Audience::note( $campaign ),
						'conditional' => false,
					);
				}

				self::end( $view, $campaign, $now );
			}
		}

		foreach ( $conditional as $row ) {
			list( $campaign, $relaxed, $per_customer ) = $row;

			if ( ! self::couldApply( $evaluator, $context, $relaxed ) ) {
				continue;
			}

			$view['others'][] = array(
				'campaign'    => $campaign,
				'note'        => Audience::note( $campaign, $per_customer ),
				'conditional' => true,
			);
			self::end( $view, $relaxed, $now );
		}

		// Ends only for campaigns that are listed.
		$listed       = array_merge( array_map( static fn( Campaign $c ): int => $c->id, $view['applied'] ), array_map( static fn( array $o ): int => $o['campaign']->id, $view['others'] ) );
		$view['ends'] = array_intersect_key( $view['ends'], array_flip( $listed ) );

		self::$cache[ $id ] = $view;

		return $view;
	}

	/** Dropped because another campaign won (exclusive, stacking) or its uses are gone: not offered. */
	private static function excludedByOthers( EvaluationResult $result, int $campaign_id ): bool {
		$failure = $result->trace->failureFor( $campaign_id );

		return null !== $failure && in_array( $failure['code'], array( 'exclusive', 'stacking', 'lost_all_lines', 'usage_limit' ), true );
	}

	/**
	 * Whether a relaxed campaign (visitor and cart conditions treated as met) applies to this product,
	 * or would with more units (an incomplete set or tier).
	 */
	private static function couldApply( Evaluator $evaluator, Context $context, Campaign $relaxed ): bool {
		$result  = $evaluator->evaluate( $context, array( $relaxed ), self::usage() );
		$failure = $result->trace->failureFor( $relaxed->id );

		return in_array( $relaxed->id, $result->appliedCampaignIds(), true ) || ( null !== $failure && 'no_discount' === $failure['code'] );
	}

	/** @param array<string,mixed> $view */
	private static function end( array &$view, Campaign $campaign, \DateTimeImmutable $now ): void {
		$end = $campaign->schedule->currentEnd( $now );

		if ( null !== $end ) {
			$view['ends'][ $campaign->id ] = $end->getTimestamp();
		}
	}

	/** Forgets this request's results (tests, and code that changes campaigns mid-request). */
	public static function flush(): void {
		self::$cache = array();
		self::$usage = null;
	}

	/** Total uses left (the same for every visitor); per-customer limits make a campaign conditional. */
	private static function usage(): UsageLimits {
		if ( null === self::$usage ) {
			$totals = array();

			foreach ( CampaignRepository::limits() as $id => $limit ) {
				if ( null !== $limit['total'] ) {
					$totals[ $id ] = array(
						'total'        => $limit['total'],
						'per_customer' => null,
					);
				}
			}

			self::$usage = array() === $totals ? UsageLimits::none() : new UsageLimits( UsageRepository::remaining( $totals, 0, '', array() ) );
		}

		return self::$usage;
	}
}
