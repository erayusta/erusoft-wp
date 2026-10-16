<?php
/**
 * Keeps the customer metrics in step with one order.
 *
 * An order contributes (1 order, total − refunded) while it is paid (processing / completed) and
 * nothing otherwise. The contribution last written is recorded in the order meta `_eru_ce_metrics`;
 * each run applies only the difference, so a status flip, a repeated hook or a second refund event
 * never counts twice, and a cancellation after payment takes the order back out. Orders created before
 * the first campaign was saved are part of the customer's seed and are left alone here.
 * Runs inside OrderEvents' per-order lock.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\WooCommerce;

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository;
use WC_Order;

final class CustomerMetrics {

	public const META = '_eru_ce_metrics';

	public static function sync( WC_Order $order ): void {
		$since = MetricsRepository::since();

		if ( 0 === $since ) {
			return; // No campaign was ever saved: nothing is recorded.
		}

		$recorded = $order->get_meta( self::META );
		$recorded = is_array( $recorded ) ? $recorded : null;
		$created  = $order->get_date_created();

		if ( null === $recorded && $created && $created->getTimestamp() < $since ) {
			return; // Part of the customer's seed.
		}

		$decimals = wc_get_price_decimals();
		$paid     = in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
		$net      = Money::of( (string) $order->get_total(), $decimals ) - Money::of( (string) $order->get_total_refunded(), $decimals );
		$target   = $paid ? array( 1, max( 0, $net ) ) : array( 0, 0 );
		$customer = (int) $order->get_customer_id();
		$email    = strtolower( (string) $order->get_billing_email() );
		$key      = MetricsRepository::key( $customer, $email );

		if ( '' === $key ) {
			return;
		}

		$before = array( (int) ( $recorded['count'] ?? 0 ), (int) ( $recorded['spent'] ?? 0 ) );
		$moved  = null !== $recorded && ( $recorded['key'] ?? '' ) !== $key;

		if ( $moved ) {
			// The order changed hands (guest order linked to an account): move its contribution.
			MetricsRepository::applyToKey( (string) $recorded['key'], -$before[0], -$before[1] );
			$before = array( 0, 0 );
		}

		if ( ! $moved && $before === $target ) {
			return;
		}

		if ( $before !== $target ) {
			$paid_at = $order->get_date_paid() ?? $created;
			MetricsRepository::apply( $customer, $email, $target[0] - $before[0], $target[1] - $before[1], $paid_at ? $paid_at->getTimestamp() : null, $order->get_id() );
		}

		$order->update_meta_data(
			self::META,
			array(
				'key'   => $key,
				'count' => $target[0],
				'spent' => $target[1],
			)
		);
		$order->save_meta_data();
	}
}
