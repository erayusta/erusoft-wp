<?php
/**
 * Site footer: branding, short text, social links, up to three menu columns, contact details, copyright.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
$narin_footer_text = trim((string) narin_setting('footer_text')) ?: narin_site_description();
$narin_footer_note = trim((string) narin_setting('footer_note'));
?>
<footer class="site-footer">
	<div class="container">
		<div class="footer-grid">
			<div class="footer-brand">
				<?php narin_branding('div'); ?>
				<?php if ($narin_footer_text) : ?>
					<p><?php echo esc_html($narin_footer_text); ?></p>
				<?php endif; ?>
				<?php narin_social_row(); ?>
			</div>

			<?php
			narin_footer_menu('footer-1');
			narin_footer_menu('footer-2');
			narin_footer_menu('footer-3');
			?>

			<?php if (narin_contact_items()) : ?>
				<div class="footer-col">
					<h2 class="footer-heading"><?php esc_html_e('İletişim', 'narin'); ?></h2>
					<?php narin_contact_list('contact-list contact-list--footer'); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="footer-bottom">
			<p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html(narin_company_name()); ?></p>
			<?php if ($narin_footer_note !== '') : ?>
				<p><?php echo esc_html($narin_footer_note); ?></p>
			<?php endif; ?>
			<?php
			$narin_privacy = get_the_privacy_policy_link();
			if ($narin_privacy) {
				echo '<p>' . $narin_privacy . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped link.
			}
			?>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
