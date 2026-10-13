<?php
/**
 * Product fields and settings page.
 *
 * @package coming-soon-products-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CSP_Admin {

	public static function init() {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function product_fields() {
		echo '<div class="options_group show_if_simple show_if_variable show_if_external show_if_grouped">';
		woocommerce_wp_checkbox(
			array(
				'id'          => Eru_CSP::ENABLED,
				'label'       => __( 'Yakında', 'coming-soon-products-for-woocommerce' ),
				'description' => __( 'Satışı kapat; fiyat yerine yakında mesajı göster', 'coming-soon-products-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Eru_CSP::MESSAGE,
				'label'       => __( 'Yakında mesajı', 'coming-soon-products-for-woocommerce' ),
				'description' => __( 'Boş bırakırsanız ayarlardaki varsayılan mesaj kullanılır.', 'coming-soon-products-for-woocommerce' ),
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Eru_CSP::DATE,
				'label'       => __( 'Çıkış tarihi', 'coming-soon-products-for-woocommerce' ),
				'type'        => 'date',
				'description' => __( 'İsteğe bağlı. Bu tarih geldiğinde ürün kendiliğinden satışa açılır.', 'coming-soon-products-for-woocommerce' ),
				'desc_tip'    => true,
			)
		);
		echo '</div>';
	}

	/**
	 * WooCommerce verifies the product-edit nonce and capability before this hook runs.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_product( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce's product save handler.
		$id = $product->get_id();
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		update_post_meta( $id, Eru_CSP::ENABLED, empty( $_POST[ Eru_CSP::ENABLED ] ) ? 'no' : 'yes' );
		$msg = isset( $_POST[ Eru_CSP::MESSAGE ] ) ? sanitize_text_field( wp_unslash( $_POST[ Eru_CSP::MESSAGE ] ) ) : '';
		$msg ? update_post_meta( $id, Eru_CSP::MESSAGE, $msg ) : delete_post_meta( $id, Eru_CSP::MESSAGE );
		$date = isset( $_POST[ Eru_CSP::DATE ] ) ? sanitize_text_field( wp_unslash( $_POST[ Eru_CSP::DATE ] ) ) : '';
		( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) ? update_post_meta( $id, Eru_CSP::DATE, $date ) : delete_post_meta( $id, Eru_CSP::DATE );
		// phpcs:enable
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Yakında Ürünler', 'coming-soon-products-for-woocommerce' ), __( 'Yakında Ürünler', 'coming-soon-products-for-woocommerce' ), 'manage_woocommerce', 'eru-csp', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_csp', fn() => 'manage_woocommerce' );
		register_setting(
			'eru_csp',
			Eru_CSP::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'message'    => isset( $in['message'] ) ? sanitize_text_field( $in['message'] ) : '',
			'badge'      => isset( $in['badge'] ) ? sanitize_text_field( $in['badge'] ) : '',
			'hide_price' => empty( $in['hide_price'] ) ? 'no' : 'yes',
			'show_date'  => empty( $in['show_date'] ) ? 'no' : 'yes',
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'coming-soon-products-for-woocommerce' ) );
		}
		$s = Eru_CSP::settings();
		$n = esc_attr( Eru_CSP::OPTION );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Yakında Ürünler', 'coming-soon-products-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Ürünü yakında moduna almak için ürün düzenleme ekranında Genel sekmesindeki “Yakında” kutusunu işaretleyin.', 'coming-soon-products-for-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_csp' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="eru-csp-msg"><?php esc_html_e( 'Varsayılan mesaj', 'coming-soon-products-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="large-text" id="eru-csp-msg" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[message]" value="<?php echo esc_attr( $s['message'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="eru-csp-badge"><?php esc_html_e( 'Rozet metni', 'coming-soon-products-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="regular-text" id="eru-csp-badge" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[badge]" value="<?php echo esc_attr( $s['badge'] ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Fiyat', 'coming-soon-products-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[hide_price]" value="1" <?php checked( 'yes', $s['hide_price'] ); ?>> <?php esc_html_e( 'Fiyat yerine rozeti göster', 'coming-soon-products-for-woocommerce' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Çıkış tarihi', 'coming-soon-products-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $n; // phpcs:ignore WordPress.Security.EscapeOutput ?>[show_date]" value="1" <?php checked( 'yes', $s['show_date'] ); ?>> <?php esc_html_e( 'Girilmişse çıkış tarihini mesajın altında göster', 'coming-soon-products-for-woocommerce' ); ?></label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
