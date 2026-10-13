<?php
/** Settings page, engine notice, bulk regeneration UI and the .htaccess snippet (shown only). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WEBP_Admin {

	public static function init(): void {
		Eru_WEBP_Bulk::init();
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'engine_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Yerel WebP', 'local-webp-generator-for-wordpress' ),
			__( 'Yerel WebP', 'local-webp-generator-for-wordpress' ),
			'manage_options',
			'eru-webp',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'eru_webp',
			Eru_WEBP_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_WEBP_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	public static function engine_notice(): void {
		if ( '' !== Eru_WEBP_Converter::engine() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Yerel WebP Üretici:', 'local-webp-generator-for-wordpress' ) . '</strong> '
			. esc_html__( 'Sunucuda WebP yazabilen bir görsel kütüphanesi (GD imagewebp veya Imagick WEBP) bulunamadı; WebP kopyası üretilemez. Barındırma sağlayıcınızdan etkinleştirmesini isteyin.', 'local-webp-generator-for-wordpress' )
			. '</p></div>';
	}

	public static function assets( string $hook ): void {
		if ( 'settings_page_eru-webp' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-webp-bulk', ERU_WEBP_URL . 'assets/bulk.js', array(), ERU_WEBP_VERSION, true );
		wp_localize_script(
			'eru-webp-bulk',
			'eruWebp',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'eru_webp_bulk' ),
				'total' => Eru_WEBP_Bulk::total(),
				'i18n'  => array(
					'running' => __( 'İşleniyor…', 'local-webp-generator-for-wordpress' ),
					'done'    => __( 'Tamamlandı.', 'local-webp-generator-for-wordpress' ),
					'failed'  => __( 'İstek başarısız oldu.', 'local-webp-generator-for-wordpress' ),
				),
			)
		);
		wp_enqueue_style( 'eru-webp-admin', ERU_WEBP_URL . 'assets/admin.css', array(), ERU_WEBP_VERSION );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = Eru_WEBP_Settings::get();
		$o      = Eru_WEBP_Settings::OPTION;
		$engine = Eru_WEBP_Converter::engine();
		$labels = array(
			'imagick' => 'Imagick',
			'gd'      => 'GD',
			''        => __( 'Yok', 'local-webp-generator-for-wordpress' ),
		);
		$rules  = "<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{HTTP_ACCEPT} image/webp\nRewriteCond %{REQUEST_FILENAME} -f\nRewriteCond %{REQUEST_FILENAME}.webp -f\nRewriteRule ^(.+)\\.(jpe?g|png)$ \$1.\$2.webp [T=image/webp,L]\n</IfModule>\n<IfModule mod_headers.c>\n<FilesMatch \"\\.(jpe?g|png)$\">\nHeader append Vary Accept\n</FilesMatch>\n</IfModule>";
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Yerel WebP Üretici', 'local-webp-generator-for-wordpress' ); ?></h1>
			<p><?php echo esc_html__( 'Görsel motoru:', 'local-webp-generator-for-wordpress' ); ?> <strong><?php echo esc_html( $labels[ $engine ] ); ?></strong></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_webp' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eru-webp-q"><?php echo esc_html__( 'Kalite (30-100)', 'local-webp-generator-for-wordpress' ); ?></label></th>
						<td><input type="number" id="eru-webp-q" name="<?php echo esc_attr( $o ); ?>[quality]" min="30" max="100" value="<?php echo esc_attr( (string) $s['quality'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-webp-min"><?php echo esc_html__( 'En küçük dosya (bayt)', 'local-webp-generator-for-wordpress' ); ?></label></th>
						<td><input type="number" id="eru-webp-min" name="<?php echo esc_attr( $o ); ?>[min_bytes]" min="0" value="<?php echo esc_attr( (string) $s['min_bytes'] ); ?>" class="small-text"> <span class="description"><?php echo esc_html__( 'Bundan küçük dosyalar dönüştürülmez.', 'local-webp-generator-for-wordpress' ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-webp-serve"><?php echo esc_html__( 'Sunum', 'local-webp-generator-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-webp-serve" name="<?php echo esc_attr( $o ); ?>[serve]">
								<option value="picture" <?php selected( $s['serve'], 'picture' ); ?>><?php echo esc_html__( '&lt;picture&gt; ile (önerilen)', 'local-webp-generator-for-wordpress' ); ?></option>
								<option value="src" <?php selected( $s['serve'], 'src' ); ?>><?php echo esc_html__( 'Adresleri doğrudan .webp yap', 'local-webp-generator-for-wordpress' ); ?></option>
								<option value="off" <?php selected( $s['serve'], 'off' ); ?>><?php echo esc_html__( 'Kapalı (yalnızca üret; .htaccess kullanacağım)', 'local-webp-generator-for-wordpress' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Mevcut görselleri dönüştür', 'local-webp-generator-for-wordpress' ); ?></h2>
			<p><?php echo esc_html__( 'Medya kitaplığındaki JPEG ve PNG dosyaları küçük gruplar halinde işlenir. Orijinaller değişmez; var olan WebP kopyaları atlanır.', 'local-webp-generator-for-wordpress' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="eru-webp-start" <?php disabled( '' === $engine ); ?>><?php echo esc_html__( 'Dönüştürmeyi başlat', 'local-webp-generator-for-wordpress' ); ?></button>
				<label style="margin-left:1em"><input type="checkbox" id="eru-webp-force"> <?php echo esc_html__( 'Var olanları yeniden üret', 'local-webp-generator-for-wordpress' ); ?></label>
			</p>
			<div class="eru-webp-progress" hidden><div class="eru-webp-progress__bar" id="eru-webp-bar"></div></div>
			<p id="eru-webp-status" aria-live="polite"></p>

			<h2><?php echo esc_html__( '.htaccess (isteğe bağlı)', 'local-webp-generator-for-wordpress' ); ?></h2>
			<p><?php echo esc_html__( 'Sunum "Kapalı" ise ve Apache kullanıyorsanız, WebP destekleyen tarayıcılara kopyayı sunucu düzeyinde verebilirsiniz. Eklenti bu dosyaya hiçbir zaman kendisi yazmaz; kuralı kopyalayıp siz ekleyin.', 'local-webp-generator-for-wordpress' ); ?></p>
			<textarea readonly rows="12" class="large-text code" onclick="this.select()"><?php echo esc_textarea( $rules ); ?></textarea>
		</div>
		<?php
	}
}
