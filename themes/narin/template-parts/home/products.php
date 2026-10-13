<?php
/**
 * Home: newest products (WooCommerce only), rendered with WooCommerce's own product loop.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!narin_wc_active() || !narin_setting('show_products')) {
	return;
}
$narin_count = (int) narin_setting('products_count');
$narin_html  = do_shortcode(sprintf('[products limit="%1$d" columns="4" orderby="date" order="DESC" visibility="visible"]', $narin_count));
if (strpos($narin_html, 'class="products') === false) {
	return;
}
$narin_heading = trim((string) narin_setting('products_title')) ?: __('Yeni ürünler', 'narin');
?>
<section class="section home-products" aria-labelledby="narin-products-title">
	<div class="container">
		<div class="section-head">
			<h2 class="section-title" id="narin-products-title"><?php echo esc_html($narin_heading); ?></h2>
			<a class="text-link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Mağazaya git', 'narin'); ?><?php narin_icon('arrow-right'); ?></a>
		</div>
		<?php echo $narin_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce shortcode output. ?>
	</div>
</section>
