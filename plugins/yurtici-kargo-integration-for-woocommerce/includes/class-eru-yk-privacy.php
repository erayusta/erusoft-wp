<?php
/**
 * Personal data: exporter and eraser for the raw request log (it holds receiver name, address, phone and
 * e-mail for the retention period), and a privacy policy suggestion.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows are found through the orders of the requested e-mail address.
 */
class Eru_YK_Privacy {

	/** Hooks. */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/** Privacy policy suggestion. */
	public static function policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Yurtiçi Kargo Integration for WooCommerce',
				'<p>' . esc_html__( 'Gönderi oluşturulurken teslimat adı, adresi, telefonu ve e-posta adresi Yurtiçi Kargo\'ya iletilir. Eklentinin ham istek günlüğü bu bilgileri ayarlarda belirtilen gün sayısı kadar saklar ve sonra siler.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p>'
			);
		}
	}

	/** @param array $e Exporters. @return array */
	public static function exporters( $e ) {
		$e['eru-yk'] = array(
			'exporter_friendly_name' => __( 'Yurtiçi Kargo istek günlüğü', 'yurtici-kargo-integration-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $e;
	}

	/** @param array $e Erasers. @return array */
	public static function erasers( $e ) {
		$e['eru-yk'] = array(
			'eraser_friendly_name' => __( 'Yurtiçi Kargo istek günlüğü', 'yurtici-kargo-integration-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $e;
	}

	/** @param string $email E-mail. @return int[] Order ids. */
	private static function order_ids( $email ) {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}
		return array_map(
			'intval',
			wc_get_orders(
				array(
					'billing_email' => $email,
					'limit'         => 500,
					'return'        => 'ids',
				)
			)
		);
	}

	/**
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$ids  = self::order_ids( $email );
		$data = array();
		if ( $ids ) {
			$t    = Eru_YK_Logger::log_table();
			$in   = implode( ',', array_map( 'intval', $ids ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, order_id, operation, request FROM {$t} WHERE order_id IN ({$in}) ORDER BY id LIMIT 100 OFFSET %d", max( 0, ( (int) $page - 1 ) * 100 ) ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$data[] = array(
					'group_id'    => 'eru-yk-log',
					'group_label' => __( 'Yurtiçi Kargo istek günlüğü', 'yurtici-kargo-integration-for-woocommerce' ),
					'item_id'     => 'eru-yk-log-' . $r['id'],
					'data'        => array(
						array(
							'name'  => __( 'Tarih', 'yurtici-kargo-integration-for-woocommerce' ),
							'value' => $r['created_at'],
						),
						array(
							'name'  => __( 'Sipariş', 'yurtici-kargo-integration-for-woocommerce' ),
							'value' => $r['order_id'],
						),
						array(
							'name'  => __( 'İşlem', 'yurtici-kargo-integration-for-woocommerce' ),
							'value' => $r['operation'],
						),
						array(
							'name'  => __( 'İstek', 'yurtici-kargo-integration-for-woocommerce' ),
							'value' => $r['request'],
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $data ) < 100,
		);
	}

	/**
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$ids = self::order_ids( $email );
		$n   = 0;
		if ( $ids ) {
			$t = Eru_YK_Logger::log_table();
			$n = (int) $wpdb->query( 'DELETE FROM ' . $t . ' WHERE order_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
			// Timeline messages can quote provider error texts, which may echo receiver data.
			$n += (int) $wpdb->query( 'DELETE FROM ' . Eru_YK_Logger::event_table() . ' WHERE order_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
		}
		unset( $page );
		return array(
			'items_removed'  => $n,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
