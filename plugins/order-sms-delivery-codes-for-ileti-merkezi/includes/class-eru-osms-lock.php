<?php
/**
 * Blocks staff from moving a locked order to a delivery status without the code — BEFORE saving.
 *
 * Not at save time (woocommerce_before_order_object_save): even when save() throws, the status
 * transition hooks run and the unsaved status's e-mails / SMS go out; reverting re-fires the old
 * status hooks. So every staff entry point is handled separately.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Lock {

	public static function init(): void {
		// Order edit form (legacy + HPOS) — WooCommerce saves at priority 40.
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'form' ), 5, 1 );
		// Order list bulk actions — before WooCommerce's handler (legacy: post[], HPOS: id[]).
		add_action( 'load-edit.php', array( __CLASS__, 'bulk' ), 1 );
		add_action( 'load-woocommerce_page_wc-orders', array( __CLASS__, 'bulk' ), 1 );
		add_action( 'load-admin_page_wc-orders', array( __CLASS__, 'bulk' ), 1 );
		// List / preview status buttons.
		add_action( 'wp_ajax_woocommerce_mark_order_status', array( __CLASS__, 'ajax' ), 1 );
		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'list_buttons' ), 110, 2 );
		// Cookie-authenticated REST (block editor, mobile app session) — WP_Error before saving.
		add_filter( 'woocommerce_rest_pre_insert_shop_order_object', array( __CLASS__, 'rest' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'show_notices' ) );
	}

	/** The lock only applies while the master switch is on; switched off, changes are recorded as "without code". */
	public static function active(): bool {
		return (bool) (int) Eru_OSMS_Settings::get( 'enabled' );
	}

	public static function form( $order_id ): void {
		if ( ! self::active() || ! isset( $_POST['order_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verified its nonce.
			return;
		}
		$o = wc_get_order( $order_id );
		if ( ! $o ) {
			return;
		}
		$r = Eru_OSMS_Decision::fix_form_post(
			array( 'order_status' => wc_clean( wp_unslash( $_POST['order_status'] ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
			Eru_OSMS_Delivery::get( $o->get_id() ),
			$o->get_status(),
			Eru_OSMS_Delivery::delivered_statuses()
		);
		if ( $r['blocked'] ) {
			$_POST['order_status'] = $r['post']['order_status'];
			/* translators: %s: order number */
			self::notice( sprintf( __( '#%s: Teslimat kodu doğrulanmadan teslim durumuna geçilemez. Kodu "Teslimat kodu ve SMS" kutusundan doğrulayın. Diğer değişiklikler kaydedildi.', 'order-sms-delivery-codes-for-ileti-merkezi' ), $o->get_order_number() ) );
		}
	}

	public static function bulk(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- only reads ids; WooCommerce verifies its nonce.
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : ( isset( $_REQUEST['action2'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action2'] ) ) : '' );
		$target = Eru_OSMS_Decision::bulk_action_status( $action );
		if ( null === $target || ! self::active() ) {
			return;
		}
		$key = isset( $_REQUEST['id'] ) ? 'id' : ( isset( $_REQUEST['post'] ) ? 'post' : '' );
		if ( '' === $key ) {
			return;
		}
		$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST[ $key ] ) );
		// phpcs:enable

		$r = Eru_OSMS_Decision::split_bulk( $ids, fn( $id ) => Eru_OSMS_Delivery::blocks( $id, $target ) );
		if ( ! $r['skipped'] ) {
			return;
		}
		$_REQUEST[ $key ] = $r['keep'];
		$_GET[ $key ]     = $r['keep'];
		/* translators: 1: count, 2: order ids */
		self::notice( sprintf( __( '%1$d sipariş teslimat kodu beklediği için atlandı: #%2$s. Bu siparişleri sipariş ekranından kodla teslim edin.', 'order-sms-delivery-codes-for-ileti-merkezi' ), count( $r['skipped'] ), implode( ', #', $r['skipped'] ) ) );

		if ( ! $r['keep'] ) {
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wc-orders' ) );
			exit;
		}
	}

	public static function ajax(): void {
		if ( ! self::active() || ! isset( $_GET['status'], $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- checked below before acting.
			return;
		}
		$id = absint( $_GET['order_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! Eru_OSMS_Delivery::blocks( $id, sanitize_text_field( wp_unslash( $_GET['status'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'woocommerce-mark-order-status' );
		/* translators: %d: order id */
		self::notice( sprintf( __( '#%d: Teslimat kodu doğrulanmadan teslim durumuna geçilemez.', 'order-sms-delivery-codes-for-ileti-merkezi' ), $id ) );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}

	public static function list_buttons( $actions, $order ) {
		if ( ! $order instanceof WC_Order || ! self::active() || ! Eru_OSMS_Decision::is_locked( Eru_OSMS_Delivery::get( $order->get_id() ) ) ) {
			return $actions;
		}
		// The action KEY is not the status (WooCommerce: 'complete' → completed); compare the URL's status=.
		foreach ( (array) $actions as $k => $a ) {
			$status = Eru_OSMS_Decision::action_url_status( $a['url'] ?? '' );
			if ( '' !== $status && Eru_OSMS_Delivery::blocks( $order->get_id(), $status ) ) {
				unset( $actions[ $k ] );
			}
		}
		return $actions;
	}

	/**
	 * WooCommerce REST order update — only browser-session (nonce) requests are blocked;
	 * API clients authenticate with keys and are recorded by codeless_check().
	 */
	public static function rest( $order, $request, $creating ) {
		if ( $creating || ! self::active() || ! $order instanceof WC_Order ) {
			return $order;
		}
		$changes = $order->get_changes();
		if ( ! isset( $changes['status'] ) ) {
			return $order;
		}
		$cookie = Eru_OSMS_Decision::is_cookie_rest( $request->get_header( 'x_wp_nonce' ), $request->get_param( '_wpnonce' ) );
		if ( $cookie && Eru_OSMS_Delivery::blocks( $order->get_id(), (string) $changes['status'] ) ) {
			return new WP_Error( 'eru_osms_code_required', __( 'Teslimat kodu doğrulanmadan teslim durumuna geçilemez.', 'order-sms-delivery-codes-for-ileti-merkezi' ), array( 'status' => 403 ) );
		}
		return $order;
	}

	public static function notice( string $msg ): void {
		$k   = 'eru_osms_notice_' . get_current_user_id();
		$l   = (array) get_transient( $k );
		$l[] = $msg;
		set_transient( $k, array_values( array_filter( $l ) ), 120 );
	}

	public static function show_notices(): void {
		$k = 'eru_osms_notice_' . get_current_user_id();
		$l = get_transient( $k );
		if ( ! $l ) {
			return;
		}
		delete_transient( $k );
		foreach ( (array) $l as $m ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $m ) . '</p></div>';
		}
	}
}
