<?php
/**
 * Admin: WooCommerce → Dopigo (Panel, Bağlantı, Ürün, Sipariş, Araçlar, Günlük), the double-decrement
 * notice with its preset, tool buttons and the privacy policy text.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Viewing any tab makes no outbound request; only the "Bağlantıyı test et" button calls Dopigo directly.
 */
class Eru_DPG_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'eru-dpg';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_eru_dpg_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy' ) );
	}

	/** Menu entry under WooCommerce. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Dopigo', 'dopigo-integration-for-woocommerce' ), __( 'Dopigo', 'dopigo-integration-for-woocommerce' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	/** @param string $hook Hook. */
	public static function assets( $hook ) {
		if ( false !== strpos( (string) $hook, self::SLUG ) ) {
			wp_enqueue_style( 'eru-dpg-admin', ERU_DPG_URL . 'assets/admin.css', array(), ERU_DPG_VERSION );
		}
	}

	/** Privacy policy suggestion. */
	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Dopigo Integration for WooCommerce',
				'<p>' . esc_html__( 'Sipariş gönderimi açıksa, siparişteki ad, e-posta, telefon, fatura/teslimat adresi, varsa vergi bilgisi ve ürün satırları sipariş yönetimi için Dopigo\'ya aktarılır. Eklentinin günlük tablosu e-posta ve telefon numaralarını maskeler ve ayarlanan süre sonunda silinir.', 'dopigo-integration-for-woocommerce' ) . '</p>'
			);
		}
	}

	/** @return array<string,string> Tabs. */
	private static function tabs() {
		return array(
			'panel'      => __( 'Panel', 'dopigo-integration-for-woocommerce' ),
			'connection' => __( 'Bağlantı', 'dopigo-integration-for-woocommerce' ),
			'product'    => __( 'Ürün', 'dopigo-integration-for-woocommerce' ),
			'order'      => __( 'Sipariş', 'dopigo-integration-for-woocommerce' ),
			'tools'      => __( 'Araçlar', 'dopigo-integration-for-woocommerce' ),
			'log'        => __( 'Günlük', 'dopigo-integration-for-woocommerce' ),
		);
	}

	/** @param string $tab Tab. @param array $args Extra. @return string URL. */
	public static function url( $tab = 'panel', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/** @param int $ts Unix time. @return string Local date/time or a dash. */
	private static function when( $ts ) {
		return $ts ? wp_date( 'd.m.Y H:i', (int) $ts ) : '—';
	}

	/** @param string $gmt MySQL GMT date. @return string */
	private static function when_gmt( $gmt ) {
		return $gmt ? self::when( strtotime( $gmt . ' UTC' ) ) : '—';
	}

	// -------------------------------------------------------------------------------------------
	// Notices and actions.
	// -------------------------------------------------------------------------------------------

	/** @return array<string,array> Fixed result messages (no request text is echoed). */
	private static function messages() {
		return array(
			'queued'    => array( 'success', __( 'İş kuyruğa alındı; Action Scheduler birkaç saniye içinde çalıştırır.', 'dopigo-integration-for-woocommerce' ) ),
			'running'   => array( 'info', __( 'Bu iş zaten kuyrukta.', 'dopigo-integration-for-woocommerce' ) ),
			'preset'    => array( 'success', __( '"Yalnızca sipariş gönder" uygulandı: Woo → Dopigo stok gönderimi kapatıldı, sipariş gönderimi açık kaldı.', 'dopigo-integration-for-woocommerce' ) ),
			'reset'     => array( 'success', __( 'İçe aktarma imleci başa alındı.', 'dopigo-integration-for-woocommerce' ) ),
			'dupes'     => array( 'success', __( 'Yinelenen kayıt taraması tamamlandı.', 'dopigo-integration-for-woocommerce' ) ),
			'tested'    => array( 'info', __( 'Bağlantı testi tamamlandı; sonuç aşağıda.', 'dopigo-integration-for-woocommerce' ) ),
			'disabled'  => array( 'warning', __( 'Önce Bağlantı sekmesinden entegrasyonu açın ve kimlik bilgilerini kaydedin.', 'dopigo-integration-for-woocommerce' ) ),
			'resend'    => array( 'success', __( 'Sipariş Dopigo gönderim kuyruğuna alındı.', 'dopigo-integration-for-woocommerce' ) ),
		);
	}

	/** Admin notices: action result on our page, double-decrement warning everywhere for shop managers. */
	public static function notices() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$code = isset( $_GET['eru_dpg_msg'] ) ? sanitize_key( wp_unslash( $_GET['eru_dpg_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = self::messages();
		if ( isset( $msgs[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $msgs[ $code ][0] ), esc_html( $msgs[ $code ][1] ) );
		}
		if ( Eru_DPG_Settings::double_decrement_risk() ) {
			echo '<div class="notice notice-warning eru-dpg-notice"><p><strong>' . esc_html__( 'Dopigo: çift stok düşümü riski.', 'dopigo-integration-for-woocommerce' ) . '</strong> ' . esc_html__( 'Hem Woo → Dopigo stok senkronu hem sipariş gönderimi açık. Dopigo, gönderilen siparişin stoğunu kendisi düşer; WooCommerce\'in düşürdüğü stok da ayrıca gönderilince aynı satış iki kez düşebilir.', 'dopigo-integration-for-woocommerce' ) . '</p><p>';
			self::preset_button();
			echo ' <a href="' . esc_url( self::url( 'product' ) ) . '">' . esc_html__( 'Ürün ayarları', 'dopigo-integration-for-woocommerce' ) . '</a></p></div>';
		}
	}

	/** The one-click preset form. */
	private static function preset_button() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-dpg-inline">';
		wp_nonce_field( 'eru_dpg_action' );
		echo '<input type="hidden" name="action" value="eru_dpg_action"><input type="hidden" name="do" value="preset">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Yalnızca sipariş gönder', 'dopigo-integration-for-woocommerce' ) . '</button></form>';
	}

	/** @param string $do Action. @param string $label Label. @param string $class Button class. @param string $tab Return tab. */
	private static function action_button( $do, $label, $class = 'button', $tab = 'tools' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-dpg-inline">';
		wp_nonce_field( 'eru_dpg_action' );
		echo '<input type="hidden" name="action" value="eru_dpg_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** admin-post handler for every button. */
	public static function handle_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'dopigo-integration-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_dpg_action' );
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$tab = sanitize_key( wp_unslash( $_POST['tab'] ?? 'tools' ) );
		$tab = array_key_exists( $tab, self::tabs() ) ? $tab : 'tools';
		$msg = '';
		if ( 'preset' === $do ) {
			Eru_DPG_Settings::apply_order_only_preset();
			$msg = 'preset';
			$tab = 'product';
		} elseif ( 'test' === $do ) {
			Eru_DPG_Tools::test_connection();
			$msg = 'tested';
		} elseif ( 'reset_import' === $do ) {
			Eru_DPG_Importer::reset_state();
			$msg = 'reset';
		} elseif ( 'duplicates' === $do ) {
			set_transient( 'eru_dpg_dupes', Eru_DPG_Tools::duplicates() + array( 'time' => time() ), HOUR_IN_SECONDS );
			$msg = 'dupes';
		} elseif ( in_array( $do, Eru_DPG_Tools::JOBS, true ) ) {
			if ( ! Eru_DPG_Settings::is_enabled() ) {
				$msg = 'disabled';
			} else {
				$msg = Eru_DPG_Tools::is_queued( $do ) ? 'running' : ( Eru_DPG_Tools::queue( $do ) ? 'queued' : 'running' );
			}
		}
		wp_safe_redirect( self::url( $tab, $msg ? array( 'eru_dpg_msg' => $msg ) : array() ) );
		exit;
	}

	// -------------------------------------------------------------------------------------------
	// Page.
	// -------------------------------------------------------------------------------------------

	/** Settings page. */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'dopigo-integration-for-woocommerce' ), 403 );
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'panel'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = self::tabs();
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'panel';
		$risk = Eru_DPG_Settings::double_decrement_risk();

		echo '<div class="wrap eru-dpg"><h1>' . esc_html__( 'Dopigo Entegrasyonu', 'dopigo-integration-for-woocommerce' ) . ' <span class="eru-dpg-beta">' . esc_html__( 'beta', 'dopigo-integration-for-woocommerce' ) . '</span></h1>';
		settings_errors( Eru_DPG_Settings::OPTION );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a href="' . esc_url( self::url( $k ) ) . '" class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label );
			if ( $risk && in_array( $k, array( 'product', 'order' ), true ) ) {
				echo ' <span class="eru-dpg-badge" title="' . esc_attr__( 'Çift stok düşümü riski', 'dopigo-integration-for-woocommerce' ) . '">!</span>';
			}
			echo '</a>';
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'connection':
				self::tab_connection();
				break;
			case 'product':
				self::tab_product();
				break;
			case 'order':
				self::tab_order();
				break;
			case 'tools':
				self::tab_tools();
				break;
			case 'log':
				self::tab_log();
				break;
			default:
				self::tab_panel();
		}
		echo '</div>';
	}

	/** @param string $tab Tab key for the sanitiser. */
	private static function form_open( $tab ) {
		echo '<form method="post" action="options.php" class="eru-dpg-form">';
		settings_fields( Eru_DPG_Settings::GROUP );
		echo '<input type="hidden" name="' . esc_attr( Eru_DPG_Settings::OPTION ) . '[_tab]" value="' . esc_attr( $tab ) . '"><table class="form-table" role="presentation">';
	}

	/** Closes the form. */
	private static function form_close() {
		echo '</table>';
		submit_button( __( 'Kaydet', 'dopigo-integration-for-woocommerce' ) );
		echo '</form>';
	}

	/** @param string $key Key. @return string Field name. */
	private static function name( $key ) {
		return Eru_DPG_Settings::OPTION . '[' . $key . ']';
	}

	/** Row with a heading cell. */
	private static function row( $label, $html, $hint = '', $for = '' ) {
		echo '<tr><th scope="row">' . ( $for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ) ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput -- $html is built from escaped parts.
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** @return string Checkbox HTML. */
	private static function checkbox( $key, $label ) {
		return '<label><input type="checkbox" name="' . esc_attr( self::name( $key ) ) . '" value="1"' . checked( Eru_DPG_Settings::on( $key ), true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/** @return string Text/number input HTML. */
	private static function input( $key, $type = 'text', $class = 'regular-text', $extra = '' ) {
		return '<input type="' . esc_attr( $type ) . '" id="eru-dpg-' . esc_attr( $key ) . '" class="' . esc_attr( $class ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( (string) Eru_DPG_Settings::get( $key ) ) . '"' . $extra . '>';
	}

	/** @return string Select HTML. */
	private static function select( $key, array $opts, $name = '', $value = null ) {
		$value = null === $value ? (string) Eru_DPG_Settings::get( $key ) : (string) $value;
		$html  = '<select id="eru-dpg-' . esc_attr( sanitize_key( $key ) ) . '" name="' . esc_attr( $name ? $name : self::name( $key ) ) . '">';
		foreach ( $opts as $v => $label ) {
			$html .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( $value, (string) $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Write-only secret field. */
	private static function secret( $key, $label, $hint ) {
		$saved = '' !== (string) Eru_DPG_Settings::get( $key );
		$html  = '<input type="password" id="eru-dpg-' . esc_attr( $key ) . '" class="regular-text" name="' . esc_attr( self::name( $key ) ) . '" value="" autocomplete="new-password">';
		if ( $saved ) {
			$html .= ' <span class="eru-dpg-saved">' . esc_html__( 'Kayıtlı', 'dopigo-integration-for-woocommerce' ) . '</span><p class="description">' . esc_html__( 'Değiştirmek için yeni değer girin; boş bırakırsanız kayıtlı değer korunur.', 'dopigo-integration-for-woocommerce' ) . '</p>';
		}
		self::row( $label, $html, $hint, 'eru-dpg-' . $key );
	}

	/** The double-decrement explanation box with the preset. */
	private static function risk_box() {
		if ( ! Eru_DPG_Settings::double_decrement_risk() ) {
			return;
		}
		echo '<div class="eru-dpg-warn"><p><strong>' . esc_html__( 'Çift stok düşümü riski', 'dopigo-integration-for-woocommerce' ) . '</strong></p><p>' . esc_html__( 'Woo → Dopigo stok senkronu ve sipariş gönderimi birlikte açık. Sipariş Dopigo\'ya gittiğinde Dopigo stoğu düşer; WooCommerce de kendi stoğunu düşürüp Dopigo\'ya gönderir. Sonuç: tek satış, iki düşüm. Sipariş gönderiyorsanız stok yönünü "Dopigo → Woo" (veya Kapalı) yapın.', 'dopigo-integration-for-woocommerce' ) . '</p><p>';
		self::preset_button();
		echo '</p></div>';
	}

	// -------------------------------------------------------------------------------------------
	// Tabs.
	// -------------------------------------------------------------------------------------------

	/** Dashboard. */
	private static function tab_panel() {
		$on    = Eru_DPG_Settings::is_enabled();
		$test  = get_option( 'eru_dpg_last_test', array() );
		$dirs  = Eru_DPG_Settings::directions();
		$pull  = Eru_DPG_Products::pull_state();
		$imp   = Eru_DPG_Importer::state();
		$stats = Eru_DPG_Logger::stats( 24 );
		$last  = Eru_DPG_Logger::last_success();
		$audit = get_option( Eru_DPG_Tools::AUDIT, array() );
		$lv    = Eru_DPG_Logger::levels();

		echo '<div class="eru-dpg-cards">';

		echo '<div class="eru-dpg-card"><h2>' . esc_html__( 'Durum', 'dopigo-integration-for-woocommerce' ) . '</h2><ul>';
		echo '<li><span>' . esc_html__( 'Entegrasyon', 'dopigo-integration-for-woocommerce' ) . '</span><b class="' . ( $on ? 'ok' : 'off' ) . '">' . esc_html( $on ? __( 'Açık', 'dopigo-integration-for-woocommerce' ) : __( 'Kapalı', 'dopigo-integration-for-woocommerce' ) ) . '</b></li>';
		if ( Eru_DPG_Settings::dry_run() ) {
			echo '<li><span>' . esc_html__( 'Mod', 'dopigo-integration-for-woocommerce' ) . '</span><b class="warn">' . esc_html__( 'Kuru çalışma', 'dopigo-integration-for-woocommerce' ) . '</b></li>';
		}
		echo '<li><span>' . esc_html__( 'Son bağlantı testi', 'dopigo-integration-for-woocommerce' ) . '</span><b class="' . ( ! empty( $test['ok'] ) ? 'ok' : ( $test ? 'bad' : 'off' ) ) . '">' . esc_html( $test ? self::when( $test['time'] ?? 0 ) : '—' ) . '</b></li>';
		echo '<li><span>' . esc_html__( 'Stok', 'dopigo-integration-for-woocommerce' ) . '</span><b>' . esc_html( $dirs[ Eru_DPG_Settings::get( 'stock_direction' ) ] ?? '' ) . '</b></li>';
		echo '<li><span>' . esc_html__( 'Fiyat', 'dopigo-integration-for-woocommerce' ) . '</span><b>' . esc_html( $dirs[ Eru_DPG_Settings::get( 'price_direction' ) ] ?? '' ) . '</b></li>';
		echo '<li><span>' . esc_html__( 'Sipariş gönderimi', 'dopigo-integration-for-woocommerce' ) . '</span><b>' . esc_html( Eru_DPG_Settings::on( 'order_push' ) ? __( 'Açık', 'dopigo-integration-for-woocommerce' ) : __( 'Kapalı', 'dopigo-integration-for-woocommerce' ) ) . '</b></li>';
		echo '<li><span>' . esc_html__( 'Sipariş durumu', 'dopigo-integration-for-woocommerce' ) . '</span><b>' . esc_html( $dirs[ Eru_DPG_Settings::get( 'status_direction' ) ] ?? '' ) . '</b></li>';
		echo '</ul></div>';

		echo '<div class="eru-dpg-card"><h2>' . esc_html__( 'Son senkronlar', 'dopigo-integration-for-woocommerce' ) . '</h2><ul>';
		$rows = array(
			__( 'Dopigo → Woo stok/fiyat', 'dopigo-integration-for-woocommerce' ) => self::when_gmt( $last['product|dp_to_wc'] ?? '' ),
			__( 'Woo → Dopigo stok/fiyat', 'dopigo-integration-for-woocommerce' ) => self::when_gmt( $last['product|wc_to_dp'] ?? '' ),
			__( 'Sipariş gönderimi', 'dopigo-integration-for-woocommerce' ) => self::when_gmt( $last['order|wc_to_dp'] ?? '' ),
			__( 'Sipariş durumu', 'dopigo-integration-for-woocommerce' ) => self::when_gmt( max( $last['status|dp_to_wc'] ?? '', $last['status|wc_to_dp'] ?? '' ) ),
			__( 'İçe aktarma (son tur)', 'dopigo-integration-for-woocommerce' ) => self::when( $imp['last_pass_done'] ),
			__( 'Çekme imleci', 'dopigo-integration-for-woocommerce' ) => (string) (int) $pull['offset'],
		);
		foreach ( $rows as $k => $v ) {
			echo '<li><span>' . esc_html( $k ) . '</span><b>' . esc_html( $v ) . '</b></li>';
		}
		echo '</ul></div>';

		echo '<div class="eru-dpg-card"><h2>' . esc_html__( 'Son 24 saat', 'dopigo-integration-for-woocommerce' ) . '</h2><ul>';
		foreach ( $stats as $k => $n ) {
			echo '<li><span>' . esc_html( $lv[ $k ] ?? $k ) . '</span><b class="lvl-' . esc_attr( $k ) . '">' . esc_html( number_format_i18n( $n ) ) . '</b></li>';
		}
		echo '</ul></div>';

		echo '<div class="eru-dpg-card"><h2>' . esc_html__( 'Kuyruk ve katalog', 'dopigo-integration-for-woocommerce' ) . '</h2><ul>';
		$q = array(
			__( 'Bekleyen ürün gönderimi', 'dopigo-integration-for-woocommerce' ) => Eru_DPG_Cron::pending( 'eru_dpg_push_product' ),
			__( 'Bekleyen sipariş gönderimi', 'dopigo-integration-for-woocommerce' ) => Eru_DPG_Cron::pending( 'eru_dpg_push_order' ),
			__( 'Bekleyen durum gönderimi', 'dopigo-integration-for-woocommerce' ) => Eru_DPG_Cron::pending( 'eru_dpg_push_status' ),
			__( 'İnceleme gereken sipariş', 'dopigo-integration-for-woocommerce' ) => Eru_DPG_Orders::needs_review_count(),
			__( 'Görsel bekleyen ürün', 'dopigo-integration-for-woocommerce' ) => Eru_DPG_Importer::pending_images(),
			__( 'İçe aktarılan (toplam)', 'dopigo-integration-for-woocommerce' ) => (int) ( $imp['totals']['created'] ?? 0 ),
		);
		foreach ( $q as $k => $n ) {
			echo '<li><span>' . esc_html( $k ) . '</span><b>' . esc_html( number_format_i18n( $n ) ) . '</b></li>';
		}
		if ( $audit ) {
			echo '<li><span>' . esc_html__( 'SKU eşleşme oranı', 'dopigo-integration-for-woocommerce' ) . '</span><b>%' . esc_html( (string) $audit['coverage'] ) . '</b></li>';
		}
		echo '</ul></div>';
		echo '</div>';

		echo '<h2>' . esc_html__( 'Son hatalar', 'dopigo-integration-for-woocommerce' ) . '</h2>';
		self::log_table( Eru_DPG_Logger::rows( array( 'level' => 'failed' ), 5 ), true );
		echo '<p><a href="' . esc_url( self::url( 'log' ) ) . '">' . esc_html__( 'Tüm günlük', 'dopigo-integration-for-woocommerce' ) . ' →</a></p>';
	}

	/** Connection tab. */
	private static function tab_connection() {
		$test = get_option( 'eru_dpg_last_test', array() );
		self::form_open( 'connection' );
		self::row( __( 'Entegrasyon', 'dopigo-integration-for-woocommerce' ), self::checkbox( 'enabled', __( 'Açık', 'dopigo-integration-for-woocommerce' ) ), __( 'Ana anahtar. Kapalıyken hiçbir istek gönderilmez ve zamanlanmış iş kurulmaz.', 'dopigo-integration-for-woocommerce' ) );
		self::row(
			__( 'Kimlik doğrulama', 'dopigo-integration-for-woocommerce' ),
			self::select(
				'auth_mode',
				array(
					'password' => __( 'Kullanıcı adı + şifre (token otomatik alınır)', 'dopigo-integration-for-woocommerce' ),
					'token'    => __( 'Doğrudan API anahtarı (token)', 'dopigo-integration-for-woocommerce' ),
				)
			),
			__( 'Kullanıcı adı/şifre modunda token 12 saat önbellekte tutulur ve 401/403 yanıtında bir kez yenilenir.', 'dopigo-integration-for-woocommerce' ),
			'eru-dpg-auth_mode'
		);
		self::row( __( 'Kullanıcı adı', 'dopigo-integration-for-woocommerce' ), self::input( 'username', 'text', 'regular-text', ' autocomplete="off"' ), '', 'eru-dpg-username' );
		self::secret( 'password', __( 'Şifre', 'dopigo-integration-for-woocommerce' ), __( 'Yalnızca yazılabilir; ekrana geri yazılmaz, günlüğe düşmez.', 'dopigo-integration-for-woocommerce' ) );
		self::secret( 'token', __( 'API anahtarı', 'dopigo-integration-for-woocommerce' ), __( 'Yalnızca "Doğrudan API anahtarı" modunda kullanılır.', 'dopigo-integration-for-woocommerce' ) );
		self::row( __( 'Kayıtlı kimlik bilgileri', 'dopigo-integration-for-woocommerce' ), '<label><input type="checkbox" name="' . esc_attr( self::name( 'clear_credentials' ) ) . '" value="1"> ' . esc_html__( 'Şifreyi ve API anahtarını sil', 'dopigo-integration-for-woocommerce' ) . '</label>' );
		self::row( __( 'Kuru çalışma', 'dopigo-integration-for-woocommerce' ), self::checkbox( 'dry_run', __( 'Açık', 'dopigo-integration-for-woocommerce' ) ), __( 'Dopigo\'dan yalnızca okunur: sipariş, stok ve fiyat gönderilmez; Dopigo verisi WooCommerce\'e yazılmaz. Yapılacak işler günlüğe yazılır.', 'dopigo-integration-for-woocommerce' ) );
		self::form_close();

		echo '<h2>' . esc_html__( 'Bağlantı testi', 'dopigo-integration-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Kayıtlı bilgilerle token alır ve tek ürünlük bir liste isteği yapar. Önce kaydedin.', 'dopigo-integration-for-woocommerce' ) . '</p>';
		if ( $test ) {
			echo '<p class="eru-dpg-test ' . ( ! empty( $test['ok'] ) ? 'ok' : 'bad' ) . '">' . esc_html( self::when( $test['time'] ?? 0 ) . ' — ' . ( $test['message'] ?? '' ) ) . '</p>';
		}
		self::action_button( 'test', __( 'Bağlantıyı test et', 'dopigo-integration-for-woocommerce' ), 'button button-secondary', 'connection' );
	}

	/** Product tab. */
	private static function tab_product() {
		self::risk_box();
		$dirs = Eru_DPG_Settings::directions();
		self::form_open( 'product' );
		echo '<tr><th colspan="2"><h2>' . esc_html__( 'Stok ve fiyat', 'dopigo-integration-for-woocommerce' ) . '</h2></th></tr>';
		self::row( __( 'Stok yönü', 'dopigo-integration-for-woocommerce' ), self::select( 'stock_direction', $dirs ), __( 'Eşleşme: WooCommerce SKU = Dopigo foreign_sku (içe aktarılan ürünlerde Dopigo varyant kimliği).', 'dopigo-integration-for-woocommerce' ), 'eru-dpg-stock_direction' );
		self::row( __( 'Fiyat yönü', 'dopigo-integration-for-woocommerce' ), self::select( 'price_direction', $dirs ), '', 'eru-dpg-price_direction' );
		self::row(
			__( 'WooCommerce fiyat alanı', 'dopigo-integration-for-woocommerce' ),
			self::select(
				'price_field',
				array(
					'regular' => __( 'Normal fiyat', 'dopigo-integration-for-woocommerce' ),
					'sale'    => __( 'İndirimli fiyat', 'dopigo-integration-for-woocommerce' ),
				)
			),
			__( 'Dopigo → Woo yönünde Dopigo fiyatının yazılacağı alan.', 'dopigo-integration-for-woocommerce' ),
			'eru-dpg-price_field'
		);
		self::row(
			__( 'Woo fiyatları', 'dopigo-integration-for-woocommerce' ),
			self::select(
				'vat_mode',
				array(
					'gross' => __( 'KDV dahil (brüt)', 'dopigo-integration-for-woocommerce' ),
					'net'   => __( 'KDV hariç (net)', 'dopigo-integration-for-woocommerce' ),
				)
			),
			__( 'Dopigo fiyatları KDV dahildir. Net seçilirse gönderirken KDV eklenir, alırken düşülür.', 'dopigo-integration-for-woocommerce' ),
			'eru-dpg-vat_mode'
		);
		self::row( __( 'Varsayılan KDV (%)', 'dopigo-integration-for-woocommerce' ), self::input( 'default_vat', 'number', 'small-text', ' min="0" max="100"' ), __( 'WooCommerce vergi oranı bulunamazsa kullanılır.', 'dopigo-integration-for-woocommerce' ), 'eru-dpg-default_vat' );
		self::row( __( 'Woo → Dopigo kuyruğu', 'dopigo-integration-for-woocommerce' ), self::checkbox( 'queue_updates', __( 'Güncellemeleri kuyruğa al (önerilir)', 'dopigo-integration-for-woocommerce' ) ), __( 'Açıkken ürün kaydı sırasında istek yapılmaz; ürün başına tek bir Action Scheduler işi kurulur.', 'dopigo-integration-for-woocommerce' ) );

		echo '<tr><th colspan="2"><h2>' . esc_html__( 'Ürün içe aktarma (Dopigo → Woo)', 'dopigo-integration-for-woocommerce' ) . '</h2></th></tr>';
		$ints = array_map( fn( $i ) => $i[1], Eru_DPG_Cron::intervals() );
		self::row( __( 'Zamanlanmış içe aktarma', 'dopigo-integration-for-woocommerce' ), self::checkbox( 'import_enabled', __( 'Açık', 'dopigo-integration-for-woocommerce' ) ) . ' &nbsp; ' . self::select( 'import_interval', $ints ), __( 'Kapalıyken yalnızca Araçlar sekmesinden elle başlatılır.', 'dopigo-integration-for-woocommerce' ) );
		self::row(
			__( 'Yeni ürün durumu', 'dopigo-integration-for-woocommerce' ),
			self::select(
				'import_status',
				array(
					'draft'   => __( 'Taslak', 'dopigo-integration-for-woocommerce' ),
					'pending' => __( 'İnceleme bekliyor', 'dopigo-integration-for-woocommerce' ),
					'publish' => __( 'Yayında', 'dopigo-integration-for-woocommerce' ),
				)
			),
			__( 'Yalnızca yeni oluşturulan ürünlere uygulanır.', 'dopigo-integration-for-woocommerce' ),
			'eru-dpg-import_status'
		);
		self::row(
			__( 'Kategori', 'dopigo-integration-for-woocommerce' ),
			self::select(
				'category_mode',
				array(
					'append'  => __( 'Ekle (mevcut kategoriler korunur)', 'dopigo-integration-for-woocommerce' ),
					'replace' => __( 'Değiştir', 'dopigo-integration-for-woocommerce' ),
				)
			),
			'',
			'eru-dpg-category_mode'
		);
		$parent = wp_dropdown_categories(
			array(
				'taxonomy'         => 'product_cat',
				'hide_empty'       => false,
				'hierarchical'     => true,
				'name'             => self::name( 'category_parent' ),
				'id'               => 'eru-dpg-category_parent',
				'selected'         => (int) Eru_DPG_Settings::get( 'category_parent' ),
				'show_option_none' => __( '— Kök —', 'dopigo-integration-for-woocommerce' ),
				'option_none_value' => '0',
				'echo'             => false,
			)
		);
		self::row( __( 'Üst kategori', 'dopigo-integration-for-woocommerce' ), $parent, __( 'Dopigo kategorileri bu kategorinin altında aranır; yoksa oluşturulur.', 'dopigo-integration-for-woocommerce' ), 'eru-dpg-category_parent' );
		self::row( __( 'Bütçe (adım başına)', 'dopigo-integration-for-woocommerce' ), self::input( 'import_time_budget', 'number', 'small-text', ' min="5" max="120"' ) . ' ' . esc_html__( 'sn', 'dopigo-integration-for-woocommerce' ) . ' &nbsp; ' . self::input( 'import_page_size', 'number', 'small-text', ' min="1" max="100"' ) . ' ' . esc_html__( 'ürün/sayfa', 'dopigo-integration-for-woocommerce' ) . ' &nbsp; ' . self::input( 'import_image_budget', 'number', 'small-text', ' min="0" max="200"' ) . ' ' . esc_html__( 'görsel', 'dopigo-integration-for-woocommerce' ), __( 'Ürünler görsellerden önce kaydedilir; bütçe biten görseller kuyruğa alınır (önce her ürüne bir kapak, sonra galeriler).', 'dopigo-integration-for-woocommerce' ) );
		self::form_close();
	}

	/** Order tab. */
	private static function tab_order() {
		self::risk_box();
		$dirs = Eru_DPG_Settings::directions();
		$dp   = Eru_DPG_Settings::dopigo_statuses();
		$map  = Eru_DPG_Settings::status_map();
		$wc   = array();
		foreach ( wc_get_order_statuses() as $k => $label ) {
			$wc[ preg_replace( '/^wc-/', '', $k ) ] = $label;
		}

		self::form_open( 'order' );
		self::row( __( 'Sipariş gönderimi', 'dopigo-integration-for-woocommerce' ), self::checkbox( 'order_push', __( 'Siparişleri Dopigo\'ya gönder', 'dopigo-integration-for-woocommerce' ) ), __( 'Gönderim kuyruktan yapılır; aynı sipariş için iki olay tek Dopigo siparişi oluşturur.', 'dopigo-integration-for-woocommerce' ) );
		self::row(
			__( 'Tetik', 'dopigo-integration-for-woocommerce' ),
			self::select(
				'order_trigger',
				array(
					'paid'    => __( 'Ödeme alınınca (İşleniyor / Tamamlandı)', 'dopigo-integration-for-woocommerce' ),
					'created' => __( 'Sipariş oluşunca', 'dopigo-integration-for-woocommerce' ),
				)
			),
			'',
			'eru-dpg-order_trigger'
		);
		self::row( __( 'Dopigo\'daki ilk durum', 'dopigo-integration-for-woocommerce' ), self::select( 'push_status', array( 'mapped' => __( 'Eşleme tablosuna göre', 'dopigo-integration-for-woocommerce' ) ) + $dp ), '', 'eru-dpg-push_status' );
		self::row( __( 'Sipariş durumu senkronu', 'dopigo-integration-for-woocommerce' ), self::select( 'status_direction', $dirs ), __( 'Dopigo → Woo yönü 5 dakikada bir çalışır ve yalnızca bu sitenin Dopigo\'ya gönderdiği siparişleri değiştirir.', 'dopigo-integration-for-woocommerce' ), 'eru-dpg-status_direction' );
		self::row( __( 'Satış kanalı adı', 'dopigo-integration-for-woocommerce' ), self::input( 'sales_channel', 'text', 'regular-text', ' placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"' ), __( 'Boş bırakılırsa site başlığı kullanılır.', 'dopigo-integration-for-woocommerce' ), 'eru-dpg-sales_channel' );
		echo '</table><h2>' . esc_html__( 'Durum eşleme', 'dopigo-integration-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( '"Değiştirme" seçilen ya da eşlenmeyen durumlar hiçbir şeyi değiştirmez.', 'dopigo-integration-for-woocommerce' ) . '</p><div class="eru-dpg-maps"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'WooCommerce', 'dopigo-integration-for-woocommerce' ) . '</th><th>→ ' . esc_html__( 'Dopigo', 'dopigo-integration-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $wc as $k => $label ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . self::select( 'wc_' . $k, array( '' => __( '— Değiştirme —', 'dopigo-integration-for-woocommerce' ) ) + $dp, self::name( 'status_map' ) . '[wc_to_dp][' . $k . ']', $map['wc_to_dp'][ $k ] ?? '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Dopigo', 'dopigo-integration-for-woocommerce' ) . '</th><th>→ ' . esc_html__( 'WooCommerce', 'dopigo-integration-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $dp as $k => $label ) {
			echo '<tr><td>' . esc_html( $label ) . ' <code>' . esc_html( $k ) . '</code></td><td>' . self::select( 'dp_' . $k, array( '' => __( '— Değiştirme —', 'dopigo-integration-for-woocommerce' ) ) + $wc, self::name( 'status_map' ) . '[dp_to_wc][' . $k . ']', $map['dp_to_wc'][ $k ] ?? '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div><table class="form-table" role="presentation">';
		self::form_close();
	}

	/** Tools tab. */
	private static function tab_tools() {
		$labels = Eru_DPG_Tools::labels();
		$on     = Eru_DPG_Settings::is_enabled();
		echo '<h2>' . esc_html__( 'Arka plan işleri', 'dopigo-integration-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Düğmeler işi yalnızca kuyruğa alır; Action Scheduler zaman bütçesine uyan adımlarla tamamlar. Durum bu sayfayı yenileyince güncellenir.', 'dopigo-integration-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped eru-dpg-jobs"><thead><tr><th>' . esc_html__( 'İş', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Durum', 'dopigo-integration-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		$imp = Eru_DPG_Importer::state();
		foreach ( $labels as $job => $label ) {
			$st = Eru_DPG_Tools::job_state( $job );
			if ( Eru_DPG_Tools::is_queued( $job ) ) {
				$info = __( 'Kuyrukta / çalışıyor', 'dopigo-integration-for-woocommerce' );
			} elseif ( 'import' === $job ) {
				/* translators: 1: offset, 2: date */
				$info = sprintf( __( 'İmleç: %1$d · son tur: %2$s', 'dopigo-integration-for-woocommerce' ), (int) $imp['offset'], self::when( $imp['last_pass_done'] ) );
			} elseif ( 'push_all' === $job && ! empty( $st['done'] ) ) {
				/* translators: 1: updated, 2: failed */
				$info = sprintf( __( 'Bitti: %1$d gönderildi, %2$d hata', 'dopigo-integration-for-woocommerce' ), (int) $st['updated'], (int) $st['failed'] );
			} elseif ( 'pull_now' === $job ) {
				$info = self::when( Eru_DPG_Products::pull_state()['last_run'] );
			} else {
				$info = '—';
			}
			echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( $info ) . '</td><td>';
			if ( $on ) {
				self::action_button( $job, __( 'Başlat', 'dopigo-integration-for-woocommerce' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table><p>';
		self::action_button( 'reset_import', __( 'İçe aktarma imlecini sıfırla', 'dopigo-integration-for-woocommerce' ) );
		self::action_button( 'duplicates', __( 'Yinelenenleri bul', 'dopigo-integration-for-woocommerce' ) );
		echo '</p>';
		if ( ! $on ) {
			echo '<p class="description">' . esc_html__( 'İşleri başlatmak için önce Bağlantı sekmesinden entegrasyonu açın.', 'dopigo-integration-for-woocommerce' ) . '</p>';
		}

		$audit = get_option( Eru_DPG_Tools::AUDIT, array() );
		if ( $audit ) {
			echo '<h2>' . esc_html__( 'SKU eşleştirme denetimi', 'dopigo-integration-for-woocommerce' ) . '</h2><p>';
			/* translators: 1: date, 2: coverage, 3: matched, 4: units, 5: no sku, 6: dopigo variants, 7: shared */
			echo esc_html( sprintf( __( '%1$s · eşleşme %%%2$s (%3$d / %4$d birim) · SKU\'suz: %5$d · Dopigo varyantı: %6$d · paylaşılan foreign_sku: %7$d', 'dopigo-integration-for-woocommerce' ), self::when( $audit['generated_at'] ), (string) $audit['coverage'], $audit['matched'], $audit['wc_units'], $audit['no_sku'], $audit['dopigo_variants'], $audit['shared_foreign'] ) );
			echo '</p>';
			if ( ! empty( $audit['samples'] ) ) {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Dopigo\'da bulunamayan', 'dopigo-integration-for-woocommerce' ) . '</th><th>SKU</th></tr></thead><tbody>';
				foreach ( $audit['samples'] as $s ) {
					echo '<tr><td><a href="' . esc_url( get_edit_post_link( (int) $s['id'] ) ?: '#' ) . '">' . esc_html( $s['name'] ) . '</a></td><td><code>' . esc_html( $s['sku'] ) . '</code></td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		$d = get_transient( 'eru_dpg_dupes' );
		if ( is_array( $d ) ) {
			echo '<h2>' . esc_html__( 'Yinelenenler', 'dopigo-integration-for-woocommerce' ) . '</h2>';
			$blocks = array(
				array( __( 'Aynı SKU\'yu taşıyan WooCommerce ürünleri', 'dopigo-integration-for-woocommerce' ), array_map( fn( $r ) => array( $r['sku'], $r['c'], $r['ids'] ), $d['wc_sku'] ) ),
				array( __( 'Aynı Dopigo varyantına bağlı birden çok ürün', 'dopigo-integration-for-woocommerce' ), array_map( fn( $r ) => array( $r['variant'], $r['c'], $r['ids'] ), $d['links'] ) ),
				array( __( 'Dopigo\'da paylaşılan foreign_sku (denetim indeksinden)', 'dopigo-integration-for-woocommerce' ), array_map( fn( $k, $c ) => array( $k, $c, '' ), array_keys( $d['shared'] ), $d['shared'] ) ),
			);
			foreach ( $blocks as $b ) {
				echo '<h3>' . esc_html( $b[0] ) . '</h3>';
				if ( ! $b[1] ) {
					echo '<p class="description">' . esc_html__( 'Yok.', 'dopigo-integration-for-woocommerce' ) . '</p>';
					continue;
				}
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Değer', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Adet', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Ürün kimlikleri', 'dopigo-integration-for-woocommerce' ) . '</th></tr></thead><tbody>';
				foreach ( $b[1] as $r ) {
					echo '<tr><td><code>' . esc_html( (string) $r[0] ) . '</code></td><td>' . esc_html( (string) $r[1] ) . '</td><td>' . esc_html( (string) $r[2] ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		echo '<h2>' . esc_html__( 'Bütçeler ve saklama', 'dopigo-integration-for-woocommerce' ) . '</h2>';
		self::form_open( 'tools' );
		self::row( __( 'Zaman bütçesi', 'dopigo-integration-for-woocommerce' ), self::input( 'time_budget', 'number', 'small-text', ' min="5" max="120"' ) . ' ' . esc_html__( 'sn / adım', 'dopigo-integration-for-woocommerce' ), __( '5 dakikalık çekme ve araç adımları bu süreyi aşmaz.', 'dopigo-integration-for-woocommerce' ), 'eru-dpg-time_budget' );
		self::row( __( 'Sayfa boyutu', 'dopigo-integration-for-woocommerce' ), self::input( 'page_size', 'number', 'small-text', ' min="10" max="100"' ) . ' ' . esc_html__( 'kayıt', 'dopigo-integration-for-woocommerce' ), '', 'eru-dpg-page_size' );
		self::row( __( 'Çekme başına sayfa', 'dopigo-integration-for-woocommerce' ), self::input( 'pull_pages', 'number', 'small-text', ' min="1" max="50"' ), __( 'Dopigo → Woo stok/fiyat turu her 5 dakikada en çok bu kadar sayfa okur ve kaldığı yerden devam eder.', 'dopigo-integration-for-woocommerce' ), 'eru-dpg-pull_pages' );
		self::row( __( 'Günlük saklama', 'dopigo-integration-for-woocommerce' ), self::input( 'log_retention', 'number', 'small-text', ' min="1" max="365"' ) . ' ' . esc_html__( 'gün', 'dopigo-integration-for-woocommerce' ), '', 'eru-dpg-log_retention' );
		self::form_close();
	}

	/** Log tab. */
	private static function tab_log() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$f = array(
			'channel' => isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '',
			'level'   => isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '',
			's'       => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable
		$f['channel'] = array_key_exists( $f['channel'], Eru_DPG_Logger::channels() ) ? $f['channel'] : '';
		$f['level']   = array_key_exists( $f['level'], Eru_DPG_Logger::levels() ) ? $f['level'] : '';
		$per          = 50;
		$total        = Eru_DPG_Logger::count( $f );

		echo '<form method="get" class="eru-dpg-filter"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="log">';
		echo self::select( 'channel', array( '' => __( 'Tüm kanallar', 'dopigo-integration-for-woocommerce' ) ) + Eru_DPG_Logger::channels(), 'channel', $f['channel'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::select( 'level', array( '' => __( 'Tüm düzeyler', 'dopigo-integration-for-woocommerce' ) ) + Eru_DPG_Logger::levels(), 'level', $f['level'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="' . esc_attr__( 'Sipariş no, SKU, metin', 'dopigo-integration-for-woocommerce' ) . '"> ';
		submit_button( __( 'Süz', 'dopigo-integration-for-woocommerce' ), 'secondary', '', false );
		/* translators: %s: row count */
		echo ' <span class="description">' . esc_html( sprintf( __( '%s kayıt', 'dopigo-integration-for-woocommerce' ), number_format_i18n( $total ) ) ) . '</span></form>';

		self::log_table( Eru_DPG_Logger::rows( $f, $per, ( $paged - 1 ) * $per ) );

		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', self::url( 'log', array_filter( $f ) ) ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
		echo '<p class="description">' . esc_html__( 'Kayıtlarda şifre, token, e-posta ve telefon numarası maskelenir. Saklama süresi Araçlar sekmesindedir.', 'dopigo-integration-for-woocommerce' ) . '</p>';
	}

	/** @param array $rows Rows. @param bool $compact Dashboard variant. */
	private static function log_table( array $rows, $compact = false ) {
		$ch = Eru_DPG_Logger::channels();
		$lv = Eru_DPG_Logger::levels();
		$dr = array(
			'wc_to_dp' => 'Woo → Dopigo',
			'dp_to_wc' => 'Dopigo → Woo',
		);
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Kayıt yok.', 'dopigo-integration-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped eru-dpg-log"><thead><tr><th>' . esc_html__( 'Zaman', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Düzey', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Kanal', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Yön', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Referans', 'dopigo-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Mesaj', 'dopigo-integration-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td class="nowrap">' . esc_html( self::when_gmt( $r['created_at'] ) ) . '</td><td><span class="eru-dpg-lvl lvl-' . esc_attr( $r['level'] ) . '">' . esc_html( $lv[ $r['level'] ] ?? $r['level'] ) . '</span></td><td>' . esc_html( $ch[ $r['channel'] ] ?? $r['channel'] ) . '</td><td>' . esc_html( $dr[ $r['direction'] ] ?? '' ) . '</td><td>' . ( '' !== (string) $r['ref'] ? '<code>' . esc_html( $r['ref'] ) . '</code>' : '' ) . '</td><td>' . esc_html( $r['message'] );
			if ( ! $compact && ! empty( $r['context'] ) && 'null' !== $r['context'] ) {
				echo '<details><summary>' . esc_html__( 'Ayrıntı', 'dopigo-integration-for-woocommerce' ) . '</summary><pre>' . esc_html( (string) wp_json_encode( json_decode( $r['context'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
