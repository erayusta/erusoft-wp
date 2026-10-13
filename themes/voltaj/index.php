<?php
/**
 * Blog index, archives and search results: a card grid with a page header.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();

if (is_search()) {
	/* translators: %s: search query */
	$voltaj_title = sprintf(__('"%s" için sonuçlar', 'voltaj'), get_search_query());
} elseif (is_archive()) {
	$voltaj_title = wp_strip_all_tags(get_the_archive_title());
} elseif (is_home() && !is_front_page()) {
	$voltaj_title = single_post_title('', false);
} else {
	$voltaj_title = __('Blog', 'voltaj');
}
$voltaj_desc = is_archive() ? get_the_archive_description() : '';
?>
<main id="main-content" class="vj-main vj-blog">
	<div class="vj-container">
		<header class="vj-page-head">
			<h1 class="vj-page-title"><?php echo esc_html($voltaj_title); ?></h1>
			<?php if ($voltaj_desc) : ?>
				<div class="vj-page-head__desc"><?php echo wp_kses_post($voltaj_desc); ?></div>
			<?php endif; ?>
			<?php if (is_search()) : ?>
				<?php get_search_form(); ?>
			<?php endif; ?>
		</header>

		<?php if (have_posts()) : ?>
			<div class="vj-post-grid">
				<?php
				while (have_posts()) {
					the_post();
					voltaj_post_card(get_post(), 'h2');
				}
				?>
			</div>
			<?php voltaj_pagination(); ?>
		<?php else : ?>
			<div class="vj-empty">
				<p><?php echo esc_html(is_search() ? __('Aramanızla eşleşen içerik bulunamadı. Farklı bir kelime deneyin.', 'voltaj') : __('Henüz yayımlanmış içerik yok.', 'voltaj')); ?></p>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
