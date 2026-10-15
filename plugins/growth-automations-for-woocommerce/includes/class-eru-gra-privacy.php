<?php
/**
 * Privacy exporter, eraser and retention purge for captured carts, alerts and generated coupons.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the plugin stores about an e-mail address.
 */
class Eru_GRA_Privacy {

	const GROUP = 'eru-gra';

	/** Hooks. */
	public static function init() {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $e ) {
				$e[ self::GROUP ] = array( 'exporter_friendly_name' => __( 'Büyüme otomasyonları', 'growth-automations-for-woocommerce' ), 'callback' => array( __CLASS__, 'export' ) );
				return $e;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $e ) {
				$e[ self::GROUP ] = array( 'eraser_friendly_name' => __( 'Büyüme otomasyonları', 'growth-automations-for-woocommerce' ), 'callback' => array( __CLASS__, 'erase' ) );
				return $e;
			}
		);
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	/** Suggested privacy-policy text. */
	public static function policy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Growth Automations for WooCommerce',
				'<p>' . esc_html__( 'Onayınız varsa e-posta adresiniz ve sepetinizdeki ürün/adet bilgisi, siparişi tamamlamamanız halinde size hatırlatma göndermek için saklanır ve saklama süresi sonunda silinir. Stok bildirimi için e-posta adresiniz onayınızdan sonra ürün stoğa girene kadar tutulur; bildirim gönderildikten sonra saklama süresi sonunda silinir. Her pazarlama e-postasında vazgeçme bağlantısı bulunur; vazgeçen adresler yalnızca özet (hash) olarak bir engelleme listesinde tutulur.', 'growth-automations-for-woocommerce' ) . '</p>'
			);
		}
	}

	/** @param string $email E-mail. @return int[] Alert post IDs. */
	private static function alert_ids( $email ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s", Eru_GRA_Alerts::CPT, Eru_GRA_Consent::norm( $email ) ) ) );
	}

	/** @param string $email E-mail. @return int[] Generated coupon IDs restricted to the address (at most 100). */
	private static function coupon_ids( $email ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"' . Eru_GRA_Consent::norm( $email ) . '"' ) . '%';
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = %s INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = 'customer_email' WHERE p.post_type = 'shop_coupon' AND e.meta_value LIKE %s LIMIT 100", Eru_GRA_Coupons::META, $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param string $email E-mail. @return array[] Cart rows. */
	private static function cart_rows( $email ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Eru_GRA_DB::carts() . ' WHERE email = %s ORDER BY id DESC LIMIT 100', Eru_GRA_Consent::norm( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param string $email E-mail. @return array */
	public static function export( $email ) {
		$data  = array();
		$label = __( 'Büyüme otomasyonları', 'growth-automations-for-woocommerce' );
		foreach ( self::cart_rows( $email ) as $r ) {
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => $label,
				'item_id'     => 'eru-gra-cart-' . $r['id'],
				'data'        => array(
					array( 'name' => __( 'E-posta', 'growth-automations-for-woocommerce' ), 'value' => $r['email'] ),
					array( 'name' => __( 'Onay kaynağı', 'growth-automations-for-woocommerce' ), 'value' => $r['consent_source'] ),
					array( 'name' => __( 'Durum', 'growth-automations-for-woocommerce' ), 'value' => $r['status'] ),
					array( 'name' => __( 'Sepet (ürün ve adet)', 'growth-automations-for-woocommerce' ), 'value' => $r['cart'] ),
					array( 'name' => __( 'Kayıt tarihi (UTC)', 'growth-automations-for-woocommerce' ), 'value' => $r['created_at'] ),
				),
			);
		}
		foreach ( self::alert_ids( $email ) as $id ) {
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => $label,
				'item_id'     => 'eru-gra-alert-' . $id,
				'data'        => array(
					array( 'name' => __( 'E-posta', 'growth-automations-for-woocommerce' ), 'value' => get_post_field( 'post_title', $id ) ),
					array( 'name' => __( 'Stok bildirimi ürünü', 'growth-automations-for-woocommerce' ), 'value' => (int) get_post_meta( $id, '_eru_gra_target', true ) ),
					array( 'name' => __( 'Durum', 'growth-automations-for-woocommerce' ), 'value' => get_post_meta( $id, '_eru_gra_state', true ) ),
					array( 'name' => __( 'Onay tarihi (UTC)', 'growth-automations-for-woocommerce' ), 'value' => get_post_meta( $id, '_eru_gra_confirmed_at', true ) ),
				),
			);
		}
		foreach ( self::coupon_ids( $email ) as $cid ) {
			$c      = new WC_Coupon( $cid );
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => $label,
				'item_id'     => 'eru-gra-coupon-' . $cid,
				'data'        => array(
					array( 'name' => __( 'Kupon kodu', 'growth-automations-for-woocommerce' ), 'value' => $c->get_code() ),
					array( 'name' => __( 'E-posta kısıtı', 'growth-automations-for-woocommerce' ), 'value' => implode( ', ', $c->get_email_restrictions() ) ),
					array( 'name' => __( 'Kullanım sayısı', 'growth-automations-for-woocommerce' ), 'value' => $c->get_usage_count() ),
				),
			);
		}
		$user = get_user_by( 'email', $email );
		if ( $user && '' !== (string) get_user_meta( $user->ID, Eru_GRA_Consent::USER_META, true ) ) {
			$data[] = array(
				'group_id'    => self::GROUP,
				'group_label' => $label,
				'item_id'     => 'eru-gra-optin-' . $user->ID,
				'data'        => array( array( 'name' => __( 'Pazarlama e-postası izni', 'growth-automations-for-woocommerce' ), 'value' => get_user_meta( $user->ID, Eru_GRA_Consent::USER_META, true ) ) ),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Removes carts, alerts and the address from generated coupons (unused ones are deleted, used ones keep only
	 * accounting data). The suppression entry is a one-way hash kept so the address is not mailed again.
	 *
	 * @param string $email E-mail. @return array
	 */
	public static function erase( $email ) {
		global $wpdb;
		$n     = 0;
		$email = Eru_GRA_Consent::norm( $email );
		$n    += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_GRA_DB::carts() . ' WHERE email = %s', $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( self::alert_ids( $email ) as $id ) {
			$n += wp_delete_post( $id, true ) ? 1 : 0;
		}
		$cids = self::coupon_ids( $email );
		foreach ( $cids as $cid ) {
			$c = new WC_Coupon( $cid );
			if ( 0 === (int) $c->get_usage_count() ) {
				$c->delete( true );
			} else {
				$c->set_email_restrictions( array() );
				$c->save();
				delete_post_meta( $cid, '_used_by' );
			}
			++$n;
		}
		$user = get_user_by( 'email', $email );
		if ( $user && '' !== (string) get_user_meta( $user->ID, Eru_GRA_Consent::USER_META, true ) ) {
			delete_user_meta( $user->ID, Eru_GRA_Consent::USER_META );
			++$n;
		}
		$kept = Eru_GRA_Consent::has_record( $email );
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => $kept,
			'messages'       => $kept ? array( __( 'Pazarlama e-postasından vazgeçme kaydı (tek yönlü özet) yeniden e-posta gönderilmemesi için saklanır.', 'growth-automations-for-woocommerce' ) ) : array(),
			'done'           => count( $cids ) < 100, // More coupons than one batch: WordPress calls again.
		);
	}

	/** Retention purge in batches: old carts and alerts, expired unconfirmed alerts. Re-arms itself while more remain. */
	public static function purge() {
		global $wpdb;
		$size   = Eru_GRA_Settings::batch_size();
		$days   = max( 1, (int) Eru_GRA_Settings::get( 'general', 'retention_days' ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$carts  = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_GRA_DB::carts() . ' WHERE updated_at < %s LIMIT %d', $cutoff, $size ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$base  = array(
			'post_type'      => Eru_GRA_Alerts::CPT,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => $size,
			'no_found_rows'  => true,
		);
		// Confirmed alerts that still wait for a restock are kept (see the privacy text); only finished ones age out.
		$old   = get_posts(
			$base + array(
				'date_query' => array( array( 'before' => $cutoff, 'column' => 'post_date_gmt' ) ),
				'meta_query' => array( array( 'key' => '_eru_gra_state', 'value' => array( 'sent', 'pending' ), 'compare' => 'IN' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$stale = get_posts(
			$base + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_eru_gra_state', 'value' => 'pending' ),
					array( 'key' => '_eru_gra_token_expires', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( array_unique( array_merge( $old, $stale ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( $carts >= $size || count( $old ) >= $size || count( $stale ) >= $size ) {
			Eru_GRA_Runner::rearm_hook( 'eru_gra_purge_more', array() );
		}
	}
}
