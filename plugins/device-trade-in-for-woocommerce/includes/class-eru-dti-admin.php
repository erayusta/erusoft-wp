<?php
/**
 * Admin screens: settings, device table, trade-in list, order metabox.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything requires manage_woocommerce and a nonce.
 */
class Eru_DTI_Admin {

	const CAP = 'manage_woocommerce';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_tab_pages' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_eru_dti_device_save', array( __CLASS__, 'device_save' ) );
		add_action( 'admin_post_eru_dti_device_delete', array( __CLASS__, 'device_delete' ) );
		add_action( 'admin_post_eru_dti_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_eru_dti_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_eru_dti_tradein_update', array( __CLASS__, 'tradein_update' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
	}

	/** One WooCommerce menu entry; the device and trade-in pages are reached through its tabs. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Takas ayarları', 'device-trade-in-for-woocommerce' ), __( 'Takas', 'device-trade-in-for-woocommerce' ), self::CAP, 'eru-dti', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'woocommerce', __( 'Takas cihazları', 'device-trade-in-for-woocommerce' ), __( 'Takas Cihazları', 'device-trade-in-for-woocommerce' ), self::CAP, 'eru-dti-devices', array( __CLASS__, 'page_devices' ) );
		add_submenu_page( 'woocommerce', __( 'Takas kayıtları', 'device-trade-in-for-woocommerce' ), __( 'Takas Kayıtları', 'device-trade-in-for-woocommerce' ), self::CAP, 'eru-dti-tradeins', array( __CLASS__, 'page_tradeins' ) );
	}

	/** Hide the tab pages from the menu; they stay routable and keep "Takas" highlighted. */
	public static function hide_tab_pages() {
		remove_submenu_page( 'woocommerce', 'eru-dti-devices' );
		remove_submenu_page( 'woocommerce', 'eru-dti-tradeins' );
	}

	/**
	 * @param string|null $submenu_file Current submenu file.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file ) {
		global $plugin_page;
		return in_array( $plugin_page, array( 'eru-dti-devices', 'eru-dti-tradeins' ), true ) ? 'eru-dti' : $submenu_file;
	}

	/** @param string $hook Admin hook. */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'eru-dti' ) ) {
			return;
		}
		wp_enqueue_style( 'eru-dti-admin', ERU_DTI_URL . 'assets/admin.css', array(), ERU_DTI_VERSION );
		wp_enqueue_script( 'eru-dti-admin', ERU_DTI_URL . 'assets/admin.js', array(), ERU_DTI_VERSION, true );
		wp_localize_script( 'eru-dti-admin', 'eruDtiAdmin', array( 'remove' => __( 'Kaldır', 'device-trade-in-for-woocommerce' ) ) );
	}

	/** Dies unless the current user may manage and the nonce is valid. @param string $action Nonce action. */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'device-trade-in-for-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** @param string $page Page slug. @param array $args Extra query args. */
	private static function back( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	/* ---------------- Settings ---------------- */

	/** Settings page. */
	public static function page_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s    = Eru_DTI_Settings::all();
		$name = Eru_DTI_Settings::OPTION;
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cats = is_wp_error( $cats ) ? array() : $cats;
		echo '<div class="wrap eru-dti-admin"><h1>' . esc_html__( 'Takas ayarları', 'device-trade-in-for-woocommerce' ) . '</h1>';
		self::tabs( 'eru-dti' );
		echo '<form method="post" action="options.php">';
		settings_fields( Eru_DTI_Settings::GROUP );

		echo '<h2>' . esc_html__( 'Seçim alanları', 'device-trade-in-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( '1. seviye etiketi', 'device-trade-in-for-woocommerce' ), '<input type="text" name="' . esc_attr( $name ) . '[l1_label]" value="' . esc_attr( $s['l1_label'] ) . '" class="regular-text">' );
		self::row( __( '2. seviye etiketi', 'device-trade-in-for-woocommerce' ), '<input type="text" name="' . esc_attr( $name ) . '[l2_label]" value="' . esc_attr( $s['l2_label'] ) . '" class="regular-text">' );
		self::row( __( '3. seviye etiketi', 'device-trade-in-for-woocommerce' ), '<input type="text" name="' . esc_attr( $name ) . '[l3_label]" value="' . esc_attr( $s['l3_label'] ) . '" class="regular-text"><p class="description">' . esc_html__( 'Boş bırakırsanız üçüncü seviye kullanılmaz.', 'device-trade-in-for-woocommerce' ) . '</p>' );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Cihaz durumları', 'device-trade-in-for-woocommerce' ) . '</h2>';
		echo '<table class="widefat eru-dti-conds" id="eru-dti-conds"><thead><tr><th>' . esc_html__( 'Anahtar', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Etiket', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Açıklama', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Yüzde', 'device-trade-in-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $s['conditions'] as $i => $c ) {
			self::condition_row( $name, $i, $c );
		}
		echo '</tbody></table><p><button type="button" class="button" id="eru-dti-add-cond">' . esc_html__( 'Durum ekle', 'device-trade-in-for-woocommerce' ) . '</button></p>';
		echo '<script type="application/json" id="eru-dti-cond-tpl">' . wp_json_encode( array( 'name' => $name ) ) . '</script>';

		echo '<h2>' . esc_html__( 'Fiyatlama', 'device-trade-in-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'Kampanya bonusu', 'device-trade-in-for-woocommerce' ), '<label><input type="checkbox" name="' . esc_attr( $name ) . '[bonus_enabled]" value="1"' . checked( 'yes', $s['bonus_enabled'], false ) . '> ' . esc_html__( 'Etkin', 'device-trade-in-for-woocommerce' ) . '</label> <input type="number" step="0.01" min="0" name="' . esc_attr( $name ) . '[bonus_amount]" value="' . esc_attr( $s['bonus_amount'] ) . '" class="small-text"> ' . esc_html( get_woocommerce_currency_symbol() ) );
		$mode = '<select name="' . esc_attr( $name ) . '[discount_mode]"><option value="fee"' . selected( 'fee', $s['discount_mode'], false ) . '>' . esc_html__( 'Negatif ücret (vergisiz)', 'device-trade-in-for-woocommerce' ) . '</option><option value="line_discount"' . selected( 'line_discount', $s['discount_mode'], false ) . '>' . esc_html__( 'Satır indirimi (vergi matrahını düşürür)', 'device-trade-in-for-woocommerce' ) . '</option></select><p class="description">' . esc_html__( 'Vergi uygulaması farklıdır; muhasebecinize danışın (README: Vergi).', 'device-trade-in-for-woocommerce' ) . '</p>';
		self::row( __( 'İndirim biçimi', 'device-trade-in-for-woocommerce' ), $mode );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Kapsam', 'device-trade-in-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		$opts = '';
		foreach ( $cats as $t ) {
			$opts .= '<option value="' . (int) $t->term_id . '"' . selected( in_array( (int) $t->term_id, array_map( 'intval', (array) $s['categories'] ), true ), true, false ) . '>' . esc_html( $t->name ) . '</option>';
		}
		self::row( __( 'Etkin kategoriler', 'device-trade-in-for-woocommerce' ), '<select name="' . esc_attr( $name ) . '[categories][]" multiple size="5">' . $opts . '</select>' );
		self::row( __( 'Etkin ürünler (ID)', 'device-trade-in-for-woocommerce' ), '<input type="text" name="' . esc_attr( $name ) . '[products]" value="' . esc_attr( implode( ', ', (array) $s['products'] ) ) . '" class="regular-text"><p class="description">' . esc_html__( 'Virgülle ayrılmış ürün kimlikleri. Kategori veya ürün seçilmedikçe ön yüzde hiçbir şey görünmez.', 'device-trade-in-for-woocommerce' ) . '</p>' );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Form ve teslimat', 'device-trade-in-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'Seri numarası zorunlu', 'device-trade-in-for-woocommerce' ), '<input type="checkbox" name="' . esc_attr( $name ) . '[serial_required]" value="1"' . checked( 'yes', $s['serial_required'], false ) . '>' );
		self::row( __( 'Koşullar metni', 'device-trade-in-for-woocommerce' ), '<textarea name="' . esc_attr( $name ) . '[terms_text]" rows="3" class="large-text">' . esc_textarea( $s['terms_text'] ) . '</textarea><p class="description">' . esc_html__( 'Boşsa onay kutusu gösterilmez.', 'device-trade-in-for-woocommerce' ) . '</p>' );
		self::row( __( 'Gönderim talimatı', 'device-trade-in-for-woocommerce' ), '<textarea name="' . esc_attr( $name ) . '[ship_text]" rows="4" class="large-text">' . esc_textarea( $s['ship_text'] ) . '</textarea><p class="description">' . esc_html__( 'Yer tutucular: {days} (hatırlatma günü), {order} (sipariş numarası), {reference} (aşağıdaki referans). Sipariş tamamlandı sayfasında ve hatırlatma e-postasında gösterilir.', 'device-trade-in-for-woocommerce' ) . '</p>' );
		self::row( __( '{reference} değeri', 'device-trade-in-for-woocommerce' ), '<input type="text" name="' . esc_attr( $name ) . '[ship_reference]" value="' . esc_attr( $s['ship_reference'] ) . '" class="regular-text">' );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Sipariş bekletme', 'device-trade-in-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'Cihaz onaylanana kadar beklet', 'device-trade-in-for-woocommerce' ), '<input type="checkbox" name="' . esc_attr( $name ) . '[hold_enabled]" value="1"' . checked( 'yes', $s['hold_enabled'], false ) . '>' );
		self::row( __( 'Bekleme durumu', 'device-trade-in-for-woocommerce' ), '<select name="' . esc_attr( $name ) . '[hold_status]"><option value="wc-eru-dti-hold"' . selected( 'wc-eru-dti-hold', $s['hold_status'], false ) . '>' . esc_html__( 'Takas onayı bekleniyor', 'device-trade-in-for-woocommerce' ) . '</option><option value="wc-on-hold"' . selected( 'wc-on-hold', $s['hold_status'], false ) . '>' . esc_html__( 'Beklemede (WooCommerce)', 'device-trade-in-for-woocommerce' ) . '</option></select>' );
		self::row( __( 'Reddedilen takasta iptal et', 'device-trade-in-for-woocommerce' ), '<input type="checkbox" name="' . esc_attr( $name ) . '[reject_cancel]" value="1"' . checked( 'yes', $s['reject_cancel'], false ) . '> <span class="description">' . esc_html__( 'Kapalıysa sipariş (bekletme açıksa) beklemeye alınır; her durumda sipariş notu eklenir.', 'device-trade-in-for-woocommerce' ) . '</span>' );
		$tr = '';
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$bare = substr( $slug, 3 );
			$tr  .= '<option value="' . esc_attr( $bare ) . '"' . selected( in_array( $bare, (array) $s['hold_triggers'], true ), true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		self::row( __( 'Tetikleyen durumlar', 'device-trade-in-for-woocommerce' ), '<select name="' . esc_attr( $name ) . '[hold_triggers][]" multiple size="5">' . $tr . '</select><p class="description">' . esc_html__( 'Sipariş bu durumlardan birine geçince ve takas onaylanmamışsa bekletilir. Yönetici elle yaptığı değişiklik korunur.', 'device-trade-in-for-woocommerce' ) . '</p>' );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Hatırlatma, talep formu ve saklama', 'device-trade-in-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'Gecikme hatırlatması (gün)', 'device-trade-in-for-woocommerce' ), '<input type="number" min="0" name="' . esc_attr( $name ) . '[overdue_days]" value="' . esc_attr( $s['overdue_days'] ) . '" class="small-text"> <span class="description">' . esc_html__( '0 = kapalı', 'device-trade-in-for-woocommerce' ) . '</span>' );
		self::row( __( 'Listede yok formu', 'device-trade-in-for-woocommerce' ), '<input type="checkbox" name="' . esc_attr( $name ) . '[leads_enabled]" value="1"' . checked( 'yes', $s['leads_enabled'], false ) . '>' );
		self::row( __( 'Bildirim e-postası', 'device-trade-in-for-woocommerce' ), '<input type="email" name="' . esc_attr( $name ) . '[notify_email]" value="' . esc_attr( $s['notify_email'] ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '">' );
		self::row( __( 'Veri saklama (gün)', 'device-trade-in-for-woocommerce' ), '<input type="number" min="1" name="' . esc_attr( $name ) . '[retention_days]" value="' . esc_attr( $s['retention_days'] ) . '" class="small-text">' );
		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	/** @param string $label Label. @param string $html Trusted, escaped control HTML. */
	private static function row( $label, $html ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** @param string $name Option name. @param int $i Index. @param array $c Condition. */
	private static function condition_row( $name, $i, $c ) {
		$b = esc_attr( $name ) . '[conditions][' . (int) $i . ']';
		echo '<tr>';
		echo '<td><input type="text" name="' . $b . '[key]" value="' . esc_attr( $c['key'] ) . '" class="small-text" readonly></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><input type="text" name="' . $b . '[label]" value="' . esc_attr( $c['label'] ) . '"></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><input type="text" name="' . $b . '[description]" value="' . esc_attr( $c['description'] ) . '" class="large-text"></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><input type="number" step="0.01" min="0" max="100" name="' . $b . '[percent]" value="' . esc_attr( $c['percent'] ) . '" class="small-text"> %</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><button type="button" class="button-link-delete eru-dti-del">' . esc_html__( 'Kaldır', 'device-trade-in-for-woocommerce' ) . '</button></td>';
		echo '</tr>';
	}

	/** @param string $current Current page slug. */
	private static function tabs( $current ) {
		$tabs = array(
			'eru-dti'          => __( 'Ayarlar', 'device-trade-in-for-woocommerce' ),
			'eru-dti-devices'  => __( 'Cihazlar', 'device-trade-in-for-woocommerce' ),
			'eru-dti-tradeins' => __( 'Takas kayıtları', 'device-trade-in-for-woocommerce' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			echo '<a class="nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/* ---------------- Devices ---------------- */

	/** Device table page. */
	public static function page_devices() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$edit   = absint( $_GET['edit'] ?? 0 );
		// phpcs:enable
		$list = Eru_DTI_DB::devices_list( array( 'search' => $search, 'page' => $paged, 'per_page' => 50 ) );
		$ed   = $edit ? Eru_DTI_DB::device_get( $edit ) : null;
		$l    = array( Eru_DTI_Settings::get( 'l1_label' ), Eru_DTI_Settings::get( 'l2_label' ), Eru_DTI_Settings::get( 'l3_label' ) );

		echo '<div class="wrap eru-dti-admin"><h1>' . esc_html__( 'Takas cihazları', 'device-trade-in-for-woocommerce' ) . '</h1>';
		self::tabs( 'eru-dti-devices' );
		self::notices();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-dti-devform">';
		wp_nonce_field( 'eru_dti_device_save' );
		echo '<input type="hidden" name="action" value="eru_dti_device_save"><input type="hidden" name="id" value="' . (int) ( $ed['id'] ?? 0 ) . '">';
		echo '<h2>' . esc_html( $ed ? __( 'Cihazı düzenle', 'device-trade-in-for-woocommerce' ) : __( 'Cihaz ekle', 'device-trade-in-for-woocommerce' ) ) . '</h2>';
		echo '<input type="text" name="level1" placeholder="' . esc_attr( $l[0] ) . '" value="' . esc_attr( $ed['level1'] ?? '' ) . '" required> ';
		echo '<input type="text" name="level2" placeholder="' . esc_attr( $l[1] ) . '" value="' . esc_attr( $ed['level2'] ?? '' ) . '" required> ';
		echo '<input type="text" name="level3" placeholder="' . esc_attr( $l[2] ) . '" value="' . esc_attr( $ed['level3'] ?? '' ) . '"> ';
		echo '<input type="number" step="0.01" min="0" name="base_value" placeholder="' . esc_attr__( 'Baz değer', 'device-trade-in-for-woocommerce' ) . '" value="' . esc_attr( $ed['base_value'] ?? '' ) . '" required class="small-text"> ';
		echo '<input type="number" name="sort" placeholder="' . esc_attr__( 'Sıra', 'device-trade-in-for-woocommerce' ) . '" value="' . esc_attr( $ed['sort'] ?? 0 ) . '" class="small-text"> ';
		echo '<label><input type="checkbox" name="active" value="1"' . checked( ! $ed || ! empty( $ed['active'] ), true, false ) . '> ' . esc_html__( 'Etkin', 'device-trade-in-for-woocommerce' ) . '</label> ';
		submit_button( $ed ? __( 'Güncelle', 'device-trade-in-for-woocommerce' ) : __( 'Ekle', 'device-trade-in-for-woocommerce' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<div class="eru-dti-csvbox"><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_dti_import' );
		echo '<input type="hidden" name="action" value="eru_dti_import"><strong>' . esc_html__( 'CSV içe aktar', 'device-trade-in-for-woocommerce' ) . '</strong> <input type="file" name="csv" accept=".csv,text/csv" required> ';
		submit_button( __( 'İçe aktar', 'device-trade-in-for-woocommerce' ), 'secondary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eru_dti_export' ), 'eru_dti_export' ) ) . '">' . esc_html__( 'CSV dışa aktar', 'device-trade-in-for-woocommerce' ) . '</a>';
		echo '<p class="description">level1,level2,level3,base_value,active &mdash; ' . esc_html__( 'Tutarlar 1.234,56 veya 1234.56 olabilir.', 'device-trade-in-for-woocommerce' ) . '</p></form></div>';

		echo '<form method="get" class="eru-dti-search"><input type="hidden" name="page" value="eru-dti-devices"><input type="search" name="s" value="' . esc_attr( $search ) . '"> ';
		submit_button( __( 'Ara', 'device-trade-in-for-woocommerce' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html( $l[0] ) . '</th><th>' . esc_html( $l[1] ) . '</th><th>' . esc_html( $l[2] ) . '</th><th>' . esc_html__( 'Baz değer', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Durum', 'device-trade-in-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $list['rows'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Henüz cihaz yok. Ekleyin veya CSV içe aktarın.', 'device-trade-in-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $list['rows'] as $d ) {
			echo '<tr><td>' . esc_html( $d['level1'] ) . '</td><td>' . esc_html( $d['level2'] ) . '</td><td>' . esc_html( $d['level3'] ) . '</td><td>' . wp_kses_post( wc_price( $d['base_value'] ) ) . '</td><td>' . esc_html( $d['active'] ? __( 'Etkin', 'device-trade-in-for-woocommerce' ) : __( 'Pasif', 'device-trade-in-for-woocommerce' ) ) . '</td><td>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=eru-dti-devices&edit=' . (int) $d['id'] ) ) . '">' . esc_html__( 'Düzenle', 'device-trade-in-for-woocommerce' ) . '</a> | ';
			echo '<a class="submitdelete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eru_dti_device_delete&id=' . (int) $d['id'] ), 'eru_dti_device_delete' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Silinsin mi?', 'device-trade-in-for-woocommerce' ) ) . '\')">' . esc_html__( 'Sil', 'device-trade-in-for-woocommerce' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::pagination( $list['total'], 50, $paged, array( 'page' => 'eru-dti-devices', 's' => $search ) );
		echo '</div>';
	}

	/** @param int $total Total. @param int $per Per page. @param int $paged Current. @param array $args Base args. */
	private static function pagination( $total, $per, $paged, array $args ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', add_query_arg( $args, admin_url( 'admin.php' ) ) ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
	}

	/** Shows result notices from the query string. */
	private static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['eru_dti_msg'] ) ) {
			echo '<div class="notice notice-success eru-keep"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['eru_dti_msg'] ) ) ) . '</p></div>';
		}
		$errs = get_transient( 'eru_dti_import_errors_' . get_current_user_id() );
		// phpcs:enable
		if ( $errs ) {
			delete_transient( 'eru_dti_import_errors_' . get_current_user_id() );
			echo '<div class="notice notice-warning eru-keep"><p><strong>' . esc_html__( 'Reddedilen satırlar:', 'device-trade-in-for-woocommerce' ) . '</strong></p><ul>';
			foreach ( array_slice( $errs, 0, 50 ) as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/** Saves a device. */
	public static function device_save() {
		self::guard( 'eru_dti_device_save' );
		$id   = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$data = array(
			'level1'     => sanitize_text_field( wp_unslash( $_POST['level1'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'level2'     => sanitize_text_field( wp_unslash( $_POST['level2'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'level3'     => sanitize_text_field( wp_unslash( $_POST['level3'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'base_value' => Eru_DTI_CSV::parse_amount( sanitize_text_field( wp_unslash( $_POST['base_value'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'active'     => ! empty( $_POST['active'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
			'sort'       => (int) ( $_POST['sort'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification
		);
		if ( '' === $data['level1'] || '' === $data['level2'] || null === $data['base_value'] ) {
			self::back( 'eru-dti-devices', array( 'eru_dti_msg' => __( 'Marka, model ve geçerli bir değer gerekli.', 'device-trade-in-for-woocommerce' ) ) );
		}
		if ( $id ) {
			Eru_DTI_DB::device_update( $id, $data );
		} else {
			$dup = Eru_DTI_DB::device_find( $data['level1'], $data['level2'], $data['level3'] );
			if ( $dup ) {
				Eru_DTI_DB::device_update( (int) $dup['id'], $data );
			} else {
				Eru_DTI_DB::device_insert( $data );
			}
		}
		self::back( 'eru-dti-devices', array( 'eru_dti_msg' => __( 'Kaydedildi.', 'device-trade-in-for-woocommerce' ) ) );
	}

	/** Deletes a device. */
	public static function device_delete() {
		self::guard( 'eru_dti_device_delete' );
		Eru_DTI_DB::device_delete( absint( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::back( 'eru-dti-devices', array( 'eru_dti_msg' => __( 'Silindi.', 'device-trade-in-for-woocommerce' ) ) );
	}

	/** CSV import. */
	public static function import() {
		self::guard( 'eru_dti_import' );
		$tmp = isset( $_FILES['csv']['tmp_name'] ) ? sanitize_text_field( $_FILES['csv']['tmp_name'] ) : ''; // phpcs:ignore WordPress.Security
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || filesize( $tmp ) > 2 * MB_IN_BYTES ) { // phpcs:ignore WordPress.Security
			self::back( 'eru-dti-devices', array( 'eru_dti_msg' => __( 'Dosya yüklenemedi (en çok 2 MB).', 'device-trade-in-for-woocommerce' ) ) );
		}
		$res = Eru_DTI_CSV::import( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $res['errors'] ) {
			set_transient( 'eru_dti_import_errors_' . get_current_user_id(), $res['errors'], 10 * MINUTE_IN_SECONDS );
		}
		self::back( 'eru-dti-devices', array( 'eru_dti_msg' => sprintf( /* translators: 1: added, 2: updated, 3: rejected. */ __( '%1$d eklendi, %2$d güncellendi, %3$d satır reddedildi.', 'device-trade-in-for-woocommerce' ), $res['added'], $res['updated'], count( $res['errors'] ) ) ) );
	}

	/** CSV export. */
	public static function export() {
		self::guard( 'eru_dti_export' );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="takas-cihazlari.csv"' );
		echo Eru_DTI_CSV::export(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/* ---------------- Trade-ins ---------------- */

	/** Trade-in list page. */
	public static function page_tradeins() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$list   = Eru_DTI_DB::tradeins_list( array( 'status' => $status, 'page' => $paged, 'per_page' => 30 ) );
		echo '<div class="wrap eru-dti-admin"><h1>' . esc_html__( 'Takas kayıtları', 'device-trade-in-for-woocommerce' ) . '</h1>';
		self::tabs( 'eru-dti-tradeins' );
		self::notices();
		echo '<ul class="subsubsub"><li><a href="' . esc_url( admin_url( 'admin.php?page=eru-dti-tradeins' ) ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'Tümü', 'device-trade-in-for-woocommerce' ) . '</a></li>';
		foreach ( Eru_DTI_DB::STATUSES as $st ) {
			echo ' | <li><a href="' . esc_url( admin_url( 'admin.php?page=eru-dti-tradeins&status=' . $st ) ) . '"' . ( $st === $status ? ' class="current"' : '' ) . '>' . esc_html( Eru_DTI_DB::status_label( $st ) ) . '</a></li>';
		}
		echo '</ul><br class="clear">';
		echo '<table class="wp-list-table widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Sipariş', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Cihaz', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Durum', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Seri no', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Değer', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tarih', 'device-trade-in-for-woocommerce' ) . '</th><th>' . esc_html__( 'İşlem', 'device-trade-in-for-woocommerce' ) . '</th></tr></thead><tbody>';
		if ( ! $list['rows'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Henüz takas kaydı yok.', 'device-trade-in-for-woocommerce' ) . '</td></tr>';
		}
		$conds = Eru_DTI_Settings::conditions();
		foreach ( $list['rows'] as $r ) {
			$order = $r['order_id'] ? wc_get_order( (int) $r['order_id'] ) : null;
			$dev   = Eru_DTI_DB::device_get( (int) $r['device_id'] );
			echo '<tr><td>' . (int) $r['id'] . '</td><td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( Eru_DTI_DB::device_label( $dev ) ?: '—' ) . '<br><small>' . esc_html( $conds[ $r['condition_key'] ]['label'] ?? $r['condition_key'] ) . '</small></td>';
			echo '<td>' . esc_html( Eru_DTI_DB::status_label( $r['status'] ) ) . '</td><td>' . esc_html( $r['serial'] ) . '</td><td>' . wp_kses_post( wc_price( $r['value'] ) ) . '</td><td>' . esc_html( get_date_from_gmt( $r['created_at'], 'd.m.Y H:i' ) ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-dti-statusform">';
			wp_nonce_field( 'eru_dti_tradein_update' );
			echo '<input type="hidden" name="action" value="eru_dti_tradein_update"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><select name="status">';
			foreach ( Eru_DTI_DB::STATUSES as $st ) {
				echo '<option value="' . esc_attr( $st ) . '"' . selected( $st, $r['status'], false ) . '>' . esc_html( Eru_DTI_DB::status_label( $st ) ) . '</option>';
			}
			echo '</select> <input type="text" name="note" value="' . esc_attr( (string) $r['admin_note'] ) . '" placeholder="' . esc_attr__( 'Not', 'device-trade-in-for-woocommerce' ) . '"> ';
			submit_button( __( 'Kaydet', 'device-trade-in-for-woocommerce' ), 'small', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
		self::pagination( $list['total'], 30, $paged, array( 'page' => 'eru-dti-tradeins', 'status' => $status ) );
		echo '</div>';
	}

	/** Status/note change. */
	public static function tradein_update() {
		self::guard( 'eru_dti_tradein_update' );
		$id     = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		Eru_DTI_DB::tradein_set_note( $id, wp_unslash( $_POST['note'] ?? '' ) ); // phpcs:ignore WordPress.Security
		$row = Eru_DTI_DB::tradein_get( $id );
		if ( $row && $row['status'] !== $status ) {
			Eru_DTI_DB::tradein_set_status( $id, $status );
		}
		$msg = ( $row && 'reddedildi' === $status && $row['status'] !== $status )
			? __( 'Takas reddedildi. Takas kredisi siparişe uygulanmıştı: sipariş iptal edildi veya beklemeye alındı; sipariş notunu ve ödemeyi kontrol edin.', 'device-trade-in-for-woocommerce' )
			: __( 'Kaydedildi.', 'device-trade-in-for-woocommerce' );
		self::back( 'eru-dti-tradeins', array( 'eru_dti_msg' => $msg ) );
	}

	/* ---------------- Order metabox ---------------- */

	/** Registers the metabox on both order screens. */
	public static function metabox() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $sc ) {
			add_meta_box( 'eru-dti-box', __( 'Takas', 'device-trade-in-for-woocommerce' ), array( __CLASS__, 'metabox_render' ), $sc, 'side' );
		}
	}

	/** @param WP_Post|WC_Order $post Order or post. */
	public static function metabox_render( $post ) {
		$order = $post instanceof WC_Order ? $post : wc_get_order( $post->ID );
		$row   = $order ? Eru_DTI_DB::tradein_for_order( $order->get_id() ) : null;
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'Bu siparişte takas yok.', 'device-trade-in-for-woocommerce' ) . '</p>';
			return;
		}
		$dev   = Eru_DTI_DB::device_get( (int) $row['device_id'] );
		$conds = Eru_DTI_Settings::conditions();
		echo '<p><strong>' . esc_html( Eru_DTI_DB::device_label( $dev ) ) . '</strong><br>' . esc_html( $conds[ $row['condition_key'] ]['label'] ?? $row['condition_key'] ) . '</p>';
		echo '<p>' . esc_html__( 'Seri no:', 'device-trade-in-for-woocommerce' ) . ' ' . esc_html( $row['serial'] ) . '<br>' . esc_html__( 'Değer:', 'device-trade-in-for-woocommerce' ) . ' ' . wp_kses_post( wc_price( $row['value'] ) ) . '<br>' . esc_html__( 'Durum:', 'device-trade-in-for-woocommerce' ) . ' ' . esc_html( Eru_DTI_DB::status_label( $row['status'] ) ) . '</p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=eru-dti-tradeins' ) ) . '">' . esc_html__( 'Takas kayıtlarına git', 'device-trade-in-for-woocommerce' ) . '</a></p>';
	}
}
