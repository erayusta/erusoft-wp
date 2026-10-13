<?php
/** Click & Collect shipping method (zones compatible). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CCL_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'eru_ccl';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Mağazadan teslim', 'click-collect-for-woocommerce' );
		$this->method_description = __( 'Müşteri siparişini seçtiği teslim noktasından alır. Teslim noktaları WooCommerce → Mağazadan Teslim ekranında tanımlanır; hiç nokta yoksa yöntem sunulmaz.', 'click-collect-for-woocommerce' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->instance_form_fields = array(
			'title'      => array(
				'title'   => __( 'Başlık', 'click-collect-for-woocommerce' ),
				'type'    => 'text',
				'default' => __( 'Mağazadan teslim', 'click-collect-for-woocommerce' ),
			),
			'cost'       => array(
				'title'       => __( 'Ücret', 'click-collect-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '0',
				'desc_tip'    => true,
				'description' => __( 'Genellikle 0 (ücretsiz).', 'click-collect-for-woocommerce' ),
			),
			'tax_status' => array(
				'title'   => __( 'Vergi durumu', 'click-collect-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'none',
				'options' => array(
					'taxable' => __( 'Vergilendirilebilir', 'click-collect-for-woocommerce' ),
					'none'    => __( 'Yok', 'click-collect-for-woocommerce' ),
				),
			),
		);
		$this->title      = $this->get_option( 'title', __( 'Mağazadan teslim', 'click-collect-for-woocommerce' ) );
		$this->tax_status = $this->get_option( 'tax_status', 'none' );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function is_available( $package ): bool {
		$locations = Eru_CCL_Locations::all();
		$ok        = (bool) $locations;
		// The block checkout cannot show the location picker: with several locations offer the method on classic checkout only.
		if ( count( $locations ) > 1 && function_exists( 'WC' ) && WC()->is_rest_api_request() ) {
			$ok = false;
		}
		return (bool) apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', $ok, $package, $this );
	}

	public function calculate_shipping( $package = array() ): void {
		if ( ! $this->is_available( $package ) ) {
			return;
		}
		$this->add_rate(
			array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => max( 0, (float) wc_format_decimal( $this->get_option( 'cost', '0' ) ) ),
				'package' => $package,
			)
		);
	}
}
