<?php
/**
 * Order screen box: Dopigo id, push state, last error and a "Yeniden gönder" button (HPOS and classic).
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The box sits inside the order edit form, so its button must not submit that form (the form's own
 * "action" field would win). It is a type="button" that POSTs to admin-ajax.php from script with its own
 * nonce; the handler needs manage_woocommerce and only queues the push.
 */
class Eru_DPG_Metabox {

	/** Hooks. */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'wp_ajax_eru_dpg_resend', array( __CLASS__, 'ajax_resend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** @return string[] Order edit screen ids (classic and HPOS). */
	private static function screens() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		return array_unique( $screens );
	}

	/** Script for the button, only on order edit screens. */
	public static function assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, self::screens(), true ) ) {
			wp_enqueue_script( 'eru-dpg-metabox', ERU_DPG_URL . 'assets/metabox.js', array(), ERU_DPG_VERSION, true );
			wp_localize_script(
				'eru-dpg-metabox',
				'eruDpgMetabox',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'confirm' => __( 'Sipariş Dopigo gönderim kuyruğuna alınsın mı?', 'dopigo-integration-for-woocommerce' ),
					'error'   => __( 'İstek başarısız oldu.', 'dopigo-integration-for-woocommerce' ),
				)
			);
		}
	}

	/** Registers the box on both order screens. */
	public static function add() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		foreach ( self::screens() as $screen ) {
			add_meta_box( 'eru-dpg-order', __( 'Dopigo', 'dopigo-integration-for-woocommerce' ), array( __CLASS__, 'render' ), $screen, 'side', 'high' );
		}
	}

	/** @param WP_Post|WC_Order $post_or_order Object. */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}
		$states = array(
			'sent'    => __( 'Gönderildi', 'dopigo-integration-for-woocommerce' ),
			'pending' => __( 'Gönderiliyor', 'dopigo-integration-for-woocommerce' ),
			'failed'  => __( 'Hata', 'dopigo-integration-for-woocommerce' ),
			'unknown' => __( 'Belirsiz (bir sonraki denemede Dopigo\'da aranacak)', 'dopigo-integration-for-woocommerce' ),
			'needs_review' => __( 'İnceleme gerekli', 'dopigo-integration-for-woocommerce' ),
		);
		$remote = (string) $order->get_meta( Eru_DPG_Orders::M_ID );
		$state  = (string) $order->get_meta( Eru_DPG_Orders::M_STATE );
		$error  = (string) $order->get_meta( Eru_DPG_Orders::M_ERROR );
		$synced = (int) $order->get_meta( Eru_DPG_Orders::M_SYNCED );
		$queued = Eru_DPG_Cron::pending( 'eru_dpg_push_order', array( $order->get_id() ) ) + Eru_DPG_Cron::pending( 'eru_dpg_push_order', array( $order->get_id(), 1 ) );

		echo '<div class="eru-dpg-box"><p><strong>' . esc_html__( 'Dopigo sipariş no:', 'dopigo-integration-for-woocommerce' ) . '</strong> ' . ( '' !== $remote ? '<code>' . esc_html( $remote ) . '</code>' : esc_html__( 'henüz yok', 'dopigo-integration-for-woocommerce' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Durum:', 'dopigo-integration-for-woocommerce' ) . '</strong> ' . esc_html( $queued ? __( 'Kuyrukta', 'dopigo-integration-for-woocommerce' ) : ( $states[ $state ] ?? __( 'Gönderilmedi', 'dopigo-integration-for-woocommerce' ) ) ) . '</p>';
		if ( $synced ) {
			echo '<p><strong>' . esc_html__( 'Son deneme:', 'dopigo-integration-for-woocommerce' ) . '</strong> ' . esc_html( wp_date( 'd.m.Y H:i', $synced ) ) . '</p>';
		}
		if ( '' !== $error && 'sent' !== $state ) {
			echo '<p class="eru-dpg-error" style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}
		if ( current_user_can( 'manage_woocommerce' ) && Eru_DPG_Settings::is_enabled() ) {
			echo '<button type="button" class="button eru-dpg-resend" data-order="' . esc_attr( (string) $order->get_id() ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'eru_dpg_resend_' . $order->get_id() ) ) . '">' . esc_html( '' !== $remote ? __( 'Yeniden gönder (güncelle)', 'dopigo-integration-for-woocommerce' ) : __( 'Dopigo\'ya gönder', 'dopigo-integration-for-woocommerce' ) ) . '</button>';
			echo '<p class="eru-dpg-resend-result" role="status" aria-live="polite"></p>';
			echo '<p class="description">' . esc_html__( 'Siparişteki kaydedilmemiş değişiklikler gönderilmez; önce siparişi kaydedin.', 'dopigo-integration-for-woocommerce' ) . '</p>';
		} elseif ( ! Eru_DPG_Settings::is_enabled() ) {
			echo '<p class="description">' . esc_html__( 'Entegrasyon kapalı.', 'dopigo-integration-for-woocommerce' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Core of the resend button: checks and queueing, without output (tested directly).
	 *
	 * @param int    $id    Order id.
	 * @param string $nonce Nonce from the button.
	 * @return array{code:int,ok:bool,message:string,args?:array}
	 */
	public static function resend_result( $id, $nonce ) {
		$id = absint( $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array( 'code' => 403, 'ok' => false, 'message' => __( 'Yetkiniz yok.', 'dopigo-integration-for-woocommerce' ) );
		}
		if ( ! $id || ! wp_verify_nonce( (string) $nonce, 'eru_dpg_resend_' . $id ) ) {
			return array( 'code' => 400, 'ok' => false, 'message' => __( 'Geçersiz istek; sayfayı yenileyin.', 'dopigo-integration-for-woocommerce' ) );
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return array( 'code' => 404, 'ok' => false, 'message' => __( 'Sipariş bulunamadı.', 'dopigo-integration-for-woocommerce' ) );
		}
		if ( ! Eru_DPG_Settings::is_enabled() ) {
			return array( 'code' => 409, 'ok' => false, 'message' => __( 'Entegrasyon kapalı.', 'dopigo-integration-for-woocommerce' ) );
		}
		// Existing Dopigo order → forced update. A needs_review order is a manual decision → forced create.
		$force = '' !== (string) $order->get_meta( Eru_DPG_Orders::M_ID ) || 'needs_review' === $order->get_meta( Eru_DPG_Orders::M_STATE );
		$args  = $force ? array( $id, 1 ) : array( $id );
		Eru_DPG_Cron::reset_attempts( 'eru_dpg_push_order', $args );
		$queued = Eru_DPG_Cron::enqueue( 'eru_dpg_push_order', $args );
		return array(
			'code'    => 200,
			'ok'      => true,
			'message' => $queued ? __( 'Gönderim kuyruğa alındı. Durumu görmek için birazdan sayfayı yenileyin.', 'dopigo-integration-for-woocommerce' ) : __( 'Bu sipariş zaten kuyrukta.', 'dopigo-integration-for-woocommerce' ),
			'args'    => $args,
		);
	}

	/** admin-ajax handler for the button. */
	public static function ajax_resend() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in resend_result().
		$r = self::resend_result( absint( $_POST['order'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ) );
		// phpcs:enable
		wp_send_json( array( 'ok' => $r['ok'], 'message' => $r['message'] ), $r['code'] );
	}
}
