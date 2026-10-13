<?php
/**
 * Front page: hero, featured categories, new products, the page's own content, latest posts and highlights.
 * Section order: filter narin_home_sections; each section is template-parts/home/<name>.php.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}

if ('posts' === get_option('show_on_front')) {
	get_template_part('index');
	return;
}

get_header();
?>
<main id="main-content" class="site-main home-main">
	<?php
	$narin_sections = (array) apply_filters('narin_home_sections', array('hero', 'categories', 'products', 'content', 'posts', 'highlights'));
	foreach ($narin_sections as $narin_section) {
		get_template_part('template-parts/home/' . sanitize_key($narin_section));
	}
	?>
</main>
<?php
get_footer();
