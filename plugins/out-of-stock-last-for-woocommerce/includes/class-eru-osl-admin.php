<?php
/**
 * Settings page.
 *
 * @package out-of-stock-last-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSL_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Stokta Olmayanlar Sonda', 'out-of-stock-last-for-woocommerce' ), __( 'Stokta Olmayanlar Sonda', 'out-of-stock-last-for-woocommerce' ), 'manage_woocommerce', 'eru-osl', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_osl', fn() => 'manage_woocommerce' );
		register_setting(
			'eru_osl',
			Eru_OSL::OPTION,
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
			'enabled' => empty( $in['enabled'] ) ? 'no' : 'yes',
			'search'  => empty( $in['search'] ) ? 'no' : 'yes',
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'out-of-stock-last-for-woocommerce' ) );
		}
		$s = Eru_OSL::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stokta Olmayanlar Sonda', 'out-of-stock-last-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_osl' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Sıralama', 'out-of-stock-last-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Eru_OSL::OPTION ); ?>[enabled]" value="1" <?php checked( 'yes', $s['enabled'] ); ?>>
								<?php esc_html_e( 'Stokta olmayan ürünleri mağaza ve arşiv sayfalarında listenin sonuna taşı', 'out-of-stock-last-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'Seçtiğiniz sıralama (fiyat, popülerlik, tarih…) stokta olan ürünler arasında aynen korunur.', 'out-of-stock-last-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Arama', 'out-of-stock-last-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Eru_OSL::OPTION ); ?>[search]" value="1" <?php checked( 'yes', $s['search'] ); ?>>
								<?php esc_html_e( 'Ürün arama sonuçlarına da uygula', 'out-of-stock-last-for-woocommerce' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
