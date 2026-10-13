<?php
/**
 * Remove everything demo/setup.php created and restore what it changed:
 *
 *   wp eval-file themes/voltaj/demo/reset.php
 *
 * Deletes products, variations, posts, pages and images flagged with _voltaj_demo; terms (product and post
 * categories, brands, "Güç"/"Renk" terms, menus) flagged with the _voltaj_demo term meta; the attributes and the
 * shipping zone only when the demo created them (other zones get their order back); republishes posts the demo
 * drafted unless they were edited since; removes the voltaj_* theme mods and menu locations the demo wrote and puts
 * back the ones saved before the first setup run; restores the saved options.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!defined('WP_CLI') || !WP_CLI) {
	return;
}
if (!class_exists('WooCommerce')) {
	WP_CLI::error('The Voltaj demo reset needs WooCommerce active (products, attributes and the shipping zone are WooCommerce data): wp plugin activate woocommerce');
}

$voltaj_state = get_option('voltaj_demo_state');
$voltaj_state = is_array($voltaj_state) ? $voltaj_state : array();

$voltaj_ids = get_posts(array(
	'post_type'      => array('post', 'page', 'attachment', 'product', 'product_variation'),
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_query'     => array(array('key' => '_voltaj_demo', 'compare' => 'EXISTS')),
));
foreach ($voltaj_ids as $voltaj_id) {
	$voltaj_type = get_post_type($voltaj_id);
	if (!$voltaj_type) {
		continue; // Already removed together with its parent product.
	}
	if ($voltaj_type === 'attachment') {
		wp_delete_attachment($voltaj_id, true);
	} elseif (in_array($voltaj_type, array('product', 'product_variation'), true) && function_exists('wc_get_product')) {
		$voltaj_product = wc_get_product($voltaj_id);
		if ($voltaj_product) {
			$voltaj_product->delete(true);
		} else {
			wp_delete_post($voltaj_id, true);
		}
	} else {
		wp_delete_post($voltaj_id, true);
	}
}

// Attribute taxonomies are registered by WooCommerce on init; make sure the demo's are queryable.
foreach (array('guc', 'renk') as $voltaj_attr) {
	if (!taxonomy_exists('pa_' . $voltaj_attr) && function_exists('wc_attribute_taxonomy_id_by_name') && wc_attribute_taxonomy_id_by_name($voltaj_attr)) {
		register_taxonomy('pa_' . $voltaj_attr, array('product'), array('hierarchical' => false, 'show_ui' => false, 'rewrite' => false));
	}
}
$voltaj_taxonomies = array_values(array_filter(array('category', 'nav_menu', 'product_cat', 'product_brand', 'pa_guc', 'pa_renk'), 'taxonomy_exists'));
$voltaj_terms      = get_terms(array(
	'taxonomy'   => $voltaj_taxonomies,
	'hide_empty' => false,
	// meta_query (not meta_key): WooCommerce replaces meta_key on its own taxonomies to sort them.
	'meta_query' => array(array('key' => '_voltaj_demo', 'compare' => 'EXISTS')),
));
foreach (is_wp_error($voltaj_terms) ? array() : $voltaj_terms as $voltaj_term) {
	if ($voltaj_term->taxonomy === 'nav_menu') {
		wp_delete_nav_menu($voltaj_term->term_id);
	} else {
		wp_delete_term($voltaj_term->term_id, $voltaj_term->taxonomy);
	}
}

if (function_exists('wc_delete_attribute')) {
	foreach ((array) ($voltaj_state['attributes'] ?? array()) as $voltaj_attr_id) {
		wc_delete_attribute((int) $voltaj_attr_id);
	}
}

if (!empty($voltaj_state['zone_id']) && class_exists('WC_Shipping_Zone')) {
	$voltaj_zone = new WC_Shipping_Zone((int) $voltaj_state['zone_id']);
	if ($voltaj_zone->get_id()) {
		foreach ($voltaj_zone->get_shipping_methods() as $voltaj_method) {
			delete_option('woocommerce_' . $voltaj_method->id . '_' . $voltaj_method->instance_id . '_settings');
		}
		$voltaj_zone->delete();
	}
	foreach ((array) ($voltaj_state['zone_orders'] ?? array()) as $voltaj_zone_id => $voltaj_zone_order) {
		$voltaj_other = new WC_Shipping_Zone((int) $voltaj_zone_id);
		if ($voltaj_other->get_id()) {
			$voltaj_other->set_zone_order((int) $voltaj_zone_order);
			$voltaj_other->save();
		}
	}
}

foreach ((array) ($voltaj_state['drafted_posts'] ?? array()) as $voltaj_pid => $voltaj_modified) {
	if (get_post_status($voltaj_pid) === 'draft' && get_post_field('post_modified_gmt', $voltaj_pid) === $voltaj_modified) {
		wp_update_post(array('ID' => (int) $voltaj_pid, 'post_status' => 'publish'));
	}
}

foreach ((array) ($voltaj_state['options'] ?? array()) as $voltaj_key => $voltaj_value) {
	if ($voltaj_value === null) {
		delete_option($voltaj_key);
	} else {
		update_option($voltaj_key, $voltaj_value);
	}
}

// Theme mods live in theme_mods_voltaj whether or not the theme is active. Remove what the demo wrote and put back
// the voltaj_* mods and menu locations saved before the first setup run.
$voltaj_mods = get_option('theme_mods_voltaj');
if (is_array($voltaj_mods)) {
	foreach (array_keys($voltaj_mods) as $voltaj_key) {
		if (strpos($voltaj_key, 'voltaj_') === 0 || $voltaj_key === 'nav_menu_locations') {
			unset($voltaj_mods[$voltaj_key]);
		}
	}
	foreach ((array) ($voltaj_state['theme_mods'] ?? array()) as $voltaj_key => $voltaj_value) {
		$voltaj_mods[$voltaj_key] = $voltaj_value;
	}
	update_option('theme_mods_voltaj', $voltaj_mods);
}

delete_option('voltaj_demo_state');
delete_transient('voltaj_nav_html');

if (function_exists('wc_delete_product_transients')) {
	wc_delete_product_transients();
}
if (class_exists('WC_Cache_Helper')) {
	WC_Cache_Helper::get_transient_version('shipping', true);
}
flush_rewrite_rules(false);

WP_CLI::success(sprintf('Voltaj demo removed: %d products/posts/pages/images, %d terms.', count($voltaj_ids), is_wp_error($voltaj_terms) ? 0 : count($voltaj_terms)));
