<?php
/**
 * Settings screen under WooCommerce.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One settings page, saved through options.php (capability mapped to manage_woocommerce).
 */
class Eru_CB_Admin {

	const CAP = 'manage_woocommerce';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Menu entry. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Dönüşüm araçları', 'conversion-boosters-for-woocommerce' ), __( 'Dönüşüm Araçları', 'conversion-boosters-for-woocommerce' ), self::CAP, 'eru-cb', array( __CLASS__, 'page' ) );
	}

	/** @param string $hook Admin hook. */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, 'eru-cb' ) ) {
			wp_enqueue_style( 'eru-cb-admin', ERU_CB_URL . 'assets/admin.css', array(), ERU_CB_VERSION );
		}
	}

	/** @param string $key Field. @param string $label Label. @param string $type text|number. @param string $hint Hint. */
	private static function field( $key, $label, $type = 'text', $hint = '' ) {
		$v = Eru_CB_Settings::get( $key );
		$v = is_array( $v ) ? implode( ', ', $v ) : $v;
		echo '<tr><th scope="row"><label for="eru-cb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input class="regular-text" type="' . esc_attr( 'number' === $type ? 'text' : $type ) . '" id="eru-cb-' . esc_attr( $key ) . '" name="' . esc_attr( Eru_CB_Settings::OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( (string) $v ) . '"' . ( 'number' === $type ? ' inputmode="decimal"' : '' ) . '>';
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** @param string $key Field. @param string $label Label. @param string $hint Hint. */
	private static function check( $key, $label, $hint = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( Eru_CB_Settings::OPTION . '[' . $key . ']' ) . '" value="1"' . checked( Eru_CB_Settings::is_on( $key ), true, false ) . '> ' . esc_html__( 'Etkin', 'conversion-boosters-for-woocommerce' ) . '</label>';
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** @param string $key Field. @param string $label Label. @param array $opts value => label. */
	private static function select( $key, $label, array $opts ) {
		$v = Eru_CB_Settings::get( $key );
		echo '<tr><th scope="row"><label for="eru-cb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="eru-cb-' . esc_attr( $key ) . '" name="' . esc_attr( Eru_CB_Settings::OPTION . '[' . $key . ']' ) . '">';
		foreach ( $opts as $val => $text ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $v, $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/** Settings page. */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'conversion-boosters-for-woocommerce' ), 403 );
		}
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200 ) );
		$subs = wp_count_posts( Eru_CB_Newsletter::CPT );
		echo '<div class="wrap eru-cb-admin"><h1>' . esc_html__( 'Dönüşüm Araçları', 'conversion-boosters-for-woocommerce' ) . '</h1>';
		echo '<p class="eru-cb-lead">' . esc_html__( 'Her modül ayrı bir anahtardır ve varsayılan olarak kapalıdır. Geri sayım yalnızca gerçek, sunucuda süresi dolan bir kuponun kalan süresini gösterir; sahte sayaç veya sahte kıtlık yoktur.', 'conversion-boosters-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( Eru_CB_Settings::GROUP );

		echo '<h2>' . esc_html__( '1. Ücretsiz kargo çubuğu ve öneriler', 'conversion-boosters-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::check( 'ship_enabled', __( 'Modül', 'conversion-boosters-for-woocommerce' ), __( 'Sepet ve ödeme sayfasında ilerleme çubuğu. Mini sepet için [eru_free_shipping_bar] kısa kodu.', 'conversion-boosters-for-woocommerce' ) );
		self::select( 'ship_threshold_mode', __( 'Eşik kaynağı', 'conversion-boosters-for-woocommerce' ), array( 'zone' => __( 'WooCommerce kargo bölgesi (ücretsiz kargo min. tutarı)', 'conversion-boosters-for-woocommerce' ), 'manual' => __( 'Elle gir', 'conversion-boosters-for-woocommerce' ) ) );
		self::field( 'ship_threshold_manual', __( 'Elle eşik', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Yalnızca "Elle gir" seçiliyse kullanılır.', 'conversion-boosters-for-woocommerce' ) );
		self::check( 'ship_on_cart', __( 'Sepette göster', 'conversion-boosters-for-woocommerce' ) );
		self::check( 'ship_on_checkout', __( 'Ödemede göster', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'ship_text', __( 'Metin', 'conversion-boosters-for-woocommerce' ), 'text', __( '{kalan} kalan tutarla değişir.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'ship_text_done', __( 'Eşik aşıldığında', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'ship_gap_extra', __( 'Boşluk doldurucu: en fazla ek tutar', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Kalan tutar ile kalan tutar + bu değer arasında fiyatlı ürünler önerilir. 0 = kapalı.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'ship_gap_count', __( 'Öneri sayısı', 'conversion-boosters-for-woocommerce' ), 'number' );
		self::field( 'ship_manual_products', __( 'Elle öneri listesi (ürün ID)', 'conversion-boosters-for-woocommerce' ), 'text', __( 'Çapraz satış ve aynı kategoriden sonra kullanılır. Virgülle ayırın.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'ship_exclude_products', __( 'Hariç tutulan ürünler (ID)', 'conversion-boosters-for-woocommerce' ), 'text', __( 'Virgülle ayırın.', 'conversion-boosters-for-woocommerce' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Hariç tutulan kategoriler', 'conversion-boosters-for-woocommerce' ) . '</th><td class="eru-cb-cats">';
		$sel = array_map( 'intval', (array) Eru_CB_Settings::get( 'ship_exclude_cats' ) );
		if ( is_array( $cats ) ) {
			foreach ( $cats as $c ) {
				echo '<label><input type="checkbox" name="' . esc_attr( Eru_CB_Settings::OPTION ) . '[ship_exclude_cats][]" value="' . (int) $c->term_id . '"' . checked( in_array( (int) $c->term_id, $sel, true ), true, false ) . '> ' . esc_html( $c->name ) . '</label>';
			}
		}
		echo '</td></tr></table>';

		echo '<h2>' . esc_html__( '2. Sepet önerileri', 'conversion-boosters-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::check( 'recs_enabled', __( 'Modül', 'conversion-boosters-for-woocommerce' ), __( 'Sepette çapraz satış yoksa, sepettekilerle aynı kategorilerden ürün gösterir.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'recs_count', __( 'Ürün sayısı', 'conversion-boosters-for-woocommerce' ), 'number' );
		echo '</table>';

		echo '<h2>' . esc_html__( '3. Hoş geldin kuponlu bülten', 'conversion-boosters-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		/* translators: %d: subscriber count */
		$sub_hint = sprintf( __( '[eru_cb_newsletter] kısa kodu. Şu an %d abone var (WooCommerce menüsünde "Bülten aboneleri").', 'conversion-boosters-for-woocommerce' ), (int) ( $subs->publish ?? 0 ) );
		self::check( 'nl_enabled', __( 'Modül', 'conversion-boosters-for-woocommerce' ), $sub_hint );
		self::check( 'nl_footer', __( 'Sayfa altına otomatik ekle', 'conversion-boosters-for-woocommerce' ), __( 'Kapalıysa form yalnızca kısa kodla ya da eru_cb_footer_form kancasıyla yerleştirilir.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'nl_title', __( 'Form başlığı', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'nl_consent_text', __( 'Onay metni', 'conversion-boosters-for-woocommerce' ), 'text', __( 'Onay kutusu zorunludur; metnin özeti (hash) her abonelikle saklanır.', 'conversion-boosters-for-woocommerce' ) );
		self::select( 'nl_mode', __( 'Kupon', 'conversion-boosters-for-woocommerce' ), array( 'generated' => __( 'Onaylayan her aboneye özel kupon üret', 'conversion-boosters-for-woocommerce' ), 'fixed' => __( 'Var olan sabit bir kuponu ver', 'conversion-boosters-for-woocommerce' ) ) );
		self::field( 'nl_fixed_code', __( 'Sabit kupon kodu', 'conversion-boosters-for-woocommerce' ), 'text', __( 'Yalnızca sabit kupon seçiliyse. Kod WooCommerce\'de tanımlı olmalıdır.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'nl_prefix', __( 'Kod öneki', 'conversion-boosters-for-woocommerce' ), 'text', __( 'Üretilen kod: önek + 6 karakter.', 'conversion-boosters-for-woocommerce' ) );
		self::select( 'nl_type', __( 'İndirim türü', 'conversion-boosters-for-woocommerce' ), array( 'percent' => __( 'Yüzde', 'conversion-boosters-for-woocommerce' ), 'fixed_cart' => __( 'Sabit tutar', 'conversion-boosters-for-woocommerce' ) ) );
		self::field( 'nl_amount', __( 'İndirim', 'conversion-boosters-for-woocommerce' ), 'number' );
		self::field( 'nl_min_cart', __( 'Asgari sepet tutarı', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Boş = asgari yok.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'nl_days', __( 'Geçerlilik (gün)', 'conversion-boosters-for-woocommerce' ), 'number' );
		self::field( 'nl_confirm_hours', __( 'Onay bağlantısı geçerliliği (saat)', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Çift onay: kupon yalnızca bağlantı tıklandıktan sonra oluşturulur ve e-postayla gönderilir. Süresi dolan onaysız kayıtlar silinir.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'global_hourly', __( 'Saatlik toplam sınır', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Tüm ziyaretçiler için yeni abonelik ve süreli kupon sayısına saatlik üst sınır (dağıtık kötüye kullanıma karşı devre kesici).', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'retention_days', __( 'Abone saklama süresi (gün)', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Süresi dolan abonelikler günlük işte silinir.', 'conversion-boosters-for-woocommerce' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( '4. Süreli sepet kuponu', 'conversion-boosters-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::check( 'tc_enabled', __( 'Modül', 'conversion-boosters-for-woocommerce' ), __( '"Kuponu al" tıklamasında oturuma özel, tek kullanımlık ve bitiş tarihli gerçek bir kupon üretilir ve uygulanır.', 'conversion-boosters-for-woocommerce' ) );
		self::check( 'tc_on_checkout', __( 'Ödemede de göster', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'tc_min_cart', __( 'Asgari sepet tutarı', 'conversion-boosters-for-woocommerce' ), 'number' );
		self::field( 'tc_validity', __( 'Geçerlilik (dakika)', 'conversion-boosters-for-woocommerce' ), 'number' );
		self::field( 'tc_cooldown_hours', __( 'Bekleme süresi (saat)', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Kupon süresi dolduktan sonra aynı oturuma bu süre boyunca yeni kupon verilmez.', 'conversion-boosters-for-woocommerce' ) );
		self::field( 'tc_prefix', __( 'Kod öneki', 'conversion-boosters-for-woocommerce' ) );
		self::select( 'tc_type', __( 'İndirim türü', 'conversion-boosters-for-woocommerce' ), array( 'percent' => __( 'Yüzde', 'conversion-boosters-for-woocommerce' ), 'fixed_cart' => __( 'Sabit tutar', 'conversion-boosters-for-woocommerce' ) ) );
		self::field( 'tc_amount', __( 'İndirim', 'conversion-boosters-for-woocommerce' ), 'number' );
		self::field( 'tc_ip_hourly', __( 'IP başına saatlik kupon sınırı', 'conversion-boosters-for-woocommerce' ), 'number', __( 'Çerez silerek kupon üretmeyi sınırlar.', 'conversion-boosters-for-woocommerce' ) );
		echo '</table>';

		submit_button();
		echo '</form></div>';
	}
}
