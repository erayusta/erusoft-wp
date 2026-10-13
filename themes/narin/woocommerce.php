<?php
/**
 * Wrapper for WooCommerce views. Shop and product taxonomy archives get the filter-drawer layout; single
 * products go through woocommerce_content() (content-single-product.php). Every core hook still fires.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
$narin_archive = is_shop() || is_product_taxonomy();
?>
<main id="main-content" class="site-main shop-main<?php echo $narin_archive ? ' shop-archive' : ' shop-single'; ?>">
	<div class="container">
		<?php
		/**
		 * Hook: woocommerce_before_main_content (breadcrumb; the theme removed the default wrapper).
		 */
		do_action('woocommerce_before_main_content');

		if (!$narin_archive) :
			woocommerce_content();
		else :
			$narin_terms = narin_shop_filter_terms();
			?>
			<header class="shop-header">
				<?php if (apply_filters('woocommerce_show_page_title', true)) : ?>
					<h1 class="page-title"><?php woocommerce_page_title(); ?></h1>
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

			<div class="shop-layout">
				<div class="filter-backdrop" hidden></div>
				<aside class="shop-filters" id="narin-filters" aria-label="<?php esc_attr_e('Filtreler', 'narin'); ?>">
					<div class="shop-filters__head">
						<h2 class="shop-filters__title"><?php esc_html_e('Filtrele', 'narin'); ?></h2>
						<button type="button" class="header-tool filter-close"><?php narin_icon('x'); ?><span class="screen-reader-text"><?php esc_html_e('Filtreleri kapat', 'narin'); ?></span></button>
					</div>
					<?php if ($narin_terms) : ?>
						<div class="filter-group">
							<h3 class="filter-group__title"><?php echo is_product_category() ? esc_html__('Alt kategoriler', 'narin') : esc_html__('Kategoriler', 'narin'); ?></h3>
							<ul class="filter-list">
								<?php foreach ($narin_terms as $narin_term) : ?>
									<?php $narin_current = is_product_category($narin_term->slug); ?>
									<li>
										<a href="<?php echo esc_url(get_term_link($narin_term)); ?>"<?php echo $narin_current ? ' aria-current="page" class="is-active"' : ''; ?>>
											<span><?php echo esc_html($narin_term->name); ?></span>
											<span class="filter-list__count"><?php echo (int) $narin_term->count; ?></span>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php if (is_active_sidebar('shop-filters')) : ?>
						<div class="filter-widgets"><?php dynamic_sidebar('shop-filters'); ?></div>
					<?php endif; ?>
				</aside>

				<div class="shop-results">
					<?php if (woocommerce_product_loop()) : ?>
						<div class="shop-toolbar">
							<button type="button" class="btn btn-outline filter-toggle" aria-expanded="false" aria-controls="narin-filters"><?php narin_icon('sliders'); ?><?php esc_html_e('Filtrele', 'narin'); ?></button>
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
