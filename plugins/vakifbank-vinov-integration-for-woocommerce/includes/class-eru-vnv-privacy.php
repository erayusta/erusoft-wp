<?php
/**
 * Personal data: exporter and eraser for the masked identity number and the phone kept on Vinov orders, the
 * retention purge, and a privacy policy suggestion.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The identity number itself is never stored (the session holds an HMAC, the order a mask with the first two
 * and last two digits). Per order the plugin keeps `_eru_vnv_tckn_mask` and `_eru_vnv_phone`; both are
 * exported and erased through the WordPress privacy tools (orders found by billing e-mail) and removed from
 * orders older than the retention period by the daily job. Instruction ids, amounts and order notes are
 * business records and stay.
 */
class Eru_VNV_Privacy {

	const PER  = 50;
	const KEYS = array( '_eru_vnv_tckn_mask', '_eru_vnv_phone' );

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
				'VakıfBank Vinov Integration for WooCommerce',
				'<p>' . esc_html__( 'Vinov ile ödemede TC kimlik numaranız ve cep telefonunuz, SMS doğrulaması ve ödeme talimatı için VakıfBank\'a iletilir. TC kimlik numaranız sitede saklanmaz: oturumda yalnızca geri çevrilemez bir özeti (HMAC) tutulur, siparişe yalnızca ilk iki ve son iki hanesi görünen maskeli hâli yazılır. Cep telefonu numaranız destek amacıyla siparişte saklanır. Maskeli numara ve telefon, ayarlarda belirtilen süre sonunda siparişten silinir ve WordPress kişisel veri dışa aktarma/silme araçlarına bağlıdır. Talimat numarası, tutar ve sipariş notları ticari kayıt olarak saklanır.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>'
			);
		}
	}

	/** @param array $e Exporters. @return array */
	public static function exporters( $e ) {
		$e['eru-vnv'] = array(
			'exporter_friendly_name' => __( 'VakıfBank Vinov ödeme bilgileri', 'vakifbank-vinov-integration-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $e;
	}

	/** @param array $e Erasers. @return array */
	public static function erasers( $e ) {
		$e['eru-vnv'] = array(
			'eraser_friendly_name' => __( 'VakıfBank Vinov ödeme bilgileri', 'vakifbank-vinov-integration-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $e;
	}

	/**
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return WC_Order[] Orders of the e-mail address (one page).
	 */
	private static function orders( $email, $page ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return array();
		}
		return wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::PER,
				'paged'         => max( 1, (int) $page ),
				'orderby'       => 'ID',
				'order'         => 'ASC',
				'type'          => 'shop_order',
			)
		);
	}

	/**
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$orders = self::orders( $email, $page );
		$data   = array();
		foreach ( $orders as $o ) {
			$mask  = (string) $o->get_meta( '_eru_vnv_tckn_mask' );
			$phone = (string) $o->get_meta( '_eru_vnv_phone' );
			if ( '' === $mask && '' === $phone ) {
				continue;
			}
			$rows = array(
				array(
					'name'  => __( 'Sipariş', 'vakifbank-vinov-integration-for-woocommerce' ),
					'value' => $o->get_order_number(),
				),
			);
			if ( '' !== $mask ) {
				$rows[] = array(
					'name'  => __( 'TC kimlik no (maskeli)', 'vakifbank-vinov-integration-for-woocommerce' ),
					'value' => $mask,
				);
			}
			if ( '' !== $phone ) {
				$rows[] = array(
					'name'  => __( 'Cep telefonu', 'vakifbank-vinov-integration-for-woocommerce' ),
					'value' => $phone,
				);
			}
			$data[] = array(
				'group_id'    => 'eru-vnv',
				'group_label' => __( 'VakıfBank Vinov ödeme bilgileri', 'vakifbank-vinov-integration-for-woocommerce' ),
				'item_id'     => 'eru-vnv-' . $o->get_id(),
				'data'        => $rows,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $orders ) < self::PER,
		);
	}

	/** @param WC_Order $o Order. @return int Meta values removed. */
	private static function strip( WC_Order $o ) {
		$n = 0;
		foreach ( self::KEYS as $k ) {
			if ( $o->meta_exists( $k ) ) {
				$o->delete_meta_data( $k );
				++$n;
			}
		}
		if ( $n ) {
			$o->save();
		}
		return $n;
	}

	/**
	 * @param string $email E-mail.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		// Erasing does not shrink the result set (the orders stay), so pages advance normally.
		$orders   = self::orders( $email, $page );
		$removed  = 0;
		$retained = false;
		foreach ( $orders as $o ) {
			$removed += self::strip( $o );
			if ( '' !== (string) $o->get_meta( '_eru_vnv_instruction_id' ) ) {
				$retained = true;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Vinov talimat numarası, tutar ve sipariş notları ticari kayıt olarak saklandı; kişisel veri içermez.', 'vakifbank-vinov-integration-for-woocommerce' ) ) : array(),
			'done'           => count( $orders ) < self::PER,
		);
	}

	/**
	 * Retention: removes the mask and phone from orders created before the cut-off, in bounded batches.
	 *
	 * @param int $days Retention in days.
	 * @return int Orders cleaned.
	 */
	public static function purge( $days ) {
		$cut = time() - max( 1, (int) $days ) * DAY_IN_SECONDS;
		$n   = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$orders = wc_get_orders(
				array(
					'limit'        => 100,
					'type'         => 'shop_order',
					'date_created' => '<' . $cut,
					'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'     => '_eru_vnv_tckn_mask',
							'compare' => 'EXISTS',
						),
						array(
							'key'     => '_eru_vnv_phone',
							'compare' => 'EXISTS',
						),
					),
				)
			);
			foreach ( $orders as $o ) {
				self::strip( $o );
				++$n;
			}
			if ( count( $orders ) < 100 ) {
				break;
			}
		}
		return $n;
	}
}
