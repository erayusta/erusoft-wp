<?php
/**
 * Home: latest posts.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!voltaj_setting('show_posts')) {
	return;
}
$voltaj_posts = get_posts(array('posts_per_page' => 3, 'post_status' => 'publish', 'ignore_sticky_posts' => true, 'has_password' => false));
if (!$voltaj_posts) {
	return;
}
$voltaj_heading = trim((string) voltaj_setting('posts_title')) ?: __('Blog', 'voltaj');
$voltaj_blog    = (int) get_option('page_for_posts');
?>
<section class="vj-section vj-home-posts" aria-labelledby="vj-posts-title">
	<div class="vj-container">
		<div class="vj-section__head">
			<h2 class="vj-section__title" id="vj-posts-title"><?php echo esc_html($voltaj_heading); ?></h2>
			<?php if ($voltaj_blog) : ?>
				<a class="vj-section__more" href="<?php echo esc_url(get_permalink($voltaj_blog)); ?>"><?php esc_html_e('Tüm yazılar', 'voltaj'); ?><?php voltaj_icon('arrow-right'); ?></a>
			<?php endif; ?>
		</div>
		<div class="vj-post-grid">
			<?php
			foreach ($voltaj_posts as $voltaj_post) {
				voltaj_post_card($voltaj_post, 'h3');
			}
			?>
		</div>
	</div>
</section>
