<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce → Ayarlar → Kupon Koruması */
class Eru_Cbg_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'eru_cbg';
		$this->label = __( 'Kupon Koruması', 'coupon-brute-force-guard-for-woocommerce' );
		parent::__construct();
	}

	public function get_settings_for_default_section(): array {
		return array(
			array(
				'title' => __( 'Kupon Brute-Force Koruması', 'coupon-brute-force-guard-for-woocommerce' ),
				'desc'  => __( 'Pencere süresi içinde belirlenen sayıda başarısız kupon denemesi yapan istemci (giriş yapmış kullanıcı veya IP) kilitlenir. Mağaza yöneticileri muaftır.', 'coupon-brute-force-guard-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'eru_cbg_section',
			),
			array(
				'title'   => __( 'Koruma', 'coupon-brute-force-guard-for-woocommerce' ),
				'desc'    => __( 'Başarısız kupon denemelerini say ve sınırla', 'coupon-brute-force-guard-for-woocommerce' ),
				'id'      => 'eru_cbg_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'             => __( 'İzin verilen başarısız deneme', 'coupon-brute-force-guard-for-woocommerce' ),
				'desc_tip'          => __( 'Bu sayıya ulaşıldığında istemci kilitlenir.', 'coupon-brute-force-guard-for-woocommerce' ),
				'id'                => 'eru_cbg_attempts',
				'type'              => 'number',
				'default'           => '5',
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			),
			array(
				'title'             => __( 'Deneme penceresi (dakika)', 'coupon-brute-force-guard-for-woocommerce' ),
				'desc_tip'          => __( 'Başarısız denemeler bu süre içinde sayılır.', 'coupon-brute-force-guard-for-woocommerce' ),
				'id'                => 'eru_cbg_window',
				'type'              => 'number',
				'default'           => '15',
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			),
			array(
				'title'             => __( 'Kilit süresi (dakika)', 'coupon-brute-force-guard-for-woocommerce' ),
				'desc_tip'          => __( 'Kilitli istemci bu süre boyunca hiçbir kuponu uygulayamaz.', 'coupon-brute-force-guard-for-woocommerce' ),
				'id'                => 'eru_cbg_lockout',
				'type'              => 'number',
				'default'           => '60',
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			),
			array(
				'title'   => __( 'Hata mesajları', 'coupon-brute-force-guard-for-woocommerce' ),
				'desc'    => __( 'Kuponun var olduğunu sızdıran hata mesajlarını tek tip mesaja çevir (süresi dolmuş, kullanım limiti dolmuş, size ait değil)', 'coupon-brute-force-guard-for-woocommerce' ),
				'id'      => 'eru_cbg_uniform_errors',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'       => __( 'Kilit mesajı', 'coupon-brute-force-guard-for-woocommerce' ),
				'desc_tip'    => __( 'Boş bırakırsanız varsayılan mesaj gösterilir.', 'coupon-brute-force-guard-for-woocommerce' ),
				'id'          => 'eru_cbg_message',
				'type'        => 'textarea',
				'default'     => '',
				'placeholder' => __( 'Çok fazla başarısız kupon denemesi yaptınız. Lütfen daha sonra tekrar deneyin.', 'coupon-brute-force-guard-for-woocommerce' ),
				'css'         => 'min-width:350px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'eru_cbg_section',
			),
		);
	}
}
