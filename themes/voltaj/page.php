<?php
/**
 * Pages. WooCommerce cart, checkout and account pages use the full width; other pages a readable column.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
$voltaj_wide = voltaj_wc_active() && (is_cart() || is_checkout() || is_account_page());
?>
<main id="main-content" class="vj-main vj-page<?php echo $voltaj_wide ? ' vj-page--shop' : ''; ?>">
	<?php while (have_posts()) : the_post(); ?>
		<div class="vj-container<?php echo $voltaj_wide ? '' : ' vj-container--narrow'; ?>">
			<header class="vj-page-head">
				<h1 class="vj-page-title"><?php the_title(); ?></h1>
			</header>
			<div class="<?php echo $voltaj_wide ? 'vj-shop-page' : 'vj-prose'; ?>">
				<?php the_content(); ?>
			</div>
			<?php
			wp_link_pages();
			if (!$voltaj_wide && (comments_open() || get_comments_number())) {
				comments_template();
			}
			?>
		</div>
	<?php endwhile; ?>
</main>
<?php
get_footer();
