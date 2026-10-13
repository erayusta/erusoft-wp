<?php
/** Bulk action, date-range screen and settings (HPOS + legacy). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OJE_Admin {

	const RANGE_CAP = 5000;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_eru_oje_download', array( __CLASS__, 'handle_range_download' ) );

		// HPOS and legacy order list bulk actions.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk' ), 10, 3 );
	}

	public static function bulk_actions( $actions ) {
		$actions['eru_oje_export'] = __( 'JSON olarak dışa aktar', 'order-json-export-for-woocommerce' );
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'eru_oje_export' !== $action ) {
			return $redirect;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}
		$orders = array();
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$o = wc_get_order( $id );
			if ( $o instanceof WC_Order ) {
				$orders[] = $o;
			}
		}
		Eru_OJE_Exporter::download( Eru_OJE_Exporter::to_json( $orders ) );
		return $redirect; // download() exits.
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Sipariş JSON Dışa Aktarım', 'order-json-export-for-woocommerce' ), __( 'JSON Dışa Aktarım', 'order-json-export-for-woocommerce' ), 'manage_woocommerce', 'eru-oje', array( __CLASS__, 'render' ) );
	}

	public static function register_settings(): void {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_oje', fn() => 'manage_woocommerce' );
		register_setting( 'eru_oje', 'eru_oje_meta_allowlist', array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_keys' ), 'default' => '' ) );
		register_setting( 'eru_oje', 'eru_oje_include_note', array( 'type' => 'string', 'sanitize_callback' => static fn( $v ) => 'yes' === $v ? 'yes' : 'no', 'default' => 'no' ) );
	}

	public static function sanitize_keys( $raw ): string {
		$keys = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $k ) {
			$k = trim( sanitize_text_field( $k ) );
			if ( '' !== $k && preg_match( '/^[A-Za-z0-9_\-:.]{1,100}$/', $k ) ) {
				$keys[] = $k;
			}
		}
		return implode( "\n", array_unique( $keys ) );
	}

	public static function handle_range_download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'order-json-export-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_oje_download' );
		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$st   = isset( $_POST['statuses'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['statuses'] ) ) : array();
		$valid = array_keys( wc_get_order_statuses() );
		$st    = array_values( array_intersect( $st, $valid ) );

		$all   = array();
		$page  = 1;
		$cap   = self::RANGE_CAP;
		do {
			$res = Eru_OJE_Exporter::query(
				array(
					'after'    => $from ? $from . ' 00:00:00' : '',
					'before'   => $to ? $to . ' 23:59:59' : '',
					'statuses' => $st,
					'limit'    => 100,
					'page'     => $page,
				)
			);
			if ( 1 === $page && $res['total'] > $cap ) {
				// Refuse instead of silently truncating.
				wp_safe_redirect( add_query_arg( array( 'page' => 'eru-oje', 'eru_oje_toolarge' => (int) $res['total'] ), admin_url( 'admin.php' ) ) );
				exit;
			}
			$all = array_merge( $all, $res['orders'] );
			$page++;
		} while ( $page <= $res['pages'] );

		Eru_OJE_Exporter::download( Eru_OJE_Exporter::to_json( $all ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$statuses = wc_get_order_statuses();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sipariş JSON Dışa Aktarım', 'order-json-export-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['eru_oje_toolarge'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification -- display-only notice. ?>
				<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: 1: order count, 2: limit */ __( 'Seçilen aralıkta %1$d sipariş var; tek indirmede en çok %2$d sipariş aktarılır. Lütfen aralığı daraltın veya REST uç noktasını kullanın.', 'order-json-export-for-woocommerce' ), absint( $_GET['eru_oje_toolarge'] ), self::RANGE_CAP ) ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Tarih aralığına göre dışa aktar', 'order-json-export-for-woocommerce' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_oje_download">
				<?php wp_nonce_field( 'eru_oje_download' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="eru-oje-from"><?php esc_html_e( 'Başlangıç', 'order-json-export-for-woocommerce' ); ?></label></th><td><input type="date" id="eru-oje-from" name="from"></td></tr>
					<tr><th><label for="eru-oje-to"><?php esc_html_e( 'Bitiş', 'order-json-export-for-woocommerce' ); ?></label></th><td><input type="date" id="eru-oje-to" name="to"></td></tr>
					<tr><th><?php esc_html_e( 'Durumlar', 'order-json-export-for-woocommerce' ); ?></th><td>
						<?php foreach ( $statuses as $key => $label ) : ?>
							<label style="display:inline-block;margin:0 12px 4px 0"><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Hiçbiri seçilmezse tüm durumlar dahil edilir.', 'order-json-export-for-woocommerce' ); ?></p>
					</td></tr>
				</table>
				<?php submit_button( __( 'JSON indir', 'order-json-export-for-woocommerce' ), 'primary', 'submit', false ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Ayarlar', 'order-json-export-for-woocommerce' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_oje' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="eru-oje-meta"><?php esc_html_e( 'İzinli sipariş meta anahtarları', 'order-json-export-for-woocommerce' ); ?></label></th><td>
						<textarea id="eru-oje-meta" name="eru_oje_meta_allowlist" rows="5" class="large-text code" placeholder="_ornek_meta_anahtari"><?php echo esc_textarea( (string) get_option( 'eru_oje_meta_allowlist', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satıra bir anahtar. Listede olmayan hiçbir sipariş meta alanı dışa aktarılmaz (varsayılan: boş).', 'order-json-export-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Müşteri notu', 'order-json-export-for-woocommerce' ); ?></th><td>
						<label><input type="checkbox" name="eru_oje_include_note" value="yes" <?php checked( get_option( 'eru_oje_include_note', 'no' ), 'yes' ); ?>> <?php esc_html_e( 'Müşteri notunu dahil et', 'order-json-export-for-woocommerce' ); ?></label>
					</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'REST uç noktası', 'order-json-export-for-woocommerce' ); ?></h2>
			<p><code>GET <?php echo esc_html( rest_url( 'eru-oje/v1/orders' ) ); ?>?after=2026-01-01&amp;per_page=50&amp;page=1</code></p>
			<p class="description"><?php esc_html_e( 'Yetki: manage_woocommerce (Uygulama Parolası ile Basic kimlik doğrulama). En çok 100 sipariş/sayfa; toplam sayı X-WP-Total başlığındadır.', 'order-json-export-for-woocommerce' ); ?></p>
			<p class="description"><?php esc_html_e( 'Dışa aktarılan dosyalar müşteri kişisel verisi içerir; güvenli saklayın ve KVKK yükümlülüklerinize uyun.', 'order-json-export-for-woocommerce' ); ?></p>
		</div>
		<?php
	}
}
