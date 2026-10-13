<?php
/**
 * Home: latest posts.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!narin_setting('show_posts')) {
	return;
}
$narin_posts = get_posts(array('posts_per_page' => 3, 'post_status' => 'publish', 'ignore_sticky_posts' => true, 'has_password' => false));
if (!$narin_posts) {
	return;
}
$narin_heading = trim((string) narin_setting('posts_title')) ?: __('Günlük', 'narin');
$narin_blog    = (int) get_option('page_for_posts');
?>
<section class="section home-posts" aria-labelledby="narin-posts-title">
	<div class="container">
		<div class="section-head">
			<h2 class="section-title" id="narin-posts-title"><?php echo esc_html($narin_heading); ?></h2>
			<?php if ($narin_blog) : ?>
				<a class="text-link" href="<?php echo esc_url(get_permalink($narin_blog)); ?>"><?php esc_html_e('Tüm yazılar', 'narin'); ?><?php narin_icon('arrow-right'); ?></a>
			<?php endif; ?>
		</div>
		<div class="post-grid">
			<?php
			foreach ($narin_posts as $narin_post) {
				narin_post_card($narin_post, 'h3');
			}
			?>
		</div>
	</div>
</section>
