<?php
/**
 * Home: about, from the page chosen in the Customizer (title, excerpt, featured image) plus optional points.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!ufuk_setting('show_about')) {
	return;
}

$ufuk_page_id = (int) ufuk_setting('about_page');
$ufuk_page    = $ufuk_page_id ? get_post($ufuk_page_id) : null;
if (!$ufuk_page || $ufuk_page->post_status !== 'publish') {
	ufuk_admin_hint(__('Hakkımızda bölümü için bir sayfa seçin; başlık, özet ve öne çıkan görsel o sayfadan alınır.', 'ufuk'), 'ufuk_sections');
	return;
}

$ufuk_excerpt = ufuk_safe_excerpt($ufuk_page, 45);
$ufuk_points  = array_filter(array_map('trim', explode("\n", (string) ufuk_setting('about_points'))));
?>
<section class="section section-alt" id="hakkimizda" aria-labelledby="about-title">
	<div class="container split">
		<div class="split-visual reveal<?php echo has_post_thumbnail($ufuk_page) ? ' has-image' : ''; ?>">
			<?php
			if (has_post_thumbnail($ufuk_page)) {
				echo get_the_post_thumbnail($ufuk_page, 'large', array('loading' => 'lazy', 'sizes' => '(min-width: 960px) 560px, 100vw'));
			} else {
				ufuk_icon('building');
			}
			?>
		</div>
		<div class="reveal" data-reveal-delay="120">
			<h2 class="section-title" id="about-title"><?php echo esc_html(get_the_title($ufuk_page)); ?></h2>
			<?php if ($ufuk_excerpt) : ?>
				<p class="section-desc"><?php echo esc_html($ufuk_excerpt); ?></p>
			<?php endif; ?>
			<?php if ($ufuk_points) : ?>
				<ul class="check-list">
					<?php foreach ($ufuk_points as $ufuk_point) : ?>
						<li><?php ufuk_icon('check'); ?> <span><?php echo esc_html($ufuk_point); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="section-more-left">
				<a href="<?php echo esc_url(get_permalink($ufuk_page)); ?>" class="btn btn-ghost">
					<?php esc_html_e('Devamını okuyun', 'ufuk'); ?>
					<?php ufuk_icon('arrow-right'); ?>
				</a>
			</p>
		</div>
	</div>
</section>
