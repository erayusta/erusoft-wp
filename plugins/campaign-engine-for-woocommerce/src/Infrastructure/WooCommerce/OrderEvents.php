<?php
/**
 * After-checkout bookkeeping of one order, run on every status change, payment and refund:
 *
 *   paid (processing / completed)  usage consumed, rewards earned, reward coupons in the order used;
 *   failed / cancelled             usage given back, rewards reversed;
 *   refunds                        rewards lowered (full or pro-rata, see RewardService);
 *   always                         customer metrics follow the order's contribution.
 *
 * Every step is computed from the order's current state and written idempotently (unique keys,
 * absolute amounts, recorded deltas), and the whole run holds a per-order GET_LOCK, so repeated hooks,
 * retries and concurrent requests cannot count twice. When the lock is busy the run is retried later
 * through Action Scheduler.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository;
use Erusoft\CampaignEngine\Infrastructure\Jobs;
use Erusoft\CampaignEngine\Infrastructure\Lock;
use Erusoft\CampaignEngine\Infrastructure\Reward\RewardService;
use WC_Order;

final class OrderEvents {

	public static function hooks(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'onStatus' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'sync' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'sync' ), 20, 1 );
		add_action( Jobs::ORDER_HOOK, array( self::class, 'sync' ), 10, 1 );
	}

	public static function onStatus( $order_id ): void {
		self::sync( (int) $order_id );
	}

	public static function sync( $order_id ): void {
		$order_id = (int) $order_id;

		if ( $order_id <= 0 ) {
			return;
		}

		// Bookkeeping never started (no campaign was ever saved): nothing to do, unless the order itself
		// carries a campaign snapshot or a usage token (data kept from an earlier install).
		if ( 0 === MetricsRepository::since() ) {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order || ( '' === $order->get_meta( OrderWriter::META ) && '' === $order->get_meta( ReservationGate::TOKEN_META ) ) ) {
				return;
			}
		}

		list( $ran ) = Lock::with( 'order_' . $order_id, static fn() => self::run( $order_id ), 10 );

		if ( ! $ran ) {
			Jobs::scheduleOnce( Jobs::ORDER_HOOK, array( $order_id ), MINUTE_IN_SECONDS );
		}
	}

	private static function run( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || 'shop_order_refund' === $order->get_type() ) {
			return;
		}

		$status = $order->get_status();
		$paid   = in_array( $status, wc_get_is_paid_statuses(), true );

		if ( $paid ) {
			ReservationGate::consume( $order );
			RewardService::earn( $order );
			RewardService::markUsed( $order );
		} elseif ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			ReservationGate::release( $order );
			RewardService::releaseUse( $order );
		}

		RewardService::followOrder( $order );
		CustomerMetrics::sync( $order );
	}
}
