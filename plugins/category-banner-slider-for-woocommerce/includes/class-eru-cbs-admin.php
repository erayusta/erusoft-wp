<?php
/**
 * Settings page, slide repeater and category edit screen fields.
 *
 * @package category-banner-slider-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CBS_Admin {

	const TD = 'category-banner-slider-for-woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'term_fields' ), 20 );
		add_action( 'edited_product_cat', array( __CLASS__, 'term_save' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Kategori Slider', 'category-banner-slider-for-woocommerce' ), __( 'Kategori Slider', 'category-banner-slider-for-woocommerce' ), 'manage_woocommerce', 'eru-cbs', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_cbs', fn() => 'manage_woocommerce' );
		register_setting( 'eru_cbs', Eru_CBS::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ), 'default' => Eru_CBS::defaults() ) );
		register_setting( 'eru_cbs', Eru_CBS::OPTION_GLOBAL, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_global' ), 'default' => array() ) );
	}

	public static function sanitize_settings( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return get_option( Eru_CBS::OPTION, Eru_CBS::defaults() );
		}
		return Eru_CBS::sanitize_settings( $in );
	}

	public static function sanitize_global( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return get_option( Eru_CBS::OPTION_GLOBAL, array() );
		}
		return Eru_CBS::sanitize_slides( $in );
	}

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ok     = 'woocommerce_page_eru-cbs' === $hook || ( 'term.php' === $hook && $screen && 'product_cat' === $screen->taxonomy );
		if ( ! $ok ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'eru-cbs-admin', ERU_CBS_URL . 'assets/css/admin-slides.css', array( 'dashicons' ), ERU_CBS_VERSION );
		wp_enqueue_script( 'eru-cbs-admin', ERU_CBS_URL . 'assets/js/admin-slides.js', array( 'jquery', 'jquery-ui-sortable' ), ERU_CBS_VERSION, true );
	}

	public static function term_fields( $term ) {
		$enabled = get_term_meta( $term->term_id, Eru_CBS::META_ENABLED, true );
		$slides  = get_term_meta( $term->term_id, Eru_CBS::META_SLIDES, true );
		?>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Kategori Slider', 'category-banner-slider-for-woocommerce' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'eru_cbs_term', 'eru_cbs_term_nonce' ); ?>
				<label><input type="checkbox" name="eru_cbs_enabled" value="1" <?php checked( $enabled, '1' ); ?>> <?php esc_html_e( 'Bu kategori sayfasında slider göster', 'category-banner-slider-for-woocommerce' ); ?></label>
				<p class="description"><?php esc_html_e( 'Kapalıysa (veya slayt yoksa) varsa genel slaytlar gösterilir. Kısa kod bu kutudan bağımsız çalışır: [eru_category_slider term="kategori-slug"]', 'category-banner-slider-for-woocommerce' ); ?></p>
				<?php self::repeater( 'eru_cbs_slides', is_array( $slides ) ? $slides : array() ); ?>
			</td>
		</tr>
		<?php
	}

	public static function term_save( $term_id ) {
		if ( ! isset( $_POST['eru_cbs_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_cbs_term_nonce'] ) ), 'eru_cbs_term' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		update_term_meta( $term_id, Eru_CBS::META_ENABLED, empty( $_POST['eru_cbs_enabled'] ) ? '' : '1' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in sanitize_slides().
		$raw = isset( $_POST['eru_cbs_slides'] ) ? wp_unslash( $_POST['eru_cbs_slides'] ) : array();
		update_term_meta( $term_id, Eru_CBS::META_SLIDES, Eru_CBS::sanitize_slides( $raw ) );
	}

	private static function repeater( $name, $slides ) {
		$slides = array_values( $slides );
		?>
		<div class="eru-cbs-slides" data-field="<?php echo esc_attr( $name ); ?>">
			<div class="eru-cbs-slides__list">
				<?php
				if ( ! $slides ) {
					$slides = array( array() );
				}
				foreach ( $slides as $i => $slide ) {
					echo self::row( $name, $i, is_array( $slide ) ? $slide : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
			<p><button type="button" class="button eru-cbs-add-slide"><?php esc_html_e( '+ Slayt ekle', 'category-banner-slider-for-woocommerce' ); ?></button></p>
			<script type="text/html" class="eru-cbs-row-template"><?php echo self::row( $name, '__INDEX__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		</div>
		<?php
	}

	private static function row( $name, $index, $slide ) {
		$base = $name . '[' . $index . ']';
		$get  = fn( $k ) => isset( $slide[ $k ] ) ? $slide[ $k ] : '';
		ob_start();
		?>
		<div class="eru-cbs-slide-row">
			<span class="eru-cbs-drag dashicons dashicons-move" title="<?php esc_attr_e( 'Sürükleyerek sırala', 'category-banner-slider-for-woocommerce' ); ?>"></span>
			<div class="eru-cbs-images">
				<?php
				self::image_field( $base, 'image_desktop', __( 'Masaüstü', 'category-banner-slider-for-woocommerce' ), $get( 'image_desktop' ), true );
				self::image_field( $base, 'image_tablet', __( 'Tablet', 'category-banner-slider-for-woocommerce' ), $get( 'image_tablet' ), false );
				self::image_field( $base, 'image_mobile', __( 'Mobil', 'category-banner-slider-for-woocommerce' ), $get( 'image_mobile' ), false );
				?>
			</div>
			<div class="eru-cbs-meta">
				<label class="eru-cbs-field"><span><?php esc_html_e( 'Bağlantı (isteğe bağlı)', 'category-banner-slider-for-woocommerce' ); ?></span>
					<input type="url" name="<?php echo esc_attr( $base ); ?>[link_url]" value="<?php echo esc_attr( $get( 'link_url' ) ); ?>" placeholder="https://"></label>
				<label class="eru-cbs-field"><span><?php esc_html_e( 'Alternatif metin (isteğe bağlı)', 'category-banner-slider-for-woocommerce' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $base ); ?>[alt]" value="<?php echo esc_attr( $get( 'alt' ) ); ?>"></label>
				<button type="button" class="button-link-delete eru-cbs-remove-slide"><?php esc_html_e( 'Slaytı kaldır', 'category-banner-slider-for-woocommerce' ); ?></button>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function image_field( $base, $key, $label, $value, $required ) {
		$value   = absint( $value );
		$preview = $value ? wp_get_attachment_image_url( $value, 'thumbnail' ) : '';
		?>
		<div class="eru-cbs-image-field">
			<span class="eru-cbs-image-label"><?php echo esc_html( $label . ( $required ? ' *' : '' ) ); ?></span>
			<div class="eru-cbs-image-preview<?php echo $preview ? ' has-image' : ''; ?>"><?php if ( $preview ) : ?><img src="<?php echo esc_url( $preview ); ?>" alt=""><?php endif; ?></div>
			<input type="hidden" class="eru-cbs-image-id" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<span class="eru-cbs-image-actions">
				<button type="button" class="button eru-cbs-select-image"><?php esc_html_e( 'Seç', 'category-banner-slider-for-woocommerce' ); ?></button>
				<button type="button" class="button-link eru-cbs-clear-image"<?php echo $preview ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Temizle', 'category-banner-slider-for-woocommerce' ); ?></button>
			</span>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'category-banner-slider-for-woocommerce' ) );
		}
		$s = Eru_CBS::get_settings();
		$n = Eru_CBS::OPTION;
		$checks = array(
			'autoplay'    => __( 'Otomatik geçiş', 'category-banner-slider-for-woocommerce' ),
			'show_arrows' => __( 'Okları göster', 'category-banner-slider-for-woocommerce' ),
			'show_dots'   => __( 'Noktaları göster', 'category-banner-slider-for-woocommerce' ),
			'loop'        => __( 'Döngü (sondan başa)', 'category-banner-slider-for-woocommerce' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kategori Slider', 'category-banner-slider-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_cbs' ); ?>
				<h2><?php esc_html_e( 'Görünüm ve davranış', 'category-banner-slider-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eru-cbs-pos"><?php esc_html_e( 'Kategori sayfasında konum', 'category-banner-slider-for-woocommerce' ); ?></label></th>
						<td>
							<select id="eru-cbs-pos" name="<?php echo esc_attr( $n ); ?>[position]">
								<?php foreach ( Eru_CBS::positions() as $k => $l ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['position'], $k ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Varsayılan "Yok": kategori sayfalarında hiçbir şey eklenmez; kısa kodu istediğiniz yere koyabilirsiniz.', 'category-banner-slider-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<?php foreach ( $checks as $k => $l ) : ?>
						<tr><th scope="row"><?php echo esc_html( $l ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $n . '[' . $k . ']' ); ?>" value="1" <?php checked( ! empty( $s[ $k ] ) ); ?>> <?php esc_html_e( 'Açık', 'category-banner-slider-for-woocommerce' ); ?></label></td></tr>
					<?php endforeach; ?>
					<tr><th scope="row"><label for="eru-cbs-speed"><?php esc_html_e( 'Geçiş süresi (ms)', 'category-banner-slider-for-woocommerce' ); ?></label></th>
						<td><input id="eru-cbs-speed" type="number" min="1000" step="500" name="<?php echo esc_attr( $n ); ?>[autoplay_speed_ms]" value="<?php echo esc_attr( $s['autoplay_speed_ms'] ); ?>"></td></tr>
					<?php foreach ( array( 'desktop' => __( 'Masaüstü', 'category-banner-slider-for-woocommerce' ), 'tablet' => __( 'Tablet', 'category-banner-slider-for-woocommerce' ), 'mobile' => __( 'Mobil', 'category-banner-slider-for-woocommerce' ) ) as $k => $l ) : ?>
						<tr><th scope="row"><?php echo esc_html( sprintf( /* translators: %s: device */ __( 'Yükseklik — %s', 'category-banner-slider-for-woocommerce' ), $l ) ); ?></th>
							<td><input type="number" min="0" name="<?php echo esc_attr( $n . '[height_' . $k . ']' ); ?>" value="<?php echo esc_attr( $s[ 'height_' . $k ] ); ?>"> <span class="description"><?php esc_html_e( 'px (boş = görselin doğal oranı)', 'category-banner-slider-for-woocommerce' ); ?></span></td></tr>
					<?php endforeach; ?>
				</table>
				<h2><?php esc_html_e( 'Genel slaytlar', 'category-banner-slider-for-woocommerce' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Kendi slideri olmayan kategori sayfalarında gösterilir (konum seçiliyse). Boş bırakırsanız o sayfalar değişmez.', 'category-banner-slider-for-woocommerce' ); ?></p>
				<?php self::repeater( Eru_CBS::OPTION_GLOBAL, Eru_CBS::sanitize_slides( get_option( Eru_CBS::OPTION_GLOBAL, array() ) ) ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
