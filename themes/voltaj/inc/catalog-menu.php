<?php
/**
 * Catalogue panel ("Kategoriler"): a two-pane menu of up to three levels.
 *
 * Source: the "catalog" menu location when a menu is assigned to it, otherwise the product category tree.
 * The category version is cached as HTML in the `voltaj_nav_html` transient and rebuilt after category or
 * product changes. Opening, switching and closing are handled in assets/js/main.js (click, keyboard, Escape).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Product categories as nested nodes (three levels), "Uncategorized" excluded.
 *
 * @return array<int,array{id:string,title:string,url:string,children:array}>
 */
function voltaj_catalog_category_nodes() {
	if (!taxonomy_exists('product_cat')) {
		return array();
	}
	$limits = apply_filters('voltaj_catalog_limits', array('roots' => 16, 'children' => 24, 'leaves' => 8));
	$terms  = get_terms(array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'orderby'    => 'menu_order',
		'exclude'    => array((int) get_option('default_product_cat')),
	));
	if (is_wp_error($terms) || !$terms) {
		return array();
	}

	$by_parent = array();
	foreach ($terms as $term) {
		$by_parent[(int) $term->parent][] = $term;
	}

	$build = static function ($parent, $depth) use (&$build, $by_parent, $limits) {
		if (empty($by_parent[$parent]) || $depth > 3) {
			return array();
		}
		$limit = $depth === 1 ? $limits['roots'] : ($depth === 2 ? $limits['children'] : $limits['leaves']);
		$nodes = array();
		foreach (array_slice($by_parent[$parent], 0, max(1, (int) $limit)) as $term) {
			$link = get_term_link($term);
			if (is_wp_error($link)) {
				continue;
			}
			$nodes[] = array(
				'id'       => 't' . $term->term_id,
				'title'    => $term->name,
				'url'      => $link,
				'children' => $build((int) $term->term_id, $depth + 1),
			);
		}
		return $nodes;
	};

	return $build(0, 1);
}

/**
 * Items of the menu assigned to the "catalog" location as nested nodes (three levels).
 *
 * @return array<int,array{id:string,title:string,url:string,children:array}>
 */
function voltaj_catalog_menu_nodes() {
	$locations = get_nav_menu_locations();
	if (empty($locations['catalog'])) {
		return array();
	}
	$items = wp_get_nav_menu_items($locations['catalog'], array('update_post_term_cache' => false));
	if (!$items) {
		return array();
	}
	$by_parent = array();
	foreach ($items as $item) {
		$by_parent[(int) $item->menu_item_parent][] = $item;
	}
	$build = static function ($parent, $depth) use (&$build, $by_parent) {
		if (empty($by_parent[$parent]) || $depth > 3) {
			return array();
		}
		$nodes = array();
		foreach ($by_parent[$parent] as $item) {
			$nodes[] = array(
				'id'       => 'm' . $item->ID,
				'title'    => $item->title,
				'url'      => $item->url,
				'children' => $build((int) $item->ID, $depth + 1),
			);
		}
		return $nodes;
	};
	return $build(0, 1);
}

/**
 * Markup for a node tree. Roots with children are buttons that reveal their pane; roots without are links.
 *
 * @param array $nodes
 * @return string
 */
function voltaj_catalog_markup($nodes) {
	if (!$nodes) {
		return '';
	}
	$html = '<ul class="vj-cat-roots">';
	foreach ($nodes as $root) {
		$id = 'vj-cat-' . sanitize_html_class($root['id']);
		if (!$root['children']) {
			$html .= sprintf('<li class="vj-cat-root"><a class="vj-cat-root__link" href="%1$s">%2$s</a></li>', esc_url($root['url']), esc_html($root['title']));
			continue;
		}
		$html .= '<li class="vj-cat-root">';
		$html .= sprintf(
			'<button type="button" class="vj-cat-root__btn" id="%1$s-btn" aria-expanded="false" aria-controls="%1$s"><span>%2$s</span>%3$s</button>',
			esc_attr($id),
			esc_html($root['title']),
			voltaj_get_icon('chevron-right')
		);
		$html .= sprintf('<div class="vj-cat-pane" id="%1$s" role="group" aria-labelledby="%1$s-btn">', esc_attr($id));
		$html .= sprintf(
			'<a class="vj-cat-pane__all" href="%1$s">%2$s %3$s</a>',
			esc_url($root['url']),
			/* translators: %s: category name */
			esc_html(sprintf(__('Tüm %s ürünleri', 'voltaj'), $root['title'])),
			voltaj_get_icon('arrow-right')
		);
		$html .= '<ul class="vj-cat-cols">';
		foreach ($root['children'] as $child) {
			$html .= sprintf('<li class="vj-cat-col"><a class="vj-cat-col__title" href="%1$s">%2$s</a>', esc_url($child['url']), esc_html($child['title']));
			if ($child['children']) {
				$html .= '<ul class="vj-cat-leaves">';
				foreach ($child['children'] as $leaf) {
					$html .= sprintf('<li><a href="%1$s">%2$s</a></li>', esc_url($leaf['url']), esc_html($leaf['title']));
				}
				$html .= '</ul>';
			}
			$html .= '</li>';
		}
		$html .= '</ul></div></li>';
	}
	$html .= '</ul>';
	return $html;
}

/**
 * Catalogue menu HTML. The category-tree version is cached in the `voltaj_nav_html` transient (12 hours, per locale).
 *
 * @return string
 */
function voltaj_catalog_html() {
	$menu_nodes = voltaj_catalog_menu_nodes();
	if ($menu_nodes) {
		return voltaj_catalog_markup($menu_nodes);
	}

	if (!taxonomy_exists('product_cat')) {
		return '';
	}
	$locale = determine_locale();
	$cached = get_transient('voltaj_nav_html');
	if (is_array($cached) && isset($cached['locale'], $cached['html']) && $cached['locale'] === $locale) {
		return (string) $cached['html'];
	}
	$html = voltaj_catalog_markup(voltaj_catalog_category_nodes());
	set_transient('voltaj_nav_html', array('locale' => $locale, 'html' => $html), (int) apply_filters('voltaj_nav_cache_ttl', 12 * HOUR_IN_SECONDS));
	return $html;
}

/**
 * Drop the cached catalogue after category or product changes.
 */
function voltaj_flush_catalog_cache() {
	delete_transient('voltaj_nav_html');
}
add_action('created_product_cat', 'voltaj_flush_catalog_cache');
add_action('edited_product_cat', 'voltaj_flush_catalog_cache');
add_action('delete_product_cat', 'voltaj_flush_catalog_cache');
add_action('save_post_product', 'voltaj_flush_catalog_cache');
add_action('woocommerce_product_set_stock_status', 'voltaj_flush_catalog_cache');
add_action('switch_theme', 'voltaj_flush_catalog_cache');
add_action('activated_plugin', 'voltaj_flush_catalog_cache');
add_action('deactivated_plugin', 'voltaj_flush_catalog_cache');

/**
 * Also drop it when a product is trashed or deleted (category counts change).
 *
 * @param int $post_id
 */
function voltaj_flush_catalog_on_delete($post_id) {
	if (get_post_type($post_id) === 'product') {
		voltaj_flush_catalog_cache();
	}
}
add_action('trashed_post', 'voltaj_flush_catalog_on_delete');
add_action('untrashed_post', 'voltaj_flush_catalog_on_delete');
add_action('deleted_post', 'voltaj_flush_catalog_on_delete');

/**
 * Drop it when the product category term cache is cleaned (WooCommerce's drag-and-drop reorder goes through it).
 *
 * @param array  $ids
 * @param string $taxonomy
 */
function voltaj_flush_catalog_on_term_cache($ids, $taxonomy) {
	if ($taxonomy === 'product_cat') {
		voltaj_flush_catalog_cache();
	}
}
add_action('clean_term_cache', 'voltaj_flush_catalog_on_term_cache', 10, 2);

/**
 * Whether the header shows the "Kategoriler" button (there is something to show in the panel).
 *
 * @return bool
 */
function voltaj_has_catalog() {
	return voltaj_catalog_html() !== '';
}
