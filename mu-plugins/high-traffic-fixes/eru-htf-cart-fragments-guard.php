<?php
/**
 * Plugin Name: HTF — Cart Fragments Guard
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Loads WooCommerce cart fragments only for visitors who have a cart or session cookie, so cart-less visitors never trigger the wc-ajax request.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: WooCommerce's cart-fragments.js falls back to an unconditional AJAX call
 * (`/?wc-ajax=get_refreshed_fragments`) whenever sessionStorage has no fragments, even
 * when the visitor has no cart and no cookies at all. Every first-time visitor therefore
 * costs one uncacheable request that boots WordPress and WooCommerce and returns nothing
 * useful. During a traffic spike (a campaign, an announcement) these requests, not the
 * cached page HTML, are what exhausts the PHP workers.
 *
 * FIX - THE DECISION IS MADE IN THE BROWSER: the script is dequeued on the server and
 * replaced with a tiny inline loader that checks cookies. If any of
 *
 *     woocommerce_items_in_cart | woocommerce_cart_hash
 *     wp_woocommerce_session_*  | wordpress_logged_in_*
 *
 * exists, the original script is loaded as usual. If none exists the visitor cannot have a
 * cart, so the script and its AJAX call never happen. The decision must be client-side
 * because pages are typically served from a full-page cache: the same HTML goes to
 * visitors with and without a cart, and a server-side decision would be frozen into the
 * cache.
 *
 * WHY IT IS SAFE
 *   - Add-to-cart via AJAX keeps working: the header count is refreshed by updateFragments()
 *     in wc-add-to-cart.js (cart-fragments.js only writes sessionStorage).
 *   - Adding to cart creates the WooCommerce cookies; on the NEXT page load the loader
 *     includes the script and everything is back to normal.
 *   - Code that triggers `wc_fragment_refresh` does nothing, silently, while the script is
 *     absent.
 *   - Cart, checkout and account pages are never touched.
 *
 * Does nothing without WooCommerce.
 *
 * Disable: add_filter( 'eru_htf_cart_fragments_guard_enabled', '__return_false' );
 *          or define( 'ERU_HTF_CART_FRAGMENTS_GUARD', false ); in wp-config.php.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'ERU_HTF_CART_FRAGMENTS_GUARD' ) && ! ERU_HTF_CART_FRAGMENTS_GUARD ) {
	return;
}

class Eru_HTF_Cart_Fragments_Guard {

	const HANDLE = 'wc-cart-fragments';

	/** @var array|null Source URL and inline data of the dequeued script. */
	private static $taken_over = null;

	public static function run() {
		// TIMING - TWO SEPARATE TRAPS:
		//
		// 1) `wp_enqueue_scripts` is too early. Many themes enqueue the fragments script
		//    from a template part while the header renders (WooCommerce no longer enqueues
		//    it unconditionally), so wp_script_is( ..., 'enqueued' ) is still false there.
		//
		// 2) `wp_footer` priority 1 is too early for the DATA. WooCommerce localizes
		//    `wc_cart_fragments_params` at `wp_print_footer_scripts` priority 5, so reading
		//    it earlier returns nothing and the script later dies with
		//    "wc_cart_fragments_params is not defined".
		//
		// Right place: `wp_print_footer_scripts` priority 6 - after WooCommerce's localize
		// (5), before core's _wp_footer_scripts() print (10).
		add_action( 'wp_print_footer_scripts', array( __CLASS__, 'take_over' ), 6 );
		// Loader is printed after the scripts so jQuery and js-cookie are on the page.
		add_action( 'wp_footer', array( __CLASS__, 'print_loader' ), 25 );
	}

	/**
	 * Dequeue the script and remember the two things the loader needs.
	 */
	public static function take_over() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		if ( ! apply_filters( 'eru_htf_cart_fragments_guard_enabled', true ) ) {
			return;
		}

		// Cart / checkout / account: fragments are the point there - leave them alone.
		if ( ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			return;
		}

		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		// Already printed in the header: too late, keep the stock behaviour.
		if ( wp_script_is( self::HANDLE, 'done' ) ) {
			return;
		}

		$scripts = wp_scripts();
		if ( ! isset( $scripts->registered[ self::HANDLE ] ) ) {
			return;
		}

		$src = self::full_src( $scripts );
		if ( '' === $src ) {
			return; // URL could not be resolved: do nothing, keep stock behaviour.
		}

		self::$taken_over = array(
			'src'  => $src,
			// "var wc_cart_fragments_params = {...};" - must be global BEFORE the script.
			'data' => (string) $scripts->get_data( self::HANDLE, 'data' ),
		);

		wp_dequeue_script( self::HANDLE );

		// Keep the dependencies queued: when the script loads later, jQuery and js-cookie
		// must be ready. Both are static files, no PHP cost.
		wp_enqueue_script( 'jquery' );
		if ( wp_script_is( 'wc-js-cookie', 'registered' ) ) {
			wp_enqueue_script( 'wc-js-cookie' );
		}
	}

	/**
	 * Full script URL, same logic as core's WP_Scripts::do_item() including the
	 * `script_loader_src` filter (minify/CDN plugins hook into it).
	 *
	 * @param WP_Scripts $scripts Script registry.
	 * @return string
	 */
	private static function full_src( $scripts ) {
		$obj = $scripts->registered[ self::HANDLE ];
		$src = (string) $obj->src;

		if ( '' === $src ) {
			return '';
		}

		if ( ! preg_match( '|^(https?:)?//|', $src )
			&& ! ( $scripts->content_url && 0 === strpos( $src, $scripts->content_url ) ) ) {
			$src = $scripts->base_url . $src;
		}

		$ver = $obj->ver ? $obj->ver : $scripts->default_version;
		if ( $ver ) {
			$src = add_query_arg( 'ver', $ver, $src );
		}

		$src = apply_filters( 'script_loader_src', $src, self::HANDLE );

		return is_string( $src ) ? esc_url_raw( $src ) : '';
	}

	/**
	 * Cookie-checking loader. Does nothing for visitors without a cart/session.
	 */
	public static function print_loader() {
		if ( null === self::$taken_over ) {
			return;
		}

		$src  = self::$taken_over['src'];
		$data = self::$taken_over['data'];

		// The params are printed OUTSIDE the IIFE: they are defined with `var`, so inside
		// the function they would become a local and the script could not find the global.
		?>
<script id="eru-htf-cart-fragments-guard">
<?php echo $data . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JS generated by WordPress/WooCommerce itself. ?>
(function () {
	var c = document.cookie || '';

	// No cart / session cookie: no fragment request needed, the script is never loaded.
	if ( c.indexOf( 'woocommerce_items_in_cart' ) === -1
		&& c.indexOf( 'woocommerce_cart_hash' ) === -1
		&& c.indexOf( 'wp_woocommerce_session_' ) === -1
		&& c.indexOf( 'wordpress_logged_in_' ) === -1 ) {
		return;
	}

	var s = document.createElement( 'script' );
	s.src = <?php echo wp_json_encode( $src ); ?>;
	s.async = true;
	document.head.appendChild( s );
})();
</script>
		<?php
	}
}

Eru_HTF_Cart_Fragments_Guard::run();
