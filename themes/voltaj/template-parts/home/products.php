<?php
/**
 * Home: featured products (newest products when none is marked as featured).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!voltaj_wc_active() || !voltaj_setting('show_products')) {
	return;
}
$voltaj_html = voltaj_home_products_html((int) voltaj_setting('products_count'));
if ($voltaj_html === '') {
	return;
}
$voltaj_heading = trim((string) voltaj_setting('products_title')) ?: __('Öne çıkan ürünler', 'voltaj');
?>
<section class="vj-section vj-home-products" aria-labelledby="vj-products-title">
	<div class="vj-container">
		<div class="vj-section__head">
			<h2 class="vj-section__title" id="vj-products-title"><?php echo esc_html($voltaj_heading); ?></h2>
			<a class="vj-section__more" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Mağazaya git', 'voltaj'); ?><?php voltaj_icon('arrow-right'); ?></a>
		</div>
		<?php echo $voltaj_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce shortcode output. ?>
	</div>
</section>
