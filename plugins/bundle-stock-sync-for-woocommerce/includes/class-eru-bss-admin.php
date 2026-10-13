<?php
/**
 * Product edit panel ("Paket içeriği") and settings screen.
 *
 * @package bundle-stock-sync-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BSS_Admin {

	const PAGE = 'eru-bss';

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function tab( $tabs ) {
		$tabs['eru_bss'] = array(
			'label'    => __( 'Paket içeriği', 'bundle-stock-sync-for-woocommerce' ),
			'target'   => 'eru_bss_panel',
			'class'    => array( 'show_if_simple' ),
			'priority' => 65,
		);
		return $tabs;
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'eru-bss-admin', ERU_BSS_URL . 'assets/admin.css', array(), ERU_BSS_VERSION );
		wp_enqueue_script( 'eru-bss-admin', ERU_BSS_URL . 'assets/admin.js', array( 'jquery' ), ERU_BSS_VERSION, true );
	}

	public static function panel() {
		global $post;
		$id   = $post ? (int) $post->ID : 0;
		$rows = $id ? Eru_BSS_Bundles::get_components( $id ) : array();
		if ( ! $rows ) {
			$rows = array( array( 'sku' => '', 'qty' => 1 ) );
		}
		$avail = $id ? Eru_BSS_Bundles::availability( $id ) : null;
		?>
		<div id="eru_bss_panel" class="panel woocommerce_options_panel hidden">
			<?php wp_nonce_field( 'eru_bss_save', 'eru_bss_nonce' ); ?>
			<div class="options_group eru-bss">
				<p class="form-field">
					<?php esc_html_e( 'Bu ürünü bir paket olarak tanımlamak için bileşen SKU\'larını ve paket başına adetlerini girin. Paket stoğu bileşenlerden hesaplanır: en küçük (bileşen stoğu ÷ adet) değeri. Boş bırakırsanız ürün normal çalışır.', 'bundle-stock-sync-for-woocommerce' ); ?>
				</p>
				<table class="widefat eru-bss-table">
					<thead><tr>
						<th><?php esc_html_e( 'Bileşen SKU', 'bundle-stock-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Paket başına adet', 'bundle-stock-sync-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Durum', 'bundle-stock-sync-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody id="eru-bss-rows">
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php self::row( $i, $row, $id ); ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="eru-bss-add"><?php esc_html_e( 'Bileşen ekle', 'bundle-stock-sync-for-woocommerce' ); ?></button></p>
				<?php if ( $avail ) : ?>
					<p class="eru-bss-avail"><strong><?php esc_html_e( 'Hesaplanan paket stoğu:', 'bundle-stock-sync-for-woocommerce' ); ?></strong>
						<?php echo null === $avail['qty'] ? esc_html__( 'Sınırsız (bileşenlerde stok takibi yok)', 'bundle-stock-sync-for-woocommerce' ) : esc_html( (string) $avail['qty'] ); ?></p>
				<?php endif; ?>
			</div>
			<script type="text/template" id="eru-bss-row-tpl"><?php self::row( '__i__', array( 'sku' => '', 'qty' => 1 ), 0, true ); ?></script>
		</div>
		<?php
	}

	private static function row( $i, array $row, $bundle_id, $blank = false ) {
		$status = '';
		if ( ! $blank && '' !== $row['sku'] ) {
			$r      = Eru_BSS_Bundles::resolve( $bundle_id, array( $row ) );
			$r      = $r[0];
			$status = self::status_label( $r );
		}
		?>
		<tr>
			<td><input type="text" class="short" name="eru_bss[<?php echo esc_attr( (string) $i ); ?>][sku]" value="<?php echo esc_attr( $row['sku'] ); ?>" /></td>
			<td><input type="number" min="1" step="1" class="short" name="eru_bss[<?php echo esc_attr( (string) $i ); ?>][qty]" value="<?php echo esc_attr( (string) $row['qty'] ); ?>" /></td>
			<td class="eru-bss-status"><?php echo wp_kses_post( $status ); ?></td>
			<td><button type="button" class="button-link eru-bss-del" aria-label="<?php esc_attr_e( 'Satırı sil', 'bundle-stock-sync-for-woocommerce' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	private static function status_label( array $r ) {
		if ( 'missing' === $r['issue'] ) {
			return '<span class="eru-bss-bad">' . esc_html__( 'SKU bulunamadı', 'bundle-stock-sync-for-woocommerce' ) . '</span>';
		}
		if ( 'self' === $r['issue'] || 'nested' === $r['issue'] ) {
			return '<span class="eru-bss-bad">' . esc_html__( 'Geçersiz (paket)', 'bundle-stock-sync-for-woocommerce' ) . '</span>';
		}
		$p = $r['product'];
		if ( $p->managing_stock() ) {
			/* translators: 1: product name, 2: stock quantity */
			return esc_html( sprintf( __( '%1$s — stok: %2$s', 'bundle-stock-sync-for-woocommerce' ), $p->get_name(), wc_stock_amount( $p->get_stock_quantity() ) ) );
		}
		/* translators: %s: product name */
		return esc_html( sprintf( __( '%s — stok takibi yok', 'bundle-stock-sync-for-woocommerce' ), $p->get_name() ) );
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['eru_bss_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_bss_nonce'] ) ), 'eru_bss_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$rows = isset( $_POST['eru_bss'] ) && is_array( $_POST['eru_bss'] ) ? wp_unslash( $_POST['eru_bss'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in sanitize_components().
		$res  = Eru_BSS_Bundles::set_components( $post_id, $rows );
		if ( is_wp_error( $res ) ) {
			set_transient( 'eru_bss_notice_' . get_current_user_id(), $res->get_error_message(), 60 );
		}
	}

	public static function notices() {
		$key = 'eru_bss_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/* ---- settings ---- */

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=product', __( 'Paket Stok Eşitleme', 'bundle-stock-sync-for-woocommerce' ), __( 'Paket Stok Eşitleme', 'bundle-stock-sync-for-woocommerce' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function register_settings() {
		register_setting(
			'eru_bss',
			'eru_bss_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
		add_filter( 'option_page_capability_eru_bss', fn() => 'manage_woocommerce' );
	}

	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'notify' => empty( $in['notify'] ) ? 0 : 1,
			'email'  => isset( $in['email'] ) && is_email( $in['email'] ) ? sanitize_email( $in['email'] ) : '',
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'bundle-stock-sync-for-woocommerce' ) );
		}
		$opt = get_option( 'eru_bss_settings', array() );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$bundles = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", Eru_BSS_Bundles::META_COMPONENTS ) );
		?>
		<div class="wrap eru-bss">
			<h1><?php esc_html_e( 'Paket Stok Eşitleme', 'bundle-stock-sync-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Paket tanımı her ürünün düzenleme ekranındaki "Paket içeriği" sekmesinden yapılır. Paket stoğu bileşenlerden hesaplanır; paketin elle girilen stok değeri üzerine yazılır.', 'bundle-stock-sync-for-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_bss' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Yönetici bildirimi', 'bundle-stock-sync-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="eru_bss_settings[notify]" value="1" <?php checked( ! empty( $opt['notify'] ) ); ?> />
							<?php esc_html_e( 'Bir paket bileşenler yüzünden stokta kalmadığında e-posta gönder', 'bundle-stock-sync-for-woocommerce' ); ?></label></td></tr>
					<tr><th scope="row"><label for="eru-bss-email"><?php esc_html_e( 'Bildirim e-postası', 'bundle-stock-sync-for-woocommerce' ); ?></label></th>
						<td><input type="email" id="eru-bss-email" class="regular-text" name="eru_bss_settings[email]" value="<?php echo esc_attr( $opt['email'] ?? '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Boşsa site yöneticisinin e-postası kullanılır.', 'bundle-stock-sync-for-woocommerce' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Tanımlı paketler', 'bundle-stock-sync-for-woocommerce' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Paket', 'bundle-stock-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Bileşenler', 'bundle-stock-sync-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Stok', 'bundle-stock-sync-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $bundles ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Henüz paket tanımlanmadı.', 'bundle-stock-sync-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $bundles as $bid ) : ?>
					<?php
					$p = wc_get_product( (int) $bid );
					if ( ! $p ) {
						continue;
					}
					$parts = array();
					foreach ( Eru_BSS_Bundles::get_components( $bid ) as $c ) {
						$parts[] = $c['qty'] . ' × ' . $c['sku'];
					}
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $bid ) ); ?>"><?php echo esc_html( $p->get_name() ); ?></a></td>
						<td><?php echo esc_html( implode( ', ', $parts ) ); ?></td>
						<td><?php echo $p->managing_stock() ? esc_html( (string) wc_stock_amount( $p->get_stock_quantity() ) ) : esc_html__( 'Sınırsız', 'bundle-stock-sync-for-woocommerce' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
