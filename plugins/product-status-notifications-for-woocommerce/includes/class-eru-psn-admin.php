<?php
/**
 * Settings page.
 *
 * @package product-status-notifications-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PSN_Admin {

	const TD = 'product-status-notifications-for-woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Ürün Bildirimleri', 'product-status-notifications-for-woocommerce' ), __( 'Ürün Bildirimleri', 'product-status-notifications-for-woocommerce' ), 'manage_woocommerce', 'eru-psn', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_psn', fn() => 'manage_woocommerce' );
		register_setting( 'eru_psn', Eru_PSN::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => Eru_PSN::defaults() ) );
	}

	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return get_option( Eru_PSN::OPTION, Eru_PSN::defaults() );
		}
		return Eru_PSN::sanitize_settings( $in );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'product-status-notifications-for-woocommerce' ) );
		}
		$s     = Eru_PSN::get_settings();
		$n     = Eru_PSN::OPTION;
		$st    = Eru_PSN::statuses();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$ev    = array(
			'status'    => __( 'Yayın durumu değişince (aşağıdaki geçiş)', 'product-status-notifications-for-woocommerce' ),
			'stock_out' => __( 'Ürün stokta yok durumuna geçince', 'product-status-notifications-for-woocommerce' ),
			'price'     => __( 'Normal veya indirimli fiyat değişince', 'product-status-notifications-for-woocommerce' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ürün Durum Bildirimleri', 'product-status-notifications-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_psn' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Bildirim olayları', 'product-status-notifications-for-woocommerce' ); ?></th>
						<td>
							<?php foreach ( $ev as $k => $l ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[events][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $s['events'], true ) ); ?>> <?php echo esc_html( $l ); ?></label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Hiçbiri seçili değilse e-posta gönderilmez.', 'product-status-notifications-for-woocommerce' ); ?></p>
						</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Durum geçişi', 'product-status-notifications-for-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $n ); ?>[status_from]">
								<option value="any" <?php selected( $s['status_from'], 'any' ); ?>><?php esc_html_e( 'Herhangi bir durum', 'product-status-notifications-for-woocommerce' ); ?></option>
								<?php foreach ( $st as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['status_from'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
							</select> →
							<select name="<?php echo esc_attr( $n ); ?>[status_to]">
								<option value="any" <?php selected( $s['status_to'], 'any' ); ?>><?php esc_html_e( 'Herhangi bir durum', 'product-status-notifications-for-woocommerce' ); ?></option>
								<?php foreach ( $st as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['status_to'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
							</select>
						</td></tr>
					<tr><th scope="row"><label for="eru-psn-to"><?php esc_html_e( 'Alıcılar', 'product-status-notifications-for-woocommerce' ); ?></label></th>
						<td><textarea id="eru-psn-to" class="large-text" rows="3" name="<?php echo esc_attr( $n ); ?>[recipients]" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"><?php echo esc_textarea( $s['recipients'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Virgül veya satırla ayırın. Boşsa site yöneticisinin e-posta adresi kullanılır.', 'product-status-notifications-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><label for="eru-psn-rules"><?php esc_html_e( 'Alıcı kuralları', 'product-status-notifications-for-woocommerce' ); ?></label></th>
						<td><textarea id="eru-psn-rules" class="large-text code" rows="3" name="<?php echo esc_attr( $n ); ?>[rules]" placeholder="product_cat:kategori-slug = ornek@example.com"><?php echo esc_textarea( $s['rules'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'İsteğe bağlı. Her satır: taksonomi:terim-slug = e-posta, e-posta. Bir ürün bir kurala uyarsa bildirim yalnızca o kuralın alıcılarına gider (marka taksonomisi için örn. product_brand:marka-slug).', 'product-status-notifications-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Hariç tutulan kategoriler', 'product-status-notifications-for-woocommerce' ); ?></th>
						<td><div style="max-height:200px;overflow:auto;border:1px solid #c3c4c7;background:#fff;padding:8px 12px;max-width:480px">
							<?php foreach ( $terms as $t ) : ?>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[excluded][]" value="<?php echo esc_attr( $t->term_id ); ?>" <?php checked( in_array( (int) $t->term_id, array_map( 'intval', (array) $s['excluded'] ), true ) ); ?>> <?php echo esc_html( $t->name ); ?></label>
							<?php endforeach; ?>
						</div></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
