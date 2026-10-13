<?php
/**
 * Site header: skip link, announcement bar, branding, primary menu, search, account and cart links,
 * mobile drawer.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
$narin_announcement = narin_announcement_text();
$narin_shop         = narin_wc_active();
$narin_search_id    = wp_unique_id('narin-search-');
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js">
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main-content"><?php esc_html_e('İçeriğe geç', 'narin'); ?></a>

<?php if ($narin_announcement !== '') : ?>
	<div class="announcement">
		<?php
		$narin_announcement_url = narin_setting('announcement_url');
		if ($narin_announcement_url) {
			printf('<a href="%1$s">%2$s</a>', esc_url($narin_announcement_url), esc_html($narin_announcement));
		} else {
			echo '<p>' . esc_html($narin_announcement) . '</p>';
		}
		?>
	</div>
<?php endif; ?>

<header id="site-header" class="site-header">
	<div class="container header-inner">
		<button type="button" class="header-tool menu-toggle" aria-expanded="false" aria-controls="narin-drawer">
			<?php narin_icon('menu'); ?><span class="screen-reader-text"><?php esc_html_e('Menüyü aç', 'narin'); ?></span>
		</button>

		<?php narin_branding(); ?>

		<nav class="main-navigation" id="primary-navigation" aria-label="<?php esc_attr_e('Ana menü', 'narin'); ?>">
			<?php narin_primary_menu('primary-menu'); ?>
		</nav>

		<div class="header-tools">
			<button type="button" class="header-tool search-toggle" aria-expanded="false" aria-controls="narin-search">
				<?php narin_icon('search'); ?><span class="header-tool__label"><?php esc_html_e('Ara', 'narin'); ?></span>
			</button>
			<?php if ($narin_shop) : ?>
				<a class="header-tool account-link" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
					<?php narin_icon('user'); ?><span class="header-tool__label"><?php esc_html_e('Hesabım', 'narin'); ?></span>
				</a>
				<?php echo narin_cart_link_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper. ?>
			<?php endif; ?>
		</div>
	</div>

	<div class="search-panel" id="narin-search" hidden>
		<div class="container">
			<form role="search" method="get" class="search-panel__form" action="<?php echo esc_url(home_url('/')); ?>">
				<label class="screen-reader-text" for="<?php echo esc_attr($narin_search_id); ?>"><?php esc_html_e('Ara', 'narin'); ?></label>
				<input type="search" id="<?php echo esc_attr($narin_search_id); ?>" name="s" placeholder="<?php echo esc_attr($narin_shop ? __('Ürün ara…', 'narin') : __('Sitede ara…', 'narin')); ?>" autocomplete="off" required>
				<?php if ($narin_shop) : ?>
					<input type="hidden" name="post_type" value="product">
				<?php endif; ?>
				<button type="submit" class="btn btn-primary"><?php esc_html_e('Ara', 'narin'); ?></button>
				<button type="button" class="header-tool search-close"><?php narin_icon('x'); ?><span class="screen-reader-text"><?php esc_html_e('Aramayı kapat', 'narin'); ?></span></button>
			</form>
		</div>
	</div>
</header>

<div class="drawer-backdrop" hidden></div>
<div class="drawer" id="narin-drawer" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Menü', 'narin'); ?>" hidden>
	<div class="drawer__head">
		<?php narin_branding(); ?>
		<button type="button" class="header-tool drawer-close"><?php narin_icon('x'); ?><span class="screen-reader-text"><?php esc_html_e('Menüyü kapat', 'narin'); ?></span></button>
	</div>
	<nav class="drawer__nav" aria-label="<?php esc_attr_e('Mobil menü', 'narin'); ?>">
		<?php narin_primary_menu('drawer-menu'); ?>
	</nav>
	<?php if ($narin_shop) : ?>
		<div class="drawer__links">
			<a class="btn btn-outline" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Hesabım', 'narin'); ?></a>
			<a class="btn btn-primary" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php esc_html_e('Sepet', 'narin'); ?></a>
		</div>
	<?php endif; ?>
	<?php narin_social_row(); ?>
</div>
