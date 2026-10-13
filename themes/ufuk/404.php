<?php
/**
 * 404.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
$ufuk_contact_url = ufuk_contact_page_url();
?>
<main id="main-content">
	<section class="error-404">
		<div class="container">
			<p class="error-code" aria-hidden="true">404</p>
			<h1 class="section-title"><?php esc_html_e('Bu sayfayı bulamadık', 'ufuk'); ?></h1>
			<p class="section-desc"><?php esc_html_e('Aradığınız sayfa taşınmış ya da kaldırılmış olabilir. Ana sayfaya dönebilir veya arama yapabilirsiniz.', 'ufuk'); ?></p>
			<div class="error-actions">
				<a href="<?php echo esc_url(home_url('/')); ?>" class="btn btn-primary"><?php esc_html_e('Ana sayfaya dön', 'ufuk'); ?></a>
				<?php if ($ufuk_contact_url) : ?>
					<a href="<?php echo esc_url($ufuk_contact_url); ?>" class="btn btn-ghost"><?php esc_html_e('Bize ulaşın', 'ufuk'); ?></a>
				<?php endif; ?>
			</div>
			<?php get_search_form(); ?>
		</div>
	</section>
</main>
<?php
get_footer();
