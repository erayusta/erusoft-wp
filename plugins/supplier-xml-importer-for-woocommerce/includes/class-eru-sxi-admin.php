<?php
/**
 * Admin screens: feed list, feed editor with field mapping, run panel and dry-run preview.
 *
 * @package supplier-xml-importer-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SXI_Admin {

	const PAGE = 'eru-sxi';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_eru_sxi_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_eru_sxi_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'wp_ajax_eru_sxi_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_eru_sxi_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_eru_sxi_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_eru_sxi_sample', array( __CLASS__, 'ajax_sample' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=product', __( 'Tedarikçi XML', 'supplier-xml-importer-for-woocommerce' ), __( 'Tedarikçi XML', 'supplier-xml-importer-for-woocommerce' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'eru-sxi-admin', ERU_SXI_URL . 'assets/admin.css', array(), ERU_SXI_VERSION );
		wp_enqueue_script( 'eru-sxi-admin', ERU_SXI_URL . 'assets/admin.js', array( 'jquery' ), ERU_SXI_VERSION, true );
		wp_localize_script(
			'eru-sxi-admin',
			'eruSxi',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'eru_sxi' ),
				'confirm' => __( 'Ürünler gerçekten oluşturulacak / güncellenecek. Devam edilsin mi?', 'supplier-xml-importer-for-woocommerce' ),
				'working' => __( 'İşleniyor: %1$d / %2$d', 'supplier-xml-importer-for-woocommerce' ),
				'staging' => __( 'XML okunuyor…', 'supplier-xml-importer-for-woocommerce' ),
				'final'   => __( 'Eksik ürünler işleniyor…', 'supplier-xml-importer-for-woocommerce' ),
				'failed'  => __( 'İşlem başarısız oldu.', 'supplier-xml-importer-for-woocommerce' ),
				'path'    => __( 'Yol', 'supplier-xml-importer-for-woocommerce' ),
				'value'   => __( 'Örnek değer', 'supplier-xml-importer-for-woocommerce' ),
			)
		);
	}

	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => 'product', 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'supplier-xml-importer-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	private static function ajax_guard() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'eru_sxi', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Yetkisiz istek.', 'supplier-xml-importer-for-woocommerce' ) ), 403 );
		}
		$id = isset( $_POST['feed'] ) ? sanitize_key( wp_unslash( $_POST['feed'] ) ) : '';
		if ( ! Eru_SXI_Feeds::get( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Besleme bulunamadı.', 'supplier-xml-importer-for-woocommerce' ) ), 404 );
		}
		return $id;
	}

	/* ---- form handlers ---- */

	public static function handle_save() {
		self::guard();
		check_admin_referer( 'eru_sxi_save' );
		$raw = isset( $_POST['feed'] ) && is_array( $_POST['feed'] ) ? wp_unslash( $_POST['feed'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_SXI_Feeds::sanitize().
		$id  = isset( $_POST['feed_id'] ) ? sanitize_key( wp_unslash( $_POST['feed_id'] ) ) : '';
		$old = $id ? Eru_SXI_Feeds::get( $id ) : null;
		$feed = Eru_SXI_Feeds::sanitize( $raw, $old ? $old : array() );
		$feed['id'] = $old ? $old['id'] : '';
		if ( '' === $feed['name'] ) {
			$feed['name'] = __( 'Adsız besleme', 'supplier-xml-importer-for-woocommerce' );
		}
		$msg = 'saved';
		if ( ! empty( $_FILES['feed_file']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['feed_file']['error'] && is_uploaded_file( $_FILES['feed_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$stored = Eru_SXI_Feeds::store_file( $_FILES['feed_file']['tmp_name'], sanitize_file_name( wp_unslash( $_FILES['feed_file']['name'] ) ), $feed['id'] ? $feed['id'] : 'new' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $stored ) ) {
				$msg = 'uploaderr';
			} else {
				Eru_SXI_Feeds::remove_file( $feed['file'] );
				$feed['file']   = $stored;
				$feed['source'] = 'file';
			}
		}
		$feed_id = Eru_SXI_Feeds::save( $feed );
		wp_safe_redirect( self::url( array( 'feed' => $feed_id, 'msg' => $msg ) ) );
		exit;
	}

	public static function handle_delete() {
		self::guard();
		check_admin_referer( 'eru_sxi_delete' );
		$id = isset( $_GET['feed'] ) ? sanitize_key( wp_unslash( $_GET['feed'] ) ) : '';
		if ( $id ) {
			Eru_SXI_Feeds::delete( $id );
		}
		wp_safe_redirect( self::url( array( 'msg' => 'deleted' ) ) );
		exit;
	}

	/* ---- AJAX ---- */

	private static function public_state( $s ) {
		if ( ! $s ) {
			return null;
		}
		return array(
			'status'   => $s['status'],
			'mode'     => $s['mode'],
			'total'    => (int) $s['total'],
			'pos'      => (int) $s['pos'],
			'done'     => array_sum( array_diff_key( $s['counts'], array( 'missing' => 0 ) ) ),
			'counts'   => $s['counts'],
			'messages' => array_slice( (array) $s['messages'], -5 ),
			'busy'     => ! empty( $s['busy'] ),
		);
	}

	public static function ajax_start() {
		$id   = self::ajax_guard();
		$mode = isset( $_POST['mode'] ) && 'live' === $_POST['mode'] ? 'live' : 'dry';
		$s    = Eru_SXI_Runner::start( $id, $mode );
		if ( is_wp_error( $s ) ) {
			wp_send_json_error( array( 'message' => $s->get_error_message() ) );
		}
		wp_send_json_success( self::public_state( $s ) );
	}

	public static function ajax_step() {
		$id = self::ajax_guard();
		wp_send_json_success( self::public_state( Eru_SXI_Runner::step( $id ) ) );
	}

	public static function ajax_cancel() {
		$id = self::ajax_guard();
		wp_send_json_success( self::public_state( Eru_SXI_Runner::cancel( $id ) ) );
	}

	public static function ajax_sample() {
		$id   = self::ajax_guard();
		$feed = Eru_SXI_Feeds::get( $id );
		if ( '' === $feed['item_path'] ) {
			wp_send_json_error( array( 'message' => __( 'Önce ürün öğesi (item) yolunu girip kaydedin.', 'supplier-xml-importer-for-woocommerce' ) ) );
		}
		$dir = Eru_SXI_Feeds::dir();
		if ( 'url' === $feed['source'] ) {
			$tmp = $dir . '/sample-' . wp_generate_password( 8, false ) . '.xml';
			$res = Eru_SXI_Feeds::fetch( $feed['url'], $tmp, (int) apply_filters( 'eru_sxi_sample_bytes', MB_IN_BYTES ) );
			if ( is_wp_error( $res ) ) {
				wp_send_json_error( array( 'message' => $res->get_error_message() ) );
			}
			$rows = Eru_SXI_Reader::sample( $tmp, $feed['item_path'] );
			wp_delete_file( $tmp );
		} else {
			$rows = Eru_SXI_Reader::sample( $dir . '/' . basename( $feed['file'] ), $feed['item_path'] );
		}
		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}
		if ( ! $rows ) {
			wp_send_json_error( array( 'message' => __( 'Bu yolda ürün öğesi bulunamadı.', 'supplier-xml-importer-for-woocommerce' ) ) );
		}
		wp_send_json_success( array( 'rows' => $rows ) );
	}

	/* ---- views ---- */

	public static function render() {
		self::guard();
		$id   = isset( $_GET['feed'] ) ? sanitize_key( wp_unslash( $_GET['feed'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = array(
			'saved'     => array( 'success', __( 'Besleme kaydedildi.', 'supplier-xml-importer-for-woocommerce' ) ),
			'deleted'   => array( 'success', __( 'Besleme silindi.', 'supplier-xml-importer-for-woocommerce' ) ),
			'uploaderr' => array( 'warning', __( 'Besleme kaydedildi ancak dosya yüklenemedi (yalnızca .xml, boyut sınırı içinde).', 'supplier-xml-importer-for-woocommerce' ) ),
		);
		$msg  = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap eru-sxi"><h1>' . esc_html__( 'Tedarikçi XML İçe Aktarma', 'supplier-xml-importer-for-woocommerce' ) . '</h1>';
		if ( isset( $msgs[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $msgs[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $msgs[ $msg ][1] ) . '</p></div>';
		}
		if ( '' === $id ) {
			self::view_list();
		} else {
			self::view_edit( 'new' === $id ? null : Eru_SXI_Feeds::get( $id ) );
		}
		echo '</div>';
	}

	private static function view_list() {
		$feeds = Eru_SXI_Feeds::all();
		echo '<p>' . esc_html__( 'Tedarikçi XML beslemelerinden ürün oluşturun ve güncelleyin. Hiçbir besleme tanımlı değilken eklenti hiçbir şey yapmaz.', 'supplier-xml-importer-for-woocommerce' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'feed' => 'new' ) ) ) . '">' . esc_html__( 'Yeni besleme ekle', 'supplier-xml-importer-for-woocommerce' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Besleme', 'supplier-xml-importer-for-woocommerce' ) . '</th><th>' . esc_html__( 'Kaynak', 'supplier-xml-importer-for-woocommerce' ) . '</th><th>' . esc_html__( 'Zamanlama', 'supplier-xml-importer-for-woocommerce' ) . '</th><th>' . esc_html__( 'Son çalıştırma', 'supplier-xml-importer-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		if ( ! $feeds ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Henüz besleme tanımlanmadı.', 'supplier-xml-importer-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( array_keys( $feeds ) as $fid ) {
			$f = Eru_SXI_Feeds::get( $fid );
			$s = Eru_SXI_Runner::state( $fid );
			echo '<tr><td><a href="' . esc_url( self::url( array( 'feed' => $fid ) ) ) . '"><strong>' . esc_html( $f['name'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( 'url' === $f['source'] ? __( 'Adres (URL)', 'supplier-xml-importer-for-woocommerce' ) : __( 'Yüklenen dosya', 'supplier-xml-importer-for-woocommerce' ) ) . '</td>';
			echo '<td>' . esc_html( self::schedule_label( $f['schedule'] ) ) . '</td>';
			echo '<td>' . ( $s ? esc_html( self::status_label( $s ) ) : '&mdash;' ) . '</td>';
			echo '<td><a href="' . esc_url( self::url( array( 'feed' => $fid ) ) ) . '">' . esc_html__( 'Düzenle', 'supplier-xml-importer-for-woocommerce' ) . '</a> | <a class="submitdelete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eru_sxi_delete&feed=' . $fid ), 'eru_sxi_delete' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Besleme silinsin mi? İçe aktarılmış ürünler kalır.', 'supplier-xml-importer-for-woocommerce' ) ) . '\')">' . esc_html__( 'Sil', 'supplier-xml-importer-for-woocommerce' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function schedule_label( $k ) {
		$l = array(
			'off'        => __( 'Kapalı', 'supplier-xml-importer-for-woocommerce' ),
			'hourly'     => __( 'Saatlik', 'supplier-xml-importer-for-woocommerce' ),
			'twicedaily' => __( 'Günde iki kez', 'supplier-xml-importer-for-woocommerce' ),
			'daily'      => __( 'Günlük', 'supplier-xml-importer-for-woocommerce' ),
		);
		return $l[ $k ] ?? $k;
	}

	private static function status_label( array $s ) {
		$l = array(
			'staging'    => __( 'XML okunuyor', 'supplier-xml-importer-for-woocommerce' ),
			'processing' => __( 'İşleniyor', 'supplier-xml-importer-for-woocommerce' ),
			'finalizing' => __( 'Sonlandırılıyor', 'supplier-xml-importer-for-woocommerce' ),
			'done'       => __( 'Tamamlandı', 'supplier-xml-importer-for-woocommerce' ),
			'failed'     => __( 'Başarısız', 'supplier-xml-importer-for-woocommerce' ),
			'cancelled'  => __( 'İptal edildi', 'supplier-xml-importer-for-woocommerce' ),
		);
		$when = $s['finished'] ? $s['finished'] : $s['started'];
		return ( 'dry' === $s['mode'] ? __( 'Kuru çalıştırma', 'supplier-xml-importer-for-woocommerce' ) : __( 'İçe aktarma', 'supplier-xml-importer-for-woocommerce' ) ) . ' — ' . ( $l[ $s['status'] ] ?? $s['status'] ) . ' — ' . wp_date( 'd.m.Y H:i', (int) $when );
	}

	private static function view_edit( $feed ) {
		$new  = ! $feed;
		$feed = $feed ? $feed : Eru_SXI_Feeds::defaults();
		$f    = fn( $k ) => esc_attr( (string) $feed[ $k ] );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Tüm beslemeler', 'supplier-xml-importer-for-woocommerce' ); ?></a></p>
		<?php
		if ( ! $new ) {
			foreach ( Eru_SXI_Feeds::problems( $feed ) as $problem ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html( $problem ) . '</p></div>';
			}
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="eru-sxi-form">
			<input type="hidden" name="action" value="eru_sxi_save" />
			<input type="hidden" name="feed_id" value="<?php echo esc_attr( $feed['id'] ); ?>" />
			<?php wp_nonce_field( 'eru_sxi_save' ); ?>

			<h2><?php esc_html_e( '1. Kaynak', 'supplier-xml-importer-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="eru-sxi-name"><?php esc_html_e( 'Besleme adı', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><input type="text" id="eru-sxi-name" class="regular-text" name="feed[name]" value="<?php echo esc_attr( $feed['name'] ); ?>" /></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Kaynak türü', 'supplier-xml-importer-for-woocommerce' ); ?></th>
					<td>
						<label><input type="radio" name="feed[source]" value="url" <?php checked( 'url', $feed['source'] ); ?> /> <?php esc_html_e( 'Adres (URL)', 'supplier-xml-importer-for-woocommerce' ); ?></label>
						<label style="margin-left:1em"><input type="radio" name="feed[source]" value="file" <?php checked( 'file', $feed['source'] ); ?> /> <?php esc_html_e( 'Yüklenen dosya', 'supplier-xml-importer-for-woocommerce' ); ?></label>
					</td></tr>
				<tr><th scope="row"><label for="eru-sxi-url"><?php esc_html_e( 'Besleme adresi', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><input type="url" id="eru-sxi-url" class="large-text" name="feed[url]" value="<?php echo esc_attr( $feed['url'] ); ?>" placeholder="https://example.com/feed.xml" />
						<p class="description"><?php esc_html_e( 'Yalnızca http/https; iç ağ adresleri engellenir. Adres, sunucudan indirilir.', 'supplier-xml-importer-for-woocommerce' ); ?></p></td></tr>
				<tr><th scope="row"><label for="eru-sxi-file"><?php esc_html_e( 'XML dosyası yükle', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><input type="file" id="eru-sxi-file" name="feed_file" accept=".xml,text/xml,application/xml" />
						<?php if ( $feed['file'] ) : ?><p class="description"><?php esc_html_e( 'Yüklü bir dosya var; yenisini seçerseniz değiştirilir.', 'supplier-xml-importer-for-woocommerce' ); ?></p><?php endif; ?>
						<p class="description"><?php /* translators: %s: size */ echo esc_html( sprintf( __( 'En fazla %s.', 'supplier-xml-importer-for-woocommerce' ), size_format( Eru_SXI_Feeds::max_bytes() ) ) ); ?></p></td></tr>
			</table>

			<h2><?php esc_html_e( '2. Alan eşleme', 'supplier-xml-importer-for-woocommerce' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Her ürün öğesine göre göreli XPath ya da eleman adı yazın (örn. sku, price, @id, images/image). Boş bırakılan alan içe aktarılmaz.', 'supplier-xml-importer-for-woocommerce' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="eru-sxi-item"><?php esc_html_e( 'Ürün öğesi (item) yolu', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><input type="text" id="eru-sxi-item" class="regular-text" name="feed[item_path]" value="<?php echo esc_attr( $feed['item_path'] ); ?>" placeholder="product" />
						<p class="description"><?php esc_html_e( 'Tekrarlanan ürün elemanının adı ("product") ya da kökten yol ("catalog/product").', 'supplier-xml-importer-for-woocommerce' ); ?></p></td></tr>
				<?php foreach ( Eru_SXI_Feeds::map_fields() as $key => $label ) : ?>
					<tr><th scope="row"><label for="eru-sxi-map-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input type="text" id="eru-sxi-map-<?php echo esc_attr( $key ); ?>" class="regular-text code" name="feed[map][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $feed['map'][ $key ] ); ?>" /></td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><label for="eru-sxi-attrs"><?php esc_html_e( 'Özellikler', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><textarea id="eru-sxi-attrs" class="large-text code" rows="3" name="feed[attrs]" placeholder="Renk=attributes/attribute[@name='Renk']"><?php echo esc_textarea( $feed['attrs'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satır: Özellik adı=XPath. Ana ürün SKU eşlemesi varsa bu değerler varyasyon özellikleri olur.', 'supplier-xml-importer-for-woocommerce' ); ?></p></td></tr>
				<tr><th scope="row"><label for="eru-sxi-sep"><?php esc_html_e( 'Kategori ayracı', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><input type="text" id="eru-sxi-sep" class="small-text" name="feed[cat_sep]" value="<?php echo esc_attr( $feed['cat_sep'] ); ?>" maxlength="3" />
						<span class="description"><?php esc_html_e( '"Ana > Alt" biçimindeki yollar bu ayraçla bölünür.', 'supplier-xml-importer-for-woocommerce' ); ?></span></td></tr>
				<tr><th scope="row"><label for="eru-sxi-catmap"><?php esc_html_e( 'Kategori eşleme', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><textarea id="eru-sxi-catmap" class="large-text code" rows="3" name="feed[cat_map]" placeholder="Kaynak kategori=Mağaza kategorisi"><?php echo esc_textarea( $feed['cat_map'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'İsteğe bağlı. Her satır: besleme kategorisi=mağaza kategori yolu.', 'supplier-xml-importer-for-woocommerce' ); ?></p></td></tr>
			</table>
			<?php if ( ! $new ) : ?>
				<p><button type="button" class="button" id="eru-sxi-sample" data-feed="<?php echo esc_attr( $feed['id'] ); ?>"><?php esc_html_e( 'Örnek ürünü göster (eşleme yardımcısı)', 'supplier-xml-importer-for-woocommerce' ); ?></button></p>
				<div id="eru-sxi-sample-out"></div>
			<?php endif; ?>

			<h2><?php esc_html_e( '3. Fiyat kuralları', 'supplier-xml-importer-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="eru-sxi-rate"><?php esc_html_e( 'Sabit kur / çarpan', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><input type="text" id="eru-sxi-rate" class="small-text" name="feed[rate]" value="<?php echo esc_attr( (string) $feed['rate'] ); ?>" />
						<span class="description"><?php esc_html_e( 'Besleme fiyatı bu sayıyla çarpılır (1 = değişmez). Kur otomatik çekilmez.', 'supplier-xml-importer-for-woocommerce' ); ?></span></td></tr>
				<tr><th scope="row"><label for="eru-sxi-markup"><?php esc_html_e( 'Kâr marjı (%)', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><input type="text" id="eru-sxi-markup" class="small-text" name="feed[markup]" value="<?php echo esc_attr( (string) $feed['markup'] ); ?>" /></td></tr>
				<tr><th scope="row"><label for="eru-sxi-round"><?php esc_html_e( 'Yuvarlama', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><select id="eru-sxi-round" name="feed[rounding]">
						<option value="none" <?php selected( 'none', $feed['rounding'] ); ?>><?php esc_html_e( 'Yok (2 ondalık)', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="90" <?php selected( '90', $feed['rounding'] ); ?>><?php esc_html_e( 'En yakın ,90', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="99" <?php selected( '99', $feed['rounding'] ); ?>><?php esc_html_e( 'En yakın ,99', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="int" <?php selected( 'int', $feed['rounding'] ); ?>><?php esc_html_e( 'Tam sayıya', 'supplier-xml-importer-for-woocommerce' ); ?></option>
					</select></td></tr>
			</table>

			<h2><?php esc_html_e( '4. İçe aktarma seçenekleri', 'supplier-xml-importer-for-woocommerce' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="eru-sxi-mode"><?php esc_html_e( 'Mod', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><select id="eru-sxi-mode" name="feed[mode]">
						<option value="create_update" <?php selected( 'create_update', $feed['mode'] ); ?>><?php esc_html_e( 'Oluştur ve güncelle', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="update_only" <?php selected( 'update_only', $feed['mode'] ); ?>><?php esc_html_e( 'Yalnızca var olanları güncelle', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="create_only" <?php selected( 'create_only', $feed['mode'] ); ?>><?php esc_html_e( 'Yalnızca yenileri oluştur', 'supplier-xml-importer-for-woocommerce' ); ?></option>
					</select></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Var olan ürünlerde güncellenecek alanlar', 'supplier-xml-importer-for-woocommerce' ); ?></th>
					<td><?php foreach ( Eru_SXI_Feeds::update_fields() as $k => $label ) : ?>
						<label style="margin-right:1em"><input type="checkbox" name="feed[update_fields][]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $feed['update_fields'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?></td></tr>
				<tr><th scope="row"><label for="eru-sxi-missing"><?php esc_html_e( 'Beslemeden kaybolan ürünler', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><select id="eru-sxi-missing" name="feed[missing]">
						<option value="ignore" <?php selected( 'ignore', $feed['missing'] ); ?>><?php esc_html_e( 'Dokunma', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="outofstock" <?php selected( 'outofstock', $feed['missing'] ); ?>><?php esc_html_e( 'Stokta yok yap', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="draft" <?php selected( 'draft', $feed['missing'] ); ?>><?php esc_html_e( 'Taslağa al', 'supplier-xml-importer-for-woocommerce' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Yalnızca bu beslemeyle oluşturulmuş / eşleşmiş ürünler etkilenir; hiç ürün okunamayan çalıştırmalarda uygulanmaz.', 'supplier-xml-importer-for-woocommerce' ); ?></p></td></tr>
				<tr><th scope="row"><label for="eru-sxi-status"><?php esc_html_e( 'Yeni ürün durumu', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><select id="eru-sxi-status" name="feed[new_status]">
						<option value="draft" <?php selected( 'draft', $feed['new_status'] ); ?>><?php esc_html_e( 'Taslak', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="publish" <?php selected( 'publish', $feed['new_status'] ); ?>><?php esc_html_e( 'Yayında', 'supplier-xml-importer-for-woocommerce' ); ?></option>
						<option value="private" <?php selected( 'private', $feed['new_status'] ); ?>><?php esc_html_e( 'Özel', 'supplier-xml-importer-for-woocommerce' ); ?></option>
					</select></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Görseller', 'supplier-xml-importer-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="feed[images]" value="1" <?php checked( ! empty( $feed['images'] ) ); ?> /> <?php esc_html_e( 'Görselleri beslemedeki adreslerden medya kütüphanesine indir', 'supplier-xml-importer-for-woocommerce' ); ?></label>
					<p class="description"><?php esc_html_e( 'Aynı adres ikinci kez indirilmez.', 'supplier-xml-importer-for-woocommerce' ); ?></p></td></tr>
				<tr><th scope="row"><label for="eru-sxi-sched"><?php esc_html_e( 'Zamanlama', 'supplier-xml-importer-for-woocommerce' ); ?></label></th>
					<td><select id="eru-sxi-sched" name="feed[schedule]">
						<?php foreach ( array( 'off', 'hourly', 'twicedaily', 'daily' ) as $k ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $feed['schedule'] ); ?>><?php echo esc_html( self::schedule_label( $k ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Zamanlanmış çalıştırmalar WooCommerce ile gelen Action Scheduler üzerinden, küçük gruplar halinde yürür.', 'supplier-xml-importer-for-woocommerce' ); ?></p></td></tr>
			</table>
			<?php submit_button( $new ? __( 'Beslemeyi oluştur', 'supplier-xml-importer-for-woocommerce' ) : __( 'Kaydet', 'supplier-xml-importer-for-woocommerce' ) ); ?>
		</form>
		<?php
		if ( ! $new ) {
			self::run_panel( $feed );
		}
	}

	private static function run_panel( array $feed ) {
		$s = Eru_SXI_Runner::state( $feed['id'] );
		?>
		<hr />
		<h2><?php esc_html_e( '5. Çalıştır', 'supplier-xml-importer-for-woocommerce' ); ?></h2>
		<p><?php esc_html_e( 'Önce kuru çalıştırma yapın: hiçbir ürün değişmez, ne olacağını aşağıdaki tabloda görürsünüz. Ayarları değiştirdiyseniz önce kaydedin.', 'supplier-xml-importer-for-woocommerce' ); ?></p>
		<p class="eru-sxi-actions" data-feed="<?php echo esc_attr( $feed['id'] ); ?>" data-active="<?php echo esc_attr( Eru_SXI_Runner::is_active( $s ) ? '1' : '0' ); ?>">
			<button type="button" class="button" data-mode="dry"><?php esc_html_e( 'Kuru çalıştır (önizleme)', 'supplier-xml-importer-for-woocommerce' ); ?></button>
			<button type="button" class="button button-primary" data-mode="live"><?php esc_html_e( 'İçe aktar', 'supplier-xml-importer-for-woocommerce' ); ?></button>
			<button type="button" class="button" id="eru-sxi-cancel" hidden><?php esc_html_e( 'İptal', 'supplier-xml-importer-for-woocommerce' ); ?></button>
		</p>
		<div id="eru-sxi-progress" hidden><progress max="100" value="0"></progress> <span class="eru-sxi-ptext"></span></div>
		<div id="eru-sxi-error" class="notice notice-error inline" hidden><p></p></div>
		<?php
		if ( $s ) {
			self::result( $s );
		}
	}

	private static function result( array $s ) {
		$c     = $s['counts'];
		$dry   = 'dry' === $s['mode'];
		$names = array(
			'create'    => $dry ? __( 'Oluşturulacak', 'supplier-xml-importer-for-woocommerce' ) : __( 'Oluşturuldu', 'supplier-xml-importer-for-woocommerce' ),
			'update'    => $dry ? __( 'Güncellenecek', 'supplier-xml-importer-for-woocommerce' ) : __( 'Güncellendi', 'supplier-xml-importer-for-woocommerce' ),
			'unchanged' => __( 'Değişmedi', 'supplier-xml-importer-for-woocommerce' ),
			'skip'      => __( 'Atlandı', 'supplier-xml-importer-for-woocommerce' ),
			'error'     => __( 'Hata', 'supplier-xml-importer-for-woocommerce' ),
			'missing'   => __( 'Beslemede yok', 'supplier-xml-importer-for-woocommerce' ),
		);
		echo '<div id="eru-sxi-result"><h3>' . esc_html( self::status_label( $s ) ) . '</h3>';
		foreach ( (array) $s['messages'] as $m ) {
			echo '<div class="notice notice-' . ( 'failed' === $s['status'] ? 'error' : 'warning' ) . ' inline"><p>' . esc_html( $m ) . '</p></div>';
		}
		echo '<p class="eru-sxi-counts">';
		foreach ( $names as $k => $label ) {
			echo '<span class="eru-sxi-chip eru-sxi-' . esc_attr( $k ) . '"><strong>' . esc_html( (string) (int) $c[ $k ] ) . '</strong> ' . esc_html( $label ) . '</span> ';
		}
		echo '</p>';
		if ( $s['preview'] ) {
			$act = array(
				'create'    => __( 'Oluştur', 'supplier-xml-importer-for-woocommerce' ),
				'update'    => __( 'Güncelle', 'supplier-xml-importer-for-woocommerce' ),
				'unchanged' => __( 'Değişmedi', 'supplier-xml-importer-for-woocommerce' ),
				'skip'      => __( 'Atla', 'supplier-xml-importer-for-woocommerce' ),
				'error'     => __( 'Hata', 'supplier-xml-importer-for-woocommerce' ),
			);
			echo '<table class="widefat striped eru-sxi-preview"><thead><tr><th>SKU</th><th>' . esc_html__( 'Ürün', 'supplier-xml-importer-for-woocommerce' ) . '</th><th>' . esc_html__( 'İşlem', 'supplier-xml-importer-for-woocommerce' ) . '</th><th>' . esc_html__( 'Fiyat', 'supplier-xml-importer-for-woocommerce' ) . '</th><th>' . esc_html__( 'Stok', 'supplier-xml-importer-for-woocommerce' ) . '</th><th>' . esc_html__( 'Not', 'supplier-xml-importer-for-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $s['preview'] as $r ) {
				echo '<tr><td><code>' . esc_html( $r['sku'] ) . '</code></td><td>' . esc_html( $r['name'] ) . '</td>';
				echo '<td><span class="eru-sxi-tag eru-sxi-' . esc_attr( $r['action'] ) . '">' . esc_html( $act[ $r['action'] ] ?? $r['action'] ) . '</span></td>';
				echo '<td>' . esc_html( self::delta( $r['price_old'], $r['price_new'] ) ) . '</td><td>' . esc_html( self::delta( $r['stock_old'], $r['stock_new'] ) ) . '</td><td>' . esc_html( $r['note'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private static function delta( $old, $new ) {
		if ( null === $new || '' === $new ) {
			return '—';
		}
		if ( null === $old || '' === $old ) {
			return (string) $new;
		}
		return (string) $old === (string) $new ? (string) $new : $old . ' → ' . $new;
	}
}
