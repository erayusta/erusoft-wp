<?php
/**
 * Pages, including the WooCommerce cart, checkout and account pages (shortcode or block content).
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();

$narin_shop_page = narin_wc_active() && (is_cart() || is_checkout() || is_account_page());
?>
<main id="main-content" class="site-main">
	<?php
	while (have_posts()) :
		the_post();
		?>
		<article <?php post_class($narin_shop_page ? 'shop-page' : 'page-content'); ?>>
			<div class="container<?php echo $narin_shop_page ? '' : ' container--narrow'; ?>">
				<?php if (!(narin_wc_active() && is_wc_endpoint_url('order-received'))) : ?>
					<header class="entry-header">
						<h1 class="entry-title"><?php the_title(); ?></h1>
					</header>
				<?php endif; ?>
				<?php if (has_post_thumbnail() && !$narin_shop_page) : ?>
					<figure class="entry-media"><?php the_post_thumbnail('large', array('alt' => '')); ?></figure>
				<?php endif; ?>
				<div class="entry-content">
					<?php
					the_content();
					wp_link_pages(array('before' => '<nav class="page-links">' . esc_html__('Sayfalar:', 'narin'), 'after' => '</nav>'));
					?>
				</div>
				<?php
				if (!$narin_shop_page && (comments_open() || get_comments_number())) {
					comments_template();
				}
				?>
			</div>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
