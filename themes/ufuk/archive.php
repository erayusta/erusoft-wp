<?php
/**
 * Archives: category, tag, date, author.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content" class="section">
	<div class="container">
		<header class="section-head align-left">
			<h1 class="section-title"><?php the_archive_title(); ?></h1>
			<?php the_archive_description('<div class="section-desc">', '</div>'); ?>
		</header>

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
					<p class="empty-state"><?php esc_html_e('Bu arşivde henüz içerik yok.', 'ufuk'); ?></p>
				<?php endif; ?>
			</div>
			<?php get_sidebar(); ?>
		</div>
	</div>
</main>
<?php
get_footer();
