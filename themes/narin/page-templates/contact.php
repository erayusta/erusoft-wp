<?php
/**
 * Template Name: İletişim
 *
 * Page content, the contact details from the Customizer and the form shortcode entered there
 * (Görünüm → Özelleştir → Narin → Firma ve iletişim). The theme has no form of its own.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
$narin_shortcode = trim((string) narin_setting('contact_shortcode'));
?>
<main id="main-content" class="site-main">
	<?php
	while (have_posts()) :
		the_post();
		?>
		<article <?php post_class('page-content page-content--contact'); ?>>
			<div class="container">
				<header class="entry-header">
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>
				<div class="contact-layout<?php echo $narin_shortcode !== '' ? ' has-form' : ''; ?>">
					<div class="contact-layout__info">
						<?php if (trim(get_the_content()) !== '') : ?>
							<div class="entry-content"><?php the_content(); ?></div>
						<?php endif; ?>
						<?php narin_contact_list('contact-list contact-list--page'); ?>
						<?php narin_social_row(); ?>
						<?php
						if (!narin_contact_items()) {
							narin_admin_hint(__('Adres, telefon ve e-posta bilgilerini ekleyin.', 'narin'), 'narin_contact');
						}
						?>
					</div>
					<?php if ($narin_shortcode !== '') : ?>
						<div class="contact-layout__form"><?php echo do_shortcode($narin_shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?></div>
					<?php endif; ?>
				</div>
			</div>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
