<?php
/**
 * 404: search, a way home and (with WooCommerce) the top categories.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content" class="vj-main vj-404">
	<div class="vj-container vj-container--narrow">
		<p class="vj-404__code" aria-hidden="true">404</p>
		<h1 class="vj-page-title"><?php esc_html_e('Aradığınız sayfa bulunamadı', 'voltaj'); ?></h1>
		<p><?php esc_html_e('Bağlantı değişmiş ya da yanlış yazılmış olabilir. Ürün adı veya koduyla arayabilir ya da bir kategoriden devam edebilirsiniz.', 'voltaj'); ?></p>
		<?php voltaj_search_form('page'); ?>
		<?php if (voltaj_wc_active() && ($voltaj_terms = voltaj_top_categories(8))) : ?>
			<ul class="vj-chip-list">
				<?php foreach ($voltaj_terms as $voltaj_term) : ?>
					<li><a href="<?php echo esc_url(get_term_link($voltaj_term)); ?>"><?php echo esc_html($voltaj_term->name); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p><a class="vj-btn vj-btn--ghost" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Ana sayfaya dön', 'voltaj'); ?></a></p>
	</div>
</main>
<?php
get_footer();
