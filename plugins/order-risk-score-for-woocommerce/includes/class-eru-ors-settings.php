<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce → Ayarlar → Risk Skoru */
class Eru_Ors_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'eru_ors';
		$this->label = __( 'Risk Skoru', 'order-risk-score-for-woocommerce' );
		parent::__construct();
	}

	public function get_settings_for_default_section(): array {
		$symbol = get_woocommerce_currency_symbol();

		$fields = array(
			array(
				'title' => __( 'Sinyal ağırlıkları', 'order-risk-score-for-woocommerce' ),
				'desc'  => __( 'Sipariş oluşurken tetiklenen sinyallerin ağırlıkları toplanır ve 0-100 arasına sınırlanır. Ağırlığı 0 olan sinyal devre dışıdır. Eklenti hiçbir otomatik işlem yapmaz; karar size aittir.', 'order-risk-score-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'eru_ors_weights',
			),
		);

		foreach ( Eru_Ors_Scorer::signals() as $key => $def ) {
			$fields[] = array(
				'title'             => $def['label'],
				'id'                => 'eru_ors_weight_' . $key,
				'type'              => 'number',
				'default'           => (string) ( $def['weight'] ?? 0 ),
				'css'               => 'width:80px;',
				'custom_attributes' => array( 'min' => 0, 'max' => 100, 'step' => 1 ),
			);
		}
		$fields[] = array( 'type' => 'sectionend', 'id' => 'eru_ors_weights' );

		$fields[] = array(
			'title' => __( 'Eşikler ve listeler', 'order-risk-score-for-woocommerce' ),
			'desc'  => __( 'Listeler boş başlar; değerleri kendi mağazanızın deneyimine göre siz girersiniz. Satır veya virgülle ayırın.', 'order-risk-score-for-woocommerce' ),
			'type'  => 'title',
			'id'    => 'eru_ors_rules',
		);
		$fields[] = array(
			/* translators: %s: currency symbol */
			'title'             => sprintf( __( 'Yüksek tutar eşiği (%s)', 'order-risk-score-for-woocommerce' ), $symbol ),
			'desc_tip'          => __( 'Sipariş toplamı bu tutara eşit veya büyükse "Yüksek tutar" sinyali tetiklenir. 0 = kapalı.', 'order-risk-score-for-woocommerce' ),
			'id'                => 'eru_ors_high_amount',
			'type'              => 'number',
			'default'           => '1000',
			'custom_attributes' => array( 'min' => 0, 'step' => 'any' ),
		);
		$fields[] = array(
			/* translators: %s: currency symbol */
			'title'             => sprintf( __( 'Manuel ödeme tutar eşiği (%s)', 'order-risk-score-for-woocommerce' ), $symbol ),
			'desc_tip'          => __( 'Aşağıdaki ödeme yöntemlerinden biriyle ve bu tutarın üstündeki siparişler için.', 'order-risk-score-for-woocommerce' ),
			'id'                => 'eru_ors_manual_amount',
			'type'              => 'number',
			'default'           => '500',
			'custom_attributes' => array( 'min' => 0, 'step' => 'any' ),
		);
		$fields[] = array(
			'title'    => __( 'Manuel ödeme yöntemleri', 'order-risk-score-for-woocommerce' ),
			'desc_tip' => __( 'Ödeme yöntemi kimlikleri (ör. bacs, cheque).', 'order-risk-score-for-woocommerce' ),
			'id'       => 'eru_ors_manual_gateways',
			'type'     => 'text',
			'default'  => 'bacs, cheque',
		);
		$fields[] = array(
			'title'       => __( 'Tek kullanımlık e-posta alan adları', 'order-risk-score-for-woocommerce' ),
			'desc_tip'    => __( 'Faturalama e-postasının alan adı bu listedeyse sinyal tetiklenir (ör. example.com).', 'order-risk-score-for-woocommerce' ),
			'id'          => 'eru_ors_disposable_domains',
			'type'        => 'textarea',
			'default'     => '',
			'css'         => 'min-width:350px;height:80px;',
			'placeholder' => 'example.com',
		);
		$fields[] = array(
			'title'       => __( 'Riskli ülkeler', 'order-risk-score-for-woocommerce' ),
			'desc_tip'    => __( 'İki harfli ülke kodları (ör. US, DE). Fatura veya teslimat ülkesi listedeyse tetiklenir.', 'order-risk-score-for-woocommerce' ),
			'id'          => 'eru_ors_risky_countries',
			'type'        => 'text',
			'default'     => '',
			'placeholder' => 'US, DE',
		);
		$fields[] = array(
			'title'       => __( 'İzleme listesi', 'order-risk-score-for-woocommerce' ),
			'desc_tip'    => __( 'Her satıra bir e-posta, "@alanadi.com" biçiminde alan adı veya telefon numarası. Faturalama bilgisiyle eşleşirse sinyal tetiklenir.', 'order-risk-score-for-woocommerce' ),
			'id'          => 'eru_ors_watchlist',
			'type'        => 'textarea',
			'default'     => '',
			'css'         => 'min-width:350px;height:80px;',
			'placeholder' => "kisi@example.com\n@example.org",
		);
		$fields[] = array( 'type' => 'sectionend', 'id' => 'eru_ors_rules' );

		$fields[] = array(
			'title' => __( 'Risk bantları', 'order-risk-score-for-woocommerce' ),
			'type'  => 'title',
			'id'    => 'eru_ors_bands',
		);
		$fields[] = array(
			'title'             => __( 'Orta risk alt sınırı', 'order-risk-score-for-woocommerce' ),
			'desc_tip'          => __( 'Bu değerin altındaki puanlar "Düşük" (yeşil) sayılır.', 'order-risk-score-for-woocommerce' ),
			'id'                => 'eru_ors_band_medium',
			'type'              => 'number',
			'default'           => '30',
			'css'               => 'width:80px;',
			'custom_attributes' => array( 'min' => 0, 'max' => 100, 'step' => 1 ),
		);
		$fields[] = array(
			'title'             => __( 'Yüksek risk alt sınırı', 'order-risk-score-for-woocommerce' ),
			'desc_tip'          => __( 'Bu değer ve üstü "Yüksek" (kırmızı); arası "Orta" (turuncu).', 'order-risk-score-for-woocommerce' ),
			'id'                => 'eru_ors_band_high',
			'type'              => 'number',
			'default'           => '60',
			'css'               => 'width:80px;',
			'custom_attributes' => array( 'min' => 0, 'max' => 100, 'step' => 1 ),
		);
		$fields[] = array( 'type' => 'sectionend', 'id' => 'eru_ors_bands' );

		return $fields;
	}
}
