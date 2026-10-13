<?php
/**
 * Remove everything demo/setup.php created and restore the options it changed:
 *
 *   wp eval-file themes/narin/demo/reset.php
 *
 * Deletes products, variations, posts, pages and images flagged with _narin_demo; terms (product and post
 * categories, "Hacim" terms, menus) flagged with the _narin_demo term meta; the "Hacim" attribute and the
 * shipping zone when the demo created them; the Narin theme mods; republishes posts the demo drafted unless
 * they were edited since; and restores the options saved on the first setup run. Needs WooCommerce active.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!defined('WP_CLI') || !WP_CLI) {
	return;
}
if (!class_exists('WooCommerce')) {
	WP_CLI::error('The Narin demo reset needs WooCommerce active (products, the "Hacim" attribute and the shipping zone are WooCommerce data): wp plugin activate woocommerce');
}

$narin_state = get_option('narin_demo_state');
$narin_state = is_array($narin_state) ? $narin_state : array();

$narin_ids = get_posts(array(
	'post_type'      => array('post', 'page', 'attachment', 'product', 'product_variation'),
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_key'       => '_narin_demo',
));
foreach ($narin_ids as $narin_id) {
	$narin_type = get_post_type($narin_id);
	if ($narin_type === 'attachment') {
		wp_delete_attachment($narin_id, true);
	} elseif (in_array($narin_type, array('product', 'product_variation'), true) && function_exists('wc_get_product')) {
		$narin_product = wc_get_product($narin_id);
		if ($narin_product) {
			$narin_product->delete(true);
		} else {
			wp_delete_post($narin_id, true);
		}
	} else {
		wp_delete_post($narin_id, true);
	}
}

$narin_taxonomies = array_values(array_filter(array('category', 'nav_menu', 'product_cat', 'pa_hacim'), 'taxonomy_exists'));
if (!taxonomy_exists('pa_hacim') && function_exists('wc_attribute_taxonomy_id_by_name') && wc_attribute_taxonomy_id_by_name('hacim')) {
	register_taxonomy('pa_hacim', array('product'), array('hierarchical' => false, 'show_ui' => false, 'rewrite' => false));
	$narin_taxonomies[] = 'pa_hacim';
}
$narin_terms = get_terms(array(
	'taxonomy'   => $narin_taxonomies,
	'hide_empty' => false,
	// meta_query (not meta_key): WooCommerce replaces meta_key on its own taxonomies to sort them.
	'meta_query' => array(array('key' => '_narin_demo', 'compare' => 'EXISTS')),
));
foreach (is_wp_error($narin_terms) ? array() : $narin_terms as $narin_term) {
	if ($narin_term->taxonomy === 'nav_menu') {
		wp_delete_nav_menu($narin_term->term_id);
	} else {
		wp_delete_term($narin_term->term_id, $narin_term->taxonomy);
	}
}

if (!empty($narin_state['created_attribute']) && function_exists('wc_delete_attribute')) {
	wc_delete_attribute((int) $narin_state['created_attribute']);
}

if (!empty($narin_state['zone_id']) && class_exists('WC_Shipping_Zone')) {
	$narin_zone = new WC_Shipping_Zone((int) $narin_state['zone_id']);
	if ($narin_zone->get_id()) {
		foreach ($narin_zone->get_shipping_methods() as $narin_method) {
			delete_option('woocommerce_' . $narin_method->id . '_' . $narin_method->instance_id . '_settings');
		}
		$narin_zone->delete();
	}
	foreach ((array) ($narin_state['zone_orders'] ?? array()) as $narin_zone_id => $narin_zone_order) {
		$narin_other = new WC_Shipping_Zone((int) $narin_zone_id);
		if ($narin_other->get_id()) {
			$narin_other->set_zone_order((int) $narin_zone_order);
			$narin_other->save();
		}
	}
}

foreach ((array) ($narin_state['drafted_posts'] ?? array()) as $narin_pid => $narin_modified) {
	if (get_post_status($narin_pid) === 'draft' && get_post_field('post_modified_gmt', $narin_pid) === $narin_modified) {
		wp_update_post(array('ID' => (int) $narin_pid, 'post_status' => 'publish'));
	}
}

foreach ((array) ($narin_state['options'] ?? array()) as $narin_key => $narin_value) {
	if ($narin_value === null) {
		delete_option($narin_key);
	} else {
		update_option($narin_key, $narin_value);
	}
}

// Theme mods live in theme_mods_narin whether or not the theme is active. Remove what the demo wrote and put back
// the narin_* mods and menu locations saved before the first setup run.
$narin_mods = get_option('theme_mods_narin');
if (is_array($narin_mods)) {
	foreach (array_keys($narin_mods) as $narin_key) {
		if (strpos($narin_key, 'narin_') === 0 || $narin_key === 'nav_menu_locations') {
			unset($narin_mods[$narin_key]);
		}
	}
	foreach ((array) ($narin_state['theme_mods'] ?? array()) as $narin_key => $narin_value) {
		$narin_mods[$narin_key] = $narin_value;
	}
	update_option('theme_mods_narin', $narin_mods);
}

delete_option('narin_demo_state');

if (function_exists('wc_delete_product_transients')) {
	wc_delete_product_transients();
}
if (class_exists('WC_Cache_Helper')) {
	WC_Cache_Helper::get_transient_version('shipping', true);
}
flush_rewrite_rules(false);

WP_CLI::success(sprintf('Narin demo removed: %d products/posts/pages/images, %d terms.', count($narin_ids), is_wp_error($narin_terms) ? 0 : count($narin_terms)));
