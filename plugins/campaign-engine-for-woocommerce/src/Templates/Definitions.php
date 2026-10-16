<?php
/**
 * Ready-made campaign definitions for the template gallery. Each one is an engine definition with
 * example figures; choosing a template creates a draft that the merchant adjusts (targets, amounts,
 * dates) before publishing. Amounts are minor units of the store currency.
 *
 * Filter `eru_ce_templates` adds or changes templates; every definition must pass the campaign writer's
 * validation, the gallery hides the ones that do not.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Templates;

final class Definitions {

	/**
	 * @return array<string,array{title:string,summary:string,step:int,definition:array<string,mixed>}>
	 */
	public static function all(): array {
		$templates = array(
			'percent_off'      => array(
				'title'      => __( 'Yüzde indirim', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Seçtiğiniz ürünlerde ya da kategorilerde yüzde indirim. Örnek: %12.', 'campaign-engine-for-woocommerce' ),
				'step'       => 2,
				'definition' => array(
					'actions' => array(
						array(
							'type'    => 'product_percent',
							'percent' => '12',
						),
					),
				),
			),
			'fixed_off'        => array(
				'title'      => __( 'Sabit tutar indirim', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Seçili ürünlerin her adedinden sabit tutar düşer. Örnek: adet başına 40 TL.', 'campaign-engine-for-woocommerce' ),
				'step'       => 2,
				'definition' => array(
					'actions' => array(
						array(
							'type'   => 'product_fixed',
							'amount' => 4000,
						),
					),
				),
			),
			'campaign_price'   => array(
				'title'      => __( 'Kampanya fiyatı', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Seçili ürünler kampanya süresince belirlediğiniz fiyattan satılır. Ürünü ve fiyatı seçin.', 'campaign-engine-for-woocommerce' ),
				'step'       => 2,
				'definition' => array(
					'actions' => array(
						array(
							'type'  => 'product_price',
							'price' => 19990,
						),
					),
					'basis'   => 'current',
				),
			),
			'first_order'      => array(
				'title'      => __( 'İlk siparişe indirim', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Daha önce ödenmiş siparişi olmayan müşterinin sepetine yüzde indirim, müşteri başına bir kez (ödenmemiş siparişler de hakkı tutar). Örnek: %8.', 'campaign-engine-for-woocommerce' ),
				'step'       => 3,
				'definition' => array(
					// One use per customer, held at checkout: unpaid (e.g. bank transfer) orders cannot each take it.
					'usage_limit_per_customer' => 1,
					'actions' => array(
						array(
							'type'    => 'cart_percent',
							'percent' => '8',
						),
					),
					'rules'   => array(
						'op'       => 'and',
						'children' => array(
							array(
								'condition' => 'customer.first_order',
								'value'     => true,
							),
						),
					),
				),
			),
			'nth_order'        => array(
				'title'      => __( 'N. siparişe indirim', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Belirli sayıda ödenmiş siparişi olan müşteriye indirim. Örnek: üçüncü siparişe %6.', 'campaign-engine-for-woocommerce' ),
				'step'       => 2,
				'definition' => array(
					'actions' => array(
						array(
							'type'    => 'cart_percent',
							'percent' => '6',
						),
					),
					'rules'   => array(
						'op'       => 'and',
						'children' => array(
							array(
								'condition' => 'customer.order_count',
								'cmp'       => '==',
								'value'     => 2,
							),
						),
					),
				),
			),
			'buy_x_pay_y'      => array(
				'title'      => __( 'X al Y öde', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Aynı üründen her 3 adette en ucuzu ücretsiz: 3 al 2 öde.', 'campaign-engine-for-woocommerce' ),
				'step'       => 2,
				'definition' => array(
					'actions' => array(
						array(
							'type'         => 'set_reward',
							'set_size'     => 3,
							'reward_count' => 1,
							'reward'       => 'free',
							'target'       => 'cheapest',
							'pool'         => 'product',
							'repeat'       => 'every',
						),
					),
				),
			),
			'nth_item_percent' => array(
				'title'      => __( 'N. üründe yüzde indirim', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Aynı üründen ikinci adette indirim. Örnek: 2. üründe %30.', 'campaign-engine-for-woocommerce' ),
				'step'       => 2,
				'definition' => array(
					'actions' => array(
						array(
							'type'         => 'set_reward',
							'set_size'     => 2,
							'reward_count' => 1,
							'reward'       => 'percent',
							'value'        => '30',
							'target'       => 'cheapest',
							'pool'         => 'product',
							'repeat'       => 'every',
						),
					),
				),
			),
			'tiered'           => array(
				'title'      => __( 'Kademeli indirim', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Adet arttıkça indirim büyür. Örnek: 3-4 adet %5, 5-7 adet %8, 8 ve üstü %12.', 'campaign-engine-for-woocommerce' ),
				'step'       => 3,
				'definition' => array(
					'actions' => array(
						array(
							'type'    => 'tiered',
							'measure' => 'quantity',
							'mode'    => 'all_units',
							'group'   => 'pool',
							'tiers'   => array(
								array(
									'min'   => 3,
									'max'   => 4,
									'type'  => 'percent',
									'value' => '5',
								),
								array(
									'min'   => 5,
									'max'   => 7,
									'type'  => 'percent',
									'value' => '8',
								),
								array(
									'min'   => 8,
									'type'  => 'percent',
									'value' => '12',
								),
							),
						),
					),
				),
			),
			'free_shipping'    => array(
				'title'      => __( 'X TL üzeri ücretsiz kargo', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Sepet tutarı eşiği geçince kargo ücretsiz olur. Örnek: 850 TL ve üzeri.', 'campaign-engine-for-woocommerce' ),
				'step'       => 2,
				'definition' => array(
					'actions' => array(
						array( 'type' => 'free_shipping' ),
					),
					'rules'   => array(
						'op'       => 'and',
						'children' => array(
							array(
								'condition' => 'cart.subtotal',
								'cmp'       => '>=',
								'value'     => 85000,
							),
						),
					),
				),
			),
			'spend_earn'       => array(
				'title'      => __( 'Harca kazan', 'campaign-engine-for-woocommerce' ),
				'summary'    => __( 'Eşiği geçen ödenmiş siparişe, sonraki alışverişte kullanılacak kupon. Örnek: 1.250 TL ve üzeri siparişe 75 TL kupon (450 TL ve üzeri sepette, 30 gün geçerli).', 'campaign-engine-for-woocommerce' ),
				'step'       => 3,
				'definition' => array(
					'actions' => array(
						array(
							'type'       => 'future_reward',
							'reward'     => 'fixed',
							'value'      => 7500,
							'min_cart'   => 45000,
							'valid_days' => 30,
						),
					),
					'rules'   => array(
						'op'       => 'and',
						'children' => array(
							array(
								'condition' => 'cart.subtotal',
								'cmp'       => '>=',
								'value'     => 125000,
							),
						),
					),
				),
			),
		);

		/**
		 * Filters the template gallery.
		 *
		 * @param array $templates id => title, summary, step (wizard step to open), definition.
		 */
		return (array) apply_filters( 'eru_ce_templates', $templates );
	}

	/** @return array{title:string,summary:string,step:int,definition:array<string,mixed>}|null */
	public static function get( string $id ): ?array {
		$all = self::all();

		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}
}
