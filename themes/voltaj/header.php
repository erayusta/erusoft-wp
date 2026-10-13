<?php
/**
 * Site header: skip link, optional announcement strip, one sticky bar (logo, catalogue button, search,
 * quote / account / cart) and the catalogue panel (desktop dropdown, mobile drawer).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
$voltaj_announcement = voltaj_announcement_text();
$voltaj_shop         = voltaj_wc_active();
$voltaj_catalog      = voltaj_catalog_html();
$voltaj_quote_url    = voltaj_quote_url();
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js">
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main-content"><?php esc_html_e('İçeriğe geç', 'voltaj'); ?></a>

<?php if ($voltaj_announcement !== '') : ?>
	<div class="vj-announce">
		<div class="vj-container">
			<?php
			$voltaj_announcement_url = voltaj_setting('announcement_url');
			if ($voltaj_announcement_url) {
				printf('<a href="%1$s">%2$s%3$s</a>', esc_url($voltaj_announcement_url), esc_html($voltaj_announcement), voltaj_get_icon('arrow-right')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo '<p>' . esc_html($voltaj_announcement) . '</p>';
			}
			?>
		</div>
	</div>
<?php endif; ?>

<header id="site-header" class="vj-header">
	<div class="vj-container vj-header__bar">
		<?php voltaj_branding(); ?>

		<button type="button" class="vj-catalog-toggle" aria-expanded="false" aria-controls="vj-catalog" data-vj-catalog-toggle>
			<?php voltaj_icon('menu'); ?>
			<span class="vj-catalog-toggle__label"><?php echo esc_html($voltaj_catalog !== '' ? __('Kategoriler', 'voltaj') : __('Menü', 'voltaj')); ?></span>
		</button>

		<div class="vj-header__search">
			<?php voltaj_search_form('header'); ?>
		</div>

		<div class="vj-header__tools">
			<?php if ($voltaj_quote_url !== '') : ?>
				<a class="vj-tool vj-tool--quote" href="<?php echo esc_url($voltaj_quote_url); ?>">
					<?php voltaj_icon('file-text'); ?><span class="vj-tool__label"><?php esc_html_e('Teklif iste', 'voltaj'); ?></span>
				</a>
			<?php endif; ?>
			<?php if ($voltaj_shop) : ?>
				<a class="vj-tool vj-tool--account" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
					<?php voltaj_icon('user'); ?><span class="vj-tool__label"><?php echo esc_html(is_user_logged_in() ? __('Hesabım', 'voltaj') : __('Giriş yap', 'voltaj')); ?></span>
				</a>
				<?php echo voltaj_cart_link_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="vj-catalog-backdrop" data-vj-catalog-backdrop hidden></div>
	<div class="vj-catalog" id="vj-catalog" role="dialog" aria-modal="false" aria-label="<?php echo esc_attr($voltaj_catalog !== '' ? __('Ürün kategorileri', 'voltaj') : __('Menü', 'voltaj')); ?>" data-vj-catalog hidden>
		<div class="vj-catalog__head">
			<?php voltaj_branding(); ?>
			<button type="button" class="vj-tool vj-catalog__close" data-vj-catalog-close>
				<?php voltaj_icon('x'); ?><span class="screen-reader-text"><?php esc_html_e('Kapat', 'voltaj'); ?></span>
			</button>
		</div>
		<div class="vj-container vj-catalog__body">
			<?php if ($voltaj_catalog !== '') : ?>
				<nav class="vj-catalog__nav" aria-label="<?php esc_attr_e('Kategoriler', 'voltaj'); ?>">
					<?php echo $voltaj_catalog; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in voltaj_catalog_markup(). ?>
				</nav>
			<?php endif; ?>
			<div class="vj-catalog__aside">
				<nav aria-label="<?php esc_attr_e('Sayfalar', 'voltaj'); ?>">
					<?php voltaj_primary_menu('vj-catalog-links', 'vj-links'); ?>
				</nav>
				<?php if ($voltaj_shop) : ?>
					<div class="vj-catalog__account">
						<a class="vj-btn vj-btn--ghost" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php voltaj_icon('user'); ?><?php echo esc_html(is_user_logged_in() ? __('Hesabım', 'voltaj') : __('Giriş yap', 'voltaj')); ?></a>
						<?php if ($voltaj_quote_url !== '') : ?>
							<a class="vj-btn vj-btn--ghost" href="<?php echo esc_url($voltaj_quote_url); ?>"><?php voltaj_icon('file-text'); ?><?php esc_html_e('Teklif iste', 'voltaj'); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</header>
