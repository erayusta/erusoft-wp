<?php
/**
 * Search form (core get_search_form()). Searches posts and pages; the header search covers products.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
$voltaj_search_id = wp_unique_id('vj-sf-');
?>
<form role="search" method="get" class="vj-search vj-search--page" action="<?php echo esc_url(home_url('/')); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr($voltaj_search_id); ?>"><?php esc_html_e('Ara', 'voltaj'); ?></label>
	<input type="search" id="<?php echo esc_attr($voltaj_search_id); ?>" name="s" placeholder="<?php esc_attr_e('Sitede arayın', 'voltaj'); ?>" value="<?php echo esc_attr(get_search_query()); ?>" required>
	<button type="submit" class="vj-search__submit"><?php voltaj_icon('search'); ?><span class="screen-reader-text"><?php esc_html_e('Ara', 'voltaj'); ?></span></button>
</form>
