<?php
/**
 * WooCommerce wiring. Loaded only when WooCommerce is active (see functions.php).
 *
 * No template overrides: the product card, product page, free-shipping bar, checkout steps and the bank-transfer
 * details are built with WooCommerce's own hooks and filters plus CSS, so core notices, structured data and other
 * plugins' output keep working.
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Hook surgery that has to run after WooCommerce registered its own callbacks.
 */
function voltaj_wc_hooks() {
	// The theme renders its own content wrapper (woocommerce.php) and filter column instead of the sidebar.
	remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
	remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
	remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
	// woocommerce.php prints its own archive header, then fires woocommerce_shop_loop_header for other callbacks.
	remove_action('woocommerce_shop_loop_header', 'woocommerce_product_taxonomy_archive_header', 10);

	// Product card: no badge over the image; the price moves to the card footer next to the button.
	remove_action('woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10);
	// The theme prints the price only when it moved the core callback itself: when a plugin already removed it
	// (to hide prices), the theme prints none either (see voltaj_show_price()).
	voltaj_price_moved('loop', remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10));

	// Product page: the price moves into the buy box, the meta line (SKU, categories) goes under the title,
	// the sale badge becomes a discount note in the buy box.
	voltaj_price_moved('single', remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10));
	remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
	add_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 6);
	remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10);
}
add_action('wp', 'voltaj_wc_hooks');

/**
 * Remember whether the theme moved a core price callback (it was still hooked when voltaj_wc_hooks() ran).
 *
 * @param string    $context 'loop' or 'single'.
 * @param bool|null $moved   Set the flag; null reads it.
 * @return bool
 */
function voltaj_price_moved($context, $moved = null) {
	static $flags = array('loop' => false, 'single' => false);
	if ($moved !== null) {
		// Sticky: if voltaj_wc_hooks() runs again, its own earlier removal must not read as "a plugin removed it".
		$flags[$context] = !empty($flags[$context]) || (bool) $moved;
	}
	return !empty($flags[$context]);
}

/**
 * Whether the theme prints the price in its own spots (card footer, buy box, sticky bar, recently viewed).
 * False when a plugin removed woocommerce_template_loop_price / woocommerce_template_single_price before the
 * theme's hooks ran, or when the core callback was never moved (it then prints in its usual place).
 *
 * @param WC_Product|null $product
 * @param string          $context 'loop' or 'single'.
 * @return bool
 */
function voltaj_show_price($product, $context = 'single') {
	return (bool) apply_filters('voltaj_show_price', voltaj_price_moved($context), $product, $context);
}

/**
 * The header cart count (shown on every page while WooCommerce is active) is kept fresh by the cart fragments
 * script. Not loaded on the cart and checkout pages: they render the cart server-side and update it themselves.
 */
function voltaj_wc_assets() {
	if (is_cart() || is_checkout()) {
		return;
	}
	wp_enqueue_script('wc-cart-fragments');
}
add_action('wp_enqueue_scripts', 'voltaj_wc_assets');

/**
 * Breadcrumb markup.
 *
 * @param array $defaults
 * @return array
 */
function voltaj_wc_breadcrumb_defaults($defaults) {
	$defaults['delimiter']   = '<span class="vj-crumbs__sep" aria-hidden="true">/</span>';
	$defaults['wrap_before'] = '<nav class="woocommerce-breadcrumb vj-crumbs" aria-label="' . esc_attr__('Sayfa yolu', 'voltaj') . '">';
	$defaults['wrap_after']  = '</nav>';
	$defaults['home']        = __('Ana sayfa', 'voltaj');
	return $defaults;
}
add_filter('woocommerce_breadcrumb_defaults', 'voltaj_wc_breadcrumb_defaults');

/**
 * Header cart link (also used as a cart fragment).
 *
 * @return string
 */
function voltaj_cart_link_html() {
	$count = voltaj_cart_count();
	return sprintf(
		'<a class="vj-tool vj-cart-link" href="%1$s">%2$s<span class="vj-tool__label">%3$s</span><span class="vj-cart-count%6$s" aria-hidden="true">%4$d</span><span class="screen-reader-text">%5$s</span></a>',
		esc_url(wc_get_cart_url()),
		voltaj_get_icon('cart'),
		esc_html__('Sepet', 'voltaj'),
		$count,
		/* translators: %d: number of items in the cart */
		esc_html(sprintf(_n('Sepette %d ürün', 'Sepette %d ürün', $count, 'voltaj'), $count)),
		$count ? '' : ' is-empty'
	);
}

/**
 * Cart fragments: header cart link.
 *
 * @param array $fragments
 * @return array
 */
function voltaj_wc_fragments($fragments) {
	$fragments['a.vj-cart-link'] = voltaj_cart_link_html();
	ob_start();
	voltaj_free_shipping_bar();
	$fragments['div.vj-shipbar'] = ob_get_clean();
	return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'voltaj_wc_fragments');

/* ---------------------------------------------------------------------------------------------------------
 * Free-shipping progress (threshold read from the customer's shipping zone, never hard-coded)
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Cart subtotal as WooCommerce's free-shipping method compares it.
 *
 * @param bool $ignore_discounts Whether the method ignores coupon discounts.
 * @return float
 */
function voltaj_free_shipping_subtotal($ignore_discounts) {
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
 * Free-shipping state for the current customer, read from the "Free shipping" methods of the zone that matches the
 * cart's shipping destination. Null when shipping is disabled, when no method applies without a coupon, when free
 * shipping needs a coupon the cart does not have, or when the order already ships free.
 *
 * @return array{threshold:float,subtotal:float,remaining:float,percent:int}|null
 */
function voltaj_free_shipping_state() {
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
			return null; // Already free: nothing to show.
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

	$threshold = (float) apply_filters('voltaj_free_shipping_threshold', $best['threshold'], $zone);
	if ($threshold <= 0) {
		return null;
	}
	$subtotal = $cart->is_empty() ? 0.0 : voltaj_free_shipping_subtotal($best['ignore']);
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
function voltaj_free_shipping_bar() {
	$state = voltaj_setting('free_shipping_bar') ? voltaj_free_shipping_state() : null;
	if (!$state) {
		echo '<div class="vj-shipbar" hidden></div>';
		return;
	}
	if ($state['subtotal'] <= 0) {
		/* translators: %s: free-shipping threshold */
		$message = sprintf(esc_html__('%s ve üzeri siparişlerde kargo ücretsiz.', 'voltaj'), '<strong>' . wc_price($state['threshold']) . '</strong>');
	} elseif ($state['remaining'] > 0) {
		/* translators: %s: amount still needed for free shipping */
		$message = sprintf(esc_html__('Ücretsiz kargo için %s daha ekleyin.', 'voltaj'), '<strong>' . wc_price($state['remaining']) . '</strong>');
	} else {
		$message = '<strong>' . esc_html__('Siparişiniz ücretsiz kargo için uygun.', 'voltaj') . '</strong>';
	}
	?>
	<div class="vj-shipbar<?php echo $state['remaining'] <= 0 ? ' is-complete' : ''; ?>">
		<p class="vj-shipbar__text"><?php echo voltaj_get_icon('truck'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo wp_kses_post($message); ?></span></p>
		<div class="vj-shipbar__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $state['percent']; ?>" aria-label="<?php esc_attr_e('Ücretsiz kargo eşiğine ilerleme', 'voltaj'); ?>">
			<span class="vj-shipbar__fill" style="width:<?php echo (int) $state['percent']; ?>%"></span>
		</div>
	</div>
	<?php
}

/**
 * Free-shipping bar at the top of the cart totals.
 */
function voltaj_cart_totals_bar() {
	voltaj_free_shipping_bar();
}
add_action('woocommerce_before_cart_totals', 'voltaj_cart_totals_bar');

/* ---------------------------------------------------------------------------------------------------------
 * Product data helpers
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Stock state of a product.
 *
 * @param WC_Product $product
 * @return array{key:string,label:string}
 */
function voltaj_stock_state($product) {
	if (!$product->is_in_stock()) {
		$state = array('key' => 'out', 'label' => __('Tükendi', 'voltaj'));
	} elseif ($product->is_on_backorder()) {
		$state = array('key' => 'backorder', 'label' => __('Ön siparişle', 'voltaj'));
	} else {
		$state = array('key' => 'in', 'label' => __('Stokta', 'voltaj'));
	}
	return apply_filters('voltaj_stock_state', $state, $product);
}

/**
 * Visible product attributes as label/value rows.
 *
 * @param WC_Product $product
 * @param int        $limit 0 = all.
 * @return array<int,array{label:string,value:string}>
 */
function voltaj_key_attributes($product, $limit = 0) {
	$rows = array();
	foreach ($product->get_attributes() as $attribute) {
		if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_visible()) {
			continue;
		}
		if ($attribute->is_taxonomy()) {
			$values = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
		} else {
			$values = $attribute->get_options();
		}
		$values = array_filter(array_map('trim', array_map('strval', (array) $values)), 'strlen');
		if (!$values) {
			continue;
		}
		$rows[] = array(
			'label' => wc_attribute_label($attribute->get_name(), $product),
			'value' => implode(', ', $values),
		);
		if ($limit && count($rows) >= $limit) {
			break;
		}
	}
	return apply_filters('voltaj_key_attributes', $rows, $product, $limit);
}

/**
 * Whether a product has no price and should be quoted instead ("price on request").
 *
 * @param WC_Product $product
 * @return bool
 */
function voltaj_is_price_on_request($product) {
	$on_request = $product instanceof WC_Product && !$product->is_type(array('variable', 'grouped', 'external')) && $product->get_price() === '';
	return (bool) apply_filters('voltaj_price_on_request', $on_request, $product);
}

/**
 * Rounded discount of a simple product on sale (0 when not applicable).
 *
 * @param WC_Product $product
 * @return int
 */
function voltaj_discount_percent($product) {
	if (!$product->is_on_sale() || !$product->is_type('simple')) {
		return 0;
	}
	$regular = (float) $product->get_regular_price();
	$sale    = (float) $product->get_sale_price();
	return ($regular > 0 && $sale > 0 && $sale < $regular) ? (int) round(($regular - $sale) / $regular * 100) : 0;
}

/**
 * Price text for products without a price.
 *
 * @param string     $html
 * @param WC_Product $product
 * @return string
 */
function voltaj_price_on_request_html($html, $product) {
	if ($html === '' && voltaj_is_price_on_request($product)) {
		return '<span class="vj-price-request">' . esc_html__('Fiyat teklifle belirlenir', 'voltaj') . '</span>';
	}
	return $html;
}
add_filter('woocommerce_get_price_html', 'voltaj_price_on_request_html', 20, 2);

/* ---------------------------------------------------------------------------------------------------------
 * Product card (content-product.php, via hooks)
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Open the image frame.
 */
function voltaj_card_media_open() {
	echo '<span class="vj-card__media">';
}
add_action('woocommerce_before_shop_loop_item_title', 'voltaj_card_media_open', 5);

/**
 * Close the image frame.
 */
function voltaj_card_media_close() {
	echo '</span>';
}
add_action('woocommerce_before_shop_loop_item_title', 'voltaj_card_media_close', 15);

/**
 * SKU and stock state above the title.
 */
function voltaj_card_meta() {
	global $product;
	if (!$product instanceof WC_Product) {
		return;
	}
	$stock = voltaj_stock_state($product);
	$sku   = wc_product_sku_enabled() ? $product->get_sku() : '';
	echo '<span class="vj-card__meta">';
	if ($sku !== '') {
		printf('<span class="vj-sku"><span class="screen-reader-text">%1$s </span>%2$s</span>', esc_html__('Ürün kodu:', 'voltaj'), esc_html($sku));
	}
	printf('<span class="vj-stock is-%1$s">%2$s</span>', esc_attr($stock['key']), esc_html($stock['label']));
	echo '</span>';
}
add_action('woocommerce_shop_loop_item_title', 'voltaj_card_meta', 5);

/**
 * Key attributes below the title.
 */
function voltaj_card_specs() {
	global $product;
	$limit = (int) voltaj_setting('card_specs');
	if (!$product instanceof WC_Product || $limit < 1) {
		return;
	}
	$rows = voltaj_key_attributes($product, $limit);
	if (!$rows) {
		return;
	}
	echo '<span class="vj-card__specs">';
	foreach ($rows as $row) {
		printf('<span class="vj-card__spec"><span class="vj-card__spec-k">%1$s</span> <span class="vj-card__spec-v">%2$s</span></span>', esc_html($row['label']), esc_html($row['value']));
	}
	echo '</span>';
}
add_action('woocommerce_after_shop_loop_item_title', 'voltaj_card_specs', 3);

/**
 * Card footer: price (with the discount) next to the button.
 */
function voltaj_card_foot_open() {
	global $product;
	echo '<div class="vj-card__foot"><div class="vj-card__price">';
	if (voltaj_show_price($product, 'loop')) {
		woocommerce_template_loop_price();
		if ($product instanceof WC_Product && ($off = voltaj_discount_percent($product))) {
			/* translators: %d: discount percentage */
			printf('<span class="vj-off">%s</span>', esc_html(sprintf(__('−%%%d', 'voltaj'), $off)));
		}
	}
	echo '</div>';
}
add_action('woocommerce_after_shop_loop_item', 'voltaj_card_foot_open', 7);

/**
 * Close the card footer.
 */
function voltaj_card_foot_close() {
	echo '</div>';
}
add_action('woocommerce_after_shop_loop_item', 'voltaj_card_foot_close', 20);

/**
 * Loop button for products without a price: a link to the quote form.
 *
 * @param string     $html
 * @param WC_Product $product
 * @return string
 */
function voltaj_loop_quote_button($html, $product) {
	if (!voltaj_is_price_on_request($product)) {
		return $html;
	}
	$url = voltaj_quote_url($product->get_id());
	if ($url === '') {
		$url = get_permalink($product->get_id());
	}
	return sprintf('<a class="button vj-quote-button" href="%1$s">%2$s</a>', esc_url($url), esc_html__('Teklif iste', 'voltaj'));
}
add_filter('woocommerce_loop_add_to_cart_link', 'voltaj_loop_quote_button', 20, 2);

/* ---------------------------------------------------------------------------------------------------------
 * Product page
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Two-column wrapper around the gallery and the summary.
 */
function voltaj_pdp_open() {
	echo '<div class="vj-pdp">';
}
add_action('woocommerce_before_single_product_summary', 'voltaj_pdp_open', 5);

/**
 * Close the two-column wrapper.
 */
function voltaj_pdp_close() {
	echo '</div>';
}
add_action('woocommerce_after_single_product_summary', 'voltaj_pdp_close', 5);

/**
 * Brand in the meta line (when a brand taxonomy is configured).
 */
function voltaj_pdp_brand_meta() {
	global $product;
	$tax = voltaj_brand_taxonomy();
	if (!$product instanceof WC_Product || $tax === '' || $tax === 'product_brand') {
		return; // WooCommerce prints product_brand in the meta line itself.
	}
	$list = get_the_term_list($product->get_id(), $tax, '', ', ');
	if ($list && !is_wp_error($list)) {
		echo '<span class="vj-brand-meta">' . esc_html__('Marka:', 'voltaj') . ' ' . wp_kses_post($list) . '</span>';
	}
}
add_action('woocommerce_product_meta_end', 'voltaj_pdp_brand_meta');

/**
 * Key specification table in the summary.
 */
function voltaj_pdp_specs() {
	global $product;
	$limit = (int) voltaj_setting('pdp_specs');
	if (!$product instanceof WC_Product || $limit < 1) {
		return;
	}
	$rows = voltaj_key_attributes($product, $limit);
	if (!$rows) {
		return;
	}
	echo '<table class="vj-spec-table"><caption>' . esc_html__('Öne çıkan özellikler', 'voltaj') . '</caption><tbody>';
	foreach ($rows as $row) {
		printf('<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html($row['label']), esc_html($row['value']));
	}
	echo '</tbody></table>';
}
add_action('woocommerce_single_product_summary', 'voltaj_pdp_specs', 22);

/**
 * Open the buy box and print the price (with the discount note).
 */
function voltaj_pdp_buybox_open() {
	global $product;
	echo '<div class="vj-buybox" id="vj-buybox">';
	if (voltaj_show_price($product, 'single')) {
		echo '<div class="vj-buybox__price">';
		woocommerce_template_single_price();
		if ($product instanceof WC_Product && ($off = voltaj_discount_percent($product))) {
			/* translators: %d: discount percentage */
			printf('<span class="vj-off">%s</span>', esc_html(sprintf(__('%%%d indirim', 'voltaj'), $off)));
		}
		echo '</div>';
	}
}
add_action('woocommerce_single_product_summary', 'voltaj_pdp_buybox_open', 25);

/**
 * Quote link inside the buy box: the main action for products without a price, a secondary link otherwise.
 */
function voltaj_pdp_quote_link() {
	global $product;
	if (!$product instanceof WC_Product) {
		return;
	}
	$url = voltaj_quote_url($product->get_id());
	if ($url === '') {
		return;
	}
	if (voltaj_is_price_on_request($product)) {
		printf('<a class="vj-btn vj-btn--primary vj-btn--block" href="%1$s">%2$s%3$s</a>', esc_url($url), voltaj_get_icon('file-text'), esc_html__('Fiyat teklifi iste', 'voltaj')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif (voltaj_setting('quote_link')) {
		printf('<a class="vj-quote-link" href="%1$s">%2$s%3$s</a>', esc_url($url), voltaj_get_icon('file-text'), esc_html__('Toplu alım için teklif isteyin', 'voltaj')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action('woocommerce_single_product_summary', 'voltaj_pdp_quote_link', 32);

/**
 * Free-shipping bar at the bottom of the buy box (purchasable products only).
 */
function voltaj_pdp_shipbar() {
	global $product;
	if ($product instanceof WC_Product && $product->is_purchasable() && $product->needs_shipping()) {
		voltaj_free_shipping_bar();
	}
}
add_action('woocommerce_single_product_summary', 'voltaj_pdp_shipbar', 34);

/**
 * Close the buy box.
 */
function voltaj_pdp_buybox_close() {
	echo '</div>';
}
add_action('woocommerce_single_product_summary', 'voltaj_pdp_buybox_close', 35);

/**
 * Recently viewed strip and the mobile buy bar after the product.
 */
function voltaj_pdp_after() {
	global $product;
	if (!$product instanceof WC_Product) {
		return;
	}

	if (voltaj_setting('recently_viewed')) {
		$image = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') : wc_placeholder_img_src('woocommerce_thumbnail');
		$price = voltaj_show_price($product, 'single') ? $product->get_price_html() : '';
		$data  = array(
			'id'    => $product->get_id(),
			'url'   => get_permalink($product->get_id()),
			'title' => wp_strip_all_tags($product->get_name()),
			'sku'   => wc_product_sku_enabled() ? (string) $product->get_sku() : '',
			'image' => (string) $image,
			'price' => trim(html_entity_decode(wp_strip_all_tags($price), ENT_QUOTES, get_bloginfo('charset'))),
		);
		printf(
			'<section class="vj-recent" data-vj-recent="%1$s" aria-labelledby="vj-recent-title" hidden><h2 class="vj-section__title" id="vj-recent-title">%2$s</h2><ul class="vj-recent__list"></ul></section>',
			esc_attr(wp_json_encode($data)),
			esc_html__('Son baktıklarınız', 'voltaj')
		);
	}

	if (voltaj_setting('sticky_buy_bar') && ($product->is_purchasable() || voltaj_is_price_on_request($product)) && $product->is_in_stock()) {
		$label = voltaj_is_price_on_request($product) ? __('Teklif iste', 'voltaj') : ($product->is_type('simple') ? __('Sepete ekle', 'voltaj') : __('Seçenekleri seç', 'voltaj'));
		?>
		<div class="vj-buybar" data-vj-buybar hidden>
			<div class="vj-buybar__info">
				<span class="vj-buybar__name"><?php echo esc_html($product->get_name()); ?></span>
				<?php if (voltaj_show_price($product, 'single')) : ?>
					<span class="vj-buybar__price"><?php echo wp_kses_post($product->get_price_html()); ?></span>
				<?php endif; ?>
			</div>
			<button type="button" class="vj-btn vj-btn--primary" data-vj-buy><?php echo esc_html($label); ?></button>
		</div>
		<?php
	}
}
add_action('woocommerce_after_single_product', 'voltaj_pdp_after', 20);

/* ---------------------------------------------------------------------------------------------------------
 * Cart, checkout and thank-you
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Checkout step indicator.
 *
 * @param int $active 1 = cart, 2 = checkout, 3 = order received.
 */
function voltaj_checkout_steps($active) {
	$steps = apply_filters('voltaj_checkout_steps', array(
		1 => __('Sepet', 'voltaj'),
		2 => __('Teslimat ve ödeme', 'voltaj'),
		3 => __('Sipariş onayı', 'voltaj'),
	));
	echo '<ol class="vj-steps" aria-label="' . esc_attr__('Sipariş adımları', 'voltaj') . '">';
	foreach ($steps as $n => $label) {
		$state = $n < $active ? 'is-done' : ($n === $active ? 'is-current' : '');
		printf(
			'<li class="vj-steps__item %1$s"%2$s><span class="vj-steps__num" aria-hidden="true">%3$s</span><span class="vj-steps__label">%4$s</span></li>',
			esc_attr($state),
			$n === $active ? ' aria-current="step"' : '',
			$n < $active ? voltaj_get_icon('check') : esc_html((string) $n), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html($label)
		);
	}
	echo '</ol>';
}

/**
 * Step 1 on the cart page.
 */
function voltaj_steps_cart() {
	voltaj_checkout_steps(1);
}
add_action('woocommerce_before_cart', 'voltaj_steps_cart', 5);

/**
 * Step 2 on the checkout page.
 */
function voltaj_steps_checkout() {
	voltaj_checkout_steps(2);
}
add_action('woocommerce_before_checkout_form', 'voltaj_steps_checkout', 5);

/**
 * Step 3 on the order-received page; also swaps the bank-transfer details for the copyable version.
 */
function voltaj_steps_thankyou() {
	voltaj_checkout_steps(3);
	voltaj_bacs_swap_output();
}
add_action('woocommerce_before_thankyou', 'voltaj_steps_thankyou', 5);

/**
 * Replace the BACS gateway's thank-you output with bank cards that have copy buttons.
 * Filter voltaj_bacs_copy to false to keep WooCommerce's own list.
 */
function voltaj_bacs_swap_output() {
	if (!apply_filters('voltaj_bacs_copy', true) || !WC()->payment_gateways()) {
		return;
	}
	$gateways = WC()->payment_gateways()->payment_gateways();
	if (empty($gateways['bacs'])) {
		return;
	}
	if (remove_action('woocommerce_thankyou_bacs', array($gateways['bacs'], 'thankyou_page'))) {
		add_action('woocommerce_thankyou_bacs', 'voltaj_bacs_thankyou');
	}
}

/**
 * Copy button markup.
 *
 * @param string $value Text to copy.
 * @param string $label Accessible label.
 * @return string
 */
function voltaj_copy_button($value, $label) {
	return sprintf(
		'<button type="button" class="vj-copy" data-vj-copy="%1$s">%2$s<span class="vj-copy__text">%3$s</span><span class="screen-reader-text"> %4$s</span></button>',
		esc_attr($value),
		voltaj_get_icon('copy'),
		esc_html__('Kopyala', 'voltaj'),
		esc_html($label)
	);
}

/**
 * Bank-transfer instructions and accounts (read from the WooCommerce BACS settings) with copy buttons.
 *
 * @param int $order_id
 */
function voltaj_bacs_thankyou($order_id) {
	$order    = wc_get_order($order_id);
	$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
	if (!$order || empty($gateways['bacs'])) {
		return;
	}
	$gateway = $gateways['bacs'];
	if ($gateway->instructions) {
		echo wp_kses_post(wpautop(wptexturize(wp_kses_post($gateway->instructions))));
	}
	if (!$order->has_status(apply_filters('voltaj_bacs_show_for_statuses', array('on-hold', 'pending')))) {
		return;
	}
	$accounts = apply_filters('woocommerce_bacs_accounts', $gateway->account_details, $order_id);
	if (empty($accounts)) {
		return;
	}
	?>
	<section class="vj-bank" aria-labelledby="vj-bank-title">
		<h2 class="vj-bank__title" id="vj-bank-title"><?php esc_html_e('Havale / EFT bilgileri', 'voltaj'); ?></h2>
		<dl class="vj-bank__ref">
			<div>
				<dt><?php esc_html_e('Ödenecek tutar', 'voltaj'); ?></dt>
				<dd class="vj-mono"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e('Açıklama alanına yazın', 'voltaj'); ?></dt>
				<dd>
					<span class="vj-mono"><?php echo esc_html($order->get_order_number()); ?></span>
					<?php echo voltaj_copy_button((string) $order->get_order_number(), __('sipariş numarası', 'voltaj')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</dd>
			</div>
		</dl>
		<ul class="vj-bank__list">
			<?php foreach ((array) $accounts as $account) : ?>
				<?php
				$account = (array) $account;
				$iban    = isset($account['iban']) ? preg_replace('/\s+/', '', (string) $account['iban']) : '';
				$number  = isset($account['account_number']) ? trim((string) $account['account_number']) : '';
				if ($iban === '' && $number === '') {
					continue;
				}
				?>
				<li class="vj-bank__card">
					<?php if (!empty($account['bank_name'])) : ?>
						<p class="vj-bank__name"><?php echo esc_html(wp_unslash($account['bank_name'])); ?></p>
					<?php endif; ?>
					<?php if (!empty($account['account_name'])) : ?>
						<p class="vj-bank__holder"><?php echo esc_html(wp_unslash($account['account_name'])); ?></p>
					<?php endif; ?>
					<?php if ($iban !== '') : ?>
						<p class="vj-bank__row">
							<span class="vj-bank__label"><?php esc_html_e('IBAN', 'voltaj'); ?></span>
							<span class="vj-bank__iban vj-mono"><?php echo esc_html(trim(chunk_split($iban, 4, ' '))); ?></span>
							<?php echo voltaj_copy_button($iban, __('IBAN', 'voltaj')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</p>
					<?php endif; ?>
					<?php if ($number !== '') : ?>
						<p class="vj-bank__row"><span class="vj-bank__label"><?php esc_html_e('Hesap no', 'voltaj'); ?></span><span class="vj-mono"><?php echo esc_html($number); ?></span></p>
					<?php endif; ?>
					<?php if (!empty($account['sort_code'])) : ?>
						<p class="vj-bank__row"><span class="vj-bank__label"><?php esc_html_e('Şube kodu', 'voltaj'); ?></span><span class="vj-mono"><?php echo esc_html($account['sort_code']); ?></span></p>
					<?php endif; ?>
					<?php if (!empty($account['bic'])) : ?>
						<p class="vj-bank__row"><span class="vj-bank__label"><?php esc_html_e('BIC / SWIFT', 'voltaj'); ?></span><span class="vj-mono"><?php echo esc_html($account['bic']); ?></span></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="vj-copy-status screen-reader-text" role="status" aria-live="polite"></p>
	</section>
	<?php
}

/* ---------------------------------------------------------------------------------------------------------
 * Categories and home helpers
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Category image: the category's own thumbnail, else the first product image in it (cached in term meta).
 *
 * @param WP_Term $term
 * @param string  $size
 * @return string
 */
function voltaj_category_image($term, $size = 'voltaj-tile') {
	$thumb_id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
	if (!$thumb_id) {
		$cached = get_term_meta($term->term_id, '_voltaj_thumb', true);
		if ($cached !== '') {
			$thumb_id = (int) $cached; // 0 = cached "no image".
		} else {
			$ids = wc_get_products(array(
				'category' => array($term->slug),
				'limit'    => 5,
				'status'   => 'publish',
				'orderby'  => 'date',
				'return'   => 'ids',
			));
			foreach ($ids as $id) {
				$image = (int) get_post_thumbnail_id($id);
				if ($image) {
					$thumb_id = $image;
					break;
				}
			}
			update_term_meta($term->term_id, '_voltaj_thumb', (string) $thumb_id);
		}
	}
	return $thumb_id ? (string) wp_get_attachment_image($thumb_id, $size, false, array('alt' => '', 'loading' => 'lazy')) : '';
}

/**
 * Refresh the cached category images when a product is saved.
 *
 * @param int $post_id
 */
function voltaj_flush_category_images($post_id) {
	$terms = wp_get_post_terms($post_id, 'product_cat', array('fields' => 'ids'));
	foreach (is_wp_error($terms) ? array() : $terms as $term_id) {
		delete_term_meta((int) $term_id, '_voltaj_thumb');
	}
}
add_action('save_post_product', 'voltaj_flush_category_images');

/**
 * Trashing or restoring a product changes which image its categories fall back to.
 *
 * @param int $post_id
 */
function voltaj_flush_category_images_on_status($post_id) {
	if (get_post_type($post_id) === 'product') {
		voltaj_flush_category_images($post_id);
	}
}
add_action('trashed_post', 'voltaj_flush_category_images_on_status');
add_action('untrashed_post', 'voltaj_flush_category_images_on_status');

/**
 * Category assignment changed (quick edit, bulk edit, REST, imports): flush the old and the new categories.
 *
 * @param int    $object_id
 * @param array  $terms
 * @param array  $tt_ids     New term_taxonomy IDs.
 * @param string $taxonomy
 * @param bool   $append
 * @param array  $old_tt_ids Previous term_taxonomy IDs.
 */
function voltaj_flush_category_images_on_terms($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
	if ($taxonomy !== 'product_cat') {
		return;
	}
	foreach (array_unique(array_merge((array) $tt_ids, (array) $old_tt_ids)) as $tt_id) {
		$term = get_term_by('term_taxonomy_id', (int) $tt_id, 'product_cat');
		if ($term instanceof WP_Term) {
			delete_term_meta($term->term_id, '_voltaj_thumb');
		}
	}
}
add_action('set_object_terms', 'voltaj_flush_category_images_on_terms', 10, 6);

/**
 * Top-level product categories for the home grid.
 *
 * @param int $limit
 * @return WP_Term[]
 */
function voltaj_top_categories($limit = 8) {
	$terms = get_terms(array(
		'taxonomy'   => 'product_cat',
		'parent'     => 0,
		'hide_empty' => true,
		'orderby'    => 'menu_order',
		'number'     => $limit,
		'exclude'    => array((int) get_option('default_product_cat')),
	));
	return is_wp_error($terms) ? array() : $terms;
}

/**
 * Product grid for the home page: featured products, else the newest.
 *
 * @param int $count
 * @return string
 */
function voltaj_home_products_html($count) {
	$count = max(1, (int) $count);
	$html  = do_shortcode(sprintf('[products limit="%d" columns="4" visibility="featured"]', $count));
	if (strpos($html, 'class="products') === false) {
		$html = do_shortcode(sprintf('[products limit="%d" columns="4" orderby="date" order="DESC"]', $count));
	}
	return strpos($html, 'class="products') !== false ? $html : '';
}

/* ---------------------------------------------------------------------------------------------------------
 * Shop archive helpers
 * ------------------------------------------------------------------------------------------------------ */

/**
 * Categories for the filter column: children of the current category, else its siblings, else the top level.
 *
 * @return WP_Term[]
 */
function voltaj_shop_category_terms() {
	$current = is_product_category() ? get_queried_object() : null;
	$args    = array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'orderby'    => 'menu_order',
		'exclude'    => array((int) get_option('default_product_cat')),
	);
	$terms = get_terms($args + array('parent' => $current instanceof WP_Term ? $current->term_id : 0));
	if ((!$terms || is_wp_error($terms)) && $current instanceof WP_Term) {
		$terms = get_terms($args + array('parent' => $current->parent));
	}
	return is_wp_error($terms) ? array() : $terms;
}
