<?php
/**
 * 404: search, a way home and (with WooCommerce) a few newest products.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content" class="site-main">
	<div class="container">
		<div class="not-found">
			<p class="eyebrow">404</p>
			<h1 class="page-title"><?php esc_html_e('Aradığınız sayfa bulunamadı', 'narin'); ?></h1>
			<p><?php esc_html_e('Bağlantı değişmiş ya da yanlış yazılmış olabilir. Arama yapabilir ya da ana sayfaya dönebilirsiniz.', 'narin'); ?></p>
			<?php get_search_form(); ?>
			<p class="not-found__actions">
				<a class="btn btn-outline" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Ana sayfa', 'narin'); ?></a>
				<?php if (narin_wc_active()) : ?>
					<a class="btn btn-primary" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Mağazaya git', 'narin'); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
		if (narin_wc_active()) {
			$narin_html = do_shortcode('[products limit="4" columns="4" orderby="date" order="DESC" visibility="visible"]');
			if (strpos($narin_html, 'class="products') !== false) {
				echo '<section class="section"><h2 class="section-title">' . esc_html__('Yeni ürünler', 'narin') . '</h2>' . $narin_html . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce shortcode output.
			}
		}
		?>
	</div>
</main>
<?php
get_footer();
