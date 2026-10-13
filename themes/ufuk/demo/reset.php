<?php
/**
 * Remove everything demo/setup.php created and restore the options it changed:
 *
 *   wp eval-file themes/ufuk/demo/reset.php
 *
 * Deletes posts, pages and images flagged with _ufuk_demo, terms (category, menus) flagged with the
 * _ufuk_demo term meta (only ones the demo created), the demo's ufuk_* theme mods and menu locations, then restores the
 * options, theme mods and menu locations saved on the first setup run and republishes the posts it drafted (unless edited since).
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}
if (!defined('WP_CLI') || !WP_CLI) {
	return;
}

$ufuk_ids = get_posts(array(
	'post_type'      => array('post', 'page', 'attachment'),
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_key'       => '_ufuk_demo',
));
foreach ($ufuk_ids as $ufuk_id) {
	if (get_post_type($ufuk_id) === 'attachment') {
		wp_delete_attachment($ufuk_id, true);
	} else {
		wp_delete_post($ufuk_id, true);
	}
}

$ufuk_terms = get_terms(array(
	'taxonomy'   => array('category', 'nav_menu'),
	'hide_empty' => false,
	'meta_key'   => '_ufuk_demo',
));
foreach (is_wp_error($ufuk_terms) ? array() : $ufuk_terms as $ufuk_term) {
	if ($ufuk_term->taxonomy === 'nav_menu') {
		wp_delete_nav_menu($ufuk_term->term_id);
	} else {
		wp_delete_term($ufuk_term->term_id, $ufuk_term->taxonomy);
	}
}

$ufuk_state = get_option('ufuk_demo_state');
$ufuk_state = is_array($ufuk_state) ? $ufuk_state : array();

// Republish posts the demo drafted, unless someone edited them since (post_modified changed).
$ufuk_skipped = array();
foreach ((array) ($ufuk_state['drafted_posts'] ?? array()) as $ufuk_pid => $ufuk_modified) {
	$ufuk_pid = (int) $ufuk_pid;
	if (get_post_status($ufuk_pid) !== 'draft') {
		continue;
	}
	if (get_post_field('post_modified_gmt', $ufuk_pid, 'raw') !== $ufuk_modified) {
		$ufuk_skipped[] = $ufuk_pid;
		continue;
	}
	wp_update_post(array('ID' => $ufuk_pid, 'post_status' => 'publish'));
}
if ($ufuk_skipped) {
	WP_CLI::warning('Edited since the demo drafted them, left as draft: ' . implode(', ', $ufuk_skipped));
}

foreach ((array) ($ufuk_state['options'] ?? array()) as $ufuk_key => $ufuk_value) {
	update_option($ufuk_key, $ufuk_value);
}

// Theme mods live in theme_mods_ufuk whether or not the theme is active: drop the demo's ufuk_* mods and
// menu locations, then put back whatever existed before the first setup run.
$ufuk_mods = get_option('theme_mods_ufuk');
if (is_array($ufuk_mods)) {
	foreach (array_keys($ufuk_mods) as $ufuk_key) {
		if (strpos((string) $ufuk_key, 'ufuk_') === 0) {
			unset($ufuk_mods[$ufuk_key]);
		}
	}
	foreach ((array) ($ufuk_state['theme_mods'] ?? array()) as $ufuk_key => $ufuk_value) {
		$ufuk_mods[$ufuk_key] = $ufuk_value;
	}
	if (isset($ufuk_state['nav_menu_locations']) && is_array($ufuk_state['nav_menu_locations'])) {
		$ufuk_mods['nav_menu_locations'] = $ufuk_state['nav_menu_locations'];
	} else {
		unset($ufuk_mods['nav_menu_locations']);
	}
	update_option('theme_mods_ufuk', $ufuk_mods);
}

delete_option('ufuk_demo_state');

WP_CLI::success(sprintf('Ufuk demo removed: %d posts/pages/images, %d terms.', count($ufuk_ids), is_wp_error($ufuk_terms) ? 0 : count($ufuk_terms)));
