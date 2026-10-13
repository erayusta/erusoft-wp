<?php
/** Optional same-day shipping method (zones compatible, own fee). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SDD_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'eru_sdd';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Aynı gün teslimat', 'same-day-delivery-for-woocommerce' );
		$this->method_description = __( 'Kesim saatinden önce, çalışma gününde ve uygun şehir/ilçeye verilen siparişler için. Saat ve konum kuralları WooCommerce → Aynı Gün Teslimat ekranındadır.', 'same-day-delivery-for-woocommerce' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}

	private function init(): void {
		$this->instance_form_fields = array(
			'title'      => array(
				'title'   => __( 'Başlık', 'same-day-delivery-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Aynı gün teslimat', 'same-day-delivery-for-woocommerce' ),
			),
			'cost'       => array(
				'title'       => __( 'Ücret', 'same-day-delivery-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => '0',
				'default'     => '0',
				'desc_tip'    => true,
				'description' => __( 'Bu yönteme özel ücret.', 'same-day-delivery-for-woocommerce' ),
			),
			'tax_status' => array(
				'title'   => __( 'Vergi durumu', 'same-day-delivery-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Vergilendirilebilir', 'same-day-delivery-for-woocommerce' ),
					'none'    => __( 'Yok', 'same-day-delivery-for-woocommerce' ),
				),
			),
		);
		$this->title      = $this->get_option( 'title', __( 'Aynı gün teslimat', 'same-day-delivery-for-woocommerce' ) );
		$this->tax_status = $this->get_option( 'tax_status', 'taxable' );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function is_available( $package ): bool {
		$ok = Eru_SDD_Schedule::ships_today() && Eru_SDD_Schedule::destination_eligible( (array) ( $package['destination'] ?? array() ) );
		return (bool) apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $ok, $package, $this );
	}

	public function calculate_shipping( $package = array() ): void {
		if ( ! $this->is_available( $package ) ) {
			return;
		}
		$cost = (float) wc_format_decimal( $this->get_option( 'cost', '0' ) );
		$this->add_rate(
			array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => max( 0, $cost ),
				'package' => $package,
			)
		);
	}
}
