<?php
/**
 * Site header: skip link, branding, primary menu (a drop-down panel below 960 px), header button,
 * colour-mode toggle.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
$ufuk_auto_scheme = ufuk_setting('color_scheme') === 'auto';
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="no-js"<?php echo $ufuk_auto_scheme ? '' : ' data-theme="light"'; ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link" href="#main-content"><?php esc_html_e('İçeriğe geç', 'ufuk'); ?></a>

<header id="site-header" class="site-header">
	<div class="container header-inner">
		<?php ufuk_branding(); ?>

		<nav class="main-navigation" id="primary-navigation" aria-label="<?php esc_attr_e('Ana menü', 'ufuk'); ?>">
			<?php ufuk_primary_menu(); ?>
			<div class="nav-cta">
				<?php ufuk_button(ufuk_setting('header_cta_text'), ufuk_setting('header_cta_url'), 'btn-primary btn-block'); ?>
			</div>
		</nav>

		<div class="header-actions">
			<?php ufuk_button(ufuk_setting('header_cta_text'), ufuk_setting('header_cta_url'), 'btn-primary header-cta'); ?>
			<?php if ($ufuk_auto_scheme) : ?>
				<button type="button" class="icon-button theme-toggle" id="theme-toggle" aria-pressed="false">
					<span class="screen-reader-text"><?php esc_html_e('Koyu mod', 'ufuk'); ?></span>
					<span class="icon-moon"><?php ufuk_icon('moon'); ?></span>
					<span class="icon-sun"><?php ufuk_icon('sun'); ?></span>
				</button>
			<?php endif; ?>
			<button type="button" class="icon-button nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="primary-navigation">
				<span class="screen-reader-text"><?php esc_html_e('Menü', 'ufuk'); ?></span>
				<span class="icon-open"><?php ufuk_icon('menu'); ?></span>
				<span class="icon-close"><?php ufuk_icon('x'); ?></span>
			</button>
		</div>
	</div>
</header>
