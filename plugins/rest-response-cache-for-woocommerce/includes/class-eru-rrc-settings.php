<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce → Ayarlar → REST Önbelleği */
class Eru_Rrc_Settings extends WC_Settings_Page {

	public function __construct() {
		$this->id    = 'eru_rrc';
		$this->label = __( 'REST Önbelleği', 'rest-response-cache-for-woocommerce' );
		parent::__construct();

		add_filter( 'woocommerce_admin_settings_sanitize_option_eru_rrc_routes', array( __CLASS__, 'sanitize_routes' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_eru_rrc_ttl', array( __CLASS__, 'sanitize_ttl' ) );
		add_action( 'woocommerce_admin_field_eru_rrc_flush', array( __CLASS__, 'render_flush' ) );
		add_action( 'woocommerce_update_options_eru_rrc', array( 'Eru_Rrc', 'flush' ) );
	}

	/** Satır başına bir yol; yalnızca güvenli karakterler, boş/yinelenen satırlar atılır. */
	public static function sanitize_routes( $value ): string {
		return implode( "\n", Eru_Rrc::parse_routes( (string) $value ) );
	}

	public static function sanitize_ttl( $value ): string {
		return (string) max( 10, min( DAY_IN_SECONDS, absint( $value ) ) );
	}

	public static function render_flush( array $field ): void {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=eru_rrc_flush' ), 'eru_rrc_flush' );
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $field['title'] ) . '</th><td class="forminp"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Şimdi temizle', 'rest-response-cache-for-woocommerce' ) . '</a></td></tr>';
	}

	public function get_settings_for_default_section(): array {
		return array(
			array(
				'title' => __( 'REST Yanıt Önbelleği', 'rest-response-cache-for-woocommerce' ),
				'desc'  => __( 'Yalnızca aşağıdaki listede yer alan herkese açık GET uçları önbelleğe alınır. Giriş yapmış, kimlik bilgisi (Authorization, consumer key, nonce) veya oturum çerezi taşıyan istekler ve GET dışındaki yöntemler asla önbelleğe alınmaz. Liste boşsa hiçbir şey önbelleğe alınmaz.', 'rest-response-cache-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'eru_rrc_section',
			),
			array(
				'title'       => __( 'Önbelleğe alınacak uçlar', 'rest-response-cache-for-woocommerce' ),
				'desc_tip'    => __( 'Satır başına bir yol, örn. /wc/store/v1/products. Sonuna * koyarsanız alt yolları da kapsar (/wc/store/v1/products/*).', 'rest-response-cache-for-woocommerce' ),
				'id'          => 'eru_rrc_routes',
				'type'        => 'textarea',
				'default'     => '',
				'placeholder' => '/wc/store/v1/products',
				'css'         => 'min-width:350px;height:110px;',
			),
			array(
				'title'             => __( 'Süre (saniye)', 'rest-response-cache-for-woocommerce' ),
				'desc_tip'          => __( 'Bir yanıtın en fazla ne kadar saklanacağı (10 - 86400). Ürün veya terim değişince önbellek zaten temizlenir.', 'rest-response-cache-for-woocommerce' ),
				'id'                => 'eru_rrc_ttl',
				'type'              => 'number',
				'default'           => '300',
				'custom_attributes' => array( 'min' => 10, 'max' => 86400, 'step' => 1 ),
			),
			array(
				'title' => __( 'Önbelleği temizle', 'rest-response-cache-for-woocommerce' ),
				'id'    => 'eru_rrc_flush_btn',
				'type'  => 'eru_rrc_flush',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'eru_rrc_section',
			),
		);
	}
}
