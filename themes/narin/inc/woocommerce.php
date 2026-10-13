<?php
/**
 * WooCommerce integration. Loaded only when WooCommerce is active (see functions.php).
 *
 * The theme ships no WooCommerce template overrides: every change is made with WooCommerce's own hooks and
 * filters plus CSS, so core notices, structured data and other plugins' output keep working.
 *
 * @package Narin
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Hook surgery that has to run after WooCommerce registered its own callbacks.
 */
function narin_wc_hooks() {
	// The theme renders its own content wrapper (woocommerce.php) and a filter drawer instead of the sidebar.
	remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
	remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
	remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
	// woocommerce.php prints its own archive header, then fires woocommerce_shop_loop_header for other callbacks.
	remove_action('woocommerce_shop_loop_header', 'woocommerce_product_taxonomy_archive_header', 10);

	// Cross-sells span the full width below the two cart columns.
	remove_action('woocommerce_cart_collaterals', 'woocommerce_cross_sell_display');
	add_action('woocommerce_after_cart', 'woocommerce_cross_sell_display', 5);
}
add_action('wp', 'narin_wc_hooks');

/**
 * The header cart count (shown on every page while WooCommerce is active) is kept fresh by the cart fragments
 * script. Not loaded on the cart and checkout pages: they render the cart server-side and update it themselves.
 */
function narin_wc_assets() {
	if (is_cart() || is_checkout()) {
		return;
	}
	wp_enqueue_script('wc-cart-fragments');
}
add_action('wp_enqueue_scripts', 'narin_wc_assets');

/**
 * Breadcrumb markup.
 *
 * @param array $defaults
 * @return array
 */
function narin_wc_breadcrumb_defaults($defaults) {
	$defaults['delimiter']   = '<span class="sep" aria-hidden="true">/</span>';
	$defaults['wrap_before'] = '<nav class="woocommerce-breadcrumb narin-breadcrumb" aria-label="' . esc_attr__('Sayfa yolu', 'narin') . '">';
	$defaults['wrap_after']  = '</nav>';
	$defaults['home']        = __('Ana sayfa', 'narin');
	return $defaults;
}
add_filter('woocommerce_breadcrumb_defaults', 'narin_wc_breadcrumb_defaults');

/**
 * Header cart link (also used as a cart fragment).
 *
 * @return string
 */
function narin_cart_link_html() {
	$count = narin_cart_count();
	return sprintf(
		'<a class="header-tool cart-link" href="%1$s">%2$s<span class="header-tool__label">%3$s</span><span class="narin-cart-count" aria-hidden="true">%4$d</span><span class="screen-reader-text">%5$s</span></a>',
		esc_url(wc_get_cart_url()),
		narin_get_icon('bag'),
		esc_html__('Sepet', 'narin'),
		$count,
		/* translators: %d: number of items in the cart */
		esc_html(sprintf(_n('%d ürün', '%d ürün', $count, 'narin'), $count))
	);
}

/**
 * Cart fragments: header cart link and the free-shipping bar.
 *
 * @param array $fragments
 * @return array
 */
function narin_wc_fragments($fragments) {
	$fragments['a.cart-link'] = narin_cart_link_html();
	ob_start();
	narin_free_shipping_bar();
	$fragments['div.narin-shipbar'] = ob_get_clean();
	return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'narin_wc_fragments');

/**
 * Cart subtotal as WooCommerce's free-shipping method compares it.
 *
 * @param bool $ignore_discounts Whether the method ignores coupon discounts.
 * @return float
 */
function narin_free_shipping_subtotal($ignore_discounts) {
	$cart  = WC()->cart;
	$total = (float) $cart->get_displayed_subtotal();
	if (!$ignore_discounts) {
		$total -= (float) $cart->get_discount_total();
		if ($cart->display_prices_including_tax()) {
			$total -= (float) $cart->get_discount_tax();
		}
	}
	return round($total, wc_get_price_decimals());
}

/**
 * Free-shipping state for the current customer, read from the "Free shipping" methods of the zone that
 * matches the cart's shipping destination. Null when no method applies without a coupon, when free
 * shipping needs a coupon the cart does not have, or when shipping is disabled.
 *
 * @return array{threshold:float,subtotal:float,remaining:float,percent:int}|null
 */
function narin_free_shipping_state() {
	if (!function_exists('WC') || !WC()->cart || !wc_shipping_enabled() || !class_exists('WC_Shipping_Zones')) {
		return null;
	}
	$cart     = WC()->cart;
	$packages = $cart->get_shipping_packages();
	$package  = $packages ? reset($packages) : array();
	if (empty($package['destination'])) {
		return null;
	}
	$has_coupon = false;
	foreach ($cart->get_coupons() as $coupon) {
		if ($coupon->is_valid() && $coupon->get_free_shipping()) {
			$has_coupon = true;
			break;
		}
	}

	$best = null;
	$zone = WC_Shipping_Zones::get_zone_matching_package($package);
	foreach ($zone->get_shipping_methods(true) as $method) {
		if ($method->id !== 'free_shipping') {
			continue;
		}
		$requires = (string) $method->get_option('requires');
		$min      = (float) $method->get_option('min_amount', 0);
		$ignore   = $method->get_option('ignore_discounts') === 'yes';
		if ($requires === '' || ($requires === 'coupon' && $has_coupon) || ($requires === 'either' && $has_coupon)) {
			return null; // Already free: nothing to encourage.
		}
		if (in_array($requires, array('min_amount', 'either'), true) || ($requires === 'both' && $has_coupon)) {
			if ($min <= 0) {
				return null;
			}
			if ($best === null || $min < $best['threshold']) {
				$best = array('threshold' => $min, 'ignore' => $ignore);
			}
		}
	}
	if ($best === null) {
		return null;
	}

	$threshold = (float) apply_filters('narin_free_shipping_threshold', $best['threshold'], $zone);
	if ($threshold <= 0) {
		return null;
	}
	$subtotal = $cart->is_empty() ? 0.0 : narin_free_shipping_subtotal($best['ignore']);
	return array(
		'threshold' => $threshold,
		'subtotal'  => $subtotal,
		'remaining' => max(0.0, $threshold - $subtotal),
		'percent'   => (int) min(100, max(0, round($subtotal / $threshold * 100))),
	);
}

/**
 * Free-shipping progress bar. Always prints its wrapper (hidden when empty) so the cart fragment can replace it.
 */
function narin_free_shipping_bar() {
	$state = narin_setting('free_shipping_bar') ? narin_free_shipping_state() : null;
	if (!$state) {
		echo '<div class="narin-shipbar" hidden></div>';
		return;
	}
	if ($state['subtotal'] <= 0) {
		/* translators: %s: free-shipping threshold */
		$message = sprintf(esc_html__('%s ve üzeri siparişlerde kargo ücretsiz.', 'narin'), '<strong>' . wc_price($state['threshold']) . '</strong>');
	} elseif ($state['remaining'] > 0) {
		/* translators: %s: amount still needed for free shipping */
		$message = sprintf(esc_html__('Ücretsiz kargo için %s daha ekleyin.', 'narin'), '<strong>' . wc_price($state['remaining']) . '</strong>');
	} else {
		$message = '<strong>' . esc_html__('Siparişiniz ücretsiz kargo için uygun.', 'narin') . '</strong>';
	}
	?>
	<div class="narin-shipbar<?php echo $state['remaining'] <= 0 ? ' is-complete' : ''; ?>">
		<p class="narin-shipbar__text"><?php narin_icon('truck'); ?><span><?php echo wp_kses_post($message); ?></span></p>
		<div class="narin-shipbar__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $state['percent']; ?>" aria-label="<?php esc_attr_e('Ücretsiz kargo eşiğine ilerleme', 'narin'); ?>">
			<span class="narin-shipbar__fill" style="width:<?php echo (int) $state['percent']; ?>%"></span>
		</div>
	</div>
	<?php
}

/**
 * Check-mark list from a textarea setting, plus the optional shipping note.
 *
 * @param string $class List class.
 */
function narin_points_list($class) {
	$items = array();
	foreach (narin_setting_lines('product_points') as $line) {
		$items[] = $line['text'] !== '' ? $line['title'] . ' · ' . $line['text'] : $line['title'];
	}
	$note = trim((string) narin_setting('shipping_note'));
	if (!$items && $note === '') {
		return;
	}
	echo '<ul class="' . esc_attr($class) . '">';
	if ($note !== '') {
		echo '<li class="is-shipping">' . narin_get_icon('truck') . '<span>' . esc_html($note) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	foreach ($items as $item) {
		echo '<li>' . narin_get_icon('check') . '<span>' . esc_html($item) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</ul>';
}

/* ---------------------------------------------------------------------------------------------------------
 * Product page
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Two-column wrapper around the gallery and the summary.
 */
function narin_pdp_open() {
	echo '<div class="narin-pdp-top">';
}
add_action('woocommerce_before_single_product_summary', 'narin_pdp_open', 5);

/**
 * Close the two-column wrapper.
 */
function narin_pdp_close() {
	echo '</div>';
}
add_action('woocommerce_after_single_product_summary', 'narin_pdp_close', 5);

/**
 * Below the add-to-cart form: free-shipping bar (purchasable products that ship), shipping note and product points.
 */
function narin_pdp_extras() {
	global $product;
	if ($product instanceof WC_Product && $product->is_purchasable() && $product->needs_shipping()) {
		narin_free_shipping_bar();
	}
	narin_points_list('narin-points');
}
add_action('woocommerce_single_product_summary', 'narin_pdp_extras', 35);

/**
 * Recently viewed strip and the mobile buy bar after the product.
 */
function narin_pdp_after() {
	global $product;
	if (!$product instanceof WC_Product) {
		return;
	}

	if (narin_setting('recently_viewed')) {
		$image = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') : wc_placeholder_img_src('woocommerce_thumbnail');
		$data  = array(
			'id'    => $product->get_id(),
			'url'   => get_permalink($product->get_id()),
			'title' => wp_strip_all_tags($product->get_name()),
			'image' => (string) $image,
			'price' => html_entity_decode(wp_strip_all_tags(wc_price(wc_get_price_to_display($product))), ENT_QUOTES, get_bloginfo('charset')),
		);
		printf(
			'<section class="narin-recent" data-narin-recent="%1$s" hidden><h2 class="narin-recent__title">%2$s</h2><ul class="narin-recent__list"></ul></section>',
			esc_attr(wp_json_encode($data)),
			esc_html__('Son baktıklarınız', 'narin')
		);
	}

	if (narin_setting('sticky_buy_bar') && $product->is_purchasable() && $product->is_in_stock()) {
		?>
		<div class="narin-mobile-bar narin-mobile-bar--product" data-narin-buybar hidden>
			<div class="narin-mobile-bar__info">
				<span class="narin-mobile-bar__name"><?php echo esc_html($product->get_name()); ?></span>
				<span class="narin-mobile-bar__price"><?php echo wp_kses_post($product->get_price_html()); ?></span>
			</div>
			<button type="button" class="btn btn-primary" data-narin-buy><?php echo esc_html($product->is_type('simple') ? __('Sepete ekle', 'narin') : __('Seçenekleri gör', 'narin')); ?></button>
		</div>
		<?php
	}
}
add_action('woocommerce_after_single_product', 'narin_pdp_after', 20);

/**
 * Sale badge with the rounded discount for simple products.
 *
 * @param string     $html
 * @param WP_Post    $post
 * @param WC_Product $product
 * @return string
 */
function narin_sale_flash($html, $post, $product) {
	if ($product instanceof WC_Product && $product->is_type('simple')) {
		$regular = (float) $product->get_regular_price();
		$sale    = (float) $product->get_sale_price();
		if ($regular > 0 && $sale > 0 && $sale < $regular) {
			$percent = (int) round(($regular - $sale) / $regular * 100);
			/* translators: %d: discount percentage */
			return '<span class="onsale">' . esc_html(sprintf(__('%%%d indirim', 'narin'), $percent)) . '</span>';
		}
	}
	return '<span class="onsale">' . esc_html__('İndirim', 'narin') . '</span>';
}
add_filter('woocommerce_sale_flash', 'narin_sale_flash', 10, 3);

/* ---------------------------------------------------------------------------------------------------------
 * Cart and checkout
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Free-shipping bar at the top of the cart totals.
 */
function narin_cart_totals_bar() {
	narin_free_shipping_bar();
}
add_action('woocommerce_before_cart_totals', 'narin_cart_totals_bar');

/**
 * Mobile bar on the cart page: total + checkout link.
 */
function narin_cart_mobile_bar() {
	if (!narin_setting('sticky_buy_bar') || !WC()->cart || WC()->cart->is_empty()) {
		return;
	}
	?>
	<div class="narin-mobile-bar narin-mobile-bar--cart">
		<div class="narin-mobile-bar__info">
			<span class="narin-mobile-bar__label"><?php esc_html_e('Toplam', 'narin'); ?></span>
			<span class="narin-mobile-bar__price" data-narin-total><?php echo wp_kses_post(WC()->cart->get_total()); ?></span>
		</div>
		<a class="btn btn-primary" href="<?php echo esc_url(wc_get_checkout_url()); ?>"><?php esc_html_e('Ödemeye geç', 'narin'); ?></a>
	</div>
	<?php
}
add_action('woocommerce_after_cart', 'narin_cart_mobile_bar', 20);

/**
 * Mobile bar on the checkout page: total + a button that submits the real checkout form.
 */
function narin_checkout_mobile_bar() {
	if (!narin_setting('sticky_buy_bar') || !WC()->cart || WC()->cart->is_empty()) {
		return;
	}
	?>
	<div class="narin-mobile-bar narin-mobile-bar--checkout">
		<div class="narin-mobile-bar__info">
			<span class="narin-mobile-bar__label"><?php esc_html_e('Toplam', 'narin'); ?></span>
			<span class="narin-mobile-bar__price" data-narin-total><?php echo wp_kses_post(WC()->cart->get_total()); ?></span>
		</div>
		<button type="button" class="btn btn-primary" data-narin-place-order><?php esc_html_e('Siparişi tamamla', 'narin'); ?></button>
	</div>
	<?php
}
add_action('woocommerce_after_checkout_form', 'narin_checkout_mobile_bar', 20);

/**
 * Shipping note and points above the place-order button.
 */
function narin_checkout_points() {
	narin_points_list('narin-points narin-points--checkout');
}
add_action('woocommerce_review_order_before_submit', 'narin_checkout_points', 5);

/**
 * Small product thumbnails in the checkout order review.
 *
 * @param string $name
 * @param array  $cart_item
 * @return string
 */
function narin_checkout_item_thumbnail($name, $cart_item) {
	if (!is_checkout() || is_cart() || empty($cart_item['data']) || !$cart_item['data'] instanceof WC_Product) {
		return $name;
	}
	return '<span class="narin-review-item">' . $cart_item['data']->get_image('woocommerce_gallery_thumbnail', array('class' => 'narin-review-item__img')) . '<span class="narin-review-item__name">' . $name . '</span></span>';
}
add_filter('woocommerce_cart_item_name', 'narin_checkout_item_thumbnail', 20, 2);

/* ---------------------------------------------------------------------------------------------------------
 * My account
 * ------------------------------------------------------------------------------------------------------ */

/**
 * User card above the account navigation.
 */
function narin_account_user_card() {
	$user = wp_get_current_user();
	if (!$user->exists()) {
		return;
	}
	$name    = $user->first_name !== '' ? $user->first_name : $user->display_name;
	$initial = mb_strtoupper(mb_substr($name, 0, 1));
	printf(
		'<div class="narin-account-user"><span class="narin-account-user__avatar" aria-hidden="true">%1$s</span><span class="narin-account-user__meta"><strong>%2$s</strong><small>%3$s</small></span></div>',
		esc_html($initial),
		esc_html($name),
		esc_html($user->user_email)
	);
}
add_action('woocommerce_before_account_navigation', 'narin_account_user_card');

/**
 * Quick-link cards on the account dashboard.
 */
function narin_account_dashboard_cards() {
	$menu  = wc_get_account_menu_items();
	$cards = array(
		'orders'       => array('file-text', __('Siparişler', 'narin'), __('Siparişlerinizi ve durumlarını görüntüleyin.', 'narin')),
		'edit-address' => array('home', __('Adresler', 'narin'), __('Fatura ve teslimat adreslerinizi düzenleyin.', 'narin')),
		'edit-account' => array('user', __('Hesap bilgileri', 'narin'), __('Adınızı, e-postanızı ve parolanızı güncelleyin.', 'narin')),
	);
	$out = '';
	foreach ($cards as $endpoint => $card) {
		if (!isset($menu[$endpoint])) {
			continue;
		}
		$out .= sprintf(
			'<a class="narin-account-card" href="%1$s">%2$s<strong>%3$s</strong><span>%4$s</span></a>',
			esc_url(wc_get_account_endpoint_url($endpoint)),
			narin_get_icon($card[0]),
			esc_html($card[1]),
			esc_html($card[2])
		);
	}
	if ($out !== '') {
		echo '<div class="narin-account-cards">' . $out . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action('woocommerce_account_dashboard', 'narin_account_dashboard_cards', 5);

/* ---------------------------------------------------------------------------------------------------------
 * Shop archive helpers
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Categories for the filter drawer: children of the current category, else the top-level categories.
 *
 * @return WP_Term[]
 */
function narin_shop_filter_terms() {
	$current = is_product_category() ? get_queried_object() : null;
	$args    = array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'exclude'    => array((int) get_option('default_product_cat')),
	);
	$terms = get_terms($args + array('parent' => $current instanceof WP_Term ? $current->term_id : 0));
	if ((!$terms || is_wp_error($terms)) && $current instanceof WP_Term) {
		$terms = get_terms($args + array('parent' => $current->parent));
	}
	return is_wp_error($terms) ? array() : $terms;
}
