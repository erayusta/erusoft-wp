<?php
/**
 * WordPress privacy exporter and eraser for subscribers.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subscribers by e-mail.
 */
class Eru_CB_Privacy {

	/** Hooks. */
	public static function init() {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $e ) {
				$e['eru-cb-subscribers'] = array( 'exporter_friendly_name' => __( 'Bülten aboneliği', 'conversion-boosters-for-woocommerce' ), 'callback' => array( __CLASS__, 'export' ) );
				return $e;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $e ) {
				$e['eru-cb-subscribers'] = array( 'eraser_friendly_name' => __( 'Bülten aboneliği', 'conversion-boosters-for-woocommerce' ), 'callback' => array( __CLASS__, 'erase' ) );
				return $e;
			}
		);
	}

	/** @param string $email E-mail. @return int[] Subscriber IDs. */
	private static function ids( $email ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s", Eru_CB_Newsletter::CPT, strtolower( $email ) ) ) );
	}

	/**
	 * Coupons that hold the address: the one recorded on the subscriber plus any generated coupon restricted to it.
	 *
	 * @param string $email E-mail. @return int[] Coupon IDs (at most 100).
	 */
	private static function coupon_ids( $email ) {
		global $wpdb;
		$email = strtolower( $email );
		$ids   = array();
		foreach ( self::ids( $email ) as $sid ) {
			$code = (string) get_post_meta( $sid, '_eru_cb_coupon', true );
			$cid  = '' !== $code ? wc_get_coupon_id_by_code( $code ) : 0;
			if ( $cid && '1' === (string) get_post_meta( $cid, Eru_CB_Coupons::META, true ) ) {
				$ids[] = (int) $cid;
			}
		}
		$like = '%' . $wpdb->esc_like( '"' . $email . '"' ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = 'customer_email' WHERE p.post_type = 'shop_coupon' AND e.meta_value LIKE %s LIMIT 100", Eru_CB_Coupons::META, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_values( array_unique( array_merge( $ids, array_map( 'intval', $rows ) ) ) );
	}

	/** @param string $email E-mail. @return array */
	public static function export( $email ) {
		$data = array();
		foreach ( self::ids( $email ) as $id ) {
			$data[] = array(
				'group_id'    => 'eru-cb-subscribers',
				'group_label' => __( 'Bülten aboneliği', 'conversion-boosters-for-woocommerce' ),
				'item_id'     => 'eru-cb-subscriber-' . $id,
				'data'        => array(
					array( 'name' => __( 'E-posta', 'conversion-boosters-for-woocommerce' ), 'value' => get_the_title( $id ) ),
					array( 'name' => __( 'Durum', 'conversion-boosters-for-woocommerce' ), 'value' => get_post_meta( $id, '_eru_cb_status', true ) ),
					array( 'name' => __( 'Onay tarihi (UTC)', 'conversion-boosters-for-woocommerce' ), 'value' => get_post_meta( $id, '_eru_cb_consent_at', true ) ),
					array( 'name' => __( 'Onay metni', 'conversion-boosters-for-woocommerce' ), 'value' => get_post_meta( $id, '_eru_cb_consent_text', true ) ),
					array( 'name' => __( 'Kaynak', 'conversion-boosters-for-woocommerce' ), 'value' => get_post_meta( $id, '_eru_cb_source', true ) ),
					array( 'name' => __( 'Kupon', 'conversion-boosters-for-woocommerce' ), 'value' => get_post_meta( $id, '_eru_cb_coupon', true ) ),
				),
			);
		}
		foreach ( self::coupon_ids( $email ) as $cid ) {
			$c      = new WC_Coupon( $cid );
			$data[] = array(
				'group_id'    => 'eru-cb-subscribers',
				'group_label' => __( 'Bülten aboneliği', 'conversion-boosters-for-woocommerce' ),
				'item_id'     => 'eru-cb-coupon-' . $cid,
				'data'        => array(
					array( 'name' => __( 'Kupon kodu', 'conversion-boosters-for-woocommerce' ), 'value' => $c->get_code() ),
					array( 'name' => __( 'E-posta kısıtı', 'conversion-boosters-for-woocommerce' ), 'value' => implode( ', ', $c->get_email_restrictions() ) ),
					array( 'name' => __( 'Kullanım sayısı', 'conversion-boosters-for-woocommerce' ), 'value' => $c->get_usage_count() ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Removes the subscriber and the address from its coupons: unused generated coupons are deleted, used ones keep
	 * only their accounting data (restriction and used-by e-mails removed).
	 *
	 * @param string $email E-mail. @return array
	 */
	public static function erase( $email ) {
		$n = 0;
		foreach ( self::coupon_ids( $email ) as $cid ) {
			$c = new WC_Coupon( $cid );
			if ( 0 === (int) $c->get_usage_count() ) {
				$c->delete( true ); // Also clears WooCommerce's code lookup cache.
				++$n;
				continue;
			}
			$c->set_email_restrictions( array() );
			$c->save();
			delete_post_meta( $cid, '_used_by' );
			++$n;
		}
		foreach ( self::ids( $email ) as $id ) {
			$n += wp_delete_post( $id, true ) ? 1 : 0;
		}
		return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
