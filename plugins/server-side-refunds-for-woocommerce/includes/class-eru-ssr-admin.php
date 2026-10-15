<?php
/**
 * Admin: queue screen, order metabox, test button AJAX, assets.
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SSR_Admin {

	const PAGE = 'eru-ssr-queue';
	const CAP  = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_ssr_row', array( __CLASS__, 'row_action' ) );
		add_action( 'wp_ajax_eru_ssr_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
		// Shop managers can open and save the integration screen.
		add_filter( 'option_page_capability_woocommerce_eru_ssr_settings', fn() => self::CAP );
	}

	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Server-Side Refunds for WooCommerce',
				wp_kses_post( '<p>' . __( 'Bir iade veya iptal olduğunda sipariş numarası, tutar, para birimi ve (varsa) Google Analytics istemci kimliği ile takma adlı kullanıcı kimliği, ayarladığınız Google Analytics 4 ve/veya Matomo hizmetine sunucudan gönderilir. Gönderilmiş kuyruk kayıtları saklama süresi sonunda silinir.', 'server-side-refunds-for-woocommerce' ) . '</p>' )
			);
		}
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'İade Kuyruğu', 'server-side-refunds-for-woocommerce' ), __( 'İade Kuyruğu', 'server-side-refunds-for-woocommerce' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		$settings = isset( $_GET['section'] ) && 'eru_ssr' === sanitize_key( wp_unslash( $_GET['section'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$queue    = false !== strpos( (string) $hook, self::PAGE );
		$screen   = get_current_screen();
		$order    = $screen && function_exists( 'wc_get_page_screen_id' ) && wc_get_page_screen_id( 'shop-order' ) === $screen->id;
		if ( ! $settings && ! $queue && ! $order ) {
			return;
		}
		wp_enqueue_style( 'eru-ssr-admin', ERU_SSR_URL . 'assets/admin.css', array(), ERU_SSR_VERSION );
		if ( $settings ) {
			wp_enqueue_script( 'eru-ssr-admin', ERU_SSR_URL . 'assets/admin.js', array(), ERU_SSR_VERSION, true );
			wp_localize_script( 'eru-ssr-admin', 'eruSsr', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'eru_ssr_test' ), 'error' => __( 'İstek başarısız.', 'server-side-refunds-for-woocommerce' ) ) );
		}
	}

	public static function ajax_test() {
		check_ajax_referer( 'eru_ssr_test' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'ok' => false, 'messages' => array( __( 'Yetkiniz yok.', 'server-side-refunds-for-woocommerce' ) ) ), 403 );
		}
		$dest = isset( $_POST['dest'] ) ? sanitize_key( wp_unslash( $_POST['dest'] ) ) : '';
		if ( 'ga4' === $dest ) {
			wp_send_json_success( Eru_SSR_GA4::test() );
		}
		if ( 'matomo' === $dest ) {
			wp_send_json_success( Eru_SSR_Matomo::test() );
		}
		wp_send_json_error( array( 'ok' => false, 'messages' => array( 'dest?' ) ), 400 );
	}

	public static function row_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'server-side-refunds-for-woocommerce' ), 403 );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		check_admin_referer( 'eru_ssr_row_' . $do . '_' . $id );
		if ( 'retry' === $do ) {
			Eru_SSR_Queue::retry( $id );
		} elseif ( 'delete' === $do ) {
			Eru_SSR_Queue::delete( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	private static function row_link( $do, $id, $label ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=eru_ssr_row&do=' . $do . '&id=' . (int) $id ), 'eru_ssr_row_' . $do . '_' . (int) $id );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/** Table rows shared by the queue screen and the order metabox. */
	private static function table( array $rows, $with_actions ) {
		?>
		<table class="widefat striped">
			<thead><tr>
				<th>#</th><th><?php esc_html_e( 'Hedef', 'server-side-refunds-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Sipariş', 'server-side-refunds-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Tür / tutar', 'server-side-refunds-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Durum', 'server-side-refunds-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Deneme', 'server-side-refunds-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Sonraki / gönderim (UTC)', 'server-side-refunds-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Hata', 'server-side-refunds-for-woocommerce' ); ?></th>
				<?php if ( $with_actions ) : ?><th></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="<?php echo $with_actions ? 9 : 8; ?>"><?php esc_html_e( 'Kayıt yok.', 'server-side-refunds-for-woocommerce' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $rows as $r ) :
				$p      = json_decode( $r->payload, true );
				$status = Eru_SSR_Queue::status_of( $r );
				$labels = array( 'pending' => __( 'Bekliyor', 'server-side-refunds-for-woocommerce' ), 'sent' => __( 'Gönderildi', 'server-side-refunds-for-woocommerce' ), 'failed' => __( 'Başarısız', 'server-side-refunds-for-woocommerce' ) );
				?>
				<tr>
					<td><?php echo (int) $r->id; ?></td>
					<td><?php echo esc_html( 'ga4' === $r->destination ? 'GA4' : 'Matomo' ); ?></td>
					<td><?php echo esc_html( (string) ( $p['order_number'] ?? $r->order_id ) ); ?></td>
					<td><?php echo esc_html( (string) ( $p['eru_ssr_event_type'] ?? '' ) . ' · ' . ( $p['value'] ?? '' ) . ' ' . ( $p['currency'] ?? '' ) ); ?></td>
					<td><span class="eru-ssr-badge <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ); ?></span></td>
					<td><?php echo (int) $r->attempts; ?></td>
					<td><?php echo esc_html( (string) ( $r->sent_at ? $r->sent_at : $r->next_attempt_at ) ); ?></td>
					<td class="eru-ssr-err"><?php echo esc_html( (string) $r->last_error ); ?></td>
					<?php if ( $with_actions ) : ?>
						<td>
							<?php
							if ( 'failed' === $status ) {
								echo wp_kses_post( self::row_link( 'retry', $r->id, __( 'Yeniden dene', 'server-side-refunds-for-woocommerce' ) ) ) . ' | ';
							}
							echo wp_kses_post( self::row_link( 'delete', $r->id, __( 'Sil', 'server-side-refunds-for-woocommerce' ) ) );
							?>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'server-side-refunds-for-woocommerce' ), 403 );
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$status = in_array( $status, array( 'pending', 'sent', 'failed' ), true ) ? $status : '';
		$counts = Eru_SSR_Queue::counts();
		$base   = admin_url( 'admin.php?page=' . self::PAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'İade Kuyruğu', 'server-side-refunds-for-woocommerce' ) . '</h1>';
		echo '<ul class="subsubsub">';
		$tabs = array( '' => __( 'Tümü', 'server-side-refunds-for-woocommerce' ), 'pending' => __( 'Bekliyor', 'server-side-refunds-for-woocommerce' ), 'sent' => __( 'Gönderildi', 'server-side-refunds-for-woocommerce' ), 'failed' => __( 'Başarısız', 'server-side-refunds-for-woocommerce' ) );
		$out  = array();
		foreach ( $tabs as $k => $label ) {
			$n     = '' === $k ? array_sum( $counts ) : $counts[ $k ];
			$out[] = '<li><a href="' . esc_url( $k ? add_query_arg( 'status', $k, $base ) : $base ) . '"' . ( $k === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		}
		echo wp_kses_post( implode( ' | </li>', $out ) ) . '</li></ul><br class="clear">';
		self::table( Eru_SSR_Queue::rows( $status, 100 ), true );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=integration&section=eru_ssr' ) ) . '">' . esc_html__( 'Ayarlar', 'server-side-refunds-for-woocommerce' ) . '</a></p></div>';
	}

	public static function metabox() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'eru-ssr-order', __( 'Sunucu tarafı iadeler', 'server-side-refunds-for-woocommerce' ), array( __CLASS__, 'render_metabox' ), $screen, 'normal', 'default' );
	}

	public static function render_metabox( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! current_user_can( self::CAP ) ) {
			return;
		}
		self::table( Eru_SSR_Queue::rows( '', 50, 0, $order->get_id() ), false );
	}
}
