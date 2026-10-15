<?php
/**
 * Personal data: exporter and eraser for the match log (transaction descriptions can carry payer names),
 * the short-lived transaction cache, and a privacy policy suggestion.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log rows are found through the orders of the requested e-mail address. The ledger holds only the bank
 * transaction id, order id and amount, and is kept so a transfer cannot approve a second order.
 */
class Eru_GHA_Privacy {

	const PER = 100;

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
				'Bank Transfer Auto-Approval for Garanti BBVA',
				'<p>' . esc_html__( 'Havale ile ödenen siparişleri onaylamak için mağazanın Garanti BBVA hesabına gelen hareketler (tutar, tarih, açıklama, gönderen adı ve IBAN) salt-okunur olarak okunur. Son çekilen hareketler, mağaza yöneticisinin elle eşleştirebilmesi için gönderen adıyla birlikte en fazla 10 dakika geçici olarak saklanır ve yönetim ekranında gösterilir; parçalı havaleleri aynı göndericiye göre gruplamak için bu listede gönderen IBAN\'ının (yoksa adının) sitenin gizli anahtarıyla üretilmiş geri çevrilemez bir özeti (HMAC) de tutulur. Kalıcı kayıtlarda (sipariş notu, bildirim e-postası, eşleşme günlüğü) gönderen adı yalnızca baş harfleriyle, IBAN yalnızca son 4 hanesiyle yer alır; günlükteki hareket açıklamalarında gönderen adı baş harflere çevrilir, IBAN ve 11 haneden uzun sayılar maskelenir. Açıklama başka kişisel bilgi içerebilir; eşleşme günlüğü ayarlarda belirtilen gün sayısı kadar saklanır ve sonra silinir. Günlük ve geçici liste WordPress kişisel veri dışa aktarma ve silme araçlarına bağlıdır.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</p>'
			);
		}
	}

	/** @param array $e Exporters. @return array */
	public static function exporters( $e ) {
		$e['eru-gha'] = array(
			'exporter_friendly_name' => __( 'Garanti havale eşleşme günlüğü', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $e;
	}

	/** @param array $e Erasers. @return array */
	public static function erasers( $e ) {
		$e['eru-gha'] = array(
			'eraser_friendly_name' => __( 'Garanti havale eşleşme günlüğü', 'bank-transfer-auto-approval-for-garanti-bbva' ),
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
			$t    = Eru_GHA_Log::table();
			$in   = implode( ',', array_map( 'intval', $ids ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, order_id, type, message, context FROM {$t} WHERE order_id IN ({$in}) ORDER BY id LIMIT %d OFFSET %d", self::PER, max( 0, ( (int) $page - 1 ) * self::PER ) ), ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$data[] = array(
					'group_id'    => 'eru-gha-log',
					'group_label' => __( 'Garanti havale eşleşme günlüğü', 'bank-transfer-auto-approval-for-garanti-bbva' ),
					'item_id'     => 'eru-gha-log-' . $r['id'],
					'data'        => array(
						array(
							'name'  => __( 'Tarih', 'bank-transfer-auto-approval-for-garanti-bbva' ),
							'value' => $r['created_at'],
						),
						array(
							'name'  => __( 'Sipariş', 'bank-transfer-auto-approval-for-garanti-bbva' ),
							'value' => $r['order_id'],
						),
						array(
							'name'  => __( 'Kayıt', 'bank-transfer-auto-approval-for-garanti-bbva' ),
							'value' => $r['type'] . ': ' . $r['message'],
						),
						array(
							'name'  => __( 'Hareket bilgisi', 'bank-transfer-auto-approval-for-garanti-bbva' ),
							'value' => (string) $r['context'],
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => count( $data ) < self::PER,
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
			$n = (int) $wpdb->query( 'DELETE FROM ' . Eru_GHA_Log::table() . ' WHERE order_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore
			// The 10-minute transaction cache is not per customer; drop it whole.
			delete_transient( Eru_GHA_Client::CACHE );
		}
		unset( $page );
		return array(
			'items_removed'  => $n,
			'items_retained' => $ids ? true : false,
			'messages'       => $ids ? array( __( 'Kullanılan havale kaydı (işlem no, sipariş no, tutar) aynı havalenin ikinci bir siparişi onaylamasını önlemek için saklanır; kişisel veri içermez.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ) : array(),
			'done'           => true,
		);
	}
}
