<?php
/**
 * Home: search band (title, text, large product search, optional image).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
$voltaj_title = trim((string) voltaj_setting('hero_title')) ?: voltaj_site_name();
$voltaj_text  = trim((string) voltaj_setting('hero_text')) ?: voltaj_site_description();
$voltaj_image = (int) voltaj_setting('hero_image');
// Build the image first: a deleted or non-image attachment yields no markup, and then no media box either.
$voltaj_media = $voltaj_image ? wp_get_attachment_image($voltaj_image, 'large', false, array('alt' => '', 'fetchpriority' => 'high', 'sizes' => '(min-width: 1024px) 40vw, 100vw')) : '';
?>
<section class="vj-hero<?php echo $voltaj_media ? ' has-image' : ''; ?>" aria-labelledby="vj-hero-title">
	<div class="vj-container vj-hero__inner">
		<div class="vj-hero__copy">
			<h1 class="vj-hero__title" id="vj-hero-title"><?php echo esc_html($voltaj_title); ?></h1>
			<?php if ($voltaj_text !== '') : ?>
				<p class="vj-hero__text"><?php echo esc_html($voltaj_text); ?></p>
			<?php endif; ?>
			<?php voltaj_search_form('hero'); ?>
			<?php
			if (voltaj_wc_active()) {
				$voltaj_quick = voltaj_top_categories(5);
				if ($voltaj_quick) {
					echo '<ul class="vj-hero__quick" aria-label="' . esc_attr__('Hızlı erişim', 'voltaj') . '">';
					foreach ($voltaj_quick as $voltaj_term) {
						printf('<li><a href="%1$s">%2$s</a></li>', esc_url(get_term_link($voltaj_term)), esc_html($voltaj_term->name));
					}
					echo '</ul>';
				}
			}
			?>
		</div>
		<?php if ($voltaj_media) : ?>
			<div class="vj-hero__media">
				<?php echo $voltaj_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup. ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	if (!trim((string) voltaj_setting('hero_title'))) {
		echo '<div class="vj-container">';
		voltaj_admin_hint(__('Arama bandının başlığını, metnini ve görselini Özelleştirici\'den ayarlayın.', 'voltaj'), 'voltaj_hero');
		echo '</div>';
	}
	?>
</section>
