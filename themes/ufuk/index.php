<?php
/**
 * Fallback template: blog listing, search results and any view without a more specific template.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();

$ufuk_blog_page = (int) get_option('page_for_posts');
?>
<main id="main-content" class="section">
	<div class="container">
		<header class="section-head align-left">
			<?php if (is_search()) : ?>
				<p class="kicker"><?php esc_html_e('Arama', 'ufuk'); ?></p>
				<h1 class="section-title">
					<?php
					/* translators: %s: search query */
					printf(esc_html__('“%s” için sonuçlar', 'ufuk'), esc_html(get_search_query(false)));
					?>
				</h1>
			<?php elseif (is_home() && $ufuk_blog_page && !is_front_page()) : ?>
				<h1 class="section-title"><?php echo esc_html(get_the_title($ufuk_blog_page)); ?></h1>
			<?php else : ?>
				<h1 class="section-title"><?php esc_html_e('Son yazılar', 'ufuk'); ?></h1>
			<?php endif; ?>
		</header>

		<?php
		if (is_front_page() && is_home()) {
			ufuk_admin_hint(__('Kurumsal ana sayfa bölümleri için Ayarlar → Okuma ekranında "Statik sayfa" seçin.', 'ufuk'), 'static_front_page');
		}
		?>

		<div class="blog-layout">
			<div class="blog-main">
				<?php if (have_posts()) : ?>
					<div class="blog-grid">
						<?php
						while (have_posts()) {
							the_post();
							get_template_part('template-parts/content');
						}
						?>
					</div>
					<?php ufuk_pagination(); ?>
				<?php else : ?>
					<p class="empty-state"><?php echo is_search() ? esc_html__('Aramanızla eşleşen bir içerik bulunamadı. Başka sözcüklerle tekrar deneyin.', 'ufuk') : esc_html__('Henüz yazı yayımlanmadı.', 'ufuk'); ?></p>
					<?php get_search_form(); ?>
				<?php endif; ?>
			</div>
			<?php get_sidebar(); ?>
		</div>
	</div>
</main>
<?php
get_footer();
