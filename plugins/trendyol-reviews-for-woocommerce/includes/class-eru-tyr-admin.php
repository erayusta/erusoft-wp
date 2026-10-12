<?php
/**
 * Admin page: Ürünler → Trendyol Yorumları.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tabs: Mağaza (store import, results, unmatched list with inline mapping,
 * mapping table), URL / CSV, Ayarlar, Kayıt. Every write is a nonce-checked
 * admin-post or AJAX request limited to `manage_woocommerce`.
 */
class Eru_TYR_Admin {

	const PAGE               = 'eru-tyr';
	const CAP                = 'manage_woocommerce';
	const NONCE              = 'eru_tyr_admin';
	const SCREEN             = 'product_page_eru-tyr';
	const UNMATCHED_PER_PAGE = 50;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ERU_TYR_FILE ), array( __CLASS__, 'action_links' ) );
		// Deactivation clears the daily event; restore it when the setting is on.
		add_action( 'admin_init', array( 'Eru_TYR_Store_Import', 'sync_schedule' ) );

		add_action( 'admin_post_eru_tyr_start', array( __CLASS__, 'post_start' ) );
		add_action( 'admin_post_eru_tyr_url', array( __CLASS__, 'post_url' ) );
		add_action( 'admin_post_eru_tyr_csv', array( __CLASS__, 'post_csv' ) );
		add_action( 'admin_post_eru_tyr_save', array( __CLASS__, 'post_save' ) );
		add_action( 'wp_ajax_eru_tyr_map', array( __CLASS__, 'ajax_map' ) );
		add_action( 'wp_ajax_eru_tyr_unmap', array( __CLASS__, 'ajax_unmap' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Trendyol Yorumları', 'trendyol-reviews-for-woocommerce' ),
			__( 'Trendyol Yorumları', 'trendyol-reviews-for-woocommerce' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function screen_ids( $ids ) {
		$ids[] = self::SCREEN;
		return $ids;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Trendyol Yorumları', 'trendyol-reviews-for-woocommerce' ) . '</a>' );
		return $links;
	}

	public static function enqueue( $hook ): void {
		if ( self::SCREEN !== $hook ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'eru-tyr-admin', ERU_TYR_URL . 'assets/admin/admin.css', array(), ERU_TYR_VERSION );
		wp_enqueue_script( 'eru-tyr-admin', ERU_TYR_URL . 'assets/admin/admin.js', array( 'jquery', 'wc-enhanced-select' ), ERU_TYR_VERSION, true );
		wp_localize_script(
			'eru-tyr-admin',
			'eruTyrAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'pick'    => __( 'Önce bir WooCommerce ürünü seçin.', 'trendyol-reviews-for-woocommerce' ),
					'working' => __( 'Kaydediliyor…', 'trendyol-reviews-for-woocommerce' ),
					'error'   => __( 'Hata:', 'trendyol-reviews-for-woocommerce' ),
					'confirm' => __( 'Bu eşleştirme kaldırılsın mı? İçe aktarılmış yorumlar silinmez.', 'trendyol-reviews-for-woocommerce' ),
				),
			)
		);
	}

	protected static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'post_type' => 'product', 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------- */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = array(
			'store'    => __( 'Mağaza', 'trendyol-reviews-for-woocommerce' ),
			'url'      => __( 'URL / CSV', 'trendyol-reviews-for-woocommerce' ),
			'settings' => __( 'Ayarlar', 'trendyol-reviews-for-woocommerce' ),
			'log'      => __( 'Kayıt', 'trendyol-reviews-for-woocommerce' ),
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'store'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'store';
		?>
		<div class="wrap eru-tyr-wrap">
			<h1><?php esc_html_e( 'Trendyol Yorumları', 'trendyol-reviews-for-woocommerce' ); ?></h1>
			<?php self::print_notice(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( array( 'tab' => $key ) ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'url':
					self::render_url_tab();
					break;
				case 'settings':
					self::render_settings_tab();
					break;
				case 'log':
					self::render_log_tab();
					break;
				default:
					self::render_store_tab();
			}
			?>
			<p class="eru-tyr-legal description">
				<?php esc_html_e( 'Yalnızca kendi Trendyol mağazanızın ürün yorumlarını içe aktarın. Trendyol, sahibinin tescilli markasıdır; bu eklentinin Trendyol ile bir bağlantısı yoktur. Ürün listesi resmi Satıcı API\'sinden, yorumlar Trendyol\'un herkese açık ancak belgelenmemiş yorum uç noktasından okunur.', 'trendyol-reviews-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}

	protected static function render_store_tab(): void {
		$seller     = (string) Eru_TYR_Settings::get( 'seller_id' );
		$has_secret = '' !== Eru_TYR_Settings::get_secret();
		$run        = Eru_TYR_Store_Import::get_run();
		if ( 'error' === ( $run['status'] ?? '' ) && ! empty( $run['message'] ) ) {
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( (string) $run['message'] ) );
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eru-tyr-start">
			<input type="hidden" name="action" value="eru_tyr_start" />
			<?php wp_nonce_field( 'eru_tyr_start' ); ?>
			<p class="description"><?php esc_html_e( 'Mağaza içe aktarımı ürün listenizi Trendyol Satıcı API\'sinden alır. Bilgiler Trendyol Satıcı Paneli → Hesap Bilgileri → Entegrasyon Bilgileri sayfasındadır. Tek ürün ve CSV içe aktarımı için API bilgisi gerekmez.', 'trendyol-reviews-for-woocommerce' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="eru-tyr-seller"><?php esc_html_e( 'Satıcı ID', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td><input name="seller_id" id="eru-tyr-seller" type="text" inputmode="numeric" pattern="[0-9]{1,12}" class="regular-text" placeholder="000000" value="<?php echo esc_attr( $seller ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="eru-tyr-key"><?php esc_html_e( 'API Key', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td><input name="api_key" id="eru-tyr-key" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( (string) Eru_TYR_Settings::get( 'api_key' ) ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="eru-tyr-secret"><?php esc_html_e( 'API Secret', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td>
						<input name="api_secret" id="eru-tyr-secret" type="password" class="regular-text" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( $has_secret ? __( '•••••••• kayıtlı — değiştirmek için yazın', 'trendyol-reviews-for-woocommerce' ) : '' ); ?>" <?php echo $has_secret ? '' : 'required'; ?> />
						<?php submit_button( __( 'İçe aktar', 'trendyol-reviews-for-woocommerce' ), 'primary', 'submit', false ); ?>
						<p class="description"><?php esc_html_e( 'Ürünler sayfa sayfa arka planda (Action Scheduler) listelenir; barkod veya SKU ile eşleşen her ürünün yorumları ayrı bir işte içe aktarılır, eşleşmeyenler aşağıda listelenir.', 'trendyol-reviews-for-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
		</form>

		<h2><?php esc_html_e( 'Son içe aktarım', 'trendyol-reviews-for-woocommerce' ); ?></h2>
		<?php if ( ! $run ) : ?>
			<p><?php esc_html_e( 'Henüz içe aktarım yapılmadı.', 'trendyol-reviews-for-woocommerce' ); ?></p>
		<?php else : ?>
			<?php
			$statuses = array(
				'queued'  => __( 'Kuyrukta', 'trendyol-reviews-for-woocommerce' ),
				'running'   => __( 'Ürünler listeleniyor', 'trendyol-reviews-for-woocommerce' ),
				'importing' => __( 'Yorumlar içe aktarılıyor', 'trendyol-reviews-for-woocommerce' ),
				'done'    => __( 'Tamamlandı', 'trendyol-reviews-for-woocommerce' ),
				'error'   => __( 'Hata', 'trendyol-reviews-for-woocommerce' ),
			);
			$status   = (string) ( $run['status'] ?? '' );
			$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$rows     = array(
				__( 'Satıcı ID', 'trendyol-reviews-for-woocommerce' )         => (string) ( $run['seller_id'] ?? '' ),
				__( 'Durum', 'trendyol-reviews-for-woocommerce' )             => $statuses[ $status ] ?? $status,
				__( 'İşlenen sayfa', 'trendyol-reviews-for-woocommerce' )     => number_format_i18n( (int) ( $run['pages'] ?? 0 ) ),
				__( 'Trendyol ürünü', 'trendyol-reviews-for-woocommerce' )    => number_format_i18n( (int) ( $run['products'] ?? 0 ) ),
				__( 'İşlenen ürün', 'trendyol-reviews-for-woocommerce' )      => number_format_i18n( (int) ( $run['processed'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $run['queued'] ?? 0 ) ),
				__( 'Yeni yorum', 'trendyol-reviews-for-woocommerce' )        => number_format_i18n( (int) ( $run['imported'] ?? 0 ) ),
				__( 'Atlanan yorum', 'trendyol-reviews-for-woocommerce' )     => number_format_i18n( (int) ( $run['skipped'] ?? 0 ) ),
				__( 'Eşleşmeyen ürün', 'trendyol-reviews-for-woocommerce' )   => number_format_i18n( (int) ( $run['unmatched'] ?? 0 ) ),
				__( 'Hata', 'trendyol-reviews-for-woocommerce' )              => number_format_i18n( (int) ( $run['errors'] ?? 0 ) ),
				__( 'Başlangıç', 'trendyol-reviews-for-woocommerce' )         => ! empty( $run['started'] ) ? wp_date( $format, (int) $run['started'] ) : '—',
				__( 'Bitiş', 'trendyol-reviews-for-woocommerce' )             => ! empty( $run['finished'] ) ? wp_date( $format, (int) $run['finished'] ) : '—',
			);
			if ( ! empty( $run['message'] ) ) {
				$rows[ __( 'Mesaj', 'trendyol-reviews-for-woocommerce' ) ] = (string) $run['message'];
			}
			?>
			<table class="widefat striped eru-tyr-results">
				<tbody>
					<?php foreach ( $rows as $label => $value ) : ?>
						<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td class="eru-tyr-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $value ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( in_array( $status, array( 'queued', 'running', 'importing' ), true ) ) : ?>
				<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Durumu yenile', 'trendyol-reviews-for-woocommerce' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		self::render_unmatched();
		self::render_map();
	}

	protected static function render_unmatched(): void {
		$all   = Eru_TYR_Store_Import::get_unmatched();
		$total = count( $all );
		$pages = max( 1, (int) ceil( $total / self::UNMATCHED_PER_PAGE ) );
		$page  = isset( $_GET['upage'] ) ? min( $pages, max( 1, absint( $_GET['upage'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$items = array_slice( $all, ( $page - 1 ) * self::UNMATCHED_PER_PAGE, self::UNMATCHED_PER_PAGE, true );
		?>
		<h2>
			<?php
			printf(
				/* translators: %s: number of unmatched products */
				esc_html__( 'Eşleşmeyen ürünler (%s)', 'trendyol-reviews-for-woocommerce' ),
				esc_html( number_format_i18n( $total ) )
			);
			?>
		</h2>
		<p class="description"><?php esc_html_e( 'Barkod veya SKU ile bulunamayan Trendyol ürünleri. Bir WooCommerce ürünü seçip "Eşleştir"e basın; eşleştirme kaydedilir ve o ürünün yorumları hemen kuyruğa alınır.', 'trendyol-reviews-for-woocommerce' ); ?></p>
		<table class="widefat striped eru-tyr-unmatched">
			<thead><tr>
				<th><?php esc_html_e( 'Trendyol ürünü', 'trendyol-reviews-for-woocommerce' ); ?></th>
				<th class="eru-tyr-col-codes"><?php esc_html_e( 'Barkod / kod', 'trendyol-reviews-for-woocommerce' ); ?></th>
				<th class="eru-tyr-col-pick"><?php esc_html_e( 'WooCommerce ürünü', 'trendyol-reviews-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $items ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Eşleşmeyen ürün yok.', 'trendyol-reviews-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $items as $content_id => $item ) : ?>
					<tr data-content="<?php echo esc_attr( (string) $content_id ); ?>">
						<td>
							<?php if ( ! empty( $item['url'] ) ) : ?>
								<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['title'] ?? '' ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $item['title'] ?? '' ); ?>
							<?php endif; ?>
							<br /><span class="eru-tyr-code">#<?php echo esc_html( (string) $content_id ); ?></span>
						</td>
						<td class="eru-tyr-code"><?php echo esc_html( trim( ( $item['barcode'] ?? '' ) . ( ! empty( $item['sku'] ) ? ' / ' . $item['sku'] : '' ), ' /' ) ); ?></td>
						<td class="eru-tyr-pick">
							<select class="wc-product-search eru-tyr-product" style="width:260px" data-placeholder="<?php esc_attr_e( 'Ürün arayın…', 'trendyol-reviews-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products" data-allow_clear="true"></select>
							<button type="button" class="button eru-tyr-map-btn"><?php esc_html_e( 'Eşleştir', 'trendyol-reviews-for-woocommerce' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => esc_url_raw( self::url( array( 'upage' => '%#%' ) ) ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
	}

	protected static function render_map(): void {
		$map = Eru_TYR_Matcher::get_map();
		?>
		<h2>
			<?php
			printf(
				/* translators: %s: number of manual mappings */
				esc_html__( 'Eşleştirme tablosu (%s)', 'trendyol-reviews-for-woocommerce' ),
				esc_html( number_format_i18n( count( $map ) ) )
			);
			?>
		</h2>
		<p class="description"><?php esc_html_e( 'Elle yapılan eşleştirmeler barkod ve SKU eşleşmesinden önce gelir.', 'trendyol-reviews-for-woocommerce' ); ?></p>
		<table class="widefat striped eru-tyr-map">
			<thead><tr>
				<th><?php esc_html_e( 'Trendyol ürün no', 'trendyol-reviews-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'WooCommerce ürünü', 'trendyol-reviews-for-woocommerce' ); ?></th>
				<th class="eru-tyr-col-action"></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $map ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Henüz elle eşleştirme yok.', 'trendyol-reviews-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( array_slice( $map, 0, 500, true ) as $content_id => $product_id ) : ?>
					<tr data-content="<?php echo esc_attr( (string) $content_id ); ?>">
						<td class="eru-tyr-code"><?php echo esc_html( (string) $content_id ); ?></td>
						<td>
							<?php if ( 'product' === get_post_type( $product_id ) ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $product_id ) ); ?>"><?php echo esc_html( get_the_title( $product_id ) ); ?></a>
							<?php else : ?>
								<?php esc_html_e( '(silinmiş ürün)', 'trendyol-reviews-for-woocommerce' ); ?>
							<?php endif; ?>
						</td>
						<td><button type="button" class="button-link-delete eru-tyr-unmap-btn"><?php esc_html_e( 'Kaldır', 'trendyol-reviews-for-woocommerce' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	protected static function render_url_tab(): void {
		?>
		<h2><?php esc_html_e( 'Tek ürün (Trendyol linki)', 'trendyol-reviews-for-woocommerce' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eru_tyr_url" />
			<?php wp_nonce_field( 'eru_tyr_url' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="eru-tyr-url"><?php esc_html_e( 'Trendyol ürün linki', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td><input name="trendyol" id="eru-tyr-url" type="text" class="large-text" placeholder="https://www.trendyol.com/marka/urun-adi-p-123456" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="eru-tyr-url-product"><?php esc_html_e( 'WooCommerce ürünü', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td><select name="product_id" id="eru-tyr-url-product" class="wc-product-search" style="width:360px" data-placeholder="<?php esc_attr_e( 'Ürün arayın…', 'trendyol-reviews-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products" required></select></td>
				</tr>
			</table>
			<?php submit_button( __( 'Yorumları içe aktar', 'trendyol-reviews-for-woocommerce' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Toplu (CSV)', 'trendyol-reviews-for-woocommerce' ); ?></h2>
		<p class="description"><?php esc_html_e( 'İki sütun: 1) WooCommerce ürün ID, ürün linki veya SKU, 2) Trendyol ürün linki veya ürün numarası. Virgül, noktalı virgül veya sekme ile ayrılmış olabilir; başlık satırı yok sayılır. En fazla 2 MB ve 1000 satır. Her satır eşleştirme tablosuna yazılır ve arka planda içe aktarılır.', 'trendyol-reviews-for-woocommerce' ); ?></p>
		<pre class="eru-tyr-example">urun,trendyol
123,https://www.trendyol.com/demo-marka/ornek-urun-p-900001
DM-KP-02,900002</pre>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eru_tyr_csv" />
			<?php wp_nonce_field( 'eru_tyr_csv' ); ?>
			<input type="file" name="eru_tyr_csv" accept=".csv,text/csv" required />
			<?php submit_button( __( 'CSV yükle ve kuyruğa al', 'trendyol-reviews-for-woocommerce' ), 'secondary' ); ?>
		</form>
		<?php
	}

	protected static function render_settings_tab(): void {
		$s = Eru_TYR_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eru_tyr_save" />
			<?php wp_nonce_field( 'eru_tyr_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="eru-tyr-barcode-meta"><?php esc_html_e( 'Barkod meta anahtarı', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td>
						<input name="barcode_meta" id="eru-tyr-barcode-meta" type="text" class="regular-text code" value="<?php echo esc_attr( $s['barcode_meta'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Ürün barkodunun tutulduğu meta alanı. Varsayılan _global_unique_id (WooCommerce GTIN/EAN alanı).', 'trendyol-reviews-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="eru-tyr-max"><?php esc_html_e( 'Ürün başına en fazla yorum', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td><input name="max_reviews" id="eru-tyr-max" type="number" min="1" max="1000" value="<?php echo esc_attr( (string) $s['max_reviews'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="eru-tyr-min"><?php esc_html_e( 'İçe aktarılacak en düşük puan', 'trendyol-reviews-for-woocommerce' ); ?></label></th>
					<td>
						<select name="min_rating" id="eru-tyr-min">
							<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
								<option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( (int) $s['min_rating'], $i ); ?>><?php echo esc_html( (string) $i ); ?> ★</option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Yorum fotoğrafları', 'trendyol-reviews-for-woocommerce' ); ?></th>
					<td>
						<label><input type="checkbox" name="import_photos" value="1" <?php checked( $s['import_photos'] ); ?> /> <?php esc_html_e( 'Müşteri fotoğraflarını (yalnızca Trendyol CDN) uploads/trendyol-reviews/ klasörüne indir ve göster', 'trendyol-reviews-for-woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'Fotoğraflarda kişisel veri (yüz, adres, etiket) bulunabilir; açmadan önce aydınlatma metninizi kontrol edin.', 'trendyol-reviews-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Yapısal veri', 'trendyol-reviews-for-woocommerce' ); ?></th>
					<td>
						<label><input type="checkbox" name="structured_data" value="1" <?php checked( $s['structured_data'] ); ?> /> <?php esc_html_e( 'İçe aktarılan yorumları ürün yapısal verisine (puan ortalaması, yorumlar) dahil et', 'trendyol-reviews-for-woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'Varsayılan kapalı: Google, başka sitelerden toplanan yorumların yorum zengin sonuçlarında kullanılmasına izin vermez.', 'trendyol-reviews-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Günlük senkron', 'trendyol-reviews-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="sync_enabled" value="1" <?php checked( $s['sync_enabled'] ); ?> /> <?php esc_html_e( 'Her gün yeni yorumları çek (API bilgileri kayıtlıysa tüm mağaza, değilse eşleştirme tablosundaki ürünler)', 'trendyol-reviews-for-woocommerce' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Kaldırırken', 'trendyol-reviews-for-woocommerce' ); ?></th>
					<td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?> /> <?php esc_html_e( 'Eklenti silinirken içe aktarılan tüm yorumları da sil', 'trendyol-reviews-for-woocommerce' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'İsim maskeleme', 'trendyol-reviews-for-woocommerce' ); ?></th>
					<td><p class="description"><?php esc_html_e( 'Yorumcu isimleri her zaman maskelenerek kaydedilir (ör. "A*** Y***"); açık isim veritabanına yazılmaz.', 'trendyol-reviews-for-woocommerce' ); ?></p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	protected static function render_log_tab(): void {
		$entries = array_reverse( Eru_TYR_Logger::all() );
		?>
		<table class="widefat striped eru-tyr-log">
			<thead><tr>
				<th class="eru-tyr-col-time"><?php esc_html_e( 'Zaman', 'trendyol-reviews-for-woocommerce' ); ?></th>
				<th class="eru-tyr-col-level"><?php esc_html_e( 'Seviye', 'trendyol-reviews-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Mesaj', 'trendyol-reviews-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
				<?php if ( ! $entries ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Henüz kayıt yok.', 'trendyol-reviews-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $entries as $entry ) : ?>
					<tr class="eru-tyr-log-<?php echo esc_attr( (string) ( $entry['level'] ?? 'info' ) ); ?>">
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['level'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	protected static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'trendyol-reviews-for-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	protected static function redirect( string $tab, string $type, string $message ): void {
		set_transient( 'eru_tyr_notice_' . get_current_user_id(), array( $type, $message ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'tab' => $tab ) ) );
		exit;
	}

	protected static function print_notice(): void {
		$key    = 'eru_tyr_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		$type = in_array( $notice[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice[0] : 'info';
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( (string) $notice[1] ) );
	}

	public static function post_start(): void {
		self::guard( 'eru_tyr_start' );
		$seller = Eru_TYR_Settings::sanitize_seller_id( isset( $_POST['seller_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seller_id'] ) ) : '' );
		if ( '' === $seller ) {
			self::redirect( 'store', 'error', __( 'Geçersiz Satıcı ID. Trendyol Satıcı ID yalnızca rakamlardan oluşur.', 'trendyol-reviews-for-woocommerce' ) );
		}
		Eru_TYR_Settings::update(
			array(
				'seller_id' => $seller,
				'api_key'   => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
			)
		);
		$secret = isset( $_POST['api_secret'] ) ? Eru_TYR_Settings::sanitize_secret( wp_unslash( $_POST['api_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_secret() keeps every printable character of the secret.
		if ( '' !== $secret ) {
			Eru_TYR_Settings::set_secret( $secret ); // Empty keeps the stored secret.
		}
		$started = Eru_TYR_Store_Import::start( $seller );
		if ( is_wp_error( $started ) ) {
			self::redirect( 'store', 'error', $started->get_error_message() );
		}
		self::redirect( 'store', 'success', __( 'İçe aktarım kuyruğa alındı; mağaza sayfaları arka planda sırayla işlenecek.', 'trendyol-reviews-for-woocommerce' ) );
	}

	public static function post_url(): void {
		self::guard( 'eru_tyr_url' );
		$trendyol   = isset( $_POST['trendyol'] ) ? sanitize_text_field( wp_unslash( $_POST['trendyol'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$result     = Eru_TYR_Url_Import::import_url( $trendyol, $product_id );
		if ( is_wp_error( $result ) ) {
			self::redirect( 'url', 'error', $result->get_error_message() );
		}
		self::redirect(
			'url',
			'success',
			sprintf(
				/* translators: 1: imported reviews, 2: skipped reviews */
				__( '%1$d yeni yorum içe aktarıldı, %2$d yorum atlandı (boş veya puan sınırının altında).', 'trendyol-reviews-for-woocommerce' ),
				$result['imported'],
				$result['skipped']
			)
		);
	}

	public static function post_csv(): void {
		self::guard( 'eru_tyr_csv' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated field by field below.
		$file = isset( $_FILES['eru_tyr_csv'] ) && is_array( $_FILES['eru_tyr_csv'] ) ? $_FILES['eru_tyr_csv'] : array();
		$tmp  = (string) ( $file['tmp_name'] ?? '' );
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			self::redirect( 'url', 'error', __( 'Lütfen bir .csv dosyası seçin.', 'trendyol-reviews-for-woocommerce' ) );
		}
		$parsed = Eru_TYR_Url_Import::parse_csv( $tmp );
		if ( is_wp_error( $parsed ) ) {
			self::redirect( 'url', 'error', $parsed->get_error_message() );
		}
		$queued = Eru_TYR_Url_Import::queue_rows( $parsed['rows'] );
		/* translators: 1: queued rows, 2: invalid rows */
		Eru_TYR_Logger::log( sprintf( __( 'CSV: %1$d satır kuyruğa alındı, %2$d satır geçersiz.', 'trendyol-reviews-for-woocommerce' ), $queued, count( $parsed['invalid'] ) ) );
		$message = sprintf(
			/* translators: 1: queued rows, 2: invalid rows */
			__( '%1$d satır eşleştirildi ve kuyruğa alındı, %2$d satır geçersiz.', 'trendyol-reviews-for-woocommerce' ),
			$queued,
			count( $parsed['invalid'] )
		);
		if ( $parsed['invalid'] ) {
			$message .= ' ' . sprintf(
				/* translators: %s: line numbers */
				__( 'Geçersiz satırlar: %s', 'trendyol-reviews-for-woocommerce' ),
				implode( ', ', array_slice( $parsed['invalid'], 0, 20 ) ) . ( count( $parsed['invalid'] ) > 20 ? '…' : '' )
			);
		}
		self::redirect( 'url', $queued ? 'success' : 'warning', $message );
	}

	public static function post_save(): void {
		self::guard( 'eru_tyr_save' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Eru_TYR_Settings::update() sanitises every key.
		Eru_TYR_Settings::update(
			array(
				'barcode_meta'        => (string) ( $in['barcode_meta'] ?? '' ),
				'max_reviews'         => (int) ( $in['max_reviews'] ?? 100 ),
				'min_rating'          => (int) ( $in['min_rating'] ?? 1 ),
				'import_photos'       => ! empty( $in['import_photos'] ),
				'structured_data'     => ! empty( $in['structured_data'] ),
				'sync_enabled'        => ! empty( $in['sync_enabled'] ),
				'delete_on_uninstall' => ! empty( $in['delete_on_uninstall'] ),
			)
		);
		Eru_TYR_Store_Import::sync_schedule();
		self::redirect( 'settings', 'success', __( 'Ayarlar kaydedildi.', 'trendyol-reviews-for-woocommerce' ) );
	}

	protected static function verify_ajax(): void {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Bu işlem için yetkiniz yok.', 'trendyol-reviews-for-woocommerce' ) ), 403 );
		}
	}

	public static function ajax_map(): void {
		self::verify_ajax();
		$content_id = isset( $_POST['content_id'] ) ? absint( $_POST['content_id'] ) : 0;
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! Eru_TYR_Matcher::set_mapping( $content_id, $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce ürünü bulunamadı.', 'trendyol-reviews-for-woocommerce' ) ) );
		}
		Eru_TYR_Store_Import::forget_unmatched( $content_id );
		Eru_TYR_Store_Import::queue_product( $product_id, $content_id );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: product name */
					__( '"%s" ile eşleştirildi; yorumlar kuyruğa alındı.', 'trendyol-reviews-for-woocommerce' ),
					get_the_title( $product_id )
				),
			)
		);
	}

	public static function ajax_unmap(): void {
		self::verify_ajax();
		$content_id = isset( $_POST['content_id'] ) ? absint( $_POST['content_id'] ) : 0;
		Eru_TYR_Matcher::remove_mapping( $content_id );
		wp_send_json_success();
	}
}
