<?php
/**
 * Admin form handlers (admin-post.php). Each *_request() method takes the unslashed POST array, checks the
 * capability and the nonce, and only enqueues work: no marketplace request is made inside a page request.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability manage_woocommerce on every handler (order screen buttons: also edit_shop_orders on that order).
 * Results come back as a one-time notice on the page the form was posted from.
 */
class Eru_MPO_Admin_Actions {

	const CAP = 'manage_woocommerce';

	/** Hooks. */
	public static function init() {
		foreach ( array( 'full_sync', 'migrate', 'sync_now', 'test_connection', 'purge_log', 'refetch', 'ack', 'save_bridge' ) as $a ) {
			add_action( 'admin_post_eru_mpo_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * @param array  $post   Unslashed POST.
	 * @param string $action Nonce action.
	 * @param string $field  Nonce field.
	 * @return true|WP_Error
	 */
	private static function guard( array $post, $action, $field = '_wpnonce' ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new WP_Error( 'eru_mpo_forbidden', __( 'Bu işlem için yetkiniz yok.', 'marketplace-orders-for-woocommerce' ) );
		}
		if ( ! wp_verify_nonce( (string) ( $post[ $field ] ?? '' ), $action ) ) {
			return new WP_Error( 'eru_mpo_bad_nonce', __( 'Güvenlik doğrulaması başarısız; sayfayı yenileyip tekrar deneyin.', 'marketplace-orders-for-woocommerce' ) );
		}
		return true;
	}

	/** @param array $post POST. @return string Known marketplace key ('' = unknown). */
	private static function mp( array $post ) {
		$mp = sanitize_key( (string) ( $post['mp'] ?? '' ) );
		return '' !== $mp && Eru_MPO_Registry::get( $mp ) ? $mp : '';
	}

	/** @return WP_Error */
	private static function unknown() {
		return new WP_Error( 'eru_mpo_unknown', __( 'Bilinmeyen pazaryeri.', 'marketplace-orders-for-woocommerce' ) );
	}

	/** "Tüm stokları gönder": queues the full sync batches. @param array $post POST. @return true|WP_Error */
	public static function full_sync_request( array $post ) {
		$g = self::guard( $post, 'eru_mpo_full_sync' );
		if ( true !== $g ) {
			return $g;
		}
		$mp = self::mp( $post );
		return '' === $mp ? self::unknown() : Eru_MPO_Stock_Bridge::full_sync_start( $mp );
	}

	/** Migrator "Geçişi başlat": schedules the first batch. @param array $post POST. @return true|WP_Error */
	public static function migrate_request( array $post ) {
		$g = self::guard( $post, 'eru_mpo_migrate' );
		return true === $g ? Eru_MPO_Migrator::start() : $g;
	}

	/** "Şimdi senkronla". @param array $post POST. @return true|WP_Error */
	public static function sync_now_request( array $post ) {
		$mp = self::mp( $post );
		$g  = self::guard( $post, 'eru_mpo_sync_now_' . $mp );
		if ( true !== $g ) {
			return $g;
		}
		return '' === $mp ? self::unknown() : Eru_MPO_Sync::run_soon( $mp );
	}

	/** "Bağlantıyı test et": queues one read request. @param array $post POST. @return true|WP_Error */
	public static function test_connection_request( array $post ) {
		$mp = self::mp( $post );
		$g  = self::guard( $post, 'eru_mpo_test_' . $mp );
		if ( true !== $g ) {
			return $g;
		}
		$a = '' !== $mp ? Eru_MPO_Registry::get( $mp ) : null;
		if ( ! $a || ! method_exists( $a, 'test_connection' ) ) {
			return self::unknown();
		}
		if ( ! $a->is_configured() ) {
			return new WP_Error( 'eru_mpo_not_ready', __( 'Önce API bilgilerini girip kaydedin.', 'marketplace-orders-for-woocommerce' ) );
		}
		update_option(
			Eru_MPO_Jobs::conn_option( $mp ),
			array(
				'status' => 'queued',
				'at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
		return Eru_MPO_Jobs::enqueue( Eru_MPO_Jobs::TEST, array( $mp ) ) ? true : new WP_Error( 'eru_mpo_no_scheduler', __( 'Action Scheduler bulunamadı.', 'marketplace-orders-for-woocommerce' ) );
	}

	/** Log purge (expired rows or all rows, optionally of one marketplace). @param array $post POST. @return true|WP_Error */
	public static function purge_log_request( array $post ) {
		$g = self::guard( $post, 'eru_mpo_purge_log' );
		if ( true !== $g ) {
			return $g;
		}
		$scope = 'all' === ( $post['scope'] ?? '' ) ? 'all' : 'expired';
		$mp    = 'all' === $scope ? substr( sanitize_key( (string) ( $post['mp'] ?? '' ) ), 0, 32 ) : '';
		if ( 'all' === $scope && 'yes' !== ( $post['confirm'] ?? '' ) ) {
			return new WP_Error( 'eru_mpo_confirm', __( 'Kayıtları kalıcı olarak silmek için onay kutusunu işaretleyin.', 'marketplace-orders-for-woocommerce' ) );
		}
		return Eru_MPO_Jobs::enqueue( Eru_MPO_Jobs::PURGE, array( $scope, $mp ) ) ? true : new WP_Error( 'eru_mpo_no_scheduler', __( 'Action Scheduler bulunamadı.', 'marketplace-orders-for-woocommerce' ) );
	}

	/**
	 * Order screen buttons share one nonce per order (field eru_mpo_order_nonce).
	 *
	 * @param array $post POST.
	 * @return WC_Order|WP_Error
	 */
	private static function order_from( array $post ) {
		$id = absint( $post['eru_mpo_order_id'] ?? ( $post['order_id'] ?? 0 ) );
		$g  = self::guard( $post, 'eru_mpo_order_' . $id, 'eru_mpo_order_nonce' );
		if ( true !== $g ) {
			return $g;
		}
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order || ! Eru_MPO_Stock::is_marketplace_order( $order ) || ! current_user_can( 'edit_shop_orders', $id ) ) {
			return new WP_Error( 'eru_mpo_unknown', __( 'Pazaryeri siparişi bulunamadı.', 'marketplace-orders-for-woocommerce' ) );
		}
		return $order;
	}

	/** "Yeniden çek": queues the refetch of the order's marketplace. @param array $post POST. @return true|WP_Error */
	public static function refetch_request( array $post ) {
		$order = self::order_from( $post );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		return Eru_MPO_Jobs::enqueue( Eru_MPO_Jobs::REFETCH, array( (int) $order->get_id() ) ) ? true : new WP_Error( 'eru_mpo_no_scheduler', __( 'Action Scheduler bulunamadı.', 'marketplace-orders-for-woocommerce' ) );
	}

	/**
	 * "Pazaryerine bildir / paketle": queues the acknowledgement. A failed one starts over (an explicit decision of
	 * the admin); a done one is never sent again.
	 *
	 * @param array $post POST.
	 * @return true|WP_Error
	 */
	public static function ack_request( array $post ) {
		$order = self::order_from( $post );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$a = Eru_MPO_Registry::get( (string) $order->get_meta( '_eru_mpo_marketplace' ) );
		if ( ! $a || 'never' === Eru_MPO_Ack::mode( $a->key() ) ) {
			return new WP_Error( 'eru_mpo_not_ready', __( 'Bu pazaryerinde bildirim/paketleme modu "Gönderme": önce pazaryeri sekmesinden açın.', 'marketplace-orders-for-woocommerce' ) );
		}
		$p = Eru_MPO_Ack::profile( $a );
		if ( 'failed' === (string) $order->get_meta( $p['state'] ) ) {
			$order->delete_meta_data( $p['state'] );
			$order->delete_meta_data( $p['tries'] );
			$order->save_meta_data();
		}
		return Eru_MPO_Ack::enqueue( $order ) ? true : new WP_Error( 'eru_mpo_done', __( 'Bu sipariş için bildirim zaten yapılmış.', 'marketplace-orders-for-woocommerce' ) );
	}

	/** Stock bridge form of one marketplace. @param array $post POST. @return true|WP_Error */
	public static function save_bridge_request( array $post ) {
		$mp = self::mp( $post );
		$g  = self::guard( $post, 'eru_mpo_save_bridge_' . $mp );
		if ( true !== $g ) {
			return $g;
		}
		if ( '' === $mp ) {
			return self::unknown();
		}
		$in = isset( $post['bridge'] ) && is_array( $post['bridge'] ) ? $post['bridge'] : array();
		Eru_MPO_Settings::mp_update(
			$mp,
			array(
				'push_stock' => 'yes' === ( $in['push_stock'] ?? '' ) ? 'yes' : 'no',
				'push_price' => 'yes' === ( $in['push_price'] ?? '' ) ? 'yes' : 'no',
				'rules'      => Eru_MPO_Rules::sanitize(
					array(
						'excluded_cats'    => (array) ( $in['excluded_cats'] ?? array() ),
						'excluded_brands'  => (array) ( $in['excluded_brands'] ?? array() ),
						'excluded_skus'    => (string) ( $in['excluded_skus'] ?? '' ),
						'stock_floor'      => $in['stock_floor'] ?? 0,
						'stock_ceiling'    => $in['stock_ceiling'] ?? 0,
						'untracked_qty'    => $in['untracked_qty'] ?? null,
						'price_multiplier' => $in['price_multiplier'] ?? '1',
						'price_add'        => $in['price_add'] ?? '0',
						'rounding'         => $in['rounding'] ?? 'none',
					)
				),
			)
		);
		// Rules only affect what is sent from now on: offer a full sync once on the tab.
		set_transient( 'eru_mpo_rules_saved_' . get_current_user_id(), $mp, HOUR_IN_SECONDS );
		return true;
	}

	/** @return array Unslashed POST. */
	private static function post() {
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler checks its nonce.
	}

	/**
	 * Stores the outcome as a one-time notice and redirects back.
	 *
	 * @param true|WP_Error $r   Result.
	 * @param string        $ok  Success text.
	 * @param string        $url Where to go.
	 */
	private static function finish( $r, $ok, $url ) {
		if ( is_wp_error( $r ) && in_array( $r->get_error_code(), array( 'eru_mpo_forbidden', 'eru_mpo_bad_nonce' ), true ) ) {
			wp_die( esc_html( $r->get_error_message() ), 403 );
		}
		set_transient(
			'eru_mpo_notice_' . get_current_user_id(),
			array(
				'type' => is_wp_error( $r ) ? 'error' : 'success',
				'text' => is_wp_error( $r ) ? $r->get_error_message() : $ok,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** @param string $tab Tab. @return string Page URL. */
	private static function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => Eru_MPO_Admin::PAGE,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/** @param int $id Order. @return string Edit screen URL. */
	private static function order_url( $id ) {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $id ) : admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
	}

	/** admin-post handlers. */
	public static function handle_full_sync() {
		$p = self::post();
		self::finish( self::full_sync_request( $p ), __( 'Tüm stok ve fiyatlar arka planda kuyruğa alınıyor; ilerlemeyi bu sekmede görebilirsiniz.', 'marketplace-orders-for-woocommerce' ), self::tab_url( 'stok' ) );
	}

	/** admin-post. */
	public static function handle_migrate() {
		self::finish( self::migrate_request( self::post() ), __( 'Geçiş başlatıldı; arka planda gruplar halinde çalışıyor.', 'marketplace-orders-for-woocommerce' ), self::tab_url( 'gecis' ) );
	}

	/** admin-post. */
	public static function handle_sync_now() {
		$p = self::post();
		self::finish( self::sync_now_request( $p ), __( 'Senkronizasyon kuyruğa alındı; birkaç saniye içinde arka planda çalışır.', 'marketplace-orders-for-woocommerce' ), self::tab_url( sanitize_key( (string) ( $p['mp'] ?? 'genel' ) ) ) );
	}

	/** admin-post. */
	public static function handle_test_connection() {
		$p = self::post();
		self::finish( self::test_connection_request( $p ), __( 'Bağlantı testi kuyruğa alındı; sonucu birkaç saniye sonra bu sayfada görünür.', 'marketplace-orders-for-woocommerce' ), self::tab_url( sanitize_key( (string) ( $p['mp'] ?? 'genel' ) ) ) );
	}

	/** admin-post. */
	public static function handle_purge_log() {
		self::finish( self::purge_log_request( self::post() ), __( 'Günlük temizliği kuyruğa alındı.', 'marketplace-orders-for-woocommerce' ), self::tab_url( 'gunluk' ) );
	}

	/** admin-post. */
	public static function handle_refetch() {
		$p = self::post();
		self::finish( self::refetch_request( $p ), __( 'Pazaryerinden çekim kuyruğa alındı; birkaç saniye içinde arka planda çalışır.', 'marketplace-orders-for-woocommerce' ), self::order_url( absint( $p['eru_mpo_order_id'] ?? 0 ) ) );
	}

	/** admin-post. */
	public static function handle_ack() {
		$p = self::post();
		self::finish( self::ack_request( $p ), __( 'Pazaryeri bildirimi kuyruğa alındı.', 'marketplace-orders-for-woocommerce' ), self::order_url( absint( $p['eru_mpo_order_id'] ?? 0 ) ) );
	}

	/** admin-post. */
	public static function handle_save_bridge() {
		self::finish( self::save_bridge_request( self::post() ), __( 'Stok köprüsü ayarları kaydedildi.', 'marketplace-orders-for-woocommerce' ), self::tab_url( 'stok' ) );
	}
}
