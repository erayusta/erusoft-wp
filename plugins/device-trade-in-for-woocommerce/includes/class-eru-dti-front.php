<?php
/**
 * Product-page selector, shortcode and the public read-only options endpoint.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the fieldset and answers eru_dti_options.
 */
class Eru_DTI_Front {

	/** @var bool Assets already enqueued. */
	private static $enqueued = false;

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_hook' ) );
		add_shortcode( 'eru_device_trade_in', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_eru_dti_options', array( __CLASS__, 'ajax_options' ) );
		add_action( 'wp_ajax_nopriv_eru_dti_options', array( __CLASS__, 'ajax_options' ) );
	}

	/** Echoes the selector on enabled products. */
	public static function render_hook() {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo self::render( $product ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** @return string */
	public static function shortcode() {
		global $product;
		if ( ! $product instanceof WC_Product && is_singular( 'product' ) ) {
			$product = wc_get_product( get_the_ID() );
		}
		return $product instanceof WC_Product ? self::render( $product ) : '';
	}

	/** @var int Product IDs rendered, to avoid a double fieldset when hook and shortcode meet. */
	private static $rendered = array();

	/** @param WC_Product $product Product. @return string HTML. */
	public static function render( $product ) {
		$pid = $product->get_id();
		if ( isset( self::$rendered[ $pid ] ) || ! Eru_DTI_Settings::product_enabled( $pid ) || ! Eru_DTI_DB::device_count() ) {
			return '';
		}
		self::$rendered[ $pid ] = true;
		self::enqueue();

		$level1 = self::level_options( '', '' );
		if ( ! $level1 ) {
			return '';
		}
		$conditions = Eru_DTI_Settings::conditions();
		$terms      = trim( (string) Eru_DTI_Settings::get( 'terms_text' ) );
		$leads      = Eru_DTI_Settings::is_on( 'leads_enabled' );
		$labels     = array( Eru_DTI_Settings::get( 'l1_label' ), Eru_DTI_Settings::get( 'l2_label' ), Eru_DTI_Settings::get( 'l3_label' ) );
		$uses_l3    = Eru_DTI_Settings::uses_l3();
		$serial_req = Eru_DTI_Settings::is_on( 'serial_required' );
		ob_start();
		include ERU_DTI_PATH . 'templates/selector.php';
		return (string) ob_get_clean();
	}

	/** Enqueues front assets once. */
	private static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		wp_enqueue_style( 'eru-dti-front', ERU_DTI_URL . 'assets/front.css', array(), ERU_DTI_VERSION );
		wp_enqueue_script( 'eru-dti-front', ERU_DTI_URL . 'assets/front.js', array(), ERU_DTI_VERSION, true );
		wp_localize_script(
			'eru-dti-front',
			'eruDti',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'leadNonce' => wp_create_nonce( 'eru_dti_lead' ),
				'choose'    => __( 'Seçin', 'device-trade-in-for-woocommerce' ),
				'est'       => __( 'Tahmini takas değeri', 'device-trade-in-for-woocommerce' ),
				'error'     => __( 'Bir hata oluştu, lütfen tekrar deneyin.', 'device-trade-in-for-woocommerce' ),
			)
		);
	}

	/**
	 * Distinct values for a level.
	 *
	 * @param string $l1 Level 1 value ('' = list level 1).
	 * @param string $l2 Level 2 value ('' with $l1 set = list level 2).
	 * @return string[]
	 */
	public static function level_options( $l1, $l2 ) {
		global $wpdb;
		$t = Eru_DTI_DB::table( 'devices' );
		if ( '' === $l1 ) {
			return $wpdb->get_col( "SELECT level1 FROM $t WHERE active = 1 GROUP BY level1 ORDER BY MIN(sort) ASC, level1 ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $wpdb->get_col( $wpdb->prepare( "SELECT level2 FROM $t WHERE active = 1 AND level1 = %s GROUP BY level2 ORDER BY MIN(sort) ASC, level2 ASC", $l1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param string $l1 Level 1. @param string $l2 Level 2. @return array[] Devices with per-condition values. */
	public static function devices_for( $l1, $l2 ) {
		global $wpdb;
		$t    = Eru_DTI_DB::table( 'devices' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE active = 1 AND level1 = %s AND level2 = %s ORDER BY sort ASC, level3 ASC", $l1, $l2 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $d ) {
			$values = array();
			foreach ( array_keys( Eru_DTI_Settings::conditions() ) as $ck ) {
				$r = Eru_DTI_Pricing::resolve( (int) $d['id'], $ck );
				if ( ! is_wp_error( $r ) ) {
					$values[ $ck ] = array( 'v' => $r['value'], 'f' => html_entity_decode( wp_strip_all_tags( wc_price( $r['value'] ) ) ) );
				}
			}
			$out[] = array( 'id' => (int) $d['id'], 'std' => '' === $d['level3'], 'label' => '' === $d['level3'] ? __( 'Standart', 'device-trade-in-for-woocommerce' ) : $d['level3'], 'values' => $values );
		}
		return $out;
	}

	/** Public, read-only, cached options endpoint. */
	public static function ajax_options() {
		// phpcs:disable WordPress.Security.NonceVerification
		$l1 = isset( $_GET['l1'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['l1'] ) ), 0, 190 ) : '';
		$l2 = isset( $_GET['l2'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['l2'] ) ), 0, 190 ) : '';
		// phpcs:enable
		// Only known values reach the cache: unknown input answers empty and creates no transient.
		if ( '' !== $l1 && ! in_array( $l1, self::level_options( '', '' ), true ) ) {
			wp_send_json_success( array( 'type' => 'level', 'options' => array() ) );
		}
		if ( '' !== $l2 && ! in_array( $l2, self::level_options( $l1, '' ), true ) ) {
			wp_send_json_success( array( 'type' => 'level', 'options' => array() ) );
		}
		$key    = 'eru_dti_opt_' . md5( $l1 . '|' . $l2 . '|' . Eru_DTI_DB::cache_ver() . '|' . get_option( 'eru_dti_settings_hash', '' ) . wc_get_price_decimals() . '|' . wp_json_encode( Eru_DTI_Settings::get( 'conditions' ) ) . Eru_DTI_Settings::get( 'bonus_enabled' ) . Eru_DTI_Settings::get( 'bonus_amount' ) );
		$cached = get_transient( $key );
		if ( false === $cached ) {
			if ( '' === $l1 ) {
				$cached = array( 'type' => 'level', 'options' => self::level_options( '', '' ) );
			} elseif ( '' === $l2 ) {
				$cached = array( 'type' => 'level', 'options' => self::level_options( $l1, '' ) );
			} else {
				$cached = array( 'type' => 'devices', 'options' => self::devices_for( $l1, $l2 ) );
			}
			set_transient( $key, $cached, HOUR_IN_SECONDS );
		}
		nocache_headers();
		header( 'Cache-Control: public, max-age=300' );
		wp_send_json_success( $cached );
	}
}
