<?php
/**
 * Front page. With a static front page: the configurable corporate sections
 * (template-parts/home/*.php, filter ufuk_home_sections). With "latest posts": the blog listing.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}

if (get_option('show_on_front') !== 'page') {
	get_template_part('index');
	return;
}

get_header();

$ufuk_sections = apply_filters('ufuk_home_sections', array('hero', 'stats', 'content', 'services', 'about', 'posts', 'cta', 'contact'));
?>
<main id="main-content" class="home-sections">
	<?php
	foreach ($ufuk_sections as $ufuk_section) {
		get_template_part('template-parts/home/' . sanitize_key($ufuk_section));
	}
	?>
</main>
<?php
get_footer();
