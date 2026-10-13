<?php
/**
 * Site footer: branding, short text, social links, footer menu, contact details, widgets, copyright.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
$ufuk_footer_text = ufuk_setting('footer_text') ?: ufuk_site_description();
$ufuk_contact     = ufuk_contact_items();
?>
	<footer class="site-footer">
		<div class="container">
			<div class="footer-grid">
				<div class="footer-brand">
					<?php ufuk_branding(false); ?>
					<?php if ($ufuk_footer_text) : ?>
						<p><?php echo esc_html($ufuk_footer_text); ?></p>
					<?php endif; ?>
					<?php ufuk_social_row(); ?>
				</div>

				<?php if (has_nav_menu('footer')) : ?>
					<nav class="footer-col" aria-label="<?php esc_attr_e('Alt bilgi menüsü', 'ufuk'); ?>">
						<h2 class="footer-heading"><?php echo esc_html(wp_get_nav_menu_name('footer')); ?></h2>
						<?php wp_nav_menu(array('theme_location' => 'footer', 'container' => false, 'menu_class' => 'menu', 'depth' => 1)); ?>
					</nav>
				<?php endif; ?>

				<?php if ($ufuk_contact) : ?>
					<div class="footer-col">
						<h2 class="footer-heading"><?php esc_html_e('İletişim', 'ufuk'); ?></h2>
						<ul class="footer-contact">
							<?php foreach ($ufuk_contact as $ufuk_item) : ?>
								<li class="footer-contact-<?php echo esc_attr($ufuk_item['key']); ?>">
									<span class="screen-reader-text"><?php echo esc_html($ufuk_item['label']); ?>:</span>
									<?php echo $ufuk_item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ufuk_contact_items(). ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>

			<?php if (is_active_sidebar('footer-1')) : ?>
				<div class="footer-widgets">
					<?php dynamic_sidebar('footer-1'); ?>
				</div>
			<?php endif; ?>

			<div class="footer-bottom">
				<p>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html(ufuk_company_name()); ?></p>
				<?php
				$ufuk_privacy = get_the_privacy_policy_link();
				if ($ufuk_privacy) {
					echo '<p>' . $ufuk_privacy . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-escaped link.
				}
				?>
			</div>
		</div>
	</footer>
<?php wp_footer(); ?>
</body>
</html>
