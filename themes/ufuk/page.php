<?php
/**
 * Generic page. The page chosen as "Hizmetler sayfası" also lists its child pages as cards.
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
		$ufuk_services = (int) ufuk_setting('services_page') === get_the_ID() ? ufuk_service_pages(get_the_ID()) : array();
		?>
		<article <?php post_class('page-content'); ?>>
			<div class="container<?php echo $ufuk_services ? '' : ' container-narrow'; ?>">
				<header class="entry-header">
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>
				<?php if (has_post_thumbnail()) : ?>
					<figure class="entry-thumb">
						<?php the_post_thumbnail('large', array('loading' => 'eager')); ?>
					</figure>
				<?php endif; ?>
				<div class="entry-content<?php echo $ufuk_services ? ' container-narrow-inner' : ''; ?>">
					<?php
					the_content();
					wp_link_pages(array('before' => '<nav class="page-links" aria-label="' . esc_attr__('Sayfalar', 'ufuk') . '">', 'after' => '</nav>'));
					?>
				</div>
				<?php if ($ufuk_services) : ?>
					<div class="page-services">
						<?php ufuk_service_cards($ufuk_services, 'h2'); ?>
					</div>
				<?php endif; ?>
				<?php
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
