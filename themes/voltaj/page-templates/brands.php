<?php
/**
 * Template Name: Markalar
 *
 * Brand directory: every term of the configured brand taxonomy (Özelleştir → Voltaj → Mağaza ve markalar),
 * grouped by initial letter, with logo and product count. Shows an empty state when there are no brands.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
$voltaj_brands = voltaj_brand_terms();
$voltaj_groups = array();
foreach ($voltaj_brands as $voltaj_brand) {
	$voltaj_groups[voltaj_brand_letter($voltaj_brand->name)][] = $voltaj_brand;
}
uksort($voltaj_groups, 'voltaj_brand_letter_cmp');
?>
<main id="main-content" class="vj-main vj-page vj-brands">
	<div class="vj-container">
		<?php $voltaj_locked = false; ?>
		<?php while (have_posts()) : the_post(); ?>
			<?php $voltaj_locked = post_password_required(); ?>
			<header class="vj-page-head">
				<h1 class="vj-page-title"><?php the_title(); ?></h1>
				<?php if ($voltaj_locked || trim(get_the_content()) !== '') : ?>
					<?php // When locked, core prints the password form and the brand list stays hidden until it is unlocked. ?>
					<div class="vj-prose vj-page-head__desc"><?php the_content(); ?></div>
				<?php endif; ?>
			</header>
		<?php endwhile; ?>

		<?php if ($voltaj_locked) : ?>
			<?php // Nothing else until the page is unlocked. ?>
		<?php elseif (!$voltaj_groups) : ?>
			<div class="vj-empty">
				<?php voltaj_icon('package'); ?>
				<p><?php esc_html_e('Henüz listelenecek marka yok.', 'voltaj'); ?></p>
				<?php
				voltaj_admin_hint(
					voltaj_brand_taxonomy() === ''
						? __('Marka taksonomisi seçili değil ya da bulunamadı (WooCommerce 9.6 ve sonrası "product_brand" sağlar).', 'voltaj')
						: __('Ürünlere marka atadığınızda markalar burada harf sırasıyla listelenir.', 'voltaj'),
					'voltaj_shop'
				);
				?>
			</div>
		<?php else : ?>
			<nav class="vj-letters" aria-label="<?php esc_attr_e('Harfe göre markalar', 'voltaj'); ?>">
				<ul>
					<?php foreach (array_keys($voltaj_groups) as $voltaj_letter) : ?>
						<li><a href="#marka-<?php echo esc_attr(voltaj_brand_letter_id($voltaj_letter)); ?>"><?php echo esc_html($voltaj_letter); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<?php foreach ($voltaj_groups as $voltaj_letter => $voltaj_terms) : ?>
				<section class="vj-brand-group" id="marka-<?php echo esc_attr(voltaj_brand_letter_id($voltaj_letter)); ?>" aria-labelledby="vj-letter-<?php echo esc_attr(voltaj_brand_letter_id($voltaj_letter)); ?>">
					<h2 class="vj-brand-group__letter" id="vj-letter-<?php echo esc_attr(voltaj_brand_letter_id($voltaj_letter)); ?>"><?php echo esc_html($voltaj_letter); ?></h2>
					<ul class="vj-brand-grid">
						<?php foreach ($voltaj_terms as $voltaj_term) : ?>
							<?php
							$voltaj_link = get_term_link($voltaj_term);
							if (is_wp_error($voltaj_link)) {
								continue;
							}
							$voltaj_logo = voltaj_brand_logo($voltaj_term);
							?>
							<li>
								<a class="vj-brand-card" href="<?php echo esc_url($voltaj_link); ?>">
									<span class="vj-brand-card__logo<?php echo $voltaj_logo ? '' : ' is-empty'; ?>">
										<?php echo $voltaj_logo ? $voltaj_logo : '<span aria-hidden="true">' . esc_html(voltaj_upper_tr(mb_substr($voltaj_term->name, 0, 2, 'UTF-8'))) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</span>
									<span class="vj-brand-card__name"><?php echo esc_html($voltaj_term->name); ?></span>
									<span class="vj-brand-card__count">
										<?php
										/* translators: %s: number of products */
										echo esc_html(sprintf(_n('%s ürün', '%s ürün', (int) $voltaj_term->count, 'voltaj'), number_format_i18n((int) $voltaj_term->count)));
										?>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
