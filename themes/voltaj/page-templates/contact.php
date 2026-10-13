<?php
/**
 * Template Name: İletişim
 *
 * Contact page: contact details from the Customizer, page content and the contact form (or the form
 * shortcode set in the Customizer).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content" class="vj-main vj-page vj-form-page">
	<div class="vj-container">
		<?php while (have_posts()) : the_post(); ?>
			<header class="vj-page-head">
				<h1 class="vj-page-title"><?php the_title(); ?></h1>
			</header>
			<div class="vj-form-page__grid">
				<div class="vj-form-page__main">
					<?php if (post_password_required()) : ?>
						<?php // Core prints the password form; the contact form stays hidden until the page is unlocked. ?>
						<div class="vj-prose"><?php the_content(); ?></div>
					<?php else : ?>
						<?php if (trim(get_the_content()) !== '') : ?>
							<div class="vj-prose"><?php the_content(); ?></div>
						<?php endif; ?>
						<?php voltaj_form('contact'); ?>
					<?php endif; ?>
				</div>
				<aside class="vj-form-page__aside">
					<?php if (voltaj_contact_items()) : ?>
						<div class="vj-panel">
							<h2 class="vj-panel__title"><?php esc_html_e('İletişim bilgileri', 'voltaj'); ?></h2>
							<?php voltaj_contact_list(); ?>
						</div>
					<?php else : ?>
						<?php voltaj_admin_hint(__('Adres, telefon ve e-posta bilgilerini Özelleştirici\'den ekleyin.', 'voltaj'), 'voltaj_contact'); ?>
					<?php endif; ?>
				</aside>
			</div>
		<?php endwhile; ?>
	</div>
</main>
<?php
get_footer();
