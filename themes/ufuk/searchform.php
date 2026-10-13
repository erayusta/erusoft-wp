<?php
/**
 * Search form.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
$ufuk_search_id = wp_unique_id('ufuk-search-');
?>
<form role="search" method="get" class="search-form" action="<?php echo esc_url(home_url('/')); ?>">
	<label class="screen-reader-text" for="<?php echo esc_attr($ufuk_search_id); ?>"><?php esc_html_e('Ara', 'ufuk'); ?></label>
	<input type="search" id="<?php echo esc_attr($ufuk_search_id); ?>" name="s" placeholder="<?php esc_attr_e('Sitede ara…', 'ufuk'); ?>" value="<?php echo esc_attr(get_search_query()); ?>" required>
	<button type="submit" class="btn btn-primary">
		<span class="screen-reader-text"><?php esc_html_e('Ara', 'ufuk'); ?></span>
		<?php ufuk_icon('search'); ?>
	</button>
</form>
