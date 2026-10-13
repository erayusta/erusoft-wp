<?php
/**
 * Template Name: İletişim
 *
 * Page content followed by the contact details and form from the Customizer.
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
		?>
		<article <?php post_class('page-content page-content--contact'); ?>>
			<div class="container">
				<header class="entry-header">
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>
				<?php if (trim(get_the_content()) !== '') : ?>
					<div class="entry-content container-narrow-inner">
						<?php the_content(); ?>
					</div>
				<?php endif; ?>
			</div>
			<?php get_template_part('template-parts/contact-block', null, array('heading' => false)); ?>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
