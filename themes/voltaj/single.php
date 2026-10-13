<?php
/**
 * Single post: header with reading time, a table of contents built from the headings (sticky column on wide
 * screens), content, tags, previous/next links, related posts and comments.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content" class="vj-main vj-single">
	<?php
	while (have_posts()) :
		the_post();
		$voltaj_locked = post_password_required();
		$voltaj_html   = apply_filters('the_content', get_the_content()); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$voltaj_html   = str_replace(']]>', ']]&gt;', $voltaj_html);
		$voltaj_toc    = array();
		if (!$voltaj_locked) {
			list($voltaj_toc, $voltaj_html) = voltaj_build_toc($voltaj_html);
		}
		$voltaj_cats = get_the_category();
		?>
		<article id="post-<?php the_ID(); ?>" <?php post_class('vj-article'); ?>>
			<header class="vj-article__head vj-container vj-container--narrow">
				<?php if ($voltaj_cats) : ?>
					<p class="vj-article__cat"><a href="<?php echo esc_url(get_category_link($voltaj_cats[0])); ?>"><?php echo esc_html($voltaj_cats[0]->name); ?></a></p>
				<?php endif; ?>
				<h1 class="vj-article__title"><?php the_title(); ?></h1>
				<p class="vj-article__meta">
					<time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date()); ?></time>
					<?php if (!$voltaj_locked) : ?>
						<span>
							<?php
							$voltaj_minutes = voltaj_reading_time(get_post());
							/* translators: %d: reading time in minutes */
							echo esc_html(sprintf(_n('%d dakikalık okuma', '%d dakikalık okuma', $voltaj_minutes, 'voltaj'), $voltaj_minutes));
							?>
						</span>
					<?php endif; ?>
				</p>
			</header>

			<?php if (has_post_thumbnail() && !$voltaj_locked) : ?>
				<figure class="vj-article__media vj-container"><?php the_post_thumbnail('large', array('alt' => '')); ?></figure>
			<?php endif; ?>

			<div class="vj-container vj-article__layout<?php echo count($voltaj_toc) >= (int) apply_filters('voltaj_toc_min_headings', 2) || is_active_sidebar('sidebar-blog') ? ' has-aside' : ''; ?>">
				<?php if (count($voltaj_toc) >= (int) apply_filters('voltaj_toc_min_headings', 2) || is_active_sidebar('sidebar-blog')) : ?>
					<aside class="vj-article__aside">
						<?php voltaj_toc($voltaj_toc); ?>
						<?php if (is_active_sidebar('sidebar-blog')) : ?>
							<div class="vj-article__widgets"><?php dynamic_sidebar('sidebar-blog'); ?></div>
						<?php endif; ?>
					</aside>
				<?php endif; ?>
				<div class="vj-article__body">
					<div class="vj-prose">
						<?php echo $voltaj_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered post content. ?>
					</div>
					<?php
					wp_link_pages(array(
						'before' => '<nav class="vj-page-links" aria-label="' . esc_attr__('Yazı sayfaları', 'voltaj') . '">',
						'after'  => '</nav>',
					));
					the_tags('<p class="vj-article__tags">', ' ', '</p>');
					the_post_navigation(array(
						'prev_text' => '<span class="vj-nav-label">' . esc_html__('Önceki yazı', 'voltaj') . '</span><span class="vj-nav-title">%title</span>',
						'next_text' => '<span class="vj-nav-label">' . esc_html__('Sonraki yazı', 'voltaj') . '</span><span class="vj-nav-title">%title</span>',
					));
					if (comments_open() || get_comments_number()) {
						comments_template();
					}
					?>
				</div>
			</div>
		</article>
		<?php
		$voltaj_related = $voltaj_cats ? get_posts(array(
			'posts_per_page'      => 3,
			'post__not_in'        => array(get_the_ID()),
			'category__in'        => wp_list_pluck($voltaj_cats, 'term_id'),
			'ignore_sticky_posts' => true,
			'has_password'        => false,
		)) : array();
		if ($voltaj_related) :
			?>
			<section class="vj-section vj-related-posts" aria-labelledby="vj-related-title">
				<div class="vj-container">
					<div class="vj-section__head"><h2 class="vj-section__title" id="vj-related-title"><?php esc_html_e('İlgili yazılar', 'voltaj'); ?></h2></div>
					<div class="vj-post-grid">
						<?php
						foreach ($voltaj_related as $voltaj_post) {
							voltaj_post_card($voltaj_post, 'h3');
						}
						?>
					</div>
				</div>
			</section>
			<?php
		endif;
	endwhile;
	?>
</main>
<?php
get_footer();
