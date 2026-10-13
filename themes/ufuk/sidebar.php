<?php
/**
 * Sidebar widget area (blog, archive, search).
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!is_active_sidebar('sidebar-1')) {
	return;
}
?>
<aside class="widget-area" aria-label="<?php esc_attr_e('Kenar çubuğu', 'ufuk'); ?>">
	<?php dynamic_sidebar('sidebar-1'); ?>
</aside>
