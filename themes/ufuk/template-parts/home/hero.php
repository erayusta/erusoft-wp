<?php
/**
 * Home: hero.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}

$ufuk_kicker = ufuk_setting('hero_kicker') ?: ufuk_site_description();
$ufuk_title  = ufuk_setting('hero_title') ?: ufuk_site_name();
$ufuk_text   = ufuk_setting('hero_text');
$ufuk_image  = (int) ufuk_setting('hero_image');
?>
<section class="hero" aria-labelledby="hero-title">
	<div class="container hero-grid">
		<div class="hero-content reveal">
			<?php if ($ufuk_kicker) : ?>
				<p class="kicker"><?php echo esc_html($ufuk_kicker); ?></p>
			<?php endif; ?>
			<h1 class="hero-title" id="hero-title"><?php echo esc_html($ufuk_title); ?></h1>
			<?php if ($ufuk_text) : ?>
				<p class="hero-subtitle"><?php echo esc_html($ufuk_text); ?></p>
			<?php endif; ?>
			<div class="hero-actions">
				<?php ufuk_button(ufuk_setting('hero_button_text'), ufuk_setting('hero_button_url'), 'btn-primary btn-lg', true); ?>
				<?php ufuk_button(ufuk_setting('hero_button2_text'), ufuk_setting('hero_button2_url'), 'btn-ghost btn-lg'); ?>
			</div>
		</div>
		<div class="hero-visual reveal<?php echo $ufuk_image ? ' has-image' : ''; ?>" data-reveal-delay="150">
			<?php
			if ($ufuk_image) {
				echo wp_get_attachment_image($ufuk_image, 'large', false, array('loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(min-width: 960px) 540px, 100vw'));
			} else {
				ufuk_icon('building');
			}
			?>
		</div>
	</div>
	<?php
	if (!ufuk_setting('hero_text') && !ufuk_setting('hero_button_text')) {
		ufuk_admin_hint(__('Giriş bölümünün başlığını, metnini, görselini ve düğmelerini Özelleştirici\'den girin.', 'ufuk'), 'ufuk_hero');
	}
	?>
</section>
