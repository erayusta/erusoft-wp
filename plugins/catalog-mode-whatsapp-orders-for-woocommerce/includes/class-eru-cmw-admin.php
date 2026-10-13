<?php
/**
 * Settings page.
 *
 * @package catalog-mode-whatsapp-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CMW_Admin {

	const TD = 'catalog-mode-whatsapp-orders-for-woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Katalog Modu ve WhatsApp', 'catalog-mode-whatsapp-orders-for-woocommerce' ), __( 'Katalog Modu ve WhatsApp', 'catalog-mode-whatsapp-orders-for-woocommerce' ), 'manage_woocommerce', 'eru-cmw', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_cmw', fn() => 'manage_woocommerce' );
		register_setting( 'eru_cmw', Eru_CMW::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => Eru_CMW::defaults() ) );
	}

	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return get_option( Eru_CMW::OPTION, Eru_CMW::defaults() );
		}
		return Eru_CMW::sanitize_settings( $in );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'catalog-mode-whatsapp-orders-for-woocommerce' ) );
		}
		$s = Eru_CMW::get_settings();
		$n = Eru_CMW::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Katalog Modu ve WhatsApp Siparişi', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></h1>
			<?php settings_errors( 'eru_cmw' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_cmw' ); ?>
				<h2><?php esc_html_e( 'WhatsApp', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="eru-cmw-num"><?php esc_html_e( 'WhatsApp numarası', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></label></th>
						<td><input id="eru-cmw-num" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[number]" value="<?php echo esc_attr( $s['number'] ); ?>" placeholder="905550000000">
						<p class="description"><?php esc_html_e( 'Ülke koduyla, yalnızca rakam (Türkiye için 90 ile başlar). 0 ile başlayan 11 haneli yerel numaralar 90… biçimine çevrilir. Boşken düğme gösterilmez.', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><label for="eru-cmw-text"><?php esc_html_e( 'Düğme yazısı', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></label></th>
						<td><input id="eru-cmw-text" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[button_text]" value="<?php echo esc_attr( $s['button_text'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="eru-cmw-msg"><?php esc_html_e( 'Mesaj şablonu', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></label></th>
						<td><textarea id="eru-cmw-msg" class="large-text" rows="3" name="<?php echo esc_attr( $n ); ?>[message]"><?php echo esc_textarea( $s['message'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Kullanılabilir alanlar: {product}, {url}, {sku}, {price}', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Düğme nerede', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_single]" value="1" <?php checked( ! empty( $s['show_single'] ) ); ?>> <?php esc_html_e( 'Ürün sayfasında', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_loop]" value="1" <?php checked( ! empty( $s['show_loop'] ) ); ?>> <?php esc_html_e( 'Ürün listelerinde', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></label></td></tr>
				</table>
				<h2><?php esc_html_e( 'Katalog modu', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Fiyatlar', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hide_price]" value="1" <?php checked( ! empty( $s['hide_price'] ) ); ?>> <?php esc_html_e( 'Fiyatları gizle', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Sepet', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[hide_cart]" value="1" <?php checked( ! empty( $s['hide_cart'] ) ); ?>> <?php esc_html_e( '"Sepete ekle" düğmelerini kaldır ve ürünleri satın alınamaz yap', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'İkisi de kapalıyken mağaza olduğu gibi çalışır.', 'catalog-mode-whatsapp-orders-for-woocommerce' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
