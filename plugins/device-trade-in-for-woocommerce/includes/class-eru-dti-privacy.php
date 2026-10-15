<?php
/**
 * WordPress privacy exporters and erasers.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leads and trade-ins by e-mail.
 */
class Eru_DTI_Privacy {

	/** Hooks. */
	public static function init() {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $e ) {
				$e['eru-dti-leads']    = array( 'exporter_friendly_name' => __( 'Takas talepleri', 'device-trade-in-for-woocommerce' ), 'callback' => array( __CLASS__, 'export_leads' ) );
				$e['eru-dti-tradeins'] = array( 'exporter_friendly_name' => __( 'Takas kayıtları', 'device-trade-in-for-woocommerce' ), 'callback' => array( __CLASS__, 'export_tradeins' ) );
				return $e;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $e ) {
				$e['eru-dti-leads']    = array( 'eraser_friendly_name' => __( 'Takas talepleri', 'device-trade-in-for-woocommerce' ), 'callback' => array( __CLASS__, 'erase_leads' ) );
				$e['eru-dti-tradeins'] = array( 'eraser_friendly_name' => __( 'Takas kayıtları', 'device-trade-in-for-woocommerce' ), 'callback' => array( __CLASS__, 'erase_tradeins' ) );
				return $e;
			}
		);
	}

	/** @param string $email E-mail. @return array */
	public static function export_leads( $email ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Eru_DTI_DB::table( 'leads' ) . ' WHERE email = %s', $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$data = array();
		foreach ( $rows as $r ) {
			$data[] = array(
				'group_id'    => 'eru-dti-leads',
				'group_label' => __( 'Takas talepleri', 'device-trade-in-for-woocommerce' ),
				'item_id'     => 'eru-dti-lead-' . $r['id'],
				'data'        => array(
					array( 'name' => __( 'Ad', 'device-trade-in-for-woocommerce' ), 'value' => $r['name'] ),
					array( 'name' => __( 'E-posta', 'device-trade-in-for-woocommerce' ), 'value' => $r['email'] ),
					array( 'name' => __( 'Telefon', 'device-trade-in-for-woocommerce' ), 'value' => $r['phone'] ),
					array( 'name' => __( 'Cihaz', 'device-trade-in-for-woocommerce' ), 'value' => $r['device_text'] ),
					array( 'name' => __( 'Tarih', 'device-trade-in-for-woocommerce' ), 'value' => $r['created_at'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/** @param string $email E-mail. @return array */
	public static function export_tradeins( $email ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Eru_DTI_DB::table( 'tradeins' ) . ' WHERE customer_email = %s', $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$data = array();
		foreach ( $rows as $r ) {
			$data[] = array(
				'group_id'    => 'eru-dti-tradeins',
				'group_label' => __( 'Takas kayıtları', 'device-trade-in-for-woocommerce' ),
				'item_id'     => 'eru-dti-tradein-' . $r['id'],
				'data'        => array(
					array( 'name' => __( 'Sipariş', 'device-trade-in-for-woocommerce' ), 'value' => $r['order_id'] ),
					array( 'name' => __( 'Seri no', 'device-trade-in-for-woocommerce' ), 'value' => $r['serial'] ),
					array( 'name' => __( 'Değer', 'device-trade-in-for-woocommerce' ), 'value' => $r['value'] ),
					array( 'name' => __( 'Durum', 'device-trade-in-for-woocommerce' ), 'value' => Eru_DTI_DB::status_label( $r['status'] ) ),
					array( 'name' => __( 'Tarih', 'device-trade-in-for-woocommerce' ), 'value' => $r['created_at'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/** @param string $email E-mail. @return array */
	public static function erase_leads( $email ) {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_DTI_DB::table( 'leads' ) . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}

	/** Trade-in rows stay for accounting; e-mail and serial are cleared. @param string $email E-mail. @return array */
	public static function erase_tradeins( $email ) {
		global $wpdb;
		$n = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Eru_DTI_DB::table( 'tradeins' ) . " SET customer_email = '', serial = '' WHERE customer_email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
