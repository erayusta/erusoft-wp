<?php
/**
 * Attribute filters for shop and product taxonomy archives.
 *
 * Groups come from WooCommerce's global attribute taxonomies (pa_*); each term shows how many published products
 * in the current archive carry it. Counts are cached in one transient per archive scope (6 hours) that stores the
 * cache version next to the data; a version bump on product, term or attribute changes makes the next request
 * recompute and overwrite it, so old versions never pile up as separate transients. Links use WooCommerce's native `filter_<attribute>` and
 * `query_type_<attribute>` query arguments, so WC_Query does the filtering itself.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Cache version; bumped on product, term and attribute changes.
 *
 * @return int
 */
function voltaj_filters_version() {
	return (int) get_option('voltaj_filters_ver', 1);
}

/**
 * Invalidate every cached filter set.
 */
function voltaj_filters_bump() {
	update_option('voltaj_filters_ver', voltaj_filters_version() + 1, true);
}
add_action('save_post_product', 'voltaj_filters_bump');
// Product saves that only change terms (catalogue visibility, attributes) do not always fire save_post_product.
add_action('woocommerce_new_product', 'voltaj_filters_bump');
add_action('woocommerce_update_product', 'voltaj_filters_bump');
add_action('woocommerce_attribute_added', 'voltaj_filters_bump');
add_action('woocommerce_attribute_updated', 'voltaj_filters_bump');
add_action('woocommerce_attribute_deleted', 'voltaj_filters_bump');
add_action('woocommerce_product_set_stock_status', 'voltaj_filters_bump');
add_action('woocommerce_variation_set_stock_status', 'voltaj_filters_bump');
add_action('update_option_woocommerce_hide_out_of_stock_items', 'voltaj_filters_bump');

/**
 * Invalidate after term edits in product taxonomies.
 *
 * @param int    $term_id
 * @param int    $tt_id
 * @param string $taxonomy
 */
function voltaj_filters_bump_on_term($term_id, $tt_id, $taxonomy) {
	if ($taxonomy === 'product_cat' || strpos((string) $taxonomy, 'pa_') === 0 || $taxonomy === voltaj_brand_taxonomy()) {
		voltaj_filters_bump();
	}
}
add_action('edited_term', 'voltaj_filters_bump_on_term', 10, 3);
add_action('delete_term', 'voltaj_filters_bump_on_term', 10, 3);

/**
 * Invalidate when a product is trashed or deleted.
 *
 * @param int $post_id
 */
function voltaj_filters_bump_on_delete($post_id) {
	if (get_post_type($post_id) === 'product') {
		voltaj_filters_bump();
	}
}
add_action('trashed_post', 'voltaj_filters_bump_on_delete');
add_action('deleted_post', 'voltaj_filters_bump_on_delete');

/**
 * Attribute filter groups for the current archive.
 *
 * @return array<int,array{label:string,name:string,terms:array<int,object>}>
 */
function voltaj_attribute_filters() {
	if (!function_exists('wc_get_attribute_taxonomies')) {
		return array();
	}
	$scope = is_product_taxonomy() ? get_queried_object() : null;
	$scope = $scope instanceof WP_Term ? $scope : null;
	$ver   = voltaj_filters_version();
	$key   = 'voltaj_filters_' . ($scope ? $scope->term_taxonomy_id : 0);

	$cached = get_transient($key);
	if (is_array($cached) && isset($cached['ver'], $cached['data']) && (int) $cached['ver'] === $ver && is_array($cached['data'])) {
		return $cached['data'];
	}

	global $wpdb;
	$scope_sql = '';
	if ($scope) {
		$ids = array((int) $scope->term_id);
		if (is_taxonomy_hierarchical($scope->taxonomy)) {
			$children = get_term_children($scope->term_id, $scope->taxonomy);
			if (!is_wp_error($children)) {
				$ids = array_merge($ids, array_map('intval', $children));
			}
		}
		$in        = implode(',', array_map('intval', $ids));
		$scope_sql = $wpdb->prepare(
			" JOIN {$wpdb->term_relationships} scope_tr ON scope_tr.object_id = tr.object_id
			  JOIN {$wpdb->term_taxonomy} scope_tt ON scope_tt.term_taxonomy_id = scope_tr.term_taxonomy_id
			       AND scope_tt.taxonomy = %s AND scope_tt.term_id IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$scope->taxonomy
		);
	}
	// Products the catalogue does not list: hidden from the catalogue, and out of stock when the store hides those.
	$hidden_tt = array();
	if (function_exists('wc_get_product_visibility_term_ids')) {
		$visibility = wc_get_product_visibility_term_ids();
		if (!empty($visibility['exclude-from-catalog'])) {
			$hidden_tt[] = (int) $visibility['exclude-from-catalog'];
		}
		if (get_option('woocommerce_hide_out_of_stock_items') === 'yes' && !empty($visibility['outofstock'])) {
			$hidden_tt[] = (int) $visibility['outofstock'];
		}
	}
	// wc_get_product_visibility_term_ids() returns term_taxonomy_ids, matched directly against term_relationships.
	$hidden_sql = $hidden_tt
		? " AND tr.object_id NOT IN (SELECT hidden_tr.object_id FROM {$wpdb->term_relationships} hidden_tr WHERE hidden_tr.term_taxonomy_id IN (" . implode(',', array_map('intval', $hidden_tt)) . '))'
		: '';

	$limit  = max(2, (int) apply_filters('voltaj_filter_terms_limit', 20));
	$groups = array();

	foreach (wc_get_attribute_taxonomies() as $attribute) {
		$taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);
		if (!taxonomy_exists($taxonomy)) {
			continue;
		}
		// Same term order as the attribute's "Default sort order" setting (custom order, name, numeric name or ID).
		$orderby  = (string) $attribute->attribute_orderby;
		$order_by = array(
			'menu_order' => 'ord ASC, t.name ASC',
			'id'         => 't.term_id ASC',
		);
		$order_sql = isset($order_by[$orderby]) ? $order_by[$orderby] : 't.name ASC';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- table names, fixed ORDER BY strings and a prepared scope fragment; cached in a transient.
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT tr.object_id) AS cnt, MAX(CAST(COALESCE(tm.meta_value, 0) AS SIGNED)) AS ord
			 FROM {$wpdb->terms} t
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
			 JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
			 LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = 'order'
			 {$scope_sql}
			 WHERE 1 = 1 {$hidden_sql}
			 GROUP BY t.term_id, t.name, t.slug
			 ORDER BY {$order_sql}
			 LIMIT %d",
			$taxonomy,
			$limit
		));
		// phpcs:enable
		if (!$rows) {
			continue;
		}
		if ($orderby === 'name_num') {
			usort($rows, static function ($a, $b) {
				return strnatcasecmp($a->name, $b->name);
			});
		}
		$groups[] = array(
			'label' => $attribute->attribute_label,
			'name'  => $attribute->attribute_name,
			'terms' => array_map(static function ($row) {
				return (object) array('name' => $row->name, 'slug' => $row->slug, 'count' => (int) $row->cnt);
			}, $rows),
		);
	}

	$groups = apply_filters('voltaj_attribute_filters', $groups, $scope);
	set_transient($key, array('ver' => $ver, 'data' => $groups), (int) apply_filters('voltaj_filters_cache_ttl', 6 * HOUR_IN_SECONDS));
	return $groups;
}

/**
 * Selected slugs of an attribute filter in the current request.
 *
 * @param string $name Attribute name without the pa_ prefix.
 * @return string[]
 */
function voltaj_filter_selected($name) {
	$param = 'filter_' . $name;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public filter links.
	$raw = isset($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
	return $raw === '' ? array() : array_values(array_filter(array_map('sanitize_title', explode(',', $raw))));
}

/**
 * Archive URL with the current filter, price, sort and search arguments (no pagination).
 *
 * @param bool $keep_filters Keep the attribute filters.
 * @return string
 */
function voltaj_filter_base_url($keep_filters = true) {
	if (is_product_taxonomy()) {
		$url = get_term_link(get_queried_object());
		$url = is_wp_error($url) ? home_url('/') : $url;
	} else {
		$url = (string) wc_get_page_permalink('shop');
	}
	$keep = array('min_price', 'max_price', 'orderby', 'rating_filter', 's', 'post_type');
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public filter links.
	foreach ($_GET as $key => $value) {
		$key = sanitize_key($key);
		if (!is_string($value)) {
			continue;
		}
		$is_filter = strpos($key, 'filter_') === 0 || strpos($key, 'query_type_') === 0;
		if (in_array($key, $keep, true) || ($keep_filters && $is_filter)) {
			$url = add_query_arg($key, rawurlencode(sanitize_text_field(wp_unslash($value))), $url);
		}
	}
	// phpcs:enable
	return $url;
}

/**
 * Whether any attribute filter is active.
 *
 * @return bool
 */
function voltaj_filters_active() {
	foreach (array_keys($_GET) as $key) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (strpos((string) $key, 'filter_') === 0) {
			return true;
		}
	}
	return false;
}

/**
 * Link that toggles one term of an attribute filter (OR within the attribute).
 *
 * @param string $name Attribute name without pa_.
 * @param string $slug Term slug.
 * @return string
 */
function voltaj_filter_toggle_url($name, $slug) {
	$selected = voltaj_filter_selected($name);
	if (in_array($slug, $selected, true)) {
		$selected = array_diff($selected, array($slug));
	} else {
		$selected[] = $slug;
	}
	$url = remove_query_arg(array('filter_' . $name, 'query_type_' . $name), voltaj_filter_base_url());
	if ($selected) {
		$url = add_query_arg(array(
			'filter_' . $name     => implode(',', $selected),
			'query_type_' . $name => 'or',
		), $url);
	}
	return $url;
}

/**
 * Render the attribute filter groups and the "clear" link.
 *
 * @return int Number of groups shown.
 */
function voltaj_render_attribute_filters() {
	$groups = voltaj_attribute_filters();
	$shown  = 0;
	foreach ($groups as $group) {
		$selected = voltaj_filter_selected($group['name']);
		// A group with a single value filters nothing, unless it is the active one.
		if (count($group['terms']) < 2 && !$selected) {
			continue;
		}
		$shown++;
		$id = 'vj-filter-' . sanitize_html_class($group['name']);
		?>
		<div class="vj-filter">
			<h3 class="vj-filter__title" id="<?php echo esc_attr($id); ?>"><?php echo esc_html($group['label']); ?></h3>
			<ul class="vj-filter__list" aria-labelledby="<?php echo esc_attr($id); ?>">
				<?php foreach ($group['terms'] as $term) : ?>
					<?php $on = in_array($term->slug, $selected, true); ?>
					<li>
						<a class="vj-filter__opt<?php echo $on ? ' is-on' : ''; ?>" href="<?php echo esc_url(voltaj_filter_toggle_url($group['name'], $term->slug)); ?>" rel="nofollow"<?php echo $on ? ' aria-current="true"' : ''; ?>>
							<span class="vj-filter__box" aria-hidden="true"><?php echo $on ? voltaj_get_icon('check') : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="vj-filter__name"><?php echo esc_html($term->name); ?><?php echo $on ? '<span class="screen-reader-text"> ' . esc_html__('(seçili, kaldırmak için tıklayın)', 'voltaj') . '</span>' : ''; ?></span>
							<span class="vj-filter__count"><?php echo (int) $term->count; ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
	if (voltaj_filters_active()) {
		printf('<a class="vj-filter__clear" href="%1$s">%2$s%3$s</a>', esc_url(voltaj_filter_base_url(false)), voltaj_get_icon('x'), esc_html__('Filtreleri temizle', 'voltaj')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return $shown;
}
