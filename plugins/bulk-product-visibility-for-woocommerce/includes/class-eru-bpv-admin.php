<?php
/**
 * Admin screen and AJAX endpoints.
 *
 * @package bulk-product-visibility-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BPV_Admin {

	const PAGE = 'eru-bpv';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_eru_bpv_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_eru_bpv_apply', array( __CLASS__, 'ajax_apply' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=product', __( 'Toplu Görünürlük', 'bulk-product-visibility-for-woocommerce' ), __( 'Toplu Görünürlük', 'bulk-product-visibility-for-woocommerce' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'eru-bpv-admin', ERU_BPV_URL . 'assets/admin.css', array(), ERU_BPV_VERSION );
		wp_enqueue_script( 'eru-bpv-admin', ERU_BPV_URL . 'assets/admin.js', array( 'jquery' ), ERU_BPV_VERSION, true );
		wp_localize_script(
			'eru-bpv-admin',
			'eruBpv',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'eru_bpv' ),
				'matches' => __( '%d ürün eşleşti.', 'bulk-product-visibility-for-woocommerce' ),
				'sample'  => __( 'İlk örnekler:', 'bulk-product-visibility-for-woocommerce' ),
				'progress' => __( 'İşleniyor: %1$d / %2$d', 'bulk-product-visibility-for-woocommerce' ),
				'done'    => __( 'Tamamlandı: %d ürün güncellendi.', 'bulk-product-visibility-for-woocommerce' ),
				'failed'  => __( 'İşlem başarısız oldu.', 'bulk-product-visibility-for-woocommerce' ),
			)
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'bulk-product-visibility-for-woocommerce' ) );
		}
		?>
		<div class="wrap eru-bpv">
			<h1><?php esc_html_e( 'Toplu Görünürlük', 'bulk-product-visibility-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Ürünleri filtreleyin, uygulanacak değişikliği seçin, önce eşleşen ürün sayısını görün, sonra onaylayın. İşlem küçük gruplar halinde yürütülür.', 'bulk-product-visibility-for-woocommerce' ); ?></p>
			<form id="eru-bpv-form" onsubmit="return false">
				<h2><?php esc_html_e( '1. Filtre', 'bulk-product-visibility-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="eru-bpv-cat"><?php esc_html_e( 'Kategori', 'bulk-product-visibility-for-woocommerce' ); ?></label></th>
						<td><?php
						wp_dropdown_categories(
							array(
								'taxonomy'        => 'product_cat',
								'name'            => 'category',
								'id'              => 'eru-bpv-cat',
								'show_option_all' => __( 'Tüm kategoriler', 'bulk-product-visibility-for-woocommerce' ),
								'hide_empty'      => false,
								'hierarchical'    => true,
								'value_field'     => 'term_id',
							)
						);
						?></td></tr>
					<tr><th scope="row"><label for="eru-bpv-stock"><?php esc_html_e( 'Stok durumu', 'bulk-product-visibility-for-woocommerce' ); ?></label></th>
						<td><select name="stock" id="eru-bpv-stock">
							<option value=""><?php esc_html_e( 'Hepsi', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="instock"><?php esc_html_e( 'Stokta', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="outofstock"><?php esc_html_e( 'Stokta yok', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="onbackorder"><?php esc_html_e( 'Ön sipariş', 'bulk-product-visibility-for-woocommerce' ); ?></option>
						</select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Fiyat aralığı', 'bulk-product-visibility-for-woocommerce' ); ?></th>
						<td><input type="number" step="0.01" min="0" name="price_min" class="small-text" placeholder="<?php esc_attr_e( 'En az', 'bulk-product-visibility-for-woocommerce' ); ?>"> –
							<input type="number" step="0.01" min="0" name="price_max" class="small-text" placeholder="<?php esc_attr_e( 'En çok', 'bulk-product-visibility-for-woocommerce' ); ?>"></td></tr>
				</table>
				<h2><?php esc_html_e( '2. Değişiklik', 'bulk-product-visibility-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="eru-bpv-vis"><?php esc_html_e( 'Katalog görünürlüğü', 'bulk-product-visibility-for-woocommerce' ); ?></label></th>
						<td><select name="visibility" id="eru-bpv-vis">
							<option value=""><?php esc_html_e( '— değiştirme —', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="visible"><?php esc_html_e( 'Mağaza ve aramada görünür', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="catalog"><?php esc_html_e( 'Yalnızca mağaza listelerinde', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="search"><?php esc_html_e( 'Yalnızca aramada', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="hidden"><?php esc_html_e( 'Gizli', 'bulk-product-visibility-for-woocommerce' ); ?></option>
						</select></td></tr>
					<tr><th scope="row"><label for="eru-bpv-status"><?php esc_html_e( 'Yayın durumu', 'bulk-product-visibility-for-woocommerce' ); ?></label></th>
						<td><select name="status" id="eru-bpv-status">
							<option value=""><?php esc_html_e( '— değiştirme —', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Yayında', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="draft"><?php esc_html_e( 'Taslak', 'bulk-product-visibility-for-woocommerce' ); ?></option>
							<option value="private"><?php esc_html_e( 'Özel', 'bulk-product-visibility-for-woocommerce' ); ?></option>
						</select></td></tr>
				</table>
				<p>
					<button type="button" class="button button-primary" id="eru-bpv-preview"><?php esc_html_e( 'Önizle (hiçbir şey değişmez)', 'bulk-product-visibility-for-woocommerce' ); ?></button>
					<button type="button" class="button" id="eru-bpv-apply" disabled><?php esc_html_e( 'Onayla ve uygula', 'bulk-product-visibility-for-woocommerce' ); ?></button>
				</p>
			</form>
			<div id="eru-bpv-out" role="status" aria-live="polite"></div>
			<div id="eru-bpv-bar" hidden><span></span></div>
		</div>
		<?php
	}

	private static function posted() {
		$f = array();
		foreach ( array( 'category', 'stock', 'price_min', 'price_max' ) as $k ) {
			$f[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		$c = array();
		foreach ( array( 'visibility', 'status' ) as $k ) {
			$c[ $k ] = isset( $_POST[ $k ] ) ? sanitize_key( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		return array( $f, $c );
	}

	private static function guard() {
		check_ajax_referer( 'eru_bpv', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok.', 'bulk-product-visibility-for-woocommerce' ) ), 403 );
		}
	}

	public static function ajax_preview() {
		self::guard();
		list( $f, $c ) = self::posted();
		$r = Eru_BPV_Engine::preview( $f, $c, true );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
		}
		wp_send_json_success( $r );
	}

	public static function ajax_apply() {
		self::guard();
		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$r      = Eru_BPV_Engine::run_batch( $token, $offset, true );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
		}
		wp_send_json_success( $r );
	}
}
