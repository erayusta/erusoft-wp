<?php
/** WooCommerce > Toplu Teklif settings page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WIB_Admin {

	const CAP = 'manage_woocommerce';
	const TD  = 'wholesale-inquiry-box-for-woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_wib_save', array( __CLASS__, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Toplu Teklif', 'wholesale-inquiry-box-for-woocommerce' ), __( 'Toplu Teklif', 'wholesale-inquiry-box-for-woocommerce' ), self::CAP, 'eru-wib', array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'wholesale-inquiry-box-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_wib_save' );
		$raw = isset( $_POST['o'] ) ? (array) wp_unslash( $_POST['o'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_WIB_Settings::sanitize().
		update_option( Eru_WIB_Settings::OPTION, Eru_WIB_Settings::sanitize( $raw ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-wib', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$o     = Eru_WIB_Settings::get();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$n     = 'o';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Toplu Alım Teklif Kutusu', 'wholesale-inquiry-box-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'wholesale-inquiry-box-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_wib_save">
				<?php wp_nonce_field( 'eru_wib_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Kutuyu göster', 'wholesale-inquiry-box-for-woocommerce' ); ?></th><td><label><input type="checkbox" name="<?php echo $n; ?>[enabled]" value="1" <?php checked( ! empty( $o['enabled'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>> <?php esc_html_e( 'Ürün sayfalarında teklif kutusunu etkinleştir', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></td></tr>
					<tr><th><label for="eru-wib-title"><?php esc_html_e( 'Başlık', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></th><td><input type="text" id="eru-wib-title" class="regular-text" name="<?php echo $n; ?>[title]" value="<?php echo esc_attr( $o['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>"></td></tr>
					<tr><th><label for="eru-wib-text"><?php esc_html_e( 'Açıklama', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></th><td><textarea id="eru-wib-text" class="large-text" rows="2" name="<?php echo $n; ?>[text]"><?php echo esc_textarea( $o['text'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></textarea></td></tr>
					<tr><th><label for="eru-wib-button"><?php esc_html_e( 'Düğme yazısı', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></th><td><input type="text" id="eru-wib-button" class="regular-text" name="<?php echo $n; ?>[button]" value="<?php echo esc_attr( $o['button'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>"></td></tr>
					<tr><th><label for="eru-wib-min"><?php esc_html_e( 'Asgari adet', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></th><td><input type="number" id="eru-wib-min" min="1" name="<?php echo $n; ?>[min_qty]" value="<?php echo esc_attr( (int) $o['min_qty'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" style="width:100px"></td></tr>
					<tr><th><label for="eru-wib-email"><?php esc_html_e( 'Teklifin gideceği e-posta', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></th><td><input type="email" id="eru-wib-email" class="regular-text" name="<?php echo $n; ?>[email]" value="<?php echo esc_attr( $o['email'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="description"><?php esc_html_e( 'Boşsa site yöneticisinin e-posta adresi kullanılır.', 'wholesale-inquiry-box-for-woocommerce' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Kategoriler', 'wholesale-inquiry-box-for-woocommerce' ); ?></th><td>
						<div style="max-height:220px;overflow:auto;border:1px solid #c3c4c7;padding:8px 12px;background:#fff;max-width:400px">
						<?php if ( ! is_wp_error( $terms ) ) : foreach ( $terms as $t ) : ?>
							<label style="display:block;margin:2px 0"><input type="checkbox" name="<?php echo $n; ?>[category_ids][]" value="<?php echo esc_attr( $t->term_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" <?php checked( in_array( (int) $t->term_id, array_map( 'intval', $o['category_ids'] ), true ) ); ?>> <?php echo esc_html( $t->name ); ?></label>
						<?php endforeach; endif; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Hiçbiri seçilmezse kutu tüm ürünlerde görünür. Alt kategoriler dahildir.', 'wholesale-inquiry-box-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><label for="eru-wib-wa"><?php esc_html_e( 'WhatsApp numarası (isteğe bağlı)', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></th><td><input type="text" id="eru-wib-wa" class="regular-text" name="<?php echo $n; ?>[whatsapp]" value="<?php echo esc_attr( $o['whatsapp'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" placeholder="905000000000"><p class="description"><?php esc_html_e( 'Ülke kodu ile, + ve boşluksuz. Doluysa forma bir WhatsApp düğmesi eklenir.', 'wholesale-inquiry-box-for-woocommerce' ); ?></p></td></tr>
					<tr><th><label for="eru-wib-wat"><?php esc_html_e( 'WhatsApp mesaj şablonu', 'wholesale-inquiry-box-for-woocommerce' ); ?></label></th><td><textarea id="eru-wib-wat" class="large-text" rows="2" name="<?php echo $n; ?>[wa_template]"><?php echo esc_textarea( $o['wa_template'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></textarea><p class="description"><?php esc_html_e( 'Yer tutucular: {urun}, {sku}, {link}.', 'wholesale-inquiry-box-for-woocommerce' ); ?></p></td></tr>
				</table>
				<?php submit_button( __( 'Kaydet', 'wholesale-inquiry-box-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}
}
