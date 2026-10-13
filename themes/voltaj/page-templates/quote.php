<?php
/**
 * Template Name: Teklif İste
 *
 * Quote request: page content, optional intro text and the quote form (sent by e-mail to the Customizer
 * recipient, never stored). Opened from a product (?urun=ID), the product field is pre-filled.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
$voltaj_intro = trim((string) voltaj_setting('quote_intro'));
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
						<?php // Core prints the password form; the quote form stays hidden until the page is unlocked. ?>
						<div class="vj-prose"><?php the_content(); ?></div>
					<?php else : ?>
						<?php if (trim(get_the_content()) !== '') : ?>
							<div class="vj-prose"><?php the_content(); ?></div>
						<?php endif; ?>
						<?php if ($voltaj_intro !== '') : ?>
							<p class="vj-lead"><?php echo esc_html($voltaj_intro); ?></p>
						<?php endif; ?>
						<?php voltaj_form('quote'); ?>
					<?php endif; ?>
				</div>
				<aside class="vj-form-page__aside">
					<?php if (voltaj_contact_items()) : ?>
						<div class="vj-panel">
							<h2 class="vj-panel__title"><?php esc_html_e('Bize ulaşın', 'voltaj'); ?></h2>
							<?php voltaj_contact_list(); ?>
						</div>
					<?php endif; ?>
				</aside>
			</div>
		<?php endwhile; ?>
	</div>
</main>
<?php
get_footer();
