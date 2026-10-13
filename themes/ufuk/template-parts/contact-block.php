<?php
/**
 * Contact details + form. Used by the home page and the "İletişim" page template.
 *
 * Args: heading (bool) print the section heading.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}

$ufuk_items    = ufuk_contact_items();
$ufuk_has_form = trim((string) ufuk_setting('contact_shortcode')) !== '' || ufuk_setting('contact_form');
$ufuk_heading  = !empty($args['heading']);

if (!$ufuk_items && !$ufuk_has_form) {
	ufuk_admin_hint(__('İletişim bilgisi girilmedi ve form kapalı.', 'ufuk'), 'ufuk_contact');
	return;
}
?>
<section class="section" id="iletisim"<?php echo $ufuk_heading ? ' aria-labelledby="contact-title"' : ' aria-label="' . esc_attr__('İletişim', 'ufuk') . '"'; ?>>
	<div class="container">
		<?php if ($ufuk_heading) : ?>
			<div class="section-head reveal">
				<h2 class="section-title" id="contact-title"><?php esc_html_e('Bize ulaşın', 'ufuk'); ?></h2>
			</div>
		<?php endif; ?>
		<div class="contact-grid<?php echo $ufuk_items && $ufuk_has_form ? '' : ' is-single'; ?>">
			<?php if ($ufuk_items) : ?>
				<div class="contact-info reveal">
					<h3><?php esc_html_e('İletişim bilgileri', 'ufuk'); ?></h3>
					<ul class="contact-info-list">
						<?php foreach ($ufuk_items as $ufuk_item) : ?>
							<li class="contact-info-item">
								<span class="icon-wrap"><?php ufuk_icon($ufuk_item['icon']); ?></span>
								<span><strong><?php echo esc_html($ufuk_item['label']); ?></strong><?php echo $ufuk_item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ufuk_contact_items(). ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php ufuk_social_row(); ?>
				</div>
			<?php endif; ?>
			<?php if ($ufuk_has_form) : ?>
				<div class="reveal" data-reveal-delay="120">
					<?php ufuk_contact_form(); ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
