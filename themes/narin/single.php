<?php
/**
 * Single post.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content" class="site-main">
	<?php
	while (have_posts()) :
		the_post();
		$narin_cats = get_the_category();
		?>
		<article <?php post_class('single-post'); ?>>
			<header class="container container--narrow entry-header">
				<p class="eyebrow">
					<?php
					if ($narin_cats) {
						echo '<a href="' . esc_url(get_category_link($narin_cats[0])) . '">' . esc_html($narin_cats[0]->name) . '</a> · ';
					}
					?>
					<time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
				</p>
				<h1 class="entry-title"><?php the_title(); ?></h1>
			</header>
			<?php if (has_post_thumbnail()) : ?>
				<figure class="container entry-media"><?php the_post_thumbnail('large', array('alt' => '')); ?></figure>
			<?php endif; ?>
			<div class="container container--narrow entry-content">
				<?php
				the_content();
				wp_link_pages(array('before' => '<nav class="page-links">' . esc_html__('Sayfalar:', 'narin'), 'after' => '</nav>'));
				?>
			</div>
			<footer class="container container--narrow entry-footer">
				<?php the_tags('<p class="tag-list">', '', '</p>'); ?>
				<?php
				the_post_navigation(array(
					'prev_text' => '<span class="nav-label">' . esc_html__('Önceki yazı', 'narin') . '</span><span class="nav-title">%title</span>',
					'next_text' => '<span class="nav-label">' . esc_html__('Sonraki yazı', 'narin') . '</span><span class="nav-title">%title</span>',
				));
				if (comments_open() || get_comments_number()) {
					comments_template();
				}
				?>
			</footer>
		</article>
		<?php
		$narin_related = get_posts(array(
			'posts_per_page'      => 3,
			'post__not_in'        => array(get_the_ID()),
			'category__in'        => wp_list_pluck($narin_cats, 'term_id'),
			'ignore_sticky_posts' => true,
			'has_password'        => false,
		));
		if ($narin_related) :
			?>
			<section class="section related-posts" aria-labelledby="narin-related-title">
				<div class="container">
					<h2 class="section-title" id="narin-related-title"><?php esc_html_e('Bunlar da ilginizi çekebilir', 'narin'); ?></h2>
					<div class="post-grid">
						<?php
						foreach ($narin_related as $narin_post) {
							narin_post_card($narin_post, 'h3');
						}
						?>
					</div>
				</div>
			</section>
			<?php
		endif;
		if (is_active_sidebar('sidebar-1')) :
			?>
			<aside class="container container--narrow blog-widgets" aria-label="<?php esc_attr_e('Kenar çubuğu', 'narin'); ?>">
				<?php dynamic_sidebar('sidebar-1'); ?>
			</aside>
			<?php
		endif;
	endwhile;
	?>
</main>
<?php
get_footer();
