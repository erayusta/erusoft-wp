<?php
/**
 * Settings page and batched reindex.
 *
 * @package smart-product-search-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SPS_Admin {

	const TD = 'smart-product-search-for-woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_eru_sps_reindex', array( __CLASS__, 'ajax_reindex' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Akıllı Ürün Arama', 'smart-product-search-for-woocommerce' ), __( 'Akıllı Ürün Arama', 'smart-product-search-for-woocommerce' ), 'manage_woocommerce', 'eru-sps', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_sps', fn() => 'manage_woocommerce' );
		register_setting( 'eru_sps', Eru_SPS::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => Eru_SPS::defaults() ) );
	}

	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return get_option( Eru_SPS::OPTION, Eru_SPS::defaults() );
		}
		return Eru_SPS::sanitize_settings( $in );
	}

	public static function assets( $hook ) {
		if ( 'woocommerce_page_eru-sps' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-sps-admin', ERU_SPS_URL . 'assets/js/admin.js', array(), ERU_SPS_VERSION, true );
		wp_localize_script(
			'eru-sps-admin',
			'ERU_SPS_ADMIN',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'eru_sps_reindex' ),
				'done'  => __( 'Tamamlandı', 'smart-product-search-for-woocommerce' ),
				'busy'  => __( 'İndeksleniyor', 'smart-product-search-for-woocommerce' ),
				'error' => __( 'Hata oluştu', 'smart-product-search-for-woocommerce' ),
			)
		);
	}

	public static function ajax_reindex() {
		check_ajax_referer( 'eru_sps_reindex', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( null, 403 );
		}
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		list( $n, $total ) = Eru_SPS::reindex_batch( $offset, 100 );
		wp_send_json_success( array( 'next' => $offset + $n, 'total' => $total, 'done' => 0 === $n || $offset + $n >= $total ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'smart-product-search-for-woocommerce' ) );
		}
		$s = Eru_SPS::get_settings();
		$n = Eru_SPS::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Akıllı Ürün Arama', 'smart-product-search-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_sps' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Arama sonuçları', 'smart-product-search-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[main_query]" value="1" <?php checked( ! empty( $s['main_query'] ) ); ?>> <?php esc_html_e( 'Mağaza arama sonuç sayfasında Türkçe karakterden bağımsız eşleştirme ve alaka sıralaması kullan', 'smart-product-search-for-woocommerce' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Canlı arama', 'smart-product-search-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[live]" value="1" <?php checked( ! empty( $s['live'] ) ); ?>> <?php esc_html_e( 'Aşağıdaki arama kutularında yazdıkça öneri listesi göster', 'smart-product-search-for-woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'Her ikisi de kapalıyken sitenizin aramasında hiçbir şey değişmez. [eru_product_search] kısa kodu her zaman canlı aramalı bir form üretir.', 'smart-product-search-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><label for="eru-sps-sel"><?php esc_html_e( 'Arama kutusu seçicisi (CSS)', 'smart-product-search-for-woocommerce' ); ?></label></th>
						<td><input id="eru-sps-sel" type="text" class="large-text" name="<?php echo esc_attr( $n ); ?>[selector]" value="<?php echo esc_attr( $s['selector'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="eru-sps-limit"><?php esc_html_e( 'Önerilen ürün sayısı', 'smart-product-search-for-woocommerce' ); ?></label></th>
						<td><input id="eru-sps-limit" type="number" min="1" max="20" name="<?php echo esc_attr( $n ); ?>[limit]" value="<?php echo esc_attr( $s['limit'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="eru-sps-min"><?php esc_html_e( 'En az karakter', 'smart-product-search-for-woocommerce' ); ?></label></th>
						<td><input id="eru-sps-min" type="number" min="1" max="5" name="<?php echo esc_attr( $n ); ?>[min_chars]" value="<?php echo esc_attr( $s['min_chars'] ); ?>"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Öneride göster', 'smart-product-search-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_sku]" value="1" <?php checked( ! empty( $s['show_sku'] ) ); ?>> <?php esc_html_e( 'Stok kodu', 'smart-product-search-for-woocommerce' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_price]" value="1" <?php checked( ! empty( $s['show_price'] ) ); ?>> <?php esc_html_e( 'Fiyat', 'smart-product-search-for-woocommerce' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[show_cats]" value="1" <?php checked( ! empty( $s['show_cats'] ) ); ?>> <?php esc_html_e( 'Kategoriler', 'smart-product-search-for-woocommerce' ); ?></label>
						</td></tr>
					<tr><th scope="row"><label for="eru-sps-rl"><?php esc_html_e( 'Dakikada istek sınırı (IP başına)', 'smart-product-search-for-woocommerce' ); ?></label></th>
						<td><input id="eru-sps-rl" type="number" min="0" max="600" name="<?php echo esc_attr( $n ); ?>[rate_limit]" value="<?php echo esc_attr( $s['rate_limit'] ); ?>">
						<p class="description"><?php esc_html_e( '0 = sınırsız. Site bir proxy/CDN arkasındaysa gerçek ziyaretçi adresi için eru_sps_client_ip filtresini kullanın.', 'smart-product-search-for-woocommerce' ); ?></p></td></tr>
					<tr><th scope="row"><label for="eru-sps-syn"><?php esc_html_e( 'Eş anlamlılar', 'smart-product-search-for-woocommerce' ); ?></label></th>
						<td><textarea id="eru-sps-syn" class="large-text" rows="5" name="<?php echo esc_attr( $n ); ?>[synonyms]" placeholder="<?php echo esc_attr( "kulaklık, headphone\nkot, jean" ); ?>"><?php echo esc_textarea( $s['synonyms'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satır bir grup; virgülle ayrılmış kelimelerden biri aranınca diğerleri de eşleşir. Varsayılan boştur.', 'smart-product-search-for-woocommerce' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Arama indeksi', 'smart-product-search-for-woocommerce' ); ?></h2>
			<p><?php esc_html_e( 'Ürünler kaydedilirken otomatik indekslenir. Mevcut ürünleri veya kategori adı değişikliklerini yansıtmak için yeniden indeksleyin. İndekslenmemiş ürünler yine de başlık ve stok koduyla bulunur.', 'smart-product-search-for-woocommerce' ); ?></p>
			<p><button type="button" class="button" id="eru-sps-reindex"><?php esc_html_e( 'Yeniden indeksle', 'smart-product-search-for-woocommerce' ); ?></button> <span id="eru-sps-status" aria-live="polite"></span></p>
		</div>
		<?php
	}
}
