<?php
/**
 * Spend-and-earn rewards, from a paid order to a coupon.
 *
 *   earn()        a paid order's snapshot rewards (applied campaigns only) become "pending" rows,
 *                 available after the maturation days (0 / 7 / 14 / 30); 0 matures at once;
 *   mature()      Action Scheduler `eru_ce_reward_mature` [reward id] at the due time, and the daily
 *                 sweep as a safety net: issues the coupon, marks "available", sends "Ödülünüz hazır";
 *   markUsed()    the reward coupon in a paid order → "used";
 *   releaseUse()  that order failed or was cancelled (WooCommerce gives the coupon use back) → the
 *                 reward is "available" again (or "expired" past its date), then the source order's
 *                 refunds are applied to it;
 *   followOrder() refunds and cancellations of the source order lower or reverse the reward:
 *                 policy "full" reverses on any refund, "pro_rata" keeps reward × (total − refunded) /
 *                 total, rounded half up to the minor unit; a full refund, failure or cancellation
 *                 reverses under both. The amount only ever goes down and is computed from the order's
 *                 totals (not from the event), so repeated refund hooks change nothing. A pending reward
 *                 is lowered before its coupon exists; an available one has its coupon lowered or
 *                 disabled; a used or expired one is only logged;
 *   sweep()       matures what is due and expires available rewards past their coupon date.
 *
 * Each reward is changed under its own GET_LOCK with compare-and-set status updates.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Reward;

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\AuditLog;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\Jobs;
use Erusoft\CampaignEngine\Infrastructure\Lock;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\OrderWriter;
use WC_Order;

final class RewardService {

	public static function hooks(): void {
		add_action( Jobs::MATURE_HOOK, array( self::class, 'mature' ), 10, 1 );
		add_filter( 'woocommerce_email_classes', array( self::class, 'emailClass' ) );
	}

	/**
	 * @param array<string,\WC_Email> $emails
	 * @return array<string,\WC_Email>
	 */
	public static function emailClass( $emails ) {
		if ( is_array( $emails ) && class_exists( 'WC_Email' ) ) {
			$emails['eru_ce_reward_ready'] = new RewardReadyEmail();
		}

		return $emails;
	}

	/** Paid order: one pending reward per rewarding campaign of the snapshot. */
	public static function earn( WC_Order $order ): void {
		$snapshot = OrderWriter::snapshot( $order );
		$applied  = array_fill_keys( OrderWriter::campaignIds( $snapshot ), true );
		$email    = strtolower( trim( (string) $order->get_billing_email() ) );

		if ( array() === (array) ( $snapshot['rewards'] ?? array() ) || '' === $email ) {
			return;
		}

		$days = Options::maturationDays();
		$due  = time() + $days * DAY_IN_SECONDS;

		foreach ( (array) $snapshot['rewards'] as $reward ) {
			$campaign_id = (int) ( $reward['campaign_id'] ?? 0 );
			$amount      = (int) ( $reward['amount_minor'] ?? 0 );

			if ( ! isset( $applied[ $campaign_id ] ) || $amount <= 0 ) {
				continue;
			}

			$id = RewardRepository::insert(
				array(
					'campaign_id'      => $campaign_id,
					'campaign_version' => (int) ( $reward['version'] ?? 1 ),
					'source_order_id'  => (int) $order->get_id(),
					'customer_id'      => (int) $order->get_customer_id(),
					'email'            => $email,
					'currency'         => (string) $order->get_currency(),
					'amount_minor'     => $amount,
					'min_cart_minor'   => (int) ( $reward['min_cart_minor'] ?? 0 ),
					'valid_days'       => (int) ( $reward['valid_days'] ?? 0 ),
					'category_ids'     => (array) ( $reward['category_ids'] ?? array() ),
					'available_at'     => Clock::mysql( $due ),
				)
			);

			if ( $id <= 0 ) {
				continue; // Already earned by an earlier hook.
			}

			AuditLog::record(
				'reward',
				$id,
				'earned',
				array(
					'order_id' => (int) $order->get_id(),
					'amount'   => $amount,
					'days'     => $days,
				)
			);

			if ( 0 === $days ) {
				self::mature( $id );
			} else {
				Jobs::scheduleOnce( Jobs::MATURE_HOOK, array( $id ), $due - time() );
			}
		}

		Jobs::ensureDailySweep();
	}

	/** Issues the coupon of a due pending reward (Action Scheduler and sweep). */
	public static function mature( $reward_id ): void {
		$reward_id = (int) $reward_id;

		if ( $reward_id <= 0 ) {
			return;
		}

		list( $ran ) = Lock::with( 'reward_' . $reward_id, static fn() => self::matureLocked( $reward_id ), 10 );

		if ( ! $ran ) {
			Jobs::scheduleOnce( Jobs::MATURE_HOOK, array( $reward_id ), MINUTE_IN_SECONDS );
		}
	}

	private static function matureLocked( int $reward_id ): void {
		$row = RewardRepository::get( $reward_id );

		if ( null === $row || RewardRepository::PENDING !== $row['status'] ) {
			return;
		}

		$due = Clock::ts( $row['available_at'] ) ?? 0;

		if ( $due > time() ) {
			Jobs::scheduleOnce( Jobs::MATURE_HOOK, array( $reward_id ), $due - time() );

			return;
		}

		$order = wc_get_order( (int) $row['source_order_id'] );

		if ( $order instanceof WC_Order ) {
			$target = self::target( $order, $row );

			if ( $target < (int) $row['amount_minor'] ) {
				self::lower( $row, $target, 'matured_after_refund' );
				$row = RewardRepository::get( $reward_id );
			}
		}

		if ( null === $row || RewardRepository::PENDING !== $row['status'] || (int) $row['amount_minor'] <= 0 ) {
			return;
		}

		$coupon = CouponIssuer::issue( $row );

		if ( null === $coupon ) {
			// No free code or the save failed: try again later.
			Jobs::scheduleOnce( Jobs::MATURE_HOOK, array( $reward_id ), HOUR_IN_SECONDS );

			return;
		}

		$changed = RewardRepository::update(
			$reward_id,
			array(
				'status'     => RewardRepository::AVAILABLE,
				'coupon_id'  => $coupon['coupon_id'],
				'code'       => $coupon['code'],
				'expires_at' => $coupon['expires'] > 0 ? Clock::mysql( $coupon['expires'] ) : null,
			),
			RewardRepository::PENDING
		);

		if ( ! $changed ) {
			return;
		}

		AuditLog::record( 'reward', $reward_id, 'available', array( 'coupon_id' => $coupon['coupon_id'] ) );

		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		$emails = $mailer ? $mailer->get_emails() : array();

		if ( isset( $emails['eru_ce_reward_ready'] ) ) {
			$emails['eru_ce_reward_ready']->trigger( (array) RewardRepository::get( $reward_id ) );
		}
	}

	/** Reward coupons used in a paid order. */
	public static function markUsed( WC_Order $order ): void {
		foreach ( RewardRepository::availableByCodes( $order->get_coupon_codes() ) as $row ) {
			if ( RewardRepository::update(
				(int) $row['id'],
				array(
					'status'        => RewardRepository::USED,
					'used_order_id' => (int) $order->get_id(),
				),
				RewardRepository::AVAILABLE
			) ) {
				AuditLog::record( 'reward', (int) $row['id'], 'used', array( 'order_id' => (int) $order->get_id() ) );
			}
		}
	}

	/** The order that used a reward coupon failed or was cancelled. */
	public static function releaseUse( WC_Order $order ): void {
		foreach ( RewardRepository::usedBy( (int) $order->get_id() ) as $row ) {
			$expires = Clock::ts( $row['expires_at'] ?? null );
			$status  = null !== $expires && $expires < time() ? RewardRepository::EXPIRED : RewardRepository::AVAILABLE;

			if ( RewardRepository::update(
				(int) $row['id'],
				array(
					'status'        => $status,
					'used_order_id' => 0,
				),
				RewardRepository::USED
			) ) {
				AuditLog::record( 'reward', (int) $row['id'], 'use_released', array( 'order_id' => (int) $order->get_id() ) );
				$source = wc_get_order( (int) $row['source_order_id'] );

				if ( $source instanceof WC_Order ) {
					self::followOrder( $source );
				}
			}
		}
	}

	/** Refunds, failure and cancellation of the source order. */
	public static function followOrder( WC_Order $order ): void {
		$rows = RewardRepository::forOrder( (int) $order->get_id() );

		foreach ( $rows as $row ) {
			$target = self::target( $order, $row );

			if ( $target >= (int) $row['amount_minor'] ) {
				continue;
			}

			Lock::with(
				'reward_' . (int) $row['id'],
				static function () use ( $row, $order ) {
					$fresh = RewardRepository::get( (int) $row['id'] );

					if ( null !== $fresh ) {
						$target = self::target( $order, $fresh );

						if ( $target < (int) $fresh['amount_minor'] ) {
							self::lower( $fresh, $target, 'refund' );
						}
					}
				},
				10
			);
		}
	}

	/**
	 * What the reward should be worth given the source order's state now.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function target( WC_Order $order, array $row ): int {
		$original = (int) $row['original_minor'];
		$decimals = wc_get_price_decimals();
		$total    = Money::of( (string) $order->get_total(), $decimals );
		$refunded = Money::of( (string) $order->get_total_refunded(), $decimals );
		$left     = $total - $refunded;

		if ( in_array( $order->get_status(), array( 'failed', 'cancelled', 'refunded' ), true ) || $total <= 0 || $left <= 0 ) {
			return 0;
		}

		if ( $refunded <= 0 ) {
			return $original;
		}

		if ( 'full' === Options::refundPolicy() ) {
			return 0;
		}

		// Half up in integers: original × left / total.
		return intdiv( 2 * $original * $left + $total, 2 * $total );
	}

	/**
	 * Matures what is due and expires what is past, one batch each.
	 *
	 * @return bool true when a batch was full (more may remain)
	 */
	public static function sweep( int $batch ): bool {
		$due = RewardRepository::dueIds( $batch );

		foreach ( $due as $id ) {
			self::mature( $id );
		}

		$expired = RewardRepository::expire( $batch );

		return count( $due ) >= $batch || $expired >= $batch;
	}

	/** Re-arms the maturation of pending rewards (activation after a deactivation). */
	public static function rearm(): void {
		foreach ( RewardRepository::pendingIds() as $id ) {
			$row = RewardRepository::get( $id );
			$due = $row ? ( Clock::ts( $row['available_at'] ) ?? time() ) : time();
			Jobs::scheduleOnce( Jobs::MATURE_HOOK, array( $id ), max( 0, $due - time() ) );
		}
	}

	/** @param array<string,mixed> $row */
	private static function lower( array $row, int $target, string $why ): void {
		$id      = (int) $row['id'];
		$status  = (string) $row['status'];
		$target  = max( 0, min( $target, (int) $row['amount_minor'] ) );
		$reverse = 0 === $target;

		if ( RewardRepository::PENDING === $status ) {
			RewardRepository::update(
				$id,
				array(
					'amount_minor' => $target,
					'status'       => $reverse ? RewardRepository::REVERSED : RewardRepository::PENDING,
				),
				RewardRepository::PENDING
			);

			if ( $reverse ) {
				Jobs::unschedule( Jobs::MATURE_HOOK, array( $id ) );
			}
		} elseif ( RewardRepository::AVAILABLE === $status ) {
			if ( $reverse ) {
				CouponIssuer::disable( (int) $row['coupon_id'] );
			} else {
				CouponIssuer::setAmount( (int) $row['coupon_id'], $target );
			}

			RewardRepository::update(
				$id,
				array(
					'amount_minor' => $target,
					'status'       => $reverse ? RewardRepository::REVERSED : RewardRepository::AVAILABLE,
				),
				RewardRepository::AVAILABLE
			);
		} else {
			// Used or expired: the coupon is gone or worthless; only record what the refund would take.
			AuditLog::record(
				'reward',
				$id,
				'reverse_after_' . $status,
				array(
					'amount' => (int) $row['amount_minor'],
					'target' => $target,
				)
			);

			return;
		}

		AuditLog::record(
			'reward',
			$id,
			$reverse ? 'reversed' : 'lowered',
			array(
				'why'    => $why,
				'before' => (int) $row['amount_minor'],
				'after'  => $target,
			)
		);
	}
}
