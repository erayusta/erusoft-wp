<?php
/**
 * Home: latest posts.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!ufuk_setting('show_posts')) {
	return;
}

$ufuk_query = new WP_Query(array(
	'post_type'           => 'post',
	'posts_per_page'      => (int) ufuk_setting('posts_count'),
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
));
if (!$ufuk_query->have_posts()) {
	return;
}

$ufuk_blog = (int) get_option('page_for_posts');
?>
<section class="section" id="yazilar" aria-labelledby="posts-title">
	<div class="container">
		<div class="section-head reveal">
			<h2 class="section-title" id="posts-title"><?php echo esc_html(ufuk_setting('posts_title') ?: __('Son yazılar', 'ufuk')); ?></h2>
		</div>
		<div class="blog-grid blog-grid--3">
			<?php
			while ($ufuk_query->have_posts()) {
				$ufuk_query->the_post();
				get_template_part('template-parts/content', null, array('heading' => 'h3'));
			}
			wp_reset_postdata();
			?>
		</div>
		<?php if ($ufuk_blog) : ?>
			<div class="section-more reveal">
				<a href="<?php echo esc_url(get_permalink($ufuk_blog)); ?>" class="btn btn-ghost">
					<?php esc_html_e('Tüm yazılar', 'ufuk'); ?>
					<?php ufuk_icon('arrow-right'); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</section>
