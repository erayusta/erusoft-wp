<?php
/**
 * Wrapper for WooCommerce views. Shop and product taxonomy archives get a filter column (categories,
 * attribute filters with counts, the "Mağaza filtreleri" widget area), which becomes a drawer on small screens.
 * Single products go through woocommerce_content() (content-single-product.php). Every core hook still fires.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
$voltaj_archive = is_shop() || is_product_taxonomy() || is_post_type_archive('product');
?>
<main id="main-content" class="vj-main vj-shop<?php echo $voltaj_archive ? ' vj-shop--archive' : ' vj-shop--single'; ?>">
	<div class="vj-container">
		<?php
		/**
		 * Hook: woocommerce_before_main_content (breadcrumb; the theme removed the default wrapper).
		 */
		do_action('woocommerce_before_main_content');

		if (!$voltaj_archive) :
			woocommerce_content();
		else :
			$voltaj_terms = voltaj_shop_category_terms();
			?>
			<header class="vj-shop__header">
				<?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
					<h1 class="vj-page-title"><?php woocommerce_page_title(); ?></h1>
				<?php endif; ?>
				<?php do_action('woocommerce_archive_description'); ?>
			</header>
			<?php
			/**
			 * Hook: woocommerce_shop_loop_header (WooCommerce 8.6+). The theme printed the title above and removed
			 * the core woocommerce_product_taxonomy_archive_header callback, so only other callbacks run here.
			 */
			do_action('woocommerce_shop_loop_header');
			?>

			<div class="vj-shop__layout">
				<div class="vj-filters-backdrop" data-vj-filters-backdrop hidden></div>
				<aside class="vj-filters" id="vj-filters" aria-label="<?php esc_attr_e('Filtreler', 'voltaj'); ?>" data-vj-filters>
					<div class="vj-filters__head">
						<h2 class="vj-filters__title"><?php voltaj_icon('sliders'); ?><?php esc_html_e('Filtrele', 'voltaj'); ?></h2>
						<button type="button" class="vj-tool vj-filters__close" data-vj-filters-close><?php voltaj_icon('x'); ?><span class="screen-reader-text"><?php esc_html_e('Filtreleri kapat', 'voltaj'); ?></span></button>
					</div>
					<?php if ($voltaj_terms) : ?>
						<div class="vj-filter">
							<h3 class="vj-filter__title" id="vj-filter-cats"><?php echo is_product_category() ? esc_html__('Alt kategoriler', 'voltaj') : esc_html__('Kategoriler', 'voltaj'); ?></h3>
							<ul class="vj-filter__list vj-filter__list--cats" aria-labelledby="vj-filter-cats">
								<?php foreach ($voltaj_terms as $voltaj_term) : ?>
									<?php $voltaj_current = is_product_category($voltaj_term->slug); ?>
									<li>
										<a class="vj-filter__opt<?php echo $voltaj_current ? ' is-on' : ''; ?>" href="<?php echo esc_url(get_term_link($voltaj_term)); ?>"<?php echo $voltaj_current ? ' aria-current="page"' : ''; ?>>
											<span class="vj-filter__name"><?php echo esc_html($voltaj_term->name); ?></span>
											<span class="vj-filter__count"><?php echo (int) $voltaj_term->count; ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php voltaj_render_attribute_filters(); ?>
					<?php if (is_active_sidebar('shop-filters')) : ?>
						<div class="vj-filters__widgets"><?php dynamic_sidebar('shop-filters'); ?></div>
					<?php endif; ?>
				</aside>

				<div class="vj-shop__results">
					<?php if (woocommerce_product_loop()) : ?>
						<div class="vj-toolbar">
							<button type="button" class="vj-btn vj-btn--ghost vj-filters-toggle" aria-expanded="false" aria-controls="vj-filters" data-vj-filters-toggle><?php voltaj_icon('sliders'); ?><?php esc_html_e('Filtrele', 'voltaj'); ?></button>
							<?php
							/**
							 * Hook: woocommerce_before_shop_loop (notices, result count, ordering).
							 */
							do_action('woocommerce_before_shop_loop');
							?>
						</div>
						<?php
						woocommerce_product_loop_start();
						if (wc_get_loop_prop('total')) {
							while (have_posts()) {
								the_post();
								do_action('woocommerce_shop_loop');
								wc_get_template_part('content', 'product');
							}
						}
						woocommerce_product_loop_end();

						/**
						 * Hook: woocommerce_after_shop_loop (pagination).
						 */
						do_action('woocommerce_after_shop_loop');
					else :
						?>
						<div class="vj-toolbar">
							<button type="button" class="vj-btn vj-btn--ghost vj-filters-toggle" aria-expanded="false" aria-controls="vj-filters" data-vj-filters-toggle><?php voltaj_icon('sliders'); ?><?php esc_html_e('Filtrele', 'voltaj'); ?></button>
						</div>
						<?php
						do_action('woocommerce_no_products_found');
					endif;
					?>
				</div>
			</div>
			<?php
		endif;

		do_action('woocommerce_after_main_content');
		do_action('woocommerce_sidebar');
		?>
	</div>
</main>
<?php
get_footer();
