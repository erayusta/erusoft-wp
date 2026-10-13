<?php
/**
 * Home: category grid (top-level product categories with image, name and product count).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!voltaj_wc_active() || !voltaj_setting('show_categories')) {
	return;
}
$voltaj_terms = voltaj_top_categories((int) apply_filters('voltaj_home_categories_limit', 8));
if (!$voltaj_terms) {
	return;
}
$voltaj_heading = trim((string) voltaj_setting('categories_title')) ?: __('Kategoriler', 'voltaj');
?>
<section class="vj-section vj-home-cats" aria-labelledby="vj-cats-title">
	<div class="vj-container">
		<div class="vj-section__head">
			<h2 class="vj-section__title" id="vj-cats-title"><?php echo esc_html($voltaj_heading); ?></h2>
			<a class="vj-section__more" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Tüm ürünler', 'voltaj'); ?><?php voltaj_icon('arrow-right'); ?></a>
		</div>
		<ul class="vj-cat-grid">
			<?php foreach ($voltaj_terms as $voltaj_term) : ?>
				<?php $voltaj_img = voltaj_category_image($voltaj_term); ?>
				<li>
					<a class="vj-cat-tile" href="<?php echo esc_url(get_term_link($voltaj_term)); ?>">
						<span class="vj-cat-tile__media<?php echo $voltaj_img ? '' : ' is-empty'; ?>"><?php echo $voltaj_img ? $voltaj_img : voltaj_get_icon('package'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span class="vj-cat-tile__name"><?php echo esc_html($voltaj_term->name); ?></span>
						<span class="vj-cat-tile__count">
							<?php
							/* translators: %s: number of products */
							echo esc_html(sprintf(_n('%s ürün', '%s ürün', (int) $voltaj_term->count, 'voltaj'), number_format_i18n((int) $voltaj_term->count)));
							?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
