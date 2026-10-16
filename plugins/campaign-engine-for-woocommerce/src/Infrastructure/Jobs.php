<?php
/**
 * Action Scheduler jobs, group `eru-ce`:
 *  - eru_ce_daily_sweep    releases stale reservations, matures due rewards, expires old ones and
 *                          applies the personal-data retention (Retention). It
 *                          re-arms itself: a follow-up in five minutes while a batch came back full,
 *                          otherwise the next day while open reservations or rewards remain. With no
 *                          open work it stops (inert again);
 *  - eru_ce_reward_mature  [reward id] at the reward's due time;
 *  - eru_ce_order_sync     [order id] when an order's lock was busy.
 *
 * "Already scheduled?" checks count PENDING actions only: `as_has_scheduled_action()` also counts the
 * running action, which would stop a running sweep from re-arming itself.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure;

use ActionScheduler_Store;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Infrastructure\Reward\RewardService;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\ReservationGate;

final class Jobs {

	public const GROUP       = 'eru-ce';
	public const SWEEP_HOOK  = 'eru_ce_daily_sweep';
	public const MATURE_HOOK = 'eru_ce_reward_mature';
	public const ORDER_HOOK  = 'eru_ce_order_sync';

	private const FOLLOW_UP = 300;

	public static function hooks(): void {
		add_action( self::SWEEP_HOOK, array( self::class, 'runDailySweep' ) );
	}

	public static function available(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler_Store' );
	}

	/**
	 * Pending actions of a hook (optionally with exact args).
	 *
	 * @return int[]
	 */
	public static function pending( string $hook, ?array $args = null ): array {
		if ( ! self::available() ) {
			return array();
		}

		$query = array(
			'hook'     => $hook,
			'group'    => self::GROUP,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 5,
		);

		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return array_map( 'intval', (array) as_get_scheduled_actions( $query, 'ids' ) );
	}

	/** Schedules a single action unless one with the same hook and args is pending. */
	public static function scheduleOnce( string $hook, array $args, int $delay ): void {
		if ( ! self::available() || array() !== self::pending( $hook, $args ) ) {
			return;
		}

		as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, self::GROUP );
	}

	public static function unschedule( string $hook, array $args ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );
		}
	}

	/** Arms the daily sweep (called when the first reservation or reward appears). */
	public static function ensureDailySweep(): void {
		self::scheduleOnce( self::SWEEP_HOOK, array(), DAY_IN_SECONDS );
	}

	/** True while reservations or rewards are open, or kept personal data will need the retention purge. */
	public static function workRemains(): bool {
		return UsageRepository::openCount() > 0 || RewardRepository::openCount() > 0 || Retention::pending();
	}

	public static function runDailySweep(): void {
		/**
		 * Filters how many rows one sweep run handles per kind of work.
		 *
		 * @param int $batch Default 200.
		 */
		$batch = max( 1, (int) apply_filters( 'eru_ce_sweep_batch', 200 ) );
		$usage = UsageRepository::sweep( $batch, Options::reservationTtl(), array( ReservationGate::class, 'verdict' ) );

		foreach ( $usage['released'] as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order ) {
				$order->add_order_note( __( 'Ödenmeyen sipariş için tutulan kampanya kullanım hakkı serbest bırakıldı.', 'campaign-engine-for-woocommerce' ) );
			}
		}

		$more = RewardService::sweep( $batch ) || $usage['more'];
		$more = Retention::run( $batch ) || $more;

		if ( $more ) {
			self::scheduleOnce( self::SWEEP_HOOK, array(), self::FOLLOW_UP );
		} elseif ( self::workRemains() ) {
			self::ensureDailySweep();
		}
	}

	/** Deactivation: every job of the plugin goes. */
	public static function clearAll(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}
}
