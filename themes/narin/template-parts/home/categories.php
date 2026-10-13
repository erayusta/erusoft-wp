<?php
/**
 * Home: featured product categories (WooCommerce only). The image is the category thumbnail, else the
 * image of a product in that category.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!narin_wc_active() || !narin_setting('show_categories')) {
	return;
}
$narin_terms = get_terms(array(
	'taxonomy'   => 'product_cat',
	'parent'     => 0,
	'hide_empty' => true,
	'number'     => (int) apply_filters('narin_home_categories_limit', 4),
	'orderby'    => 'count',
	'order'      => 'DESC',
	'exclude'    => array((int) get_option('default_product_cat')),
));
if (!$narin_terms || is_wp_error($narin_terms)) {
	return;
}
$narin_heading = trim((string) narin_setting('categories_title')) ?: __('Kategoriler', 'narin');
?>
<section class="section home-categories" aria-labelledby="narin-cat-title">
	<div class="container">
		<div class="section-head">
			<h2 class="section-title" id="narin-cat-title"><?php echo esc_html($narin_heading); ?></h2>
			<a class="text-link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Tüm ürünler', 'narin'); ?><?php narin_icon('arrow-right'); ?></a>
		</div>
		<ul class="category-grid">
			<?php
			foreach ($narin_terms as $narin_term) :
				$narin_thumb = (int) get_term_meta($narin_term->term_id, 'thumbnail_id', true);
				if (!$narin_thumb) {
					$narin_first = wc_get_products(array('category' => array($narin_term->slug), 'limit' => 1, 'status' => 'publish', 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC'));
					$narin_thumb = $narin_first ? (int) get_post_thumbnail_id($narin_first[0]) : 0;
				}
				?>
				<li class="category-card">
					<a href="<?php echo esc_url(get_term_link($narin_term)); ?>">
						<span class="category-card__media">
							<?php echo $narin_thumb ? wp_get_attachment_image($narin_thumb, 'woocommerce_thumbnail', false, array('alt' => '', 'loading' => 'lazy')) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
						<span class="category-card__name"><?php echo esc_html($narin_term->name); ?></span>
						<span class="category-card__count">
							<?php
							/* translators: %d: number of products */
							echo esc_html(sprintf(_n('%d ürün', '%d ürün', (int) $narin_term->count, 'narin'), (int) $narin_term->count));
							?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
