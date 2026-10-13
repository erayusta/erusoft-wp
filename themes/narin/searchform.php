<?php
/**
 * Search form.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
$narin_search_id = wp_unique_id('narin-sf-');
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr($narin_search_id); ?>"><?php esc_html_e('Ara', 'narin'); ?></label>
	<input type="search" id="<?php echo esc_attr($narin_search_id); ?>" name="s" placeholder="<?php esc_attr_e('Ara…', 'narin'); ?>" value="<?php echo esc_attr(get_search_query()); ?>" required>
	<button type="submit" class="btn btn-primary"><?php narin_icon('search'); ?><span class="screen-reader-text"><?php esc_html_e('Ara', 'narin'); ?></span></button>
</form>
