<?php
/**
 * Settings screen (one tab per automation), log screen and e-mail preview.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce > Büyüme Otomasyonları. Saved through options.php (capability mapped to manage_woocommerce).
 */
class Eru_GRA_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'eru-gra';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_eru_gra_preview', array( __CLASS__, 'preview' ) );
	}

	/** Menu entry. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Büyüme Otomasyonları', 'growth-automations-for-woocommerce' ), __( 'Büyüme Otomasyonları', 'growth-automations-for-woocommerce' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	/** @param string $hook Admin hook. */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::SLUG ) ) {
			wp_enqueue_style( 'eru-gra-admin', ERU_GRA_URL . 'assets/admin.css', array(), ERU_GRA_VERSION );
		}
	}

	/** @return array<string,string> Tab => label. */
	private static function tabs() {
		return array(
			'general' => __( 'Genel', 'growth-automations-for-woocommerce' ),
			'bacs'    => __( 'Havale/EFT hatırlatma', 'growth-automations-for-woocommerce' ),
			'cart'    => __( 'Terk edilmiş sepet', 'growth-automations-for-woocommerce' ),
			'winback' => __( 'Geri kazanım', 'growth-automations-for-woocommerce' ),
			'review'  => __( 'Yorum isteği', 'growth-automations-for-woocommerce' ),
			'alerts'  => __( 'Stok bildirimi', 'growth-automations-for-woocommerce' ),
			'log'     => __( 'Kayıt', 'growth-automations-for-woocommerce' ),
		);
	}

	/** @param string $tab Tab. @param string $key Key. @return string Field name. */
	private static function name( $tab, $key ) {
		return Eru_GRA_Settings::OPTION . '[' . $tab . '][' . $key . ']';
	}

	/** Text input. */
	private static function field( $tab, $key, $label, $hint = '', $type = 'text' ) {
		$v = Eru_GRA_Settings::get( $tab, $key );
		echo '<tr><th scope="row"><label for="eru-gra-' . esc_attr( $tab . '-' . $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="' . esc_attr( $type ) . '" id="eru-gra-' . esc_attr( $tab . '-' . $key ) . '" name="' . esc_attr( self::name( $tab, $key ) ) . '" value="' . esc_attr( (string) $v ) . '"' . '>';
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Textarea. */
	private static function area( $tab, $key, $label, $hint = '' ) {
		echo '<tr><th scope="row"><label for="eru-gra-' . esc_attr( $tab . '-' . $key ) . '">' . esc_html( $label ) . '</label></th><td><textarea rows="6" id="eru-gra-' . esc_attr( $tab . '-' . $key ) . '" name="' . esc_attr( self::name( $tab, $key ) ) . '">' . esc_textarea( (string) Eru_GRA_Settings::get( $tab, $key ) ) . '</textarea>';
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Checkbox. */
	private static function check( $tab, $key, $label, $hint = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( self::name( $tab, $key ) ) . '" value="1"' . checked( 'yes' === Eru_GRA_Settings::get( $tab, $key ), true, false ) . '> ' . esc_html__( 'Etkin', 'growth-automations-for-woocommerce' ) . '</label>';
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Select. */
	private static function select( $tab, $key, $label, array $opts ) {
		$v = Eru_GRA_Settings::get( $tab, $key );
		echo '<tr><th scope="row"><label for="eru-gra-' . esc_attr( $tab . '-' . $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="eru-gra-' . esc_attr( $tab . '-' . $key ) . '" name="' . esc_attr( self::name( $tab, $key ) ) . '">';
		foreach ( $opts as $val => $text ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $v, $val, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/** Placeholder reference. */
	private static function placeholders( array $extra = array() ) {
		echo '<p class="eru-gra-ph description">' . esc_html__( 'Kullanılabilir alanlar:', 'growth-automations-for-woocommerce' ) . ' ';
		foreach ( array_merge( Eru_GRA_Mail::placeholders(), $extra ) as $p ) {
			echo '<code>' . esc_html( $p ) . '</code>';
		}
		echo '</p>';
	}

	/** @param string $tab Automation. Fields shared by every e-mail automation. */
	private static function mail_fields( $tab ) {
		$days = in_array( $tab, array( 'winback', 'review' ), true );
		self::check( $tab, 'enabled', __( 'Otomasyon', 'growth-automations-for-woocommerce' ), __( 'Varsayılan olarak kapalıdır. Her 15 dakikada bir en fazla "Parti boyutu" kadar kayıt işlenir; bekleyen iş kalırsa hemen yeni bir tur kuyruğa alınır.', 'growth-automations-for-woocommerce' ) );
		self::field( $tab, 'delay', $days ? __( 'Bekleme (gün)', 'growth-automations-for-woocommerce' ) : __( 'Bekleme (saat)', 'growth-automations-for-woocommerce' ), '', 'number' );
		self::field( $tab, 'max_age_days', __( 'En fazla kaç gün öncesine bak', 'growth-automations-for-woocommerce' ), __( 'Özelliği açtığınız anda eski kayıtlara toplu e-posta gitmesini engeller.', 'growth-automations-for-woocommerce' ), 'number' );
		if ( 'cart' === $tab ) {
			self::field( $tab, 'cooldown', __( 'Aynı adrese iki e-posta arası (saat)', 'growth-automations-for-woocommerce' ), '', 'number' );
		}
		self::field( $tab, 'subject', __( 'Konu', 'growth-automations-for-woocommerce' ) );
		self::field( $tab, 'heading', __( 'Başlık', 'growth-automations-for-woocommerce' ) );
		self::area( $tab, 'body', __( 'Metin', 'growth-automations-for-woocommerce' ) );
		self::placeholders();
		self::select(
			$tab,
			'coupon_mode',
			__( 'Kupon', 'growth-automations-for-woocommerce' ),
			array(
				'none'     => __( 'Yok', 'growth-automations-for-woocommerce' ),
				'existing' => __( 'Var olan bir kuponu ver', 'growth-automations-for-woocommerce' ),
				'generate' => __( 'Her alıcıya özel, süreli, tek kullanımlık kupon üret', 'growth-automations-for-woocommerce' ),
			)
		);
		self::field( $tab, 'coupon_code', __( 'Var olan kupon kodu', 'growth-automations-for-woocommerce' ), __( 'Yalnızca "Var olan" seçiliyse. Kod WooCommerce\'de tanımlı olmalıdır; yoksa e-posta gönderilmez.', 'growth-automations-for-woocommerce' ) );
		self::field( $tab, 'coupon_prefix', __( 'Üretilen kod öneki', 'growth-automations-for-woocommerce' ), __( 'Kod: ÖNEK-8 karakter. Kupon e-posta gönderilmeden önce oluşturulur ve alıcının adresine bağlanır.', 'growth-automations-for-woocommerce' ) );
		self::select( $tab, 'coupon_type', __( 'İndirim türü', 'growth-automations-for-woocommerce' ), array( 'percent' => __( 'Yüzde', 'growth-automations-for-woocommerce' ), 'fixed_cart' => __( 'Sabit tutar', 'growth-automations-for-woocommerce' ) ) );
		self::field( $tab, 'coupon_amount', __( 'İndirim', 'growth-automations-for-woocommerce' ), '', 'text' );
		self::field( $tab, 'coupon_min', __( 'Asgari sepet tutarı', 'growth-automations-for-woocommerce' ), __( 'Boş = asgari yok.', 'growth-automations-for-woocommerce' ), 'text' );
		self::field( $tab, 'coupon_days', __( 'Kupon geçerliliği (gün)', 'growth-automations-for-woocommerce' ), '', 'number' );
		self::field( $tab, 'batch_size', __( 'Parti boyutu', 'growth-automations-for-woocommerce' ), '', 'number' );
	}

	/** Settings page. */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'growth-automations-for-woocommerce' ), 403 );
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'general';

		echo '<div class="wrap eru-gra-admin"><h1>' . esc_html__( 'Büyüme Otomasyonları', 'growth-automations-for-woocommerce' ) . '</h1>';
		echo '<p class="eru-gra-lead">' . esc_html__( 'Her otomasyon ayrı bir anahtardır ve varsayılan olarak kapalıdır. Pazarlama e-postaları (terk edilmiş sepet, geri kazanım) yalnızca açık onayı olan adreslere gider; her e-postada vazgeçme bağlantısı bulunur.', 'growth-automations-for-woocommerce' ) . '</p>';
		settings_errors( Eru_GRA_Settings::OPTION );
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'log' === $tab ) {
			self::log_tab();
			echo '</div>';
			return;
		}

		echo '<form method="post" action="options.php">';
		settings_fields( Eru_GRA_Settings::GROUP );
		echo '<input type="hidden" name="' . esc_attr( self::name( $tab, '_present' ) ) . '" value="1"><table class="form-table" role="presentation">';
		switch ( $tab ) {
			case 'general':
				self::area( 'general', 'consent_text', __( 'Ödeme sayfası onay metni', 'growth-automations-for-woocommerce' ), __( 'Onay kutusu işaretlenmeden sepet kaydedilmez. Kutu varsayılan olarak boştur.', 'growth-automations-for-woocommerce' ) );
				self::field( 'general', 'optin_text', __( 'Hesabım izin metni', 'growth-automations-for-woocommerce' ), __( 'Hesabım > Hesap ayrıntıları ekranında "pazarlama e-postaları" kutusunun yanında görünür.', 'growth-automations-for-woocommerce' ) );
				self::field( 'general', 'retention_days', __( 'Saklama süresi (gün)', 'growth-automations-for-woocommerce' ), __( 'Sepet kayıtları ve stok bildirimleri bu süreden sonra günlük işle, partiler halinde silinir.', 'growth-automations-for-woocommerce' ), 'number' );
				break;
			case 'alerts':
				self::check( 'alerts', 'enabled', __( 'Stoğa gelince haber ver', 'growth-automations-for-woocommerce' ), __( 'Stokta olmayan basit ürünlerde ve her varyasyonda form gösterir. Önce onay e-postası gider; yalnızca onaylanan istekler bildirilir.', 'growth-automations-for-woocommerce' ) );
				self::field( 'alerts', 'subject', __( 'Bildirim konusu', 'growth-automations-for-woocommerce' ) );
				self::field( 'alerts', 'heading', __( 'Bildirim başlığı', 'growth-automations-for-woocommerce' ) );
				self::area( 'alerts', 'body', __( 'Bildirim metni', 'growth-automations-for-woocommerce' ) );
				self::placeholders( array( '{product_name}', '{product_link}' ) );
				self::field( 'alerts', 'confirm_subject', __( 'Onay e-postası konusu', 'growth-automations-for-woocommerce' ) );
				self::area( 'alerts', 'confirm_body', __( 'Onay e-postası metni', 'growth-automations-for-woocommerce' ), __( '{confirm_link} zorunludur.', 'growth-automations-for-woocommerce' ) );
				self::field( 'alerts', 'confirm_hours', __( 'Onay bağlantısı geçerliliği (saat)', 'growth-automations-for-woocommerce' ), '', 'number' );
				self::field( 'alerts', 'batch_size', __( 'Parti boyutu', 'growth-automations-for-woocommerce' ), '', 'number' );
				self::field( 'alerts', 'hourly_cap', __( 'Saatlik toplam istek sınırı', 'growth-automations-for-woocommerce' ), __( 'Tüm ziyaretçiler için saatlik üst sınır (dağıtık kötüye kullanıma karşı).', 'growth-automations-for-woocommerce' ), 'number' );
				break;
			default:
				self::mail_fields( $tab );
		}
		echo '</table>';
		submit_button();
		echo '</form>';
		if ( in_array( $tab, Eru_GRA_Settings::RUNNABLE, true ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=eru_gra_preview&automation=' . $tab ), 'eru_gra_preview' );
			echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'E-posta önizlemesi (örnek verilerle)', 'growth-automations-for-woocommerce' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** Last 200 sends. */
	private static function log_tab() {
		$rows = Eru_GRA_DB::log_rows( 200 );
		echo '<p class="description">' . esc_html__( 'Son 200 işlem. E-posta adresleri maskelenir.', 'growth-automations-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped eru-gra-log"><thead><tr><th>' . esc_html__( 'Zaman (UTC)', 'growth-automations-for-woocommerce' ) . '</th><th>' . esc_html__( 'Otomasyon', 'growth-automations-for-woocommerce' ) . '</th><th>' . esc_html__( 'Alıcı', 'growth-automations-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sonuç', 'growth-automations-for-woocommerce' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Henüz kayıt yok.', 'growth-automations-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( $r['automation'] ) . '</td><td>' . esc_html( $r['email_masked'] ) . '</td><td>' . esc_html( $r['result'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Preview of one automation's e-mail with sample data (no coupon is created, nothing is sent). */
	public static function preview() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'growth-automations-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_gra_preview' );
		$key = isset( $_GET['automation'] ) ? sanitize_key( wp_unslash( $_GET['automation'] ) ) : '';
		if ( ! in_array( $key, Eru_GRA_Settings::RUNNABLE, true ) ) {
			wp_die( esc_html__( 'Geçersiz otomasyon.', 'growth-automations-for-woocommerce' ), 400 );
		}
		$cfg  = Eru_GRA_Settings::all()[ $key ];
		$vars = array(
			'customer_name'    => __( 'Ayşe', 'growth-automations-for-woocommerce' ),
			'order_number'     => '1001',
			'cart_link'        => home_url( '/?eru_gra_restore=ORNEK' ),
			'coupon_code'      => strtoupper( $cfg['coupon_prefix'] ) . '-ORNEK123',
			'coupon_expiry'    => wp_date( get_option( 'date_format' ), time() + (int) $cfg['coupon_days'] * DAY_IN_SECONDS ),
			'site_title'       => Eru_GRA_Mail::site_title(),
			'unsubscribe_link' => home_url( '/?eru_gra_unsub=ORNEK' ),
		);
		$marketing = in_array( $key, array( 'cart', 'winback' ), true );
		$extra     = '';
		if ( 'bacs' === $key ) {
			$extra = '<h2>' . esc_html__( 'Banka bilgileri', 'growth-automations-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Hesap bilgileri WooCommerce > Ayarlar > Ödemeler > Doğrudan banka havalesi ekranından alınır ve burada gerçek siparişte listelenir.', 'growth-automations-for-woocommerce' ) . '</p>';
		}
		$html = Eru_GRA_Mail::render(
			array(
				'heading'         => Eru_GRA_Mail::fill( $cfg['heading'], $vars ),
				'body'            => Eru_GRA_Mail::fill( $cfg['body'], $vars ),
				'extra_html'      => $extra,
				'marketing'       => $marketing,
				'unsubscribe_url' => $vars['unsubscribe_link'],
			)
		);
		$email  = new WC_Email();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $email->style_inline( $html ); // phpcs:ignore WordPress.Security.EscapeOutput -- WooCommerce e-mail markup built from escaped parts.
		exit;
	}
}
