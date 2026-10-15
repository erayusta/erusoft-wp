<?php
/**
 * Settings: one non-autoloaded option edited on the WooCommerce → Ayarlar → Yurtiçi Kargo tab.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything starts switched off: no outbound request and no scheduled job until the master switch is on
 * and at least one account is saved. Account passwords are write-only.
 */
class Eru_YK_Settings {

	const OPTION = 'eru_yk_settings';
	const TAB    = 'eru_yk';

	/**
	 * Default live endpoint (HTTPS, the address published in the service WSDL). There is no default test
	 * endpoint: take it from your contract documentation; test mode stays off until it is set.
	 */
	const ENDPOINT_LIVE = 'https://ws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices';

	/** @var array|null Request cache. */
	private static $cache = null;

	/** @return array Defaults. */
	public static function defaults() {
		return array(
			'enabled'          => 'no',
			'test_mode'        => 'no',
			'endpoint_live'    => self::ENDPOINT_LIVE,
			'endpoint_test'    => '',
			'accounts'         => array(),
			'rules'            => array(),
			'default_account'  => '',
			'auto_status'      => '',
			'excluded_methods' => array( 'local_pickup', 'pickup_location' ),
			'dispatch_status'  => '',
			'transit_status'   => '',
			'delivered_status' => '',
			'poll_dispatched'  => 30,
			'poll_transit'     => 60,
			'giveup'           => 100,
			'log_days'         => 7,
			'event_days'       => 90,
			'label_size'       => '100x150',
		);
	}

	/** @return array Saved settings over defaults. */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/** @param string $key Key. @return mixed */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/** @param string $key Key. @return bool */
	public static function on( $key ) {
		return 'yes' === self::get( $key );
	}

	/** @return bool Master switch on, at least one account with credentials, and an endpoint for the mode. */
	public static function is_enabled() {
		return self::on( 'enabled' ) && array() !== Eru_YK_Accounts::usable() && ! self::test_endpoint_missing();
	}

	/** @return bool Test mode is on but no test endpoint is set (nothing runs). */
	public static function test_endpoint_missing() {
		return self::test_mode() && '' === trim( (string) self::get( 'endpoint_test' ) );
	}

	/** Admin notice while test mode waits for an endpoint. */
	public static function notice() {
		if ( self::on( 'enabled' ) && self::test_endpoint_missing() && current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-error eru-keep"><p>' . esc_html__( 'Yurtiçi Kargo: test modu açık ama test uç noktası girilmemiş; entegrasyon çalışmıyor. Test adresini sözleşme dokümanınızdan alıp WooCommerce → Ayarlar → Yurtiçi Kargo ekranına girin.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p></div>';
		}
	}

	/** @return bool Test endpoint: order statuses and Turkish Shipping Carriers meta are not changed. */
	public static function test_mode() {
		return self::on( 'test_mode' );
	}

	/** @return string Endpoint for the current mode. */
	public static function endpoint() {
		$url = self::test_mode() ? (string) self::get( 'endpoint_test' ) : (string) self::get( 'endpoint_live' );
		return (string) apply_filters( 'eru_yk_endpoint', $url, self::test_mode() );
	}

	/**
	 * Internal write (tests, CLI): merges values, keeps autoload off, re-syncs the schedule.
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$new   = array_merge( $saved, $values );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $new, '', false );
		} else {
			update_option( self::OPTION, $new, false );
		}
		self::$cache = null;
		if ( class_exists( 'Eru_YK_Cron' ) ) {
			Eru_YK_Cron::sync();
		}
	}

	/** Drops the request cache (after a direct option write). */
	public static function flush() {
		self::$cache = null;
	}

	/** Hooks. */
	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'tab' ), 60 );
		add_action( 'woocommerce_settings_' . self::TAB, array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		foreach ( array( 'update_option_', 'add_option_' ) as $prefix ) {
			add_action(
				$prefix . self::OPTION,
				function () {
					self::$cache = null;
				}
			);
		}
	}

	/** @param array $tabs Tabs. @return array */
	public static function tab( $tabs ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$tabs[ self::TAB ] = __( 'Yurtiçi Kargo', 'yurtici-kargo-integration-for-woocommerce' );
		}
		return $tabs;
	}

	/** Repeater script, only on this tab. */
	public static function assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen routing only.
		if ( 'wc-settings' === ( $_GET['page'] ?? '' ) && self::TAB === ( $_GET['tab'] ?? '' ) ) {
			wp_enqueue_script( 'eru-yk-settings', ERU_YK_URL . 'assets/settings.js', array(), ERU_YK_VERSION, true );
			wp_enqueue_style( 'eru-yk-admin', ERU_YK_URL . 'assets/admin.css', array(), ERU_YK_VERSION );
		}
	}

	/** @return array<string,string> Order statuses without the wc- prefix (all registered ones). */
	public static function statuses() {
		$out = array();
		foreach ( wc_get_order_statuses() as $k => $label ) {
			$out[ preg_replace( '/^wc-/', '', $k ) ] = $label;
		}
		return $out;
	}

	/** @return array<string,string> Registered shipping method ids => titles. */
	public static function shipping_methods() {
		$out = array();
		if ( function_exists( 'WC' ) && WC()->shipping() ) {
			foreach ( WC()->shipping()->load_shipping_methods() as $id => $m ) {
				$out[ (string) $id ] = (string) ( $m->method_title ? $m->method_title : $id );
			}
		}
		foreach ( (array) self::get( 'excluded_methods' ) as $id ) {
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = $id;
			}
		}
		return $out;
	}

	/** @return array<int,string> Shipping method instances (zone methods): instance id => label. */
	public static function shipping_instances() {
		$out = array();
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $out;
		}
		$zones   = WC_Shipping_Zones::get_zones();
		$zones[] = array(
			'zone_name'        => __( 'Diğer bölgeler', 'yurtici-kargo-integration-for-woocommerce' ),
			'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods(),
		);
		foreach ( $zones as $z ) {
			foreach ( (array) ( $z['shipping_methods'] ?? array() ) as $m ) {
				$out[ (int) $m->instance_id ] = $z['zone_name'] . ' — ' . $m->get_title();
			}
		}
		return $out;
	}

	/** Renders the tab (inside WooCommerce's settings form and nonce). */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s        = self::all();
		$accounts = Eru_YK_Accounts::all();
		$statuses = self::statuses();
		$log_url  = admin_url( 'admin.php?page=eru-yk-log' );

		echo '<h2>' . esc_html__( 'Yurtiçi Kargo', 'yurtici-kargo-integration-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Siparişlerden Yurtiçi Kargo gönderisi oluşturur, barkod etiketi basar ve teslimatı zamanlanmış sorgularla izler.', 'yurtici-kargo-integration-for-woocommerce' ) . ' <a href="' . esc_url( $log_url ) . '">' . esc_html__( 'Günlüğü aç', 'yurtici-kargo-integration-for-woocommerce' ) . '</a></p>';
		echo '<input type="hidden" name="eru_yk[_present]" value="1">';

		// Genel.
		echo '<table class="form-table"><tbody>';
		self::row_checkbox( 'enabled', __( 'Entegrasyon', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Etkin (kapalıyken hiçbir istek ve zamanlanmış iş yoktur)', 'yurtici-kargo-integration-for-woocommerce' ), $s );
		self::row_checkbox( 'test_mode', __( 'Test modu', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Test uç noktasını kullan; sipariş durumları ve Kargo Firmaları metası değişmez', 'yurtici-kargo-integration-for-woocommerce' ), $s );
		foreach ( array(
			'endpoint_live' => __( 'Canlı uç nokta', 'yurtici-kargo-integration-for-woocommerce' ),
			'endpoint_test' => __( 'Test uç noktası', 'yurtici-kargo-integration-for-woocommerce' ),
		) as $k => $label ) {
			echo '<tr><th scope="row"><label for="eru-yk-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="url" class="large-text code" id="eru-yk-' . esc_attr( $k ) . '" name="eru_yk[' . esc_attr( $k ) . ']" value="' . esc_attr( (string) $s[ $k ] ) . '">';
			if ( 'endpoint_test' === $k ) {
				echo '<p class="description">' . esc_html__( 'Varsayılan yoktur: test adresini Yurtiçi sözleşme dokümanınızdan alın. Test modu bu alan dolana kadar çalışmaz.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p>';
			}
			if ( 0 === stripos( (string) $s[ $k ], 'http://' ) ) {
				echo '<p class="description eru-yk-warn">' . esc_html__( 'Şifresiz HTTP: kullanıcı adı ve şifre ağda açık metin olarak taşınır.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// Hesaplar.
		echo '<h2>' . esc_html__( 'Hesaplar', 'yurtici-kargo-integration-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Her hesap kendi web servis kullanıcısıyla çalışır. Kargo anahtarı = önek + sipariş no (iptalden sonra K1, K2 …). Önek yalnızca büyük harf ve rakam, en fazla 5 karakter ve hesaplar arasında benzersiz olmalıdır.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped eru-yk-repeater" data-repeater="accounts"><thead><tr>';
		foreach ( array( __( 'Etiket', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Web servis kullanıcı adı', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Web servis şifresi', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Kargo anahtarı öneki', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Gönderici müşteri kodu', 'yurtici-kargo-integration-for-woocommerce' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$i = 0;
		foreach ( $accounts as $a ) {
			self::account_row( (string) $i, $a );
			++$i;
		}
		echo '</tbody></table>';
		echo '<template id="eru-yk-tpl-accounts">';
		self::account_row( '__i__', array() );
		echo '</template>';
		echo '<p><button type="button" class="button eru-yk-add" data-target="accounts">' . esc_html__( 'Hesap ekle', 'yurtici-kargo-integration-for-woocommerce' ) . '</button></p>';

		// Yönlendirme.
		echo '<h2>' . esc_html__( 'Hesap seçimi (yönlendirme)', 'yurtici-kargo-integration-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Kurallar yukarıdan aşağıya denenir; ilk eşleşen kuralın hesabı kullanılır. Alt sınır dahil, üst sınır hariçtir. Boş alan "koşul yok" demektir. Hiçbiri eşleşmezse varsayılan hesap kullanılır. Yeni eklenen hesaplar kaydettikten sonra listelerde görünür.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped eru-yk-repeater" data-repeater="rules"><thead><tr>';
		foreach ( array( __( 'Hesap', 'yurtici-kargo-integration-for-woocommerce' ), __( 'En az tutar', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Tutar üst sınırı', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Kargo yöntemi örnek kimlikleri (virgülle)', 'yurtici-kargo-integration-for-woocommerce' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$i = 0;
		foreach ( (array) $s['rules'] as $r ) {
			self::rule_row( (string) $i, $r, $accounts );
			++$i;
		}
		echo '</tbody></table>';
		echo '<template id="eru-yk-tpl-rules">';
		self::rule_row( '__i__', array(), $accounts );
		echo '</template>';
		echo '<p><button type="button" class="button eru-yk-add" data-target="rules">' . esc_html__( 'Kural ekle', 'yurtici-kargo-integration-for-woocommerce' ) . '</button></p>';
		$instances = self::shipping_instances();
		if ( $instances ) {
			echo '<p class="description">' . esc_html__( 'Kargo yöntemi örnekleri:', 'yurtici-kargo-integration-for-woocommerce' ) . ' ';
			$parts = array();
			foreach ( $instances as $iid => $label ) {
				$parts[] = '#' . (int) $iid . ' ' . $label;
			}
			echo esc_html( implode( ' · ', $parts ) ) . '</p>';
		}
		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="eru-yk-default-account">' . esc_html__( 'Varsayılan hesap', 'yurtici-kargo-integration-for-woocommerce' ) . '</label></th><td><select id="eru-yk-default-account" name="eru_yk[default_account]">';
		echo '<option value="">' . esc_html__( 'İlk hesap', 'yurtici-kargo-integration-for-woocommerce' ) . '</option>';
		foreach ( $accounts as $a ) {
			echo '<option value="' . esc_attr( $a['id'] ) . '"' . selected( $s['default_account'], $a['id'], false ) . '>' . esc_html( $a['label'] ) . '</option>';
		}
		echo '</select></td></tr></tbody></table>';

		// Gönderim ve durumlar.
		echo '<h2>' . esc_html__( 'Gönderim ve durumlar', 'yurtici-kargo-integration-for-woocommerce' ) . '</h2><table class="form-table"><tbody>';
		self::row_status( 'auto_status', __( 'Otomatik gönderim tetiği', 'yurtici-kargo-integration-for-woocommerce' ), __( 'Yok (yalnızca elle)', 'yurtici-kargo-integration-for-woocommerce' ), $s, $statuses, __( 'Sipariş bu duruma geçince gönderi arka planda (Action Scheduler) oluşturulur.', 'yurtici-kargo-integration-for-woocommerce' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Otomatik gönderilmeyecek kargo yöntemleri', 'yurtici-kargo-integration-for-woocommerce' ) . '</th><td><fieldset>';
		foreach ( self::shipping_methods() as $id => $title ) {
			echo '<label style="display:block"><input type="checkbox" name="eru_yk[excluded_methods][]" value="' . esc_attr( $id ) . '"' . checked( in_array( $id, (array) $s['excluded_methods'], true ), true, false ) . '> ' . esc_html( $title ) . ' <code>' . esc_html( $id ) . '</code></label>';
		}
		echo '<p class="description">' . esc_html__( 'Bu yöntemlerle gelen siparişler otomatik gönderilmez; sipariş ekranından elle gönderilebilir.', 'yurtici-kargo-integration-for-woocommerce' ) . '</p></fieldset></td></tr>';
		$nochange = __( 'Değiştirme', 'yurtici-kargo-integration-for-woocommerce' );
		self::row_status( 'dispatch_status', __( 'Gönderi oluşunca', 'yurtici-kargo-integration-for-woocommerce' ), $nochange, $s, $statuses, '' );
		self::row_status( 'transit_status', __( 'Kargo yola çıkınca', 'yurtici-kargo-integration-for-woocommerce' ), $nochange, $s, $statuses, '' );
		self::row_status( 'delivered_status', __( 'Teslim edilince', 'yurtici-kargo-integration-for-woocommerce' ), $nochange, $s, $statuses, __( 'Her adımda siparişe not eklenir; durum yalnızca burada seçildiyse değişir. Özel sipariş durumları da listelenir.', 'yurtici-kargo-integration-for-woocommerce' ) );
		echo '</tbody></table>';

		// Takip.
		echo '<h2>' . esc_html__( 'Takip ve kayıtlar', 'yurtici-kargo-integration-for-woocommerce' ) . '</h2><table class="form-table"><tbody>';
		self::row_number( 'poll_dispatched', __( 'Sorgu aralığı: gönderi oluşturuldu (dakika)', 'yurtici-kargo-integration-for-woocommerce' ), $s, 5, 1440, '' );
		self::row_number( 'poll_transit', __( 'Sorgu aralığı: yolda (dakika)', 'yurtici-kargo-integration-for-woocommerce' ), $s, 5, 1440, '' );
		self::row_number( 'giveup', __( 'Kayıt bulunamazsa vazgeç (sorgu sayısı)', 'yurtici-kargo-integration-for-woocommerce' ), $s, 21, 1000, __( '20 sonuçsuz sorgudan sonra aralık her seferinde ikiye katlanır (en fazla 1 gün).', 'yurtici-kargo-integration-for-woocommerce' ) );
		self::row_number( 'log_days', __( 'Ham istek günlüğü saklama (gün)', 'yurtici-kargo-integration-for-woocommerce' ), $s, 1, 90, __( 'Ham istekler alıcı adı, adresi ve telefonunu içerir; süre dolunca silinir.', 'yurtici-kargo-integration-for-woocommerce' ) );
		self::row_number( 'event_days', __( 'Olay (zaman tüneli) saklama (gün)', 'yurtici-kargo-integration-for-woocommerce' ), $s, 7, 730, '' );
		echo '<tr><th scope="row"><label for="eru-yk-label-size">' . esc_html__( 'Etiket boyutu', 'yurtici-kargo-integration-for-woocommerce' ) . '</label></th><td><select id="eru-yk-label-size" name="eru_yk[label_size]">';
		foreach ( array(
			'100x150' => '100 × 150 mm',
			'a6'      => 'A6 (105 × 148 mm)',
		) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['label_size'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr></tbody></table>';
	}

	/** @param string $i Index. @param array $a Account. */
	private static function account_row( $i, array $a ) {
		$n     = 'eru_yk[accounts][' . $i . ']';
		$saved = '' !== (string) ( $a['password'] ?? '' );
		echo '<tr>';
		echo '<td><input type="hidden" name="' . esc_attr( $n . '[id]' ) . '" value="' . esc_attr( (string) ( $a['id'] ?? '' ) ) . '"><input type="text" class="regular-text" style="width:100%" name="' . esc_attr( $n . '[label]' ) . '" value="' . esc_attr( (string) ( $a['label'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Etiket', 'yurtici-kargo-integration-for-woocommerce' ) . '"></td>';
		echo '<td><input type="text" autocomplete="off" style="width:100%" name="' . esc_attr( $n . '[username]' ) . '" value="' . esc_attr( (string) ( $a['username'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Web servis kullanıcı adı', 'yurtici-kargo-integration-for-woocommerce' ) . '"></td>';
		echo '<td><input type="password" autocomplete="new-password" style="width:100%" name="' . esc_attr( $n . '[password]' ) . '" value="" placeholder="' . esc_attr( $saved ? __( 'Kayıtlı (değiştirmek için yazın)', 'yurtici-kargo-integration-for-woocommerce' ) : '' ) . '" aria-label="' . esc_attr__( 'Web servis şifresi', 'yurtici-kargo-integration-for-woocommerce' ) . '">';
		if ( $saved ) {
			echo '<br><span class="eru-yk-saved">' . esc_html__( 'Kayıtlı', 'yurtici-kargo-integration-for-woocommerce' ) . '</span> <label><input type="checkbox" name="' . esc_attr( $n . '[clear_password]' ) . '" value="1"> ' . esc_html__( 'Sil', 'yurtici-kargo-integration-for-woocommerce' ) . '</label>';
		}
		echo '</td>';
		echo '<td><input type="text" maxlength="5" pattern="[A-Za-z0-9]{0,5}" style="width:6em;text-transform:uppercase" name="' . esc_attr( $n . '[prefix]' ) . '" value="' . esc_attr( (string) ( $a['prefix'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Kargo anahtarı öneki', 'yurtici-kargo-integration-for-woocommerce' ) . '"></td>';
		echo '<td><input type="text" style="width:100%" name="' . esc_attr( $n . '[customer_code]' ) . '" value="' . esc_attr( (string) ( $a['customer_code'] ?? '' ) ) . '" aria-label="' . esc_attr__( 'Gönderici müşteri kodu', 'yurtici-kargo-integration-for-woocommerce' ) . '"></td>';
		echo '<td><button type="button" class="button-link-delete eru-yk-remove">' . esc_html__( 'Kaldır', 'yurtici-kargo-integration-for-woocommerce' ) . '</button></td>';
		echo '</tr>';
	}

	/** @param string $i Index. @param array $r Rule. @param array $accounts Accounts. */
	private static function rule_row( $i, array $r, array $accounts ) {
		$n = 'eru_yk[rules][' . $i . ']';
		echo '<tr><td><select name="' . esc_attr( $n . '[account]' ) . '" aria-label="' . esc_attr__( 'Hesap', 'yurtici-kargo-integration-for-woocommerce' ) . '">';
		foreach ( $accounts as $a ) {
			echo '<option value="' . esc_attr( $a['id'] ) . '"' . selected( (string) ( $r['account'] ?? '' ), $a['id'], false ) . '>' . esc_html( $a['label'] ) . '</option>';
		}
		echo '</select></td>';
		foreach ( array( 'min', 'max' ) as $k ) {
			echo '<td><input type="number" min="0" step="0.01" style="width:9em" name="' . esc_attr( $n . '[' . $k . ']' ) . '" value="' . esc_attr( (string) ( $r[ $k ] ?? '' ) ) . '" aria-label="' . esc_attr( 'min' === $k ? __( 'En az tutar', 'yurtici-kargo-integration-for-woocommerce' ) : __( 'Tutar üst sınırı', 'yurtici-kargo-integration-for-woocommerce' ) ) . '"></td>';
		}
		echo '<td><input type="text" style="width:100%" name="' . esc_attr( $n . '[methods]' ) . '" value="' . esc_attr( implode( ', ', array_map( 'intval', (array) ( $r['methods'] ?? array() ) ) ) ) . '" placeholder="3, 7" aria-label="' . esc_attr__( 'Kargo yöntemi örnek kimlikleri', 'yurtici-kargo-integration-for-woocommerce' ) . '"></td>';
		echo '<td><button type="button" class="button-link-delete eru-yk-remove">' . esc_html__( 'Kaldır', 'yurtici-kargo-integration-for-woocommerce' ) . '</button></td></tr>';
	}

	/** Checkbox row. */
	private static function row_checkbox( $k, $label, $desc, array $s ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="eru_yk[' . esc_attr( $k ) . ']" value="yes"' . checked( 'yes', $s[ $k ], false ) . '> ' . esc_html( $desc ) . '</label></td></tr>';
	}

	/** Status picker row. */
	private static function row_status( $k, $label, $none, array $s, array $statuses, $desc ) {
		echo '<tr><th scope="row"><label for="eru-yk-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><select id="eru-yk-' . esc_attr( $k ) . '" name="eru_yk[' . esc_attr( $k ) . ']"><option value="">' . esc_html( $none ) . '</option>';
		foreach ( $statuses as $slug => $name ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $s[ $k ], $slug, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Number row. */
	private static function row_number( $k, $label, array $s, $min, $max, $desc ) {
		echo '<tr><th scope="row"><label for="eru-yk-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" class="small-text" id="eru-yk-' . esc_attr( $k ) . '" name="eru_yk[' . esc_attr( $k ) . ']" min="' . (int) $min . '" max="' . (int) $max . '" value="' . esc_attr( (string) $s[ $k ] ) . '">';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Save handler (WooCommerce has verified its settings nonce). */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce checked by WooCommerce; sanitised field by field below.
		$in = isset( $_POST['eru_yk'] ) && is_array( $_POST['eru_yk'] ) ? wp_unslash( $_POST['eru_yk'] ) : null;
		if ( ! is_array( $in ) ) {
			return;
		}
		$result = self::sanitize( $in );
		foreach ( $result['errors'] as $e ) {
			WC_Admin_Settings::add_error( $e );
		}
		self::update( $result['values'] );
	}

	/** @param mixed $v Raw. @param int $min Min. @param int $max Max. @return int */
	private static function int( $v, $min, $max ) {
		return max( $min, min( $max, absint( $v ) ) );
	}

	/** @param mixed $v Raw. @return string Decimal string or ''. */
	private static function amount( $v ) {
		$v = str_replace( ',', '.', trim( (string) $v ) );
		return is_numeric( $v ) && (float) $v >= 0 ? (string) wc_format_decimal( $v ) : '';
	}

	/**
	 * Sanitises posted values (unslashed). Passwords left blank keep the stored value.
	 *
	 * @param array $in Posted.
	 * @return array{values:array,errors:string[]}
	 */
	public static function sanitize( array $in ) {
		$old      = self::all();
		$errors   = array();
		$statuses = array_keys( self::statuses() );
		$out      = array();
		$yes      = fn( $k ) => isset( $in[ $k ] ) && 'yes' === $in[ $k ] ? 'yes' : 'no';

		$out['enabled']   = $yes( 'enabled' );
		$out['test_mode'] = $yes( 'test_mode' );
		$live                 = esc_url_raw( trim( (string) ( $in['endpoint_live'] ?? '' ) ), array( 'https', 'http' ) );
		$out['endpoint_live'] = '' !== $live ? $live : self::ENDPOINT_LIVE;
		$out['endpoint_test'] = esc_url_raw( trim( (string) ( $in['endpoint_test'] ?? '' ) ), array( 'https', 'http' ) );
		if ( 'yes' === $out['test_mode'] && '' === $out['endpoint_test'] ) {
			$errors[] = __( 'Test modu için test uç noktası gerekli (sözleşme dokümanınızdaki adres). Girilene kadar entegrasyon çalışmaz.', 'yurtici-kargo-integration-for-woocommerce' );
		}

		// Accounts.
		$old_by_id = array();
		foreach ( (array) $old['accounts'] as $a ) {
			if ( is_array( $a ) && ! empty( $a['id'] ) ) {
				$old_by_id[ $a['id'] ] = $a;
			}
		}
		$accounts = array();
		$prefixes = array();
		foreach ( array_values( (array) ( $in['accounts'] ?? array() ) ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label    = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$username = sanitize_text_field( (string) ( $row['username'] ?? '' ) );
			$id       = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $label && '' === $username && '' === $id ) {
				continue;
			}
			if ( '' === $id || isset( $accounts[ $id ] ) ) {
				$id = 'a' . strtolower( wp_generate_password( 7, false, false ) );
			}
			$pass = (string) ( $row['password'] ?? '' );
			$pass = '' !== trim( $pass ) ? trim( $pass ) : (string) ( $old_by_id[ $id ]['password'] ?? '' );
			if ( ! empty( $row['clear_password'] ) ) {
				$pass = '';
			}
			$prefix = strtoupper( trim( (string) ( $row['prefix'] ?? '' ) ) );
			if ( ! preg_match( '/^[A-Z0-9]{0,5}$/', $prefix ) ) {
				/* translators: %s: account label */
				$errors[] = sprintf( __( '"%s" hesabının öneki geçersiz (yalnızca A-Z ve 0-9, en fazla 5 karakter); önceki değer korundu.', 'yurtici-kargo-integration-for-woocommerce' ), $label );
				$prefix   = (string) ( $old_by_id[ $id ]['prefix'] ?? '' );
			}
			if ( in_array( $prefix, $prefixes, true ) ) {
				/* translators: 1: account label, 2: prefix */
				$errors[] = sprintf( __( '"%1$s" hesabının öneki "%2$s" başka bir hesapta kullanılıyor; hesap kaydedilmedi.', 'yurtici-kargo-integration-for-woocommerce' ), $label, $prefix );
				if ( isset( $old_by_id[ $id ] ) ) {
					$accounts[ $id ] = $old_by_id[ $id ];
					$prefixes[]      = (string) $old_by_id[ $id ]['prefix'];
				}
				continue;
			}
			$prefixes[]      = $prefix;
			$accounts[ $id ] = array(
				'id'            => $id,
				'label'         => '' !== $label ? $label : $id,
				'username'      => $username,
				'password'      => $pass,
				'prefix'        => $prefix,
				'customer_code' => sanitize_text_field( (string) ( $row['customer_code'] ?? '' ) ),
			);
		}
		$out['accounts'] = array_values( $accounts );

		// Rules.
		$rules = array();
		foreach ( array_values( (array) ( $in['rules'] ?? array() ) ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$acc = sanitize_key( (string) ( $row['account'] ?? '' ) );
			if ( ! isset( $accounts[ $acc ] ) ) {
				continue;
			}
			$methods = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) ( $row['methods'] ?? '' ) ) ) ) ) );
			$rules[] = array(
				'account' => $acc,
				'min'     => self::amount( $row['min'] ?? '' ),
				'max'     => self::amount( $row['max'] ?? '' ),
				'methods' => $methods,
			);
		}
		$out['rules'] = $rules;
		$def          = sanitize_key( (string) ( $in['default_account'] ?? '' ) );
		$out['default_account'] = isset( $accounts[ $def ] ) ? $def : '';

		foreach ( array( 'auto_status', 'dispatch_status', 'transit_status', 'delivered_status' ) as $k ) {
			$v         = sanitize_key( (string) ( $in[ $k ] ?? '' ) );
			$out[ $k ] = in_array( $v, $statuses, true ) ? $v : '';
		}
		$known                   = array_keys( self::shipping_methods() );
		$out['excluded_methods'] = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['excluded_methods'] ?? array() ) ), $known ) );

		$out['poll_dispatched'] = self::int( $in['poll_dispatched'] ?? 30, 5, 1440 );
		$out['poll_transit']    = self::int( $in['poll_transit'] ?? 60, 5, 1440 );
		$out['giveup']          = self::int( $in['giveup'] ?? 100, 21, 1000 );
		$out['log_days']        = self::int( $in['log_days'] ?? 7, 1, 90 );
		$out['event_days']      = self::int( $in['event_days'] ?? 90, 7, 730 );
		$out['label_size']      = in_array( $in['label_size'] ?? '', array( '100x150', 'a6' ), true ) ? $in['label_size'] : '100x150';

		return array(
			'values' => $out,
			'errors' => $errors,
		);
	}
}
