<?php
/**
 * WooCommerce → Türkiye Adres: settings screen.
 *
 * @package turkish-address-fields-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TAF_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'inactive_notice' ) );
		// options.php checks manage_options by default; shop managers own this screen.
		add_filter( 'option_page_capability_eru_taf', fn() => 'manage_woocommerce' );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Türkiye Adres', 'turkish-address-fields-for-woocommerce' ), __( 'Türkiye Adres', 'turkish-address-fields-for-woocommerce' ), 'manage_woocommerce', 'eru-taf', array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		register_setting( 'eru_taf', Eru_TAF_Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( 'Eru_TAF_Settings', 'sanitize' ), 'default' => array() ) );
	}

	/** One-line hint on the Plugins screen while the fields are switched off. */
	public static function inactive_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || Eru_TAF_Settings::on( 'enabled' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Turkish Address Fields etkin ama adres alanları kapalı.', 'turkish-address-fields-for-woocommerce' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=eru-taf' ) ) . '">' . esc_html__( 'Ayarlardan açın', 'turkish-address-fields-for-woocommerce' ) . '</a></p></div>';
	}

	private static function block_checkout_notice(): void {
		$id   = wc_get_page_id( 'checkout' );
		$page = $id > 0 ? get_post( $id ) : null;
		if ( $page && has_block( 'woocommerce/checkout', $page ) ) {
			echo '<div class="notice notice-warning inline eru-keep"><p>' . esc_html__( 'Ödeme sayfanız blok tabanlı. Türkiye adres alanları yalnızca klasik ödeme sayfasında ([woocommerce_checkout] kısa kodu) çalışır; blok ödemede görünmez.', 'turkish-address-fields-for-woocommerce' ) . '</p></div>';
		}
	}

	private static function box( string $key, string $label, string $help = '' ): void {
		$s = Eru_TAF_Settings::all();
		echo '<label><input type="checkbox" name="' . esc_attr( Eru_TAF_Settings::OPTION . '[' . $key . ']' ) . '" value="1" ' . checked( $s[ $key ], 1, false ) . '> ' . esc_html( $label ) . '</label>';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Türkiye Adres Alanları', 'turkish-address-fields-for-woocommerce' ); ?></h1>
			<?php self::block_checkout_notice(); ?>
			<p><?php esc_html_e( 'Ödeme ve Hesabım adres formlarında İl → İlçe → Mahalle → Cadde/Sokak seçimini paketli adres verisiyle sunar. Harici servis kullanılmaz; liste verisi seçim yapıldıkça sitenizden yüklenir.', 'turkish-address-fields-for-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_taf' ); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e( 'Durum', 'turkish-address-fields-for-woocommerce' ); ?></th><td><?php self::box( 'enabled', __( 'Türkiye adres alanlarını etkinleştir', 'turkish-address-fields-for-woocommerce' ), __( 'Kapalıyken ödeme sayfası değişmez.', 'turkish-address-fields-for-woocommerce' ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Formlar', 'turkish-address-fields-for-woocommerce' ); ?></th><td>
						<?php self::box( 'billing', __( 'Fatura adresi', 'turkish-address-fields-for-woocommerce' ) ); ?><br>
						<?php self::box( 'shipping', __( 'Teslimat adresi ("Farklı bir adrese gönder")', 'turkish-address-fields-for-woocommerce' ) ); ?><br>
						<?php self::box( 'account', __( 'Hesabım → Adresler formu', 'turkish-address-fields-for-woocommerce' ) ); ?>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Seviyeler', 'turkish-address-fields-for-woocommerce' ); ?></th><td>
						<p class="description"><?php esc_html_e( 'İl (WooCommerce il alanı) ve İlçe her zaman gösterilir.', 'turkish-address-fields-for-woocommerce' ); ?></p>
						<?php self::box( 'show_neighborhood', __( 'Mahalle / Köy göster', 'turkish-address-fields-for-woocommerce' ) ); ?><br>
						<?php self::box( 'show_street', __( 'Cadde / Sokak göster (mahalle gösteriliyorsa)', 'turkish-address-fields-for-woocommerce' ) ); ?>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Zorunlu alanlar', 'turkish-address-fields-for-woocommerce' ); ?></th><td>
						<?php self::box( 'req_district', __( 'İlçe zorunlu', 'turkish-address-fields-for-woocommerce' ) ); ?><br>
						<?php self::box( 'req_neighborhood', __( 'Mahalle zorunlu', 'turkish-address-fields-for-woocommerce' ) ); ?><br>
						<?php self::box( 'req_street', __( 'Cadde / Sokak zorunlu', 'turkish-address-fields-for-woocommerce' ) ); ?>
						<p class="description"><?php esc_html_e( 'Her listenin sonunda "Listede yok / elle gireceğim" seçeneği vardır; zorunlu alan kimseyi kilitlemez.', 'turkish-address-fields-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Doğrulama ve düzenleme', 'turkish-address-fields-for-woocommerce' ); ?></th><td>
						<?php self::box( 'door_check', __( 'Bina / kapı numarası kontrolü', 'turkish-address-fields-for-woocommerce' ), __( 'Adres satırında rakam yoksa sipariş durdurulur; müşteri "Adresimde bina / kapı numarası yok" kutusuyla devam edebilir.', 'turkish-address-fields-for-woocommerce' ) ); ?>
						<?php self::box( 'normalize', __( 'Adres satırını düzenle', 'turkish-address-fields-for-woocommerce' ), __( 'Fazla boşlukları siler, tamamı büyük harf yazılmış metni düzeltir, "no:13" → "No: 13", "kat 2" → "Kat: 2", "d:5" → "Daire: 5".', 'turkish-address-fields-for-woocommerce' ) ); ?>
						<?php self::box( 'phone_check', __( 'Telefonu Türkiye numarası olarak doğrula ve 0XXXXXXXXXX biçimine getir', 'turkish-address-fields-for-woocommerce' ) ); ?>
					</td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Sipariş adresi', 'turkish-address-fields-for-woocommerce' ); ?></th><td>
						<?php self::box( 'write_address', __( 'Sipariş adres satırlarını seçimlerden oluştur', 'turkish-address-fields-for-woocommerce' ), __( 'Adres 1 = Cadde/Sokak + bina/kapı, Adres 2 = Mahalle, Şehir = İlçe. Kargo etiketleri ve dışa aktarımlar tam adresi görür. Kapalıysa parçalar yalnızca görüntülenen adrese eklenir.', 'turkish-address-fields-for-woocommerce' ) ); ?>
						<?php self::box( 'hide_postcode', __( 'Türkiye adreslerinde posta kodunu gizle (isteğe bağlı yap)', 'turkish-address-fields-for-woocommerce' ) ); ?><br>
						<?php self::box( 'lock_country', __( 'Ülkeyi Türkiye\'ye sabitle', 'turkish-address-fields-for-woocommerce' ) ); ?>
					</td></tr>
				</tbody></table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
