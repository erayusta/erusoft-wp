<?php
/** WooCommerce > Yüksek Tutarlı Sepet Teklifi: settings page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HVCO_Admin {

	const CAP = 'manage_woocommerce';
	const TD  = 'high-value-cart-offer-for-woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_hvco_save', array( __CLASS__, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Sepet Teklifi', 'high-value-cart-offer-for-woocommerce' ), __( 'Sepet Teklifi', 'high-value-cart-offer-for-woocommerce' ), self::CAP, 'eru-hvco', array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'high-value-cart-offer-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_hvco_save' );
		$raw = isset( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_HVCO_Offer::sanitize().
		update_option( Eru_HVCO_Offer::OPTION, Eru_HVCO_Offer::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-hvco', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = Eru_HVCO_Offer::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Yüksek Tutarlı Sepet Teklifi', 'high-value-cart-offer-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'high-value-cart-offer-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Sepet tutarı eşiği aştığında, WhatsApp sohbetini sepet özetiyle açan bir teklif kutusu gösterir. WhatsApp numarası girilmediği sürece eklenti hiçbir şey göstermez.', 'high-value-cart-offer-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_hvco_save">
				<?php wp_nonce_field( 'eru_hvco_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="eru-hvco-number"><?php esc_html_e( 'WhatsApp numarası', 'high-value-cart-offer-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="eru-hvco-number" name="s[number]" value="<?php echo esc_attr( $s['number'] ); ?>" placeholder="905550000000">
						<p class="description"><?php esc_html_e( 'Ülke koduyla yazın (örn. 90 ile başlayan). 0 ile başlayan Türkiye numaraları ve 00 öneki otomatik olarak uluslararası biçime çevrilir.', 'high-value-cart-offer-for-woocommerce' ); ?></p></td></tr>
					<tr><th><label for="eru-hvco-threshold"><?php esc_html_e( 'Sepet tutarı eşiği', 'high-value-cart-offer-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="eru-hvco-threshold" name="s[threshold]" value="<?php echo esc_attr( $s['threshold'] ? (string) $s['threshold'] : '' ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Kupon sonrası, kargo hariç sepet tutarı bu değere ulaşınca kutu görünür. Boş bırakılırsa her sepette görünür.', 'high-value-cart-offer-for-woocommerce' ); ?></p></td></tr>
					<tr><th><label for="eru-hvco-title"><?php esc_html_e( 'Başlık', 'high-value-cart-offer-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="regular-text" id="eru-hvco-title" name="s[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td></tr>
					<tr><th><label for="eru-hvco-message"><?php esc_html_e( 'Açıklama', 'high-value-cart-offer-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="large-text" id="eru-hvco-message" name="s[message]" value="<?php echo esc_attr( $s['message'] ); ?>"></td></tr>
					<tr><th><label for="eru-hvco-button"><?php esc_html_e( 'Buton metni', 'high-value-cart-offer-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="regular-text" id="eru-hvco-button" name="s[button]" value="<?php echo esc_attr( $s['button'] ); ?>"></td></tr>
					<tr><th><label for="eru-hvco-intro"><?php esc_html_e( 'WhatsApp mesajının ilk satırı', 'high-value-cart-offer-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="large-text" id="eru-hvco-intro" name="s[intro]" value="<?php echo esc_attr( $s['intro'] ); ?>">
						<p class="description"><?php esc_html_e( 'Ürün listesi ve sepet tutarı bu satırın altına eklenir.', 'high-value-cart-offer-for-woocommerce' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Gösterim yeri', 'high-value-cart-offer-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="s[show_cart]" value="1" <?php checked( ! empty( $s['show_cart'] ) ); ?>> <?php esc_html_e( 'Sepet sayfası', 'high-value-cart-offer-for-woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="s[show_mini]" value="1" <?php checked( ! empty( $s['show_mini'] ) ); ?>> <?php esc_html_e( 'Mini sepet', 'high-value-cart-offer-for-woocommerce' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Kaydet', 'high-value-cart-offer-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}
}
