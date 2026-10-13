<?php
/**
 * Site footer: company block (branding, short text, contact details, tax info, social links), up to three
 * menu columns and the bottom line.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
$voltaj_footer_text = trim((string) voltaj_setting('footer_text')) ?: voltaj_site_description();
$voltaj_footer_note = trim((string) voltaj_setting('footer_note'));
$voltaj_tax_info    = trim((string) voltaj_setting('tax_info'));
?>
<footer class="vj-footer">
	<div class="vj-container vj-footer__grid">
		<div class="vj-footer__company">
			<?php voltaj_branding(false); ?>
			<?php if ($voltaj_footer_text) : ?>
				<p class="vj-footer__text"><?php echo esc_html($voltaj_footer_text); ?></p>
			<?php endif; ?>
			<?php voltaj_contact_list('vj-contact-list vj-contact-list--footer'); ?>
			<?php if ($voltaj_tax_info !== '') : ?>
				<p class="vj-footer__tax"><?php echo esc_html($voltaj_tax_info); ?></p>
			<?php endif; ?>
			<?php voltaj_social_row(); ?>
		</div>
		<?php
		voltaj_footer_menu('footer-1');
		voltaj_footer_menu('footer-2');
		voltaj_footer_menu('footer-3');
		?>
	</div>
	<div class="vj-footer__bottom">
		<div class="vj-container vj-footer__bottom-inner">
			<p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html(voltaj_company_name()); ?></p>
			<?php if ($voltaj_footer_note !== '') : ?>
				<p><?php echo esc_html($voltaj_footer_note); ?></p>
			<?php endif; ?>
			<?php
			$voltaj_privacy = get_the_privacy_policy_link();
			if ($voltaj_privacy) {
				echo '<p>' . $voltaj_privacy . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped link.
			}
			?>
		</div>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
