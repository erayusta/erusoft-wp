<?php
/**
 * Blog index, archives and search results.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();

if (is_search()) {
	/* translators: %s: search query */
	$narin_title = sprintf(__('"%s" için sonuçlar', 'narin'), get_search_query());
} elseif (is_archive()) {
	$narin_title = get_the_archive_title();
} elseif (is_home() && get_option('page_for_posts')) {
	$narin_title = get_the_title((int) get_option('page_for_posts'));
} else {
	$narin_title = __('Günlük', 'narin');
}
$narin_categories = get_categories(array('hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 8));
$narin_active     = is_category() ? (int) get_queried_object_id() : 0;
$narin_blog_url   = get_option('page_for_posts') ? get_permalink((int) get_option('page_for_posts')) : home_url('/');
?>
<main id="main-content" class="site-main">
	<div class="container">
		<header class="page-header">
			<h1 class="page-title"><?php echo wp_kses_post($narin_title); ?></h1>
			<?php
			$narin_description = get_the_archive_description();
			if ($narin_description) {
				echo '<div class="page-description">' . wp_kses_post($narin_description) . '</div>';
			}
			?>
			<?php if (!is_search() && count($narin_categories) > 1) : ?>
				<nav class="chips" aria-label="<?php esc_attr_e('Kategoriler', 'narin'); ?>">
					<a class="chip<?php echo $narin_active ? '' : ' is-active'; ?>" href="<?php echo esc_url($narin_blog_url); ?>"<?php echo $narin_active ? '' : ' aria-current="page"'; ?>><?php esc_html_e('Tümü', 'narin'); ?></a>
					<?php foreach ($narin_categories as $narin_cat) : ?>
						<a class="chip<?php echo $narin_active === (int) $narin_cat->term_id ? ' is-active' : ''; ?>" href="<?php echo esc_url(get_category_link($narin_cat)); ?>"<?php echo $narin_active === (int) $narin_cat->term_id ? ' aria-current="page"' : ''; ?>><?php echo esc_html($narin_cat->name); ?></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
		</header>

		<?php if (have_posts()) : ?>
			<div class="post-grid">
				<?php
				while (have_posts()) :
					the_post();
					if (get_post_type() === 'post') {
						narin_post_card(get_post(), 'h2');
					} else {
						?>
						<article <?php post_class('post-card post-card--plain'); ?>>
							<p class="post-card__meta"><?php echo esc_html(get_post_type_object(get_post_type())->labels->singular_name); ?></p>
							<h2 class="post-card__title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
							<?php $narin_excerpt = narin_safe_excerpt(get_post(), 20); ?>
							<?php if ($narin_excerpt !== '') : ?>
								<p class="post-card__excerpt"><?php echo esc_html($narin_excerpt); ?></p>
							<?php endif; ?>
						</article>
						<?php
					}
				endwhile;
				?>
			</div>
			<?php narin_pagination(); ?>
		<?php else : ?>
			<div class="empty-state">
				<p><?php echo is_search() ? esc_html__('Aramanızla eşleşen bir sonuç bulunamadı. Başka bir kelimeyle deneyin.', 'narin') : esc_html__('Henüz yazı yok.', 'narin'); ?></p>
				<?php get_search_form(); ?>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
