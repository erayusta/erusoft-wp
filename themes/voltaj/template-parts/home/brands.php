<?php
/**
 * Home: brands strip (terms of the configured brand taxonomy, logo or name).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!voltaj_setting('show_brands')) {
	return;
}
$voltaj_brands = voltaj_brand_terms((int) apply_filters('voltaj_home_brands_limit', 12));
if (!$voltaj_brands) {
	return;
}
$voltaj_brands_page = voltaj_template_page_url('page-templates/brands.php');
?>
<section class="vj-section vj-home-brands" aria-labelledby="vj-brands-title">
	<div class="vj-container">
		<div class="vj-section__head">
			<h2 class="vj-section__title" id="vj-brands-title"><?php esc_html_e('Markalar', 'voltaj'); ?></h2>
			<?php if ($voltaj_brands_page !== '') : ?>
				<a class="vj-section__more" href="<?php echo esc_url($voltaj_brands_page); ?>"><?php esc_html_e('Tüm markalar', 'voltaj'); ?><?php voltaj_icon('arrow-right'); ?></a>
			<?php endif; ?>
		</div>
		<ul class="vj-brand-strip">
			<?php foreach ($voltaj_brands as $voltaj_brand) : ?>
				<?php
				$voltaj_link = get_term_link($voltaj_brand);
				$voltaj_logo = voltaj_brand_logo($voltaj_brand);
				if (is_wp_error($voltaj_link)) {
					continue;
				}
				?>
				<li>
					<a class="vj-brand-chip<?php echo $voltaj_logo ? ' has-logo' : ''; ?>" href="<?php echo esc_url($voltaj_link); ?>">
						<?php if ($voltaj_logo) : ?>
							<?php echo $voltaj_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="screen-reader-text"><?php echo esc_html($voltaj_brand->name); ?></span>
						<?php else : ?>
							<?php echo esc_html($voltaj_brand->name); ?>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
