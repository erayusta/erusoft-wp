<?php
/**
 * Single post.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content">
	<?php
	while (have_posts()) :
		the_post();
		?>
		<article <?php post_class('page-content'); ?>>
			<div class="container container-narrow">
				<header class="entry-header">
					<p class="entry-meta">
						<time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
						<?php if (has_category()) : ?>
							<span><?php the_category(', '); ?></span>
						<?php endif; ?>
					</p>
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>
				<?php if (has_post_thumbnail()) : ?>
					<figure class="entry-thumb">
						<?php the_post_thumbnail('large', array('loading' => 'eager')); ?>
					</figure>
				<?php endif; ?>
				<div class="entry-content">
					<?php
					the_content();
					wp_link_pages(array('before' => '<nav class="page-links" aria-label="' . esc_attr__('Yazı sayfaları', 'ufuk') . '">', 'after' => '</nav>'));
					?>
				</div>

				<?php if (has_tag()) : ?>
					<div class="entry-tags"><?php the_tags('', ''); ?></div>
				<?php endif; ?>

				<?php
				the_post_navigation(array(
					'prev_text' => '<span class="nav-label">' . esc_html__('Önceki yazı', 'ufuk') . '</span> %title',
					'next_text' => '<span class="nav-label">' . esc_html__('Sonraki yazı', 'ufuk') . '</span> %title',
					'aria_label' => __('Yazılar', 'ufuk'),
					'class'      => 'post-nav',
				));

				if (comments_open() || get_comments_number()) {
					comments_template();
				}
				?>
			</div>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
