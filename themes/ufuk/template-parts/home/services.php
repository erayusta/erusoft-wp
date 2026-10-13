<?php
/**
 * Home: services, built from the child pages of the page chosen in the Customizer.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!ufuk_setting('show_services')) {
	return;
}

$ufuk_parent = (int) ufuk_setting('services_page');
$ufuk_items  = $ufuk_parent ? ufuk_service_pages($ufuk_parent) : array();

if (!$ufuk_parent || get_post_status($ufuk_parent) !== 'publish' || !$ufuk_items) {
	ufuk_admin_hint(__('Hizmetler bölümü için bir "Hizmetler" sayfası seçin ve her hizmeti onun alt sayfası olarak ekleyin.', 'ufuk'), 'ufuk_sections');
	return;
}

$ufuk_title = ufuk_setting('services_title') ?: get_the_title($ufuk_parent);
$ufuk_intro = ufuk_setting('services_intro');
?>
<section class="section" id="hizmetler" aria-labelledby="services-title">
	<div class="container">
		<div class="section-head reveal">
			<h2 class="section-title" id="services-title"><?php echo esc_html($ufuk_title); ?></h2>
			<?php if ($ufuk_intro) : ?>
				<p class="section-desc"><?php echo esc_html($ufuk_intro); ?></p>
			<?php endif; ?>
		</div>
		<?php ufuk_service_cards($ufuk_items); ?>
		<div class="section-more reveal">
			<a href="<?php echo esc_url(get_permalink($ufuk_parent)); ?>" class="btn btn-ghost">
				<?php esc_html_e('Tüm hizmetler', 'ufuk'); ?>
				<?php ufuk_icon('arrow-right'); ?>
			</a>
		</div>
	</div>
</section>
