<?php
/**
 * Settings and generator page.
 *
 * @package pdf-product-catalog-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PPC_Admin {

	const TD = 'pdf-product-catalog-for-woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'PDF Katalog', 'pdf-product-catalog-for-woocommerce' ), __( 'PDF Katalog', 'pdf-product-catalog-for-woocommerce' ), 'manage_woocommerce', 'eru-ppc', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_ppc', fn() => 'manage_woocommerce' );
		register_setting( 'eru_ppc', Eru_PPC::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => Eru_PPC::defaults() ) );
	}

	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return get_option( Eru_PPC::OPTION, Eru_PPC::defaults() );
		}
		return Eru_PPC::sanitize_settings( $in );
	}

	private static function checklist( $terms, $parent, $depth = 0 ) {
		foreach ( $terms as $t ) {
			if ( (int) $t->parent !== (int) $parent ) {
				continue;
			}
			printf(
				'<li style="margin-left:%dpx"><label><input type="checkbox" name="cats[]" value="%d"> %s <span class="description">(%d)</span></label></li>',
				(int) $depth * 18,
				(int) $t->term_id,
				esc_html( $t->name ),
				(int) $t->count
			);
			self::checklist( $terms, $t->term_id, $depth + 1 );
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'pdf-product-catalog-for-woocommerce' ) );
		}
		$s     = Eru_PPC::get_settings();
		$n     = Eru_PPC::OPTION;
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$chk   = fn( $k, $label ) => printf( '<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label><br>', esc_attr( $n ), esc_attr( $k ), checked( ! empty( $s[ $k ] ), true, false ), esc_html( $label ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PDF Ürün Kataloğu', 'pdf-product-catalog-for-woocommerce' ); ?></h1>

			<h2><?php esc_html_e( 'Katalog oluştur', 'pdf-product-catalog-for-woocommerce' ); ?></h2>
			<form method="post" target="_blank" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_ppc_download">
				<?php wp_nonce_field( 'eru_ppc_download' ); ?>
				<ul style="max-height:240px;overflow:auto;margin:0 0 10px;padding:8px 12px;border:1px solid #c3c4c7;background:#fff;max-width:480px">
					<?php self::checklist( $terms, 0 ); ?>
				</ul>
				<p class="description"><?php esc_html_e( 'Hiçbiri seçilmezse tüm ana kategoriler dahil edilir. Katalog aşağıdaki ayarlarla yeni sekmede açılır; "Yazdır / PDF olarak kaydet" düğmesiyle tarayıcıdan PDF olarak kaydedin (hedef: PDF olarak kaydet).', 'pdf-product-catalog-for-woocommerce' ); ?></p>
				<?php submit_button( __( 'Kataloğu aç (yazdır / PDF olarak kaydet)', 'pdf-product-catalog-for-woocommerce' ), 'primary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Ayarlar', 'pdf-product-catalog-for-woocommerce' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_ppc' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="eru-ppc-title"><?php esc_html_e( 'Kapak başlığı', 'pdf-product-catalog-for-woocommerce' ); ?></label></th>
						<td><input id="eru-ppc-title" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="eru-ppc-sub"><?php esc_html_e( 'Alt başlık', 'pdf-product-catalog-for-woocommerce' ); ?></label></th>
						<td><input id="eru-ppc-sub" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[subtitle]" value="<?php echo esc_attr( $s['subtitle'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="eru-ppc-note"><?php esc_html_e( 'Kapak notu', 'pdf-product-catalog-for-woocommerce' ); ?></label></th>
						<td><textarea id="eru-ppc-note" class="large-text" rows="3" name="<?php echo esc_attr( $n ); ?>[note]"><?php echo esc_textarea( $s['note'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'İletişim bilgisi gibi metinleri buraya siz yazarsınız; eklenti hiçbir bilgi eklemez.', 'pdf-product-catalog-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><label for="eru-ppc-footer"><?php esc_html_e( 'Sayfa altlığı', 'pdf-product-catalog-for-woocommerce' ); ?></label></th>
						<td><input id="eru-ppc-footer" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[footer]" value="<?php echo esc_attr( $s['footer'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="eru-ppc-logo"><?php esc_html_e( 'Kapak logosu', 'pdf-product-catalog-for-woocommerce' ); ?></label></th>
						<td><select id="eru-ppc-logo" name="<?php echo esc_attr( $n ); ?>[logo]">
							<option value="site_icon" <?php selected( $s['logo'], 'site_icon' ); ?>><?php esc_html_e( 'Site simgesi', 'pdf-product-catalog-for-woocommerce' ); ?></option>
							<option value="custom_logo" <?php selected( $s['logo'], 'custom_logo' ); ?>><?php esc_html_e( 'Tema logosu', 'pdf-product-catalog-for-woocommerce' ); ?></option>
							<option value="none" <?php selected( $s['logo'], 'none' ); ?>><?php esc_html_e( 'Logo yok', 'pdf-product-catalog-for-woocommerce' ); ?></option>
						</select></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Sayfa düzeni', 'pdf-product-catalog-for-woocommerce' ); ?></th>
						<td><select name="<?php echo esc_attr( $n ); ?>[orientation]">
							<option value="portrait" <?php selected( $s['orientation'], 'portrait' ); ?>><?php esc_html_e( 'Dikey', 'pdf-product-catalog-for-woocommerce' ); ?></option>
							<option value="landscape" <?php selected( $s['orientation'], 'landscape' ); ?>><?php esc_html_e( 'Yatay', 'pdf-product-catalog-for-woocommerce' ); ?></option>
						</select>
						<label><?php esc_html_e( 'Sütun', 'pdf-product-catalog-for-woocommerce' ); ?> <input type="number" min="2" max="4" name="<?php echo esc_attr( $n ); ?>[columns]" value="<?php echo esc_attr( $s['columns'] ); ?>" style="width:60px"></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ürün bilgileri', 'pdf-product-catalog-for-woocommerce' ); ?></th>
						<td><?php
						$chk( 'show_image', __( 'Görsel', 'pdf-product-catalog-for-woocommerce' ) );
						$chk( 'show_sku', __( 'Stok kodu', 'pdf-product-catalog-for-woocommerce' ) );
						$chk( 'show_price', __( 'Fiyat', 'pdf-product-catalog-for-woocommerce' ) );
						$chk( 'show_desc', __( 'Kısa açıklama', 'pdf-product-catalog-for-woocommerce' ) );
						$chk( 'in_stock_only', __( 'Yalnızca stokta olanlar', 'pdf-product-catalog-for-woocommerce' ) );
						?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Ön yüz', 'pdf-product-catalog-for-woocommerce' ); ?></th>
						<td><?php $chk( 'front', __( 'Ziyaretçilerin indirebilmesine izin ver ([eru_pdf_catalog] kısa kodu)', 'pdf-product-catalog-for-woocommerce' ) ); ?>
						<p class="description"><?php esc_html_e( 'Kapalıyken yalnızca mağaza yöneticileri katalog oluşturabilir. Açıkken oluşturulan katalog bir saat önbelleğe alınır ve IP başına saatte 5 yeni oluşturma ile sınırlıdır (proxy/CDN arkasında eru_ppc_client_ip filtresini kullanın).', 'pdf-product-catalog-for-woocommerce' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
