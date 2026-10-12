<?php
/**
 * Plugin Name:       Category Base Rewrite Guard for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/category-base-rewrite-guard-for-woocommerce/
 * Description:       SEO eklentisi ürün kategori tabanını kaldırıyorsa (Rank Math "kategori tabanını kaldır") ve yeniden yazma kuralları eksik kaydedildiyse kategori kurallarını yeniden üretir; kategori sayfalarının toplu 404 vermesini önler. Kurallar tamsa hiçbir şey yapmaz.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       category-base-rewrite-guard-for-woocommerce
 * Domain Path:       /languages
 *
 * Tek dosyalı ve bağımsızdır: isterseniz bu dosyayı wp-content/mu-plugins/ altına da kopyalayabilirsiniz
 * (bu durumda --skip-plugins ile çalışan komutlar da kuralları doğru üretir).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_CBRG_VERSION', '1.0.0' );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'category-base-rewrite-guard-for-woocommerce' ) . '</p></div>'
			);
		}
	}
);

/**
 * Korumanın uygulanacağı kategori kuralı modunu döndürür.
 *
 * Varsayılan olarak Rank Math'in "ürün kategori tabanını kaldır" ayarı açıksa etkindir;
 * ayar kapalıysa taban gerçekten kullanılıyordur ve eklenti karışmaz.
 *
 * @return array|null array( 'flat' => bool ) veya etkin değilse null. flat=true: alt kategori de kökte (/alt-kategori/).
 */
function eru_cbrg_mode(): ?array {
	$mode = null;
	$rm   = get_option( 'rank-math-options-general', array() );
	if ( is_array( $rm ) && ( $rm['wc_remove_category_base'] ?? '' ) === 'on' ) {
		$mode = array( 'flat' => ( $rm['wc_remove_category_parent_slugs'] ?? '' ) === 'on' );
	}
	/**
	 * Koruma modunu değiştirir; başka bir SEO eklentisi için array( 'flat' => bool ) döndürün, kapatmak için null.
	 *
	 * @param array|null $mode Geçerli mod.
	 */
	return apply_filters( 'eru_cbrg_mode', $mode );
}

/**
 * Bir kategorinin taban-sız yolunu kurar (flat değilse üst kategori zinciriyle).
 *
 * @param array $terms term_id => array( parent, slug ).
 */
function eru_cbrg_path( array $terms, int $id ): string {
	$parts = array( $terms[ $id ]['slug'] );
	$up    = (int) $terms[ $id ]['parent'];
	$step  = 0;
	// Bozuk bir üst-kategori döngüsü sonsuza kadar dönmesin.
	while ( $up > 0 && isset( $terms[ $up ] ) && $step < 10 ) {
		array_unshift( $parts, $terms[ $up ]['slug'] );
		$up = (int) $terms[ $up ]['parent'];
		++$step;
	}
	return implode( '/', $parts );
}

/**
 * Taban-sız kategori kurallarının tamamını üretir.
 *
 * @return array|null kural => sorgu; üretilemezse null.
 */
function eru_cbrg_rules(): ?array {
	$mode = eru_cbrg_mode();
	if ( null === $mode || ! taxonomy_exists( 'product_cat' ) ) {
		return null;
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return null;
	}

	$map = array();
	foreach ( $terms as $term ) {
		$map[ (int) $term->term_id ] = array(
			'parent' => (int) $term->parent,
			'slug'   => $term->slug,
		);
	}

	global $wp_rewrite;
	$feed      = '(' . trim( implode( '|', $wp_rewrite->feeds ) ) . ')';
	$page      = $wp_rewrite->pagination_base;
	$feed_base = $wp_rewrite->feed_base;

	$rules = array();
	foreach ( $map as $id => $term ) {
		$path = $mode['flat'] ? $term['slug'] : eru_cbrg_path( $map, $id );
		$base = 'index.php?product_cat=' . $term['slug'];

		$rules[ $path . '/' . $feed_base . '/' . $feed . '/?$' ] = $base . '&feed=$matches[1]';
		$rules[ $path . '/' . $feed . '/?$' ]                    = $base . '&feed=$matches[1]';
		$rules[ $path . '/' . $page . '/?([0-9]{1,})/?$' ]       = $base . '&paged=$matches[1]';
		$rules[ $path . '/?$' ]                                  = $base;
	}
	return $rules;
}

/**
 * Kurallar eksikse kategori kurallarını başa ekler (sayfa/yazı kurallarından önce gelmeli).
 */
function eru_cbrg_filter_rules( $rules ) {
	if ( ! is_array( $rules ) ) {
		return $rules;
	}
	$ours = eru_cbrg_rules();
	if ( empty( $ours ) || empty( array_diff_key( $ours, $rules ) ) ) {
		return $rules;
	}
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( sprintf( '[category-base-rewrite-guard] %d category rules were missing and were added.', count( $ours ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	return $ours + $rules;
}
add_filter( 'rewrite_rules_array', 'eru_cbrg_filter_rules', 999 );

/**
 * Kaydedilmiş kurallarda eksik varsa (en çok saatte bir kontrol) yumuşak flush ile onarır.
 */
function eru_cbrg_heal(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || get_transient( 'eru_cbrg_checked' ) ) {
		return;
	}
	if ( null === eru_cbrg_mode() ) {
		return;
	}
	set_transient( 'eru_cbrg_checked', 1, HOUR_IN_SECONDS );

	$ours   = eru_cbrg_rules();
	$stored = get_option( 'rewrite_rules' );
	if ( ! empty( $ours ) && is_array( $stored ) && ! empty( array_diff_key( $ours, $stored ) ) ) {
		flush_rewrite_rules( false );
	}
}
add_action( 'init', 'eru_cbrg_heal', 99 );
