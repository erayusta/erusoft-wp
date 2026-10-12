<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce → Ayarlar → Mağaza Kapanışı */
class Eru_Scs_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'eru_scs';
		$this->label = __( 'Mağaza Kapanışı', 'store-closure-switch-for-woocommerce' );
		parent::__construct();

		add_filter( 'woocommerce_admin_settings_sanitize_option_eru_scs_message', array( __CLASS__, 'sanitize_message' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_eru_scs_reopen', array( __CLASS__, 'sanitize_date' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_eru_scs_scope', array( __CLASS__, 'sanitize_scope' ) );
	}

	public static function sanitize_message( $value ): string {
		return sanitize_textarea_field( (string) $value );
	}

	/** Yalnızca geçerli YYYY-AA-GG; aksi halde boş (tarihsiz kapanış). */
	public static function sanitize_date( $value ): string {
		$value = sanitize_text_field( (string) $value );
		$dt    = DateTime::createFromFormat( '!Y-m-d', $value );
		return ( $dt && $dt->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	public static function sanitize_scope( $value ): string {
		return 'site' === $value ? 'site' : 'purchase';
	}

	public function get_settings_for_default_section(): array {
		return array(
			array(
				'title' => __( 'Mağaza Kapanışı', 'store-closure-switch-for-woocommerce' ),
				'desc'  => __( 'Kapalıyken yönetici ve mağaza yöneticileri mağazayı normal görür ve alışveriş yapabilir. Ziyaretçilere 503 durumu ve Retry-After başlığı gönderilir.', 'store-closure-switch-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'eru_scs_section',
			),
			array(
				'title'   => __( 'Mağaza kapalı', 'store-closure-switch-for-woocommerce' ),
				'desc'    => __( 'Mağazayı satışa kapat', 'store-closure-switch-for-woocommerce' ),
				'id'      => 'eru_scs_enabled',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'   => __( 'Kapsam', 'store-closure-switch-for-woocommerce' ),
				'id'      => 'eru_scs_scope',
				'type'    => 'select',
				'default' => 'purchase',
				'options' => array(
					'purchase' => __( 'Yalnızca satın alma (katalog görünür; sepete ekleme, sepet ve ödeme kapalı)', 'store-closure-switch-for-woocommerce' ),
					'site'     => __( 'Tüm ön yüz (ziyaretçiler yalnızca kapalı sayfasını görür)', 'store-closure-switch-for-woocommerce' ),
				),
			),
			array(
				'title'       => __( 'Kapalı mesajı', 'store-closure-switch-for-woocommerce' ),
				'desc_tip'    => __( 'Boş bırakırsanız varsayılan mesaj gösterilir.', 'store-closure-switch-for-woocommerce' ),
				'id'          => 'eru_scs_message',
				'type'        => 'textarea',
				'default'     => '',
				'placeholder' => Eru_Scs::default_message(),
				'css'         => 'min-width:350px;',
			),
			array(
				'title'    => __( 'Yeniden açılış tarihi', 'store-closure-switch-for-woocommerce' ),
				'desc_tip' => __( 'İsteğe bağlı. Bu günün başlangıcında (site saat dilimi) kapanış kendiliğinden sona erer ve Retry-After bu tarihe göre hesaplanır. Boşsa kapanış elle kaldırılana kadar sürer.', 'store-closure-switch-for-woocommerce' ),
				'id'       => 'eru_scs_reopen',
				'type'     => 'date',
				'default'  => '',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'eru_scs_section',
			),
		);
	}
}
