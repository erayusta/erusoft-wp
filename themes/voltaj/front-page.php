<?php
/**
 * Front page: search band, category grid, featured products, brands strip, info cards, latest posts and,
 * for a static front page, the page's own content.
 * Section order: filter voltaj_home_sections; each section is template-parts/home/<name>.php.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
get_header();
?>
<main id="main-content" class="vj-main vj-home">
	<?php
	$voltaj_sections = (array) apply_filters('voltaj_home_sections', array('hero', 'categories', 'products', 'brands', 'info', 'posts', 'content'));
	foreach ($voltaj_sections as $voltaj_section) {
		get_template_part('template-parts/home/' . sanitize_key($voltaj_section));
	}
	?>
</main>
<?php
get_footer();
