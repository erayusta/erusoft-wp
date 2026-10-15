<?php
/**
 * Admin: order metabox (dispatch / query / cancel / label + timeline), order list column, bulk dispatch,
 * log viewer with purge.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The metabox sits inside the order form, so its buttons are type="button" and POST to admin-ajax.php
 * with their own per-order nonce. "Gönder" only queues the Action Scheduler job.
 */
class Eru_YK_Admin {

	const CAP = 'edit_shop_orders';

	/** Hooks. */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_eru_yk_order', array( __CLASS__, 'ajax' ) );

		// Order list (HPOS and classic).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		foreach ( array( 'woocommerce_page_wc-orders', 'edit-shop_order' ) as $screen ) {
			add_filter( 'bulk_actions-' . $screen, array( __CLASS__, 'bulk_actions' ) );
			add_filter( 'handle_bulk_actions-' . $screen, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		}
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		// Log viewer (hidden sub-page of WooCommerce, linked from the settings tab and the metabox).
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_head', fn() => remove_submenu_page( 'woocommerce', 'eru-yk-log' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_action( 'admin_post_eru_yk_purge', array( __CLASS__, 'purge' ) );
	}

	/** @return string[] Order edit screen ids. */
	private static function screens() {
		$s = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$s[] = wc_get_page_screen_id( 'shop-order' );
		}
		return array_unique( $s );
	}

	/** Scripts and styles on order and log screens. */
	public static function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$orders = in_array( $screen->id, self::screens(), true ) || in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( $orders || 'admin_page_eru-yk-log' === $screen->id || false !== strpos( (string) $screen->id, 'eru-yk-log' ) ) {
			wp_enqueue_style( 'eru-yk-admin', ERU_YK_URL . 'assets/admin.css', array(), ERU_YK_VERSION );
		}
		if ( in_array( $screen->id, self::screens(), true ) ) {
			wp_enqueue_script( 'eru-yk-metabox', ERU_YK_URL . 'assets/metabox.js', array(), ERU_YK_VERSION, true );
			wp_localize_script(
				'eru-yk-metabox',
				'eruYkMetabox',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'confirm' => array(
						'dispatch' => __( 'Yurtiçi gönderisi oluşturulsun mu? (Arka planda çalışır.)', 'yurtici-kargo-integration-for-woocommerce' ),
						'cancel'   => __( 'Yurtiçi gönderisi iptal edilsin mi? Yeniden gönderimde yeni bir kargo anahtarı kullanılır.', 'yurtici-kargo-integration-for-woocommerce' ),
						'reset'    => __( 'Gönderinin Yurtiçi\'de oluşmadığını (veya Yurtiçi tarafında iptal edildiğini) onaylıyor musunuz? Kayıt yerel olarak sıfırlanır, eski kargo anahtarı bir daha kullanılmaz.', 'yurtici-kargo-integration-for-woocommerce' ),
					),
					'error'   => __( 'İstek başarısız oldu.', 'yurtici-kargo-integration-for-woocommerce' ),
				)
			);
		}
	}

	/** Registers the box. */
	public static function add_box() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		foreach ( self::screens() as $screen ) {
			add_meta_box( 'eru-yk-order', __( 'Yurtiçi Kargo', 'yurtici-kargo-integration-for-woocommerce' ), array( __CLASS__, 'render_box' ), $screen, 'side', 'high' );
		}
	}

	/** @param WC_Order $order Order. @return string Label URL (per-order nonce). */
	public static function label_url( WC_Order $order ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=eru_yk_label&order=' . $order->get_id() ), 'eru_yk_label_' . $order->get_id() );
	}

	/** @param WP_Post|WC_Order $post_or_order Object. */
	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}
		$D        = 'Eru_YK_Dispatcher';
		$state    = (string) $order->get_meta( $D::M_STATE );
		$states   = $D::states();
		$key      = (string) $order->get_meta( $D::M_KEY );
		$tracking = (string) $order->get_meta( $D::M_TRACKING );
		$url      = (string) $order->get_meta( $D::M_URL );
		$error    = (string) $order->get_meta( $D::M_ERROR );
		$queued   = $D::has_pending( $D::HOOK, array( $order->get_id() ) ) || $D::has_pending( $D::HOOK, array( $order->get_id(), 1 ) );

		echo '<div class="eru-yk-box">';
		echo '<p><span class="eru-yk-state eru-yk-state-' . esc_attr( '' !== $state ? $state : 'none' ) . '">' . esc_html( $queued ? __( 'Kuyrukta', 'yurtici-kargo-integration-for-woocommerce' ) : ( $states[ $state ] ?? $state ) ) . '</span>';
		if ( Eru_YK_Settings::test_mode() ) {
			echo ' <span class="eru-yk-test">' . esc_html__( 'Test modu', 'yurtici-kargo-integration-for-woocommerce' ) . '</span>';
		}
		echo '</p><dl class="eru-yk-dl">';
		$rows = array(
			__( 'Hesap', 'yurtici-kargo-integration-for-woocommerce' )         => (string) $order->get_meta( $D::M_LABEL ),
			__( 'Kargo anahtarı', 'yurtici-kargo-integration-for-woocommerce' ) => $key,
			__( 'İş no', 'yurtici-kargo-integration-for-woocommerce' )         => (string) $order->get_meta( $D::M_JOB ),
			__( 'Son durum', 'yurtici-kargo-integration-for-woocommerce' )     => (string) $order->get_meta( $D::M_LASTEVENT ),
		);
		foreach ( $rows as $label => $v ) {
			if ( '' !== $v ) {
				echo '<dt>' . esc_html( $label ) . '</dt><dd><code>' . esc_html( $v ) . '</code></dd>';
			}
		}
		if ( '' !== $tracking ) {
			echo '<dt>' . esc_html__( 'Takip no', 'yurtici-kargo-integration-for-woocommerce' ) . '</dt><dd>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $tracking ) . '</a>' : esc_html( $tracking ) ) . '</dd>';
		}
		echo '</dl>';
		if ( '' !== $error && ! in_array( $state, $D::LIVE, true ) ) {
			echo '<p class="eru-yk-error">' . esc_html( $error ) . '</p>';
		}

		if ( Eru_YK_Settings::is_enabled() && current_user_can( self::CAP ) ) {
			$nonce = wp_create_nonce( 'eru_yk_order_' . $order->get_id() );
			echo '<p class="eru-yk-actions">';
			if ( ! in_array( $state, $D::LIVE, true ) && 'cancelled' !== $state ) {
				self::button( 'dispatch', '' !== $key ? __( 'Yeniden dene', 'yurtici-kargo-integration-for-woocommerce' ) : __( 'Yurtiçi\'ne gönder', 'yurtici-kargo-integration-for-woocommerce' ), $order, $nonce, 'button-primary' );
			}
			if ( '' !== $key ) {
				self::button( 'query', __( 'Sorgula', 'yurtici-kargo-integration-for-woocommerce' ), $order, $nonce );
			}
			if ( '' !== $key && ! in_array( $state, array( 'delivered', 'cancelled' ), true ) ) {
				self::button( 'cancel', __( 'İptal', 'yurtici-kargo-integration-for-woocommerce' ), $order, $nonce );
			}
			if ( $D::can_reset( $state ) ) {
				self::button( 'reset', __( 'Yerel sıfırla', 'yurtici-kargo-integration-for-woocommerce' ), $order, $nonce );
			}
			if ( in_array( $state, $D::LIVE, true ) ) {
				echo '<a class="button" target="_blank" rel="noopener" href="' . esc_url( self::label_url( $order ) ) . '">' . esc_html__( 'Etiket', 'yurtici-kargo-integration-for-woocommerce' ) . '</a>';
			}
			echo '</p><p class="eru-yk-result" role="status" aria-live="polite"></p>';
		} elseif ( ! Eru_YK_Settings::is_enabled() ) {
			echo '<p class="description">' . esc_html__( 'Entegrasyon kapalı.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p>';
		}

		$events = Eru_YK_Logger::events_for( $order->get_id(), 20 );
		if ( $events ) {
			echo '<h4>' . esc_html__( 'Zaman tüneli', 'yurtici-kargo-integration-for-woocommerce' ) . '</h4><ol class="eru-yk-timeline">';
			foreach ( $events as $e ) {
				$ts = strtotime( $e['created_at'] . ' UTC' );
				echo '<li class="eru-yk-ev-' . esc_attr( $e['level'] ) . '"><time>' . esc_html( wp_date( 'd.m.Y H:i', $ts ) ) . '</time> ' . esc_html( $e['message'] ) . '</li>';
			}
			echo '</ol>';
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=eru-yk-log&order=' . $order->get_id() ) ) . '">' . esc_html__( 'Günlükte göster', 'yurtici-kargo-integration-for-woocommerce' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** Button. */
	private static function button( $do, $label, WC_Order $order, $nonce, $class = '' ) {
		echo '<button type="button" class="button ' . esc_attr( $class ) . ' eru-yk-btn" data-do="' . esc_attr( $do ) . '" data-order="' . esc_attr( (string) $order->get_id() ) . '" data-nonce="' . esc_attr( $nonce ) . '">' . esc_html( $label ) . '</button> ';
	}

	/**
	 * Core of the metabox buttons (tested without output).
	 *
	 * @param string $do      dispatch|query|cancel|reset.
	 * @param int    $id      Order id.
	 * @param string $nonce   Nonce.
	 * @param bool   $confirm Admin confirmed "gönderi oluşmadı" (reset).
	 * @return array{code:int,ok:bool,message:string,reload?:bool}
	 */
	public static function handle( $do, $id, $nonce, $confirm = false ) {
		$id = absint( $id );
		if ( ! current_user_can( self::CAP ) ) {
			return array( 'code' => 403, 'ok' => false, 'message' => __( 'Yetkiniz yok.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		if ( ! $id || ! wp_verify_nonce( (string) $nonce, 'eru_yk_order_' . $id ) ) {
			return array( 'code' => 400, 'ok' => false, 'message' => __( 'Geçersiz istek; sayfayı yenileyin.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return array( 'code' => 404, 'ok' => false, 'message' => __( 'Sipariş bulunamadı.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		if ( ! Eru_YK_Settings::is_enabled() ) {
			return array( 'code' => 409, 'ok' => false, 'message' => __( 'Entegrasyon kapalı.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		if ( 'dispatch' === $do ) {
			if ( in_array( (string) $order->get_meta( Eru_YK_Dispatcher::M_STATE ), Eru_YK_Dispatcher::LIVE, true ) ) {
				return array( 'code' => 409, 'ok' => false, 'message' => __( 'Bu siparişin gönderisi zaten var.', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			$order->delete_meta_data( Eru_YK_Dispatcher::M_TRIES );
			$order->save();
			$q = Eru_YK_Dispatcher::enqueue( $id, true );
			if ( $q ) {
				Eru_YK_Logger::event( $id, 'queued', __( 'Gönderim kuyruğa alındı (elle).', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			return array( 'code' => 200, 'ok' => true, 'message' => $q ? __( 'Gönderim kuyruğa alındı; birazdan sayfayı yenileyin.', 'yurtici-kargo-integration-for-woocommerce' ) : __( 'Bu sipariş zaten kuyrukta.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		if ( 'query' === $do ) {
			$state = (string) $order->get_meta( Eru_YK_Dispatcher::M_STATE );
			if ( in_array( $state, array( 'dispatched', 'in_transit' ), true ) ) {
				// Same per-order lock as dispatch/cancel, so a cron pass cannot apply a transition twice.
				if ( ! Eru_YK_Lock::acquire( 'order_' . $id, Eru_YK_Dispatcher::STALE ) ) {
					return array( 'code' => 409, 'ok' => false, 'message' => __( 'Bu sipariş için başka bir işlem sürüyor; birazdan tekrar deneyin.', 'yurtici-kargo-integration-for-woocommerce' ) );
				}
				try {
					$r = Eru_YK_Tracker::track( wc_get_order( $id ) );
				} finally {
					Eru_YK_Lock::release( 'order_' . $id );
				}
				$o = wc_get_order( $id );
				return array( 'code' => 200, 'ok' => true, 'reload' => true, 'message' => $r . ( $o ? ' — ' . (string) $o->get_meta( Eru_YK_Dispatcher::M_LASTEVENT ) : '' ) );
			}
			$key     = (string) $order->get_meta( Eru_YK_Dispatcher::M_KEY );
			$account = Eru_YK_Accounts::usable()[ (string) $order->get_meta( Eru_YK_Dispatcher::M_ACCOUNT ) ] ?? null;
			if ( '' === $key || ! $account ) {
				return array( 'code' => 409, 'ok' => false, 'message' => __( 'Sorgulanacak kargo anahtarı yok.', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			$q = ( new Eru_YK_Client( $account ) )->query( $key, $id );
			$d = $q['answered'] ? Eru_YK_Client::delivery( $q['data'] ) : null;
			if ( $d ) {
				/* translators: 1: cargo key, 2: provider status */
				$msg = sprintf( __( '%1$s Yurtiçi\'de kayıtlı: %2$s. Bağlamak için "Yeniden dene"ye basın.', 'yurtici-kargo-integration-for-woocommerce' ), $key, trim( $d['status'] . ' ' . $d['message'] ) );
			} elseif ( $q['answered'] ) {
				/* translators: %s: cargo key */
				$msg = sprintf( __( '%s için Yurtiçi\'de kayıt yok.', 'yurtici-kargo-integration-for-woocommerce' ), $key );
			} else {
				$msg = Eru_YK_Dispatcher::describe( $q );
			}
			Eru_YK_Logger::event( $id, 'manual_query', $msg );
			return array( 'code' => 200, 'ok' => true, 'message' => $msg );
		}
		if ( 'reset' === $do ) {
			$r = Eru_YK_Dispatcher::reset_local( $id, (bool) $confirm );
			return array( 'code' => $r['ok'] ? 200 : 409, 'ok' => $r['ok'], 'message' => $r['message'], 'reload' => $r['ok'] );
		}
		if ( 'cancel' === $do ) {
			$r = Eru_YK_Dispatcher::cancel( $id );
			return array( 'code' => $r['ok'] ? 200 : 409, 'ok' => $r['ok'], 'message' => $r['message'], 'reload' => $r['ok'] );
		}
		return array( 'code' => 400, 'ok' => false, 'message' => __( 'Bilinmeyen işlem.', 'yurtici-kargo-integration-for-woocommerce' ) );
	}

	/** admin-ajax handler. */
	public static function ajax() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$r = self::handle( sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ), absint( $_POST['order'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), '1' === ( $_POST['confirm'] ?? '' ) );
		// phpcs:enable
		wp_send_json(
			array(
				'ok'      => $r['ok'],
				'message' => $r['message'],
				'reload'  => ! empty( $r['reload'] ),
			),
			$r['code']
		);
	}

	/** @param array $cols Columns. @return array */
	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['eru_yk'] = __( 'Yurtiçi', 'yurtici-kargo-integration-for-woocommerce' );
			}
		}
		if ( ! isset( $out['eru_yk'] ) ) {
			$out['eru_yk'] = __( 'Yurtiçi', 'yurtici-kargo-integration-for-woocommerce' );
		}
		return $out;
	}

	/** @param string $col Column. @param int|WC_Order $order_or_id Order. */
	public static function column( $col, $order_or_id ) {
		if ( 'eru_yk' !== $col ) {
			return;
		}
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order ) {
			return;
		}
		$state = (string) $order->get_meta( Eru_YK_Dispatcher::M_STATE );
		if ( '' === $state ) {
			echo '<span class="eru-yk-muted">—</span>';
			return;
		}
		$states = Eru_YK_Dispatcher::states();
		echo '<span class="eru-yk-state eru-yk-state-' . esc_attr( $state ) . '">' . esc_html( $states[ $state ] ?? $state ) . '</span>';
		$tracking = (string) $order->get_meta( Eru_YK_Dispatcher::M_TRACKING );
		$key      = (string) $order->get_meta( Eru_YK_Dispatcher::M_KEY );
		if ( '' !== $tracking || '' !== $key ) {
			echo '<br><small><code>' . esc_html( '' !== $tracking ? $tracking : $key ) . '</code></small>';
		}
	}

	/** @param array $a Bulk actions. @return array */
	public static function bulk_actions( $a ) {
		if ( current_user_can( self::CAP ) && Eru_YK_Settings::is_enabled() ) {
			$a['eru_yk_dispatch'] = __( 'Yurtiçi\'ne gönder', 'yurtici-kargo-integration-for-woocommerce' );
		}
		return $a;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Order ids.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'eru_yk_dispatch' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( self::CAP ) || ! Eru_YK_Settings::is_enabled() ) {
			return $redirect;
		}
		$r = self::bulk_dispatch( (array) $ids );
		return add_query_arg(
			array(
				'eru_yk_queued'  => $r['queued'],
				'eru_yk_skipped' => $r['skipped'],
			),
			$redirect
		);
	}

	/**
	 * Queues dispatch for several orders; skips existing shipments and excluded shipping methods.
	 *
	 * @param int[] $ids Order ids.
	 * @return array{queued:int,skipped:int}
	 */
	public static function bulk_dispatch( array $ids ) {
		$queued  = 0;
		$skipped = 0;
		foreach ( array_slice( array_map( 'absint', $ids ), 0, 500 ) as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o instanceof WC_Order || $o instanceof WC_Order_Refund || in_array( (string) $o->get_meta( Eru_YK_Dispatcher::M_STATE ), Eru_YK_Dispatcher::LIVE, true ) || '' !== Eru_YK_Dispatcher::excluded_method( $o ) ) {
				++$skipped;
				continue;
			}
			if ( Eru_YK_Dispatcher::enqueue( $id, true ) ) {
				Eru_YK_Logger::event( $id, 'queued', __( 'Gönderim kuyruğa alındı (toplu işlem).', 'yurtici-kargo-integration-for-woocommerce' ) );
				++$queued;
			} else {
				++$skipped;
			}
		}
		return array(
			'queued'  => $queued,
			'skipped' => $skipped,
		);
	}

	/** Bulk result and test-mode notices. */
	public static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['eru_yk_queued'] ) && current_user_can( self::CAP ) ) {
			/* translators: 1: queued, 2: skipped */
			$msg = sprintf( __( 'Yurtiçi: %1$d sipariş kuyruğa alındı, %2$d atlandı (gönderisi var, hariç tutulan kargo yöntemi veya zaten kuyrukta).', 'yurtici-kargo-integration-for-woocommerce' ), absint( $_GET['eru_yk_queued'] ), absint( $_GET['eru_yk_skipped'] ?? 0 ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( isset( $_GET['eru_yk_purged'] ) && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Günlük temizlendi.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p></div>';
		}
		// phpcs:enable
	}

	/** Log page (hidden from the menu). */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Yurtiçi Kargo Günlüğü', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Yurtiçi Kargo Günlüğü', 'yurtici-kargo-integration-for-woocommerce' ), 'manage_woocommerce', 'eru-yk-log', array( __CLASS__, 'render_log' ) );
	}

	/** @param string|null $file Submenu file. @return string|null */
	public static function submenu_file( $file ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- menu highlight only.
		if ( 'eru-yk-log' === ( $_GET['page'] ?? '' ) ) {
			return 'wc-settings';
		}
		return $file;
	}

	/** Log viewer. */
	public static function render_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$which = 'log' === ( $_GET['view'] ?? '' ) ? 'log' : 'events';
		$order = absint( $_GET['order'] ?? 0 );
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$data = Eru_YK_Logger::page( $which, $order, $page, 50 );
		$base = admin_url( 'admin.php?page=eru-yk-log' );

		echo '<div class="wrap eru-yk-log"><h1>' . esc_html__( 'Yurtiçi Kargo Günlüğü', 'yurtici-kargo-integration-for-woocommerce' ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=eru_yk' ) ) . '">' . esc_html__( '← Ayarlar', 'yurtici-kargo-integration-for-woocommerce' ) . '</a></p>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array(
			'events' => __( 'Olaylar', 'yurtici-kargo-integration-for-woocommerce' ),
			'log'    => __( 'Ham istekler', 'yurtici-kargo-integration-for-woocommerce' ),
		) as $k => $label ) {
			echo '<a class="nav-tab' . ( $which === $k ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'view' => $k, 'order' => $order ?: false ), $base ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		echo '<form method="get" class="eru-yk-filter"><input type="hidden" name="page" value="eru-yk-log"><input type="hidden" name="view" value="' . esc_attr( $which ) . '"><label>' . esc_html__( 'Sipariş no', 'yurtici-kargo-integration-for-woocommerce' ) . ' <input type="number" min="0" name="order" value="' . esc_attr( $order ? (string) $order : '' ) . '"></label> <button class="button">' . esc_html__( 'Süz', 'yurtici-kargo-integration-for-woocommerce' ) . '</button></form>';

		if ( 'log' === $which ) {
			echo '<p class="description">' . esc_html__( 'Ham istek ve yanıtlar; şifre alanı maskelenir. Alıcı bilgilerini içerdikleri için ayarlardaki süre dolunca silinirler.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p>';
		}
		echo '<table class="widefat striped"><thead><tr>';
		$heads = 'log' === $which
			? array( __( 'Tarih', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Sipariş', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Hesap', 'yurtici-kargo-integration-for-woocommerce' ), __( 'İşlem', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Anahtar', 'yurtici-kargo-integration-for-woocommerce' ), 'HTTP', __( 'Süre', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Hata / içerik', 'yurtici-kargo-integration-for-woocommerce' ) )
			: array( __( 'Tarih', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Sipariş', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Düzey', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Olay', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Mesaj', 'yurtici-kargo-integration-for-woocommerce' ) );
		foreach ( $heads as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $data['rows'] ) {
			echo '<tr><td colspan="' . count( $heads ) . '">' . esc_html__( 'Kayıt yok.', 'yurtici-kargo-integration-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $data['rows'] as $r ) {
			$ts    = wp_date( 'd.m.Y H:i:s', strtotime( $r['created_at'] . ' UTC' ) );
			$olink = $r['order_id'] ? '<a href="' . esc_url( add_query_arg( array( 'view' => $which, 'order' => (int) $r['order_id'] ), $base ) ) . '">#' . esc_html( (string) $r['order_id'] ) . '</a>' : '—';
			echo '<tr><td>' . esc_html( $ts ) . '</td><td>' . $olink . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			if ( 'log' === $which ) {
				echo '<td>' . esc_html( $r['account'] ) . '</td><td>' . esc_html( $r['operation'] ) . '</td><td><code>' . esc_html( $r['cargo_key'] ) . '</code></td><td>' . esc_html( (string) $r['http_code'] ) . '</td><td>' . esc_html( (string) $r['duration_ms'] ) . ' ms</td><td>';
				if ( '' !== (string) $r['error'] ) {
					echo '<span class="eru-yk-error">' . esc_html( $r['error'] ) . '</span>';
				}
				echo '<details><summary>' . esc_html__( 'İstek / yanıt', 'yurtici-kargo-integration-for-woocommerce' ) . '</summary><pre>' . esc_html( (string) $r['request'] ) . '</pre><pre>' . esc_html( (string) $r['response'] ) . '</pre></details></td>';
			} else {
				echo '<td><span class="eru-yk-lvl eru-yk-lvl-' . esc_attr( $r['level'] ) . '">' . esc_html( $r['level'] ) . '</span></td><td><code>' . esc_html( $r['type'] ) . '</code></td><td>' . esc_html( $r['message'] ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $data['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<p class="eru-yk-pages">';
			for ( $p = 1; $p <= min( $pages, 50 ); $p++ ) {
				echo $p === $page ? '<strong>' . (int) $p . '</strong> ' : '<a href="' . esc_url( add_query_arg( array( 'view' => $which, 'order' => $order ?: false, 'paged' => $p ), $base ) ) . '">' . (int) $p . '</a> ';
			}
			echo '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-yk-purge" onsubmit="return confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . esc_attr__( 'Seçilen kayıtlar kalıcı olarak silinsin mi?', 'yurtici-kargo-integration-for-woocommerce' ) . '">';
		echo '<input type="hidden" name="action" value="eru_yk_purge">';
		wp_nonce_field( 'eru_yk_purge' );
		echo '<select name="which"><option value="log">' . esc_html__( 'Ham istekleri sil', 'yurtici-kargo-integration-for-woocommerce' ) . '</option><option value="events">' . esc_html__( 'Olayları sil', 'yurtici-kargo-integration-for-woocommerce' ) . '</option><option value="all">' . esc_html__( 'Hepsini sil', 'yurtici-kargo-integration-for-woocommerce' ) . '</option></select> ';
		echo '<button class="button button-secondary">' . esc_html__( 'Temizle', 'yurtici-kargo-integration-for-woocommerce' ) . '</button></form></div>';
	}

	/** admin-post: purge. */
	public static function purge() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'yurtici-kargo-integration-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_yk_purge' );
		$which = sanitize_key( wp_unslash( $_POST['which'] ?? '' ) );
		if ( in_array( $which, array( 'log', 'events', 'all' ), true ) ) {
			Eru_YK_Logger::truncate( $which );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=eru-yk-log&eru_yk_purged=1' ) );
		exit;
	}
}
