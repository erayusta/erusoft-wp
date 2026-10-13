<?php
/**
 * Home: hero. Title falls back to the site title, text to the tagline; buttons show only when filled in.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
$narin_title  = trim((string) narin_setting('hero_title')) ?: narin_site_name();
$narin_text   = trim((string) narin_setting('hero_text')) ?: narin_site_description();
$narin_kicker = trim((string) narin_setting('hero_kicker'));
$narin_image  = (int) narin_setting('hero_image');
$narin_src    = $narin_image ? wp_get_attachment_image($narin_image, 'narin-hero', false, array('class' => 'hero__img', 'alt' => '', 'fetchpriority' => 'high', 'loading' => 'eager', 'sizes' => '(min-width: 960px) 45vw, 100vw')) : '';
?>
<section class="hero<?php echo $narin_src ? ' has-image' : ''; ?>">
	<div class="container hero__inner">
		<div class="hero__copy">
			<?php if ($narin_kicker !== '') : ?>
				<p class="eyebrow"><?php echo esc_html($narin_kicker); ?></p>
			<?php endif; ?>
			<h1 class="hero__title"><?php echo esc_html($narin_title); ?></h1>
			<?php if ($narin_text) : ?>
				<p class="hero__text"><?php echo esc_html($narin_text); ?></p>
			<?php endif; ?>
			<div class="hero__actions">
				<?php
				narin_button(trim((string) narin_setting('hero_button_text')), narin_setting('hero_button_url'), 'btn-primary');
				narin_button(trim((string) narin_setting('hero_button2_text')), narin_setting('hero_button2_url'), 'btn-link');
				?>
			</div>
		</div>
		<?php if ($narin_src) : ?>
			<div class="hero__media">
				<div class="hero__frame"><?php echo $narin_src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?></div>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php
if (!$narin_src && trim((string) narin_setting('hero_title')) === '') {
	narin_admin_hint(__('Giriş bölümüne başlık, metin ve görsel ekleyebilirsiniz.', 'narin'), 'narin_hero');
}
