<?php
/**
 * WordPress privacy tools (Tools → Export / Erase Personal Data) for what this plugin stores about a
 * person: customer metrics (order count, spend, first/last order), rewards (e-mail, amount, coupon
 * code, status) and usage rows (linked by account id or e-mail hash).
 *
 * Erasing deletes the metrics rows, removes the e-mail and account link from reward and usage rows
 * (the amounts stay, they are bookkeeping) and moves the e-mail restriction of reward coupons to an
 * address nobody owns. Order meta and order notes belong to WooCommerce's own order eraser.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Privacy;

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Infrastructure\Reward\CouponIssuer;

final class Privacy {

	public const ID        = 'eru-ce';
	private const PER_PAGE = 100;

	public static function hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	/** @param array<string,array<string,mixed>> $list */
	public static function exporters( $list ) {
		$list[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Kampanya motoru (ödüller ve müşteri özetleri)', 'campaign-engine-for-woocommerce' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $list;
	}

	/** @param array<string,array<string,mixed>> $list */
	public static function erasers( $list ) {
		$list[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Kampanya motoru (ödüller ve müşteri özetleri)', 'campaign-engine-for-woocommerce' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $list;
	}

	/** @return array{data:array<int,array<string,mixed>>,done:bool} */
	public static function export( $email, $page = 1 ): array {
		$email    = strtolower( trim( (string) $email ) );
		$page     = max( 1, (int) $page );
		$data     = array();
		$decimals = wc_get_price_decimals();

		if ( 1 === $page ) {
			$user    = get_user_by( 'email', $email );
			$metrics = array_filter( array( MetricsRepository::forEmail( $email ), $user ? MetricsRepository::get( 'u:' . $user->ID ) : null ) );

			foreach ( $metrics as $row ) {
				$data[] = array(
					'group_id'    => 'eru-ce-metrics',
					'group_label' => __( 'Kampanya müşteri özeti', 'campaign-engine-for-woocommerce' ),
					'item_id'     => 'eru-ce-metrics-' . (int) $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Sipariş sayısı', 'campaign-engine-for-woocommerce' ),
							'value' => (string) (int) $row['order_count'],
						),
						array(
							'name'  => __( 'Toplam harcama', 'campaign-engine-for-woocommerce' ),
							'value' => Money::toDecimal( (int) $row['total_spent_minor'], $decimals ),
						),
						array(
							'name'  => __( 'İlk sipariş', 'campaign-engine-for-woocommerce' ),
							'value' => (string) ( $row['first_order_at'] ?? '' ),
						),
						array(
							'name'  => __( 'Son sipariş', 'campaign-engine-for-woocommerce' ),
							'value' => (string) ( $row['last_order_at'] ?? '' ),
						),
					),
				);
			}
		}

		$rewards = RewardRepository::byEmail( $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		foreach ( $rewards as $row ) {
			$data[] = array(
				'group_id'    => 'eru-ce-rewards',
				'group_label' => __( 'Kampanya ödülleri', 'campaign-engine-for-woocommerce' ),
				'item_id'     => 'eru-ce-reward-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'E-posta', 'campaign-engine-for-woocommerce' ),
						'value' => (string) $row['email'],
					),
					array(
						'name'  => __( 'Tutar', 'campaign-engine-for-woocommerce' ),
						'value' => Money::toDecimal( (int) $row['amount_minor'], $decimals ),
					),
					array(
						'name'  => __( 'Durum', 'campaign-engine-for-woocommerce' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Kupon kodu', 'campaign-engine-for-woocommerce' ),
						'value' => (string) $row['code'],
					),
					array(
						'name'  => __( 'Kaynak sipariş', 'campaign-engine-for-woocommerce' ),
						'value' => (string) (int) $row['source_order_id'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rewards ) < self::PER_PAGE,
		);
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool} */
	public static function erase( $email, $page = 1 ): array {
		$email = strtolower( trim( (string) $email ) );
		$user  = get_user_by( 'email', $email );
		$uid   = $user ? (int) $user->ID : 0;

		$removed = MetricsRepository::delete( $email, $uid ) > 0;
		$rewards = RewardRepository::byEmail( $email, self::PER_PAGE );

		foreach ( $rewards as $row ) {
			if ( (int) $row['coupon_id'] > 0 ) {
				CouponIssuer::anonymise( (int) $row['coupon_id'] );
			}
		}

		// Rows handled here leave the e-mail query, so the next page starts again at the first row.
		$anonymised = RewardRepository::anonymiseIds( array_map( static fn( array $r ): int => (int) $r['id'], $rewards ) );
		$done       = count( $rewards ) < self::PER_PAGE;
		$usage      = 0;

		if ( $done ) {
			$anonymised += RewardRepository::anonymise( '', $uid );
			$usage       = UsageRepository::anonymise( $uid, UsageRepository::emailHash( $email ) );
		}
		$messages   = array();

		if ( $anonymised > 0 ) {
			$messages[] = __( 'Ödül kayıtları muhasebe için saklandı; e-posta ve hesap bağlantısı silindi.', 'campaign-engine-for-woocommerce' );
		}

		return array(
			'items_removed'  => $removed || $anonymised > 0 || $usage > 0,
			'items_retained' => $anonymised > 0,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
