<?php
/**
 * Settings screen (WooCommerce → Trendyol Ürün Gönderimi) and the admin AJAX lookups.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The form posts to admin-post.php (manage_woocommerce + nonce). AJAX lookups (category search, category
 * attributes, brand search, addresses, cache refresh) need the same capability and the eru_ppt_admin nonce;
 * they only read cached data or call Trendyol when the integration is on.
 */
class Eru_PPT_Admin {

	const PAGE = 'eru-ppt';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_eru_ppt_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_eru_ppt_clear_log', array( __CLASS__, 'clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'saved_notice' ) );
		foreach ( array( 'cat_search', 'cat_attrs', 'brands', 'addresses', 'cat_refresh' ) as $a ) {
			add_action( 'wp_ajax_eru_ppt_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/** Submenu under WooCommerce. */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Trendyol Ürün Gönderimi', 'product-push-for-trendyol' ),
			__( 'Trendyol Ürün Gönderimi', 'product-push-for-trendyol' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/** @param string $hook Screen hook. */
	public static function assets( $hook ) {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours    = false !== strpos( (string) $hook, self::PAGE );
		$product = $screen && in_array( $screen->id, array( 'product', 'edit-product' ), true );
		if ( ! $ours && ! $product ) {
			return;
		}
		wp_enqueue_style( 'eru-ppt-admin', ERU_PPT_URL . 'assets/admin.css', array(), ERU_PPT_VERSION );
		if ( $product && 'edit-product' === $screen->id ) {
			return;
		}
		wp_enqueue_script( 'eru-ppt-admin', ERU_PPT_URL . 'assets/admin.js', array(), ERU_PPT_VERSION, true );
		wp_localize_script(
			'eru-ppt-admin',
			'eruPpt',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'eru_ppt_admin' ),
				'i18n'  => array(
					'none'      => __( 'Seçilmedi', 'product-push-for-trendyol' ),
					'searching' => __( 'Aranıyor…', 'product-push-for-trendyol' ),
					'noResult'  => __( 'Sonuç yok', 'product-push-for-trendyol' ),
					'error'     => __( 'İstek başarısız oldu.', 'product-push-for-trendyol' ),
					'loading'   => __( 'Özellikler yükleniyor…', 'product-push-for-trendyol' ),
					'pickCat'   => __( 'Kategori seçince Trendyol özellikleri burada listelenir.', 'product-push-for-trendyol' ),
					'attr'      => __( 'Özellik', 'product-push-for-trendyol' ),
					'value'     => __( 'Değer', 'product-push-for-trendyol' ),
					'custom'    => __( 'serbest değer', 'product-push-for-trendyol' ),
					'required'  => __( 'zorunlu', 'product-push-for-trendyol' ),
					'variant'   => __( 'varyant özelliği: varyantlı üründe varyasyonlardan eşlenir', 'product-push-for-trendyol' ),
					'pickAttr'  => __( 'Trendyol özelliği seçin', 'product-push-for-trendyol' ),
					'pickAddr'  => __( 'Adres seçin', 'product-push-for-trendyol' ),
					'confirm'   => array(
						'push'  => __( 'Ürün Trendyol\'a gönderilmek üzere kuyruğa alınsın mı?', 'product-push-for-trendyol' ),
						'reset' => __( 'Trendyol durumu sıfırlansın mı? Bir sonraki gönderimde ürün önce Trendyol listelerinde aranır.', 'product-push-for-trendyol' ),
					),
				),
			)
		);
	}

	/** Settings saved / errors notice (per user, once). */
	public static function saved_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}
		$key  = 'eru_ppt_saved_' . get_current_user_id();
		$errs = get_transient( $key );
		if ( false === $errs ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-success is-dismissible eru-keep"><p>' . esc_html__( 'Ayarlar kaydedildi.', 'product-push-for-trendyol' ) . '</p></div>';
		foreach ( (array) $errs as $e ) {
			echo '<div class="notice notice-warning eru-keep"><p>' . esc_html( (string) $e ) . '</p></div>';
		}
	}

	/** Saves the form. */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'product-push-for-trendyol' ), 403 );
		}
		check_admin_referer( 'eru_ppt_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field by field in Eru_PPT_Settings::sanitize().
		$in  = isset( $_POST['eru_ppt'] ) && is_array( $_POST['eru_ppt'] ) ? wp_unslash( $_POST['eru_ppt'] ) : array();
		$res = Eru_PPT_Settings::sanitize( $in );
		Eru_PPT_Settings::update( $res['values'] );
		set_transient( 'eru_ppt_saved_' . get_current_user_id(), $res['errors'], MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/** Empties the request log. */
	public static function clear_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'product-push-for-trendyol' ), 403 );
		}
		check_admin_referer( 'eru_ppt_clear_log' );
		Eru_PPT_Log::clear();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/** AJAX guard. */
	private static function guard() {
		if ( ! check_ajax_referer( 'eru_ppt_admin', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkiniz yok veya oturum süresi doldu.', 'product-push-for-trendyol' ) ), 403 );
		}
	}

	/** @param mixed $r Result or WP_Error. */
	private static function reply( $r ) {
		if ( is_wp_error( $r ) ) {
			wp_send_json_error(
				array(
					'message' => $r->get_error_message(),
					'retry'   => 'eru_ppt_loading' === $r->get_error_code(),
				)
			);
		}
		wp_send_json_success( $r );
	}

	/** Category search over the cached tree. */
	public static function ajax_cat_search() {
		self::guard();
		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked it.
		self::reply( Eru_PPT_Categories::search( mb_substr( $q, 0, 100 ) ) );
	}

	/** Attributes of a leaf category. */
	public static function ajax_cat_attrs() {
		self::guard();
		$cat = isset( $_POST['cat'] ) ? absint( $_POST['cat'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::reply( $cat ? Eru_PPT_Categories::attributes( $cat, false, false ) : array() ); // Cache only; a miss queues a refresh.
	}

	/** Brand search. */
	public static function ajax_brands() {
		self::guard();
		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		self::reply( Eru_PPT_Client::brands( $q ) );
	}

	/** Seller addresses. */
	public static function ajax_addresses() {
		self::guard();
		self::reply( Eru_PPT_Client::addresses() );
	}

	/** Drops the caches and queues a background refetch of the tree. */
	public static function ajax_cat_refresh() {
		self::guard();
		if ( ! Eru_PPT_Settings::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Trendyol entegrasyonu kapalı veya API bilgileri eksik.', 'product-push-for-trendyol' ) ) );
		}
		Eru_PPT_Categories::flush();
		Eru_PPT_Categories::queue( 0 );
		wp_send_json_success( array( 'message' => __( 'Önbellek temizlendi; kategori ağacı arka planda yeniden alınıyor.', 'product-push-for-trendyol' ) ) );
	}

	/**
	 * Search-and-pick widget (category or brand). Results are rendered by admin.js with textContent.
	 *
	 * @param string $kind      category|brand.
	 * @param string $name_id   Field name for the id.
	 * @param string $name_name Field name for the label.
	 * @param int    $id        Current id.
	 * @param string $label     Current label.
	 * @param string $ph        Placeholder.
	 */
	public static function picker( $kind, $name_id, $name_name, $id, $label, $ph ) {
		echo '<div class="eru-ppt-pick" data-kind="' . esc_attr( $kind ) . '">';
		echo '<input type="hidden" class="eru-ppt-pick-id" name="' . esc_attr( $name_id ) . '" value="' . esc_attr( $id ? (string) $id : '' ) . '">';
		echo '<input type="hidden" class="eru-ppt-pick-name" name="' . esc_attr( $name_name ) . '" value="' . esc_attr( $label ) . '">';
		echo '<span class="eru-ppt-pick-current">' . esc_html( $id ? ( '' !== $label ? $label : '#' . $id ) . ' (#' . $id . ')' : __( 'Seçilmedi', 'product-push-for-trendyol' ) ) . '</span> ';
		echo '<button type="button" class="button-link eru-ppt-pick-clear">' . esc_html__( 'Temizle', 'product-push-for-trendyol' ) . '</button><br>';
		echo '<input type="search" class="regular-text eru-ppt-pick-q" autocomplete="off" placeholder="' . esc_attr( $ph ) . '">';
		echo '<ul class="eru-ppt-pick-results" hidden></ul></div>';
	}

	/** @return array<int,string> Product categories, indented by depth. */
	private static function wc_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$by = array();
		foreach ( $terms as $t ) {
			$by[ (int) $t->parent ][] = $t;
		}
		$out  = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, $by ) {
			foreach ( $by[ $parent ] ?? array() as $t ) {
				$out[ (int) $t->term_id ] = str_repeat( '— ', $depth ) . $t->name;
				if ( $depth < 10 ) {
					$walk( (int) $t->term_id, $depth + 1 );
				}
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	/** Renders the screen. */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s    = Eru_PPT_Settings::all();
		$cats = self::wc_categories();
		$on   = Eru_PPT_Settings::is_enabled();
		echo '<div class="wrap eru-ppt-wrap"><h1>' . esc_html__( 'Trendyol Ürün Gönderimi', 'product-push-for-trendyol' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Basit ve varyantlı ürünleri Trendyol Ürün V2 API\'sine gönderir. Ürün kaydedildiğinde otomatik gönderim yoktur: ürün ekranındaki düğme, ürün listesindeki toplu işlem veya WP-CLI ile gönderilir. Stok ve fiyat eşitlemesi bu eklentinin işi değildir.', 'product-push-for-trendyol' ) . '</p>';
		if ( 'yes' === $s['enabled'] && ! Eru_PPT_Settings::configured() ) {
			echo '<div class="notice notice-warning inline eru-keep"><p>' . esc_html__( 'Entegrasyon açık ama satıcı ID, API anahtarı veya API secret eksik; hiçbir istek gönderilmez.', 'product-push-for-trendyol' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="eru_ppt_save">';
		wp_nonce_field( 'eru_ppt_save' );

		// Bağlantı.
		echo '<h2>' . esc_html__( 'Bağlantı', 'product-push-for-trendyol' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Entegrasyon', 'product-push-for-trendyol' ) . '</th><td><label><input type="checkbox" name="eru_ppt[enabled]" value="yes"' . checked( 'yes', $s['enabled'], false ) . '> ' . esc_html__( 'Etkin (kapalıyken Trendyol\'a hiçbir istek gitmez ve zamanlanmış iş yoktur)', 'product-push-for-trendyol' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="eru-ppt-env">' . esc_html__( 'Ortam', 'product-push-for-trendyol' ) . '</label></th><td><select id="eru-ppt-env" name="eru_ppt[environment]">';
		foreach ( array(
			'live'  => __( 'Canlı', 'product-push-for-trendyol' ),
			'stage' => __( 'Test (stage)', 'product-push-for-trendyol' ),
		) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['environment'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
		self::text_row( 'base_live', __( 'Canlı API adresi', 'product-push-for-trendyol' ), $s, 'url', __( 'Trendyol\'un yayımladığı entegrasyon adresi. Yalnızca https.', 'product-push-for-trendyol' ) );
		self::text_row( 'base_stage', __( 'Test API adresi', 'product-push-for-trendyol' ), $s, 'url', __( 'Test ortamı adresi; Trendyol test ortamı erişimini (IP izni) ayrıca açar.', 'product-push-for-trendyol' ) );
		self::text_row( 'seller_id', __( 'Satıcı ID', 'product-push-for-trendyol' ), $s, 'text', __( 'Satıcı panelinde Hesap Bilgileri → Entegrasyon Bilgileri.', 'product-push-for-trendyol' ) );
		foreach ( array(
			'api_key'    => __( 'API anahtarı', 'product-push-for-trendyol' ),
			'api_secret' => __( 'API secret', 'product-push-for-trendyol' ),
		) as $k => $label ) {
			$saved = '' !== (string) $s[ $k ];
			echo '<tr><th scope="row"><label for="eru-ppt-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="password" class="regular-text" autocomplete="new-password" id="eru-ppt-' . esc_attr( $k ) . '" name="eru_ppt[' . esc_attr( $k ) . ']" value="" placeholder="' . esc_attr( $saved ? __( 'Kayıtlı (değiştirmek için yazın)', 'product-push-for-trendyol' ) : '' ) . '">';
			if ( $saved ) {
				echo ' <span class="eru-ppt-saved">' . esc_html__( 'Kayıtlı', 'product-push-for-trendyol' ) . '</span> <label><input type="checkbox" name="eru_ppt[clear_' . esc_attr( $k ) . ']" value="1"> ' . esc_html__( 'Sil', 'product-push-for-trendyol' ) . '</label>';
			}
			echo '</td></tr>';
		}
		self::text_row( 'integration_ref', __( 'Entegrasyon referansı', 'product-push-for-trendyol' ), $s, 'text', __( 'User-Agent başlığında "satıcı ID - referans" olarak gider. Kendi entegrasyonunuzsa SelfIntegration.', 'product-push-for-trendyol' ) );
		echo '</tbody></table>';
		if ( has_filter( 'eru_ppt_credentials' ) ) {
			echo '<p class="description">' . esc_html__( 'Kimlik bilgileri eru_ppt_credentials filtresiyle başka bir eklentiden geliyor olabilir.', 'product-push-for-trendyol' ) . '</p>';
		}

		// Varsayılanlar.
		echo '<h2>' . esc_html__( 'Varsayılanlar', 'product-push-for-trendyol' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Marka', 'product-push-for-trendyol' ) . '</th><td>';
		self::picker( 'brand', 'eru_ppt[brand_id]', 'eru_ppt[brand_name]', (int) $s['brand_id'], (string) $s['brand_name'], __( 'Marka ara (en az 2 harf)', 'product-push-for-trendyol' ) );
		echo '<p class="description">' . esc_html__( 'Trendyol marka listesinden. Ürün ekranında ürün bazında değiştirilebilir.', 'product-push-for-trendyol' ) . '</p></td></tr>';
		self::text_row( 'cargo_company_id', __( 'Kargo firması ID', 'product-push-for-trendyol' ), $s, 'number', __( 'Trendyol\'daki sözleşmeli kargo firmanızın kimliği (Trendyol entegrasyon dokümanındaki kargo firmaları listesi).', 'product-push-for-trendyol' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Sevkiyat / iade adresi', 'product-push-for-trendyol' ) . '</th><td class="eru-ppt-addresses">';
		foreach ( array(
			'shipment_address_id'  => __( 'Sevkiyat adresi', 'product-push-for-trendyol' ),
			'returning_address_id' => __( 'İade adresi', 'product-push-for-trendyol' ),
		) as $k => $l ) {
			echo '<label class="eru-ppt-addr">' . esc_html( $l ) . '<br><select name="eru_ppt[' . esc_attr( $k ) . ']" data-current="' . esc_attr( (string) $s[ $k ] ) . '"><option value="">' . esc_html__( 'Varsayılan (Trendyol hesabındaki)', 'product-push-for-trendyol' ) . '</option>';
			if ( $s[ $k ] ) {
				echo '<option value="' . esc_attr( (string) $s[ $k ] ) . '" selected>#' . esc_html( (string) $s[ $k ] ) . '</option>';
			}
			echo '</select></label> ';
		}
		echo '<button type="button" class="button eru-ppt-load-addresses">' . esc_html__( 'Adresleri getir', 'product-push-for-trendyol' ) . '</button> <span class="eru-ppt-msg" aria-live="polite"></span></td></tr>';
		echo '<tr><th scope="row"><label for="eru-ppt-vat">' . esc_html__( 'KDV oranı', 'product-push-for-trendyol' ) . '</label></th><td><select id="eru-ppt-vat" name="eru_ppt[vat_rate]">';
		foreach ( array( 0, 1, 10, 20 ) as $v ) {
			echo '<option value="' . (int) $v . '"' . selected( (int) $s['vat_rate'], $v, false ) . '>%' . (int) $v . '</option>';
		}
		echo '</select></td></tr>';
		self::text_row( 'delivery_duration', __( 'Sevkiyat süresi (gün)', 'product-push-for-trendyol' ), $s, 'number', '' );
		self::text_row( 'dimensional_weight', __( 'Desi', 'product-push-for-trendyol' ), $s, 'text', '' );
		self::text_row( 'list_multiplier', __( 'Liste fiyatı çarpanı', 'product-push-for-trendyol' ), $s, 'text', __( 'Liste fiyatı = normal fiyat × çarpan (1 = normal fiyat). Satış fiyatı her zaman ürünün geçerli fiyatıdır; liste fiyatı satış fiyatının altına inmez. Ürün/varyasyon ekranındaki liste fiyatı bunu geçersiz kılar.', 'product-push-for-trendyol' ) );
		self::text_row( 'title_template', __( 'Başlık şablonu', 'product-push-for-trendyol' ), $s, 'text', __( 'Yer tutucular: {name}, {sku}, {attribute:pa_renk}. En fazla 100 karakter.', 'product-push-for-trendyol' ) );
		echo '<tr><th scope="row"><label for="eru-ppt-desc">' . esc_html__( 'Açıklama ön metni', 'product-push-for-trendyol' ) . '</label></th><td><textarea id="eru-ppt-desc" class="large-text" rows="3" name="eru_ppt[description_prefix]">' . esc_textarea( (string) $s['description_prefix'] ) . '</textarea><p class="description">' . esc_html__( 'Her ürün açıklamasının başına eklenir (boş bırakılabilir).', 'product-push-for-trendyol' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Barkod kaynağı', 'product-push-for-trendyol' ) . '</th><td><select name="eru_ppt[barcode_source]">';
		foreach ( array(
			'sku'  => __( 'Stok kodu (SKU)', 'product-push-for-trendyol' ),
			'gtin' => __( 'GTIN / UPC / EAN / ISBN alanı', 'product-push-for-trendyol' ),
			'meta' => __( 'Meta alanı', 'product-push-for-trendyol' ),
		) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['barcode_source'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <input type="text" name="eru_ppt[barcode_meta_key]" value="' . esc_attr( (string) $s['barcode_meta_key'] ) . '" placeholder="' . esc_attr__( 'meta anahtarı', 'product-push-for-trendyol' ) . '" aria-label="' . esc_attr__( 'Meta anahtarı', 'product-push-for-trendyol' ) . '">';
		echo '<p class="description">' . esc_html__( 'Ürün/varyasyon ekranındaki Trendyol barkodu her zaman önce gelir. Barkod Trendyol\'da ürünün kimliğidir; gönderildikten sonra değiştirmeyin.', 'product-push-for-trendyol' ) . '</p></td></tr>';
		self::text_row( 'barcode_prefix', __( 'Yedek barkod öneki', 'product-push-for-trendyol' ), $s, 'text', __( 'Boşsa barkodu olmayan ürün gönderilmez. Doluysa barkod = önek + ürün/varyasyon ID.', 'product-push-for-trendyol' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Gönderilecek stok', 'product-push-for-trendyol' ) . '</th><td>';
		foreach ( array(
			'stock' => __( 'Gerçek stok (stok yönetimi kapalıysa 0)', 'product-push-for-trendyol' ),
			'zero'  => __( '0 (stok başka bir araçla eşitlenir)', 'product-push-for-trendyol' ),
		) as $k => $l ) {
			echo '<label style="display:block"><input type="radio" name="eru_ppt[push_quantity]" value="' . esc_attr( $k ) . '"' . checked( $s['push_quantity'], $k, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Yalnızca yeni ürün oluşturulurken gönderilir; güncellemeler stok ve fiyat taşımaz.', 'product-push-for-trendyol' ) . '</p></td></tr>';
		echo '</tbody></table>';

		// Kategori eşleme.
		echo '<h2>' . esc_html__( 'Kategori eşleme', 'product-push-for-trendyol' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Ürünün WooCommerce kategorilerinden (ve üst kategorilerinden) eşlenmiş en derin olanı kullanılır. Ürün ekranında seçilen kategori her zaman önce gelir. Yalnızca en alt seviye Trendyol kategorileri seçilebilir.', 'product-push-for-trendyol' ) . ' <button type="button" class="button-link eru-ppt-cat-refresh">' . esc_html__( 'Kategori ağacını yenile', 'product-push-for-trendyol' ) . '</button> <span class="eru-ppt-msg" aria-live="polite"></span></p>';
		echo '<table class="widefat striped eru-ppt-repeater" data-repeater="catmap"><thead><tr><th>' . esc_html__( 'WooCommerce kategorisi', 'product-push-for-trendyol' ) . '</th><th>' . esc_html__( 'Trendyol kategorisi', 'product-push-for-trendyol' ) . '</th><th></th></tr></thead><tbody>';
		$i = 0;
		foreach ( (array) $s['category_map'] as $row ) {
			self::catmap_row( (string) $i++, $row, $cats );
		}
		echo '</tbody></table><template id="eru-ppt-tpl-catmap">';
		self::catmap_row( '__i__', array(), $cats );
		echo '</template><p><button type="button" class="button eru-ppt-add" data-target="catmap">' . esc_html__( 'Satır ekle', 'product-push-for-trendyol' ) . '</button></p>';

		// Özellik eşleme.
		echo '<h2>' . esc_html__( 'Özellik eşleme', 'product-push-for-trendyol' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'WooCommerce özelliğini (ör. pa_boyut) Trendyol özelliğine bağlar. Varyantlı ürünlerde her varyasyonun değeri buradan çevrilir. Değer satırları: "woocommerce-değer-kısa-adı=trendyol-değer-ID" (ör. "m=802"). Satırı olmayan değer, Trendyol\'da aynı adda bir değer varsa ona, özellik serbest değer kabul ediyorsa metin olarak gider; yoksa doğrulama hatası verir. Kategori boşsa eşleme tüm kategorilerde geçerlidir.', 'product-push-for-trendyol' ) . '</p>';
		echo '<table class="widefat striped eru-ppt-repeater" data-repeater="attrmap"><thead><tr><th>' . esc_html__( 'Trendyol kategorisi', 'product-push-for-trendyol' ) . '</th><th>' . esc_html__( 'WooCommerce özelliği', 'product-push-for-trendyol' ) . '</th><th>' . esc_html__( 'Trendyol özelliği', 'product-push-for-trendyol' ) . '</th><th>' . esc_html__( 'Değer eşleme', 'product-push-for-trendyol' ) . '</th><th></th></tr></thead><tbody>';
		$i = 0;
		foreach ( (array) $s['attribute_map'] as $row ) {
			self::attrmap_row( (string) $i++, $row );
		}
		echo '</tbody></table><template id="eru-ppt-tpl-attrmap">';
		self::attrmap_row( '__i__', array() );
		echo '</template><datalist id="eru-ppt-wc-attrs">';
		foreach ( wc_get_attribute_taxonomies() as $t ) {
			echo '<option value="' . esc_attr( 'pa_' . $t->attribute_name ) . '">' . esc_html( $t->attribute_label ) . '</option>';
		}
		echo '</datalist><p><button type="button" class="button eru-ppt-add" data-target="attrmap">' . esc_html__( 'Satır ekle', 'product-push-for-trendyol' ) . '</button></p>';

		submit_button( __( 'Kaydet', 'product-push-for-trendyol' ) );
		echo '</form>';

		// Durum ve günlük.
		$open = count( Eru_PPT_Pusher::batches() );
		echo '<h2>' . esc_html__( 'Durum', 'product-push-for-trendyol' ) . '</h2><p>';
		echo esc_html( $on ? __( 'Entegrasyon çalışıyor; açık batch\'ler 10 dakikada bir sorgulanır.', 'product-push-for-trendyol' ) : __( 'Entegrasyon kapalı.', 'product-push-for-trendyol' ) ) . ' ';
		/* translators: %d: open batches */
		echo esc_html( sprintf( __( 'Sonucu beklenen batch: %d.', 'product-push-for-trendyol' ), $open ) ) . '</p>';
		$rows = Eru_PPT_Log::rows();
		echo '<h2>' . esc_html__( 'Son istekler', 'product-push-for-trendyol' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'Henüz istek yok.', 'product-push-for-trendyol' ) . '</p>';
		} else {
			echo '<table class="widefat striped eru-ppt-log"><thead><tr><th>' . esc_html__( 'Zaman', 'product-push-for-trendyol' ) . '</th><th>' . esc_html__( 'İstek', 'product-push-for-trendyol' ) . '</th><th>HTTP</th><th>ms</th><th>' . esc_html__( 'Hata', 'product-push-for-trendyol' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 30 ) as $r ) {
				echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $r['t'] ) ) . '</td><td><code>' . esc_html( $r['method'] . ' ' . $r['path'] ) . '</code></td><td>' . (int) $r['code'] . '</td><td>' . (int) $r['ms'] . '</td><td>' . esc_html( (string) $r['error'] ) . '</td></tr>';
			}
			echo '</tbody></table><p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eru_ppt_clear_log' ), 'eru_ppt_clear_log' ) ) . '">' . esc_html__( 'Günlüğü temizle', 'product-push-for-trendyol' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** Text/number/url row. */
	private static function text_row( $k, $label, array $s, $type, $desc ) {
		$cls = 'url' === $type ? 'large-text code' : ( 'number' === $type ? 'small-text' : 'regular-text' );
		echo '<tr><th scope="row"><label for="eru-ppt-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="' . esc_attr( $type ) . '" class="' . esc_attr( $cls ) . '" id="eru-ppt-' . esc_attr( $k ) . '" name="eru_ppt[' . esc_attr( $k ) . ']" value="' . esc_attr( (string) $s[ $k ] ) . '"' . ( 'number' === $type ? ' min="0" step="1"' : '' ) . '>';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** @param string $i Index. @param array $row Row. @param array $cats WC categories. */
	private static function catmap_row( $i, array $row, array $cats ) {
		$n = 'eru_ppt[category_map][' . $i . ']';
		echo '<tr><td><select name="' . esc_attr( $n . '[wc]' ) . '" aria-label="' . esc_attr__( 'WooCommerce kategorisi', 'product-push-for-trendyol' ) . '"><option value="">—</option>';
		foreach ( $cats as $id => $name ) {
			echo '<option value="' . (int) $id . '"' . selected( (int) ( $row['wc'] ?? 0 ), $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></td><td>';
		self::picker( 'category', $n . '[ty]', $n . '[name]', (int) ( $row['ty'] ?? 0 ), (string) ( $row['name'] ?? '' ), __( 'Trendyol kategorisi ara', 'product-push-for-trendyol' ) );
		echo '</td><td><button type="button" class="button-link-delete eru-ppt-remove">' . esc_html__( 'Kaldır', 'product-push-for-trendyol' ) . '</button></td></tr>';
	}

	/** @param string $i Index. @param array $row Row. */
	private static function attrmap_row( $i, array $row ) {
		$n     = 'eru_ppt[attribute_map][' . $i . ']';
		$lines = array();
		foreach ( (array) ( $row['values'] ?? array() ) as $k => $v ) {
			$lines[] = $k . '=' . $v;
		}
		$cat  = (int) ( $row['cat'] ?? 0 );
		$node = $cat ? get_transient( 'eru_ppt_cat_' . Eru_PPT_Settings::environment() ) : false;
		$name = is_array( $node ) && isset( $node[ $cat ] ) ? $node[ $cat ]['p'] : '';
		echo '<tr><td>';
		self::picker( 'category', $n . '[cat]', $n . '[cat_name]', $cat, $name, __( 'Boş = tüm kategoriler', 'product-push-for-trendyol' ) );
		echo '</td><td><input type="text" list="eru-ppt-wc-attrs" name="' . esc_attr( $n . '[wc]' ) . '" value="' . esc_attr( (string) ( $row['wc'] ?? '' ) ) . '" placeholder="pa_boyut" aria-label="' . esc_attr__( 'WooCommerce özelliği', 'product-push-for-trendyol' ) . '"></td>';
		echo '<td class="eru-ppt-attrpick"><input type="number" min="1" class="small-text eru-ppt-attr-id" name="' . esc_attr( $n . '[ty]' ) . '" value="' . esc_attr( ! empty( $row['ty'] ) ? (string) $row['ty'] : '' ) . '" aria-label="' . esc_attr__( 'Trendyol özellik ID', 'product-push-for-trendyol' ) . '"> <button type="button" class="button-link eru-ppt-attr-load">' . esc_html__( 'Listeden seç', 'product-push-for-trendyol' ) . '</button><select class="eru-ppt-attr-select" hidden></select><div class="eru-ppt-attr-values description"></div></td>';
		echo '<td><textarea rows="3" cols="22" name="' . esc_attr( $n . '[values]' ) . '" placeholder="s=801&#10;m=802" aria-label="' . esc_attr__( 'Değer eşleme', 'product-push-for-trendyol' ) . '">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></td>';
		echo '<td><button type="button" class="button-link-delete eru-ppt-remove">' . esc_html__( 'Kaldır', 'product-push-for-trendyol' ) . '</button></td></tr>';
	}
}
