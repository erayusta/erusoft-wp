<?php
/**
 * Campaign Engine — WooCommerce cart: line discounts through the price filter, no compounding over
 * recalculations, other price plugins before and after ours, cloned and copied carts, cart hash, the
 * read-back check, free shipping, the inert default, the campaign writer's validation, a bad stored
 * campaign row and malformed settings (safe fallback).
 *
 *   wp eval-file tests/php/ce-cart.php   (plugin active; creates and removes its own data)
 */

use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\ConfigErrors;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\Schema;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;

require __DIR__ . '/ce-wc-helpers.php';
ce_wc_require();

$mails = array();
ce_wc_guards( $mails );
wc_load_cart();
$keep  = ce_wc_snapshot_options();
$hooks = array();
$fail  = false;
ce_wc_purge( true );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );
delete_option( Options::OPTION );
Options::flush();

try {
	$cat   = ce_wc_term( 'CE Demo Koşu' );
	$other = ce_wc_term( 'CE Demo Aksesuar' );
	$p1    = ce_wc_product( 'CE Demo Tişört', '33.33', array( $cat ) );
	$p2    = ce_wc_product( 'CE Demo Şort', '249.90', array( $cat ) );
	$p3    = ce_wc_product( 'CE Demo Çanta', '89.90', array( $other ) );

	// ------------------------------------------------------------------ inert default
	$pending = fn() => as_get_scheduled_actions( array( 'group' => 'eru-ce', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 100 ), 'ids' );
	$jobs0   = $pending();
	$cart    = ce_wc_fill( array( array( $p1, 3 ), array( $p3, 1 ) ) );
	ce_ok( 'inert: no campaign -> plain WooCommerce subtotal 189.89', 18989 === ce_minor( $cart->get_subtotal() ) );
	ce_ok( 'inert: no campaign -> no applier state, no result', null === CartApplier::resultFor( $cart ) );
	ce_ok( 'inert: no Action Scheduler job enqueued', $jobs0 === $pending() );
	ce_ok( 'inert: no session key written', null === WC()->session->get( CartApplier::SIGNATURE_KEY ) );

	// ------------------------------------------------------------------ writer validation (same readers as the engine)
	$bad = CampaignWriter::validate( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '150' ) ) ) );
	ce_ok( 'writer refuses percent 150 with field + reason', is_wp_error( $bad ) && 'percent' === ( $bad->get_error_data()['field'] ?? '' ) && 'out_of_range' === ( $bad->get_error_data()['reason'] ?? '' ) );
	$bad = CampaignWriter::validate( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ), 'targets' => array( 'include' => array( 'product' => array( 'abc' ) ) ) ) );
	ce_ok( 'writer refuses a malformed target id (would turn storewide)', is_wp_error( $bad ) && 0 === strpos( (string) ( $bad->get_error_data()['field'] ?? '' ), 'targets.include.product' ) );
	$bad = CampaignWriter::validate( array( 'actions' => array( array( 'type' => 'tiered', 'tiers' => array( array( 'min' => 'x', 'value' => 5 ) ) ) ) ) );
	ce_ok( 'writer refuses a malformed tier row', is_wp_error( $bad ) && 'tiers' === ( $bad->get_error_data()['field'] ?? '' ) );
	$bad = CampaignWriter::validate( array( 'actions' => array( array( 'type' => 'nope' ) ) ) );
	ce_ok( 'writer refuses an unknown action type', is_wp_error( $bad ) );
	$bad = CampaignWriter::validate( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ), 'rules' => array( 'op' => 'and', 'children' => array( array( 'condition' => 'cart.nope', 'cmp' => '>=', 'value' => 1 ) ) ) ) );
	ce_ok( 'writer refuses an unknown condition', is_wp_error( $bad ) );
	$bad = CampaignWriter::validate( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ), 'usage_limit' => '1.5' ) );
	ce_ok( 'writer refuses a fractional usage limit', is_wp_error( $bad ) && 'usage_limit' === ( $bad->get_error_data()['field'] ?? '' ) );
	$created = CampaignWriter::create( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '150' ) ) ), 'CE Demo Hatalı ' . CE_RUN );
	ce_ok( 'writer create() with invalid data saves nothing', is_wp_error( $created ) && array() === get_posts( array( 'post_type' => 'eru_ce_campaign', 'title' => 'CE Demo Hatalı ' . CE_RUN, 'post_status' => 'any', 'fields' => 'ids' ) ) );

	// ------------------------------------------------------------------ (a) 15 % category campaign
	$c15  = ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '15' ) ),
			'targets' => array( 'include' => array( 'term' => array( $cat ) ) ),
		),
		'CE Demo Kategori Yüzde'
	);
	$row  = CampaignRepository::row( $c15 );
	ce_ok( 'writer compiled the row (active, version 1)', is_array( $row ) && 'active' === $row['status'] && 1 === (int) $row['version'] );
	global $wpdb;
	$targets = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'targets' ) . ' WHERE campaign_id = %d', $c15 ), ARRAY_A );
	ce_ok( 'writer compiled the targets table (include term)', 1 === count( $targets ) && 'term' === $targets[0]['target_type'] && (int) $cat === (int) $targets[0]['target_id'] );

	$cart = ce_wc_fill( array( array( $p1, 3 ), array( $p2, 1 ), array( $p3, 2 ) ) );
	$l    = ce_wc_lines( $cart );
	// 3 × 33.33 = 99.99 → 15 % = 14.9985 → 15.00 (rounded in the customer's favour); 249.90 → 37.485 → 37.49.
	ce_ok( '(a) T-shirt line 99.99 − 15.00 = 84.99', 8499 === $l[ $p1 ] );
	ce_ok( '(a) shorts line 249.90 − 37.49 = 212.41', 21241 === $l[ $p2 ] );
	ce_ok( '(a) bag (other category) untouched 179.80', 17980 === $l[ $p3 ] );
	$runs = array( ce_wc_snap( $cart ) );
	$cart->calculate_totals();
	$runs[] = ce_wc_snap( $cart );
	$cart->calculate_totals();
	$runs[] = ce_wc_snap( $cart );
	ce_ok( '(a) calculate_totals() three times -> identical totals (no compounding)', $runs[0] === $runs[1] && $runs[1] === $runs[2] );
	ce_ok( '(a) engine discount 52.49 = Σ base − WooCommerce subtotal', 5249 === $runs[2]['discount'] && ce_wc_base_total( $cart ) - $runs[2]['subtotal'] === $runs[2]['discount'] );
	$item = null;
	foreach ( $cart->get_cart() as $it ) {
		if ( $p1 === $it['product_id'] ) {
			$item = $it;
		}
	}
	ce_ok( '(a) product prop never written: get_price("edit") is still 33.33', '33.33' === (string) $item['data']->get_price( 'edit' ) );
	ce_ok( '(a) get_price() is the fractional net unit (84.99 / 3)', abs( (float) $item['data']->get_price() * 3 - 84.99 ) < 0.0000001 );
	ce_ok( '(a) no negative price anywhere', min( array_map( fn( $it ) => (float) $it['data']->get_price(), $cart->get_cart() ) ) >= 0 );

	// Cart hash and session: nothing of ours is in the cart item data.
	$h0 = $cart->get_cart_hash();
	$cart->calculate_totals();
	$h1   = $cart->get_cart_hash();
	$sess = $cart->get_cart_for_session();
	ce_ok( 'cart hash stable across recalculation', $h0 === $h1 );
	ce_ok( 'session copy carries no campaign key', ! preg_grep( '/eru_ce/', array_keys( reset( $sess ) ) ) );
	$cart->set_cart_contents( array() );
	WC()->session->set( 'cart', $sess );
	$cart->get_cart_from_session();
	$cart->calculate_totals();
	ce_ok( 'after a session reload: same totals and hash', ce_wc_snap( $cart ) === $runs[2] && $cart->get_cart_hash() === $h0 );

	// Cloned (recurring-cart pattern) and copied carts do not compound or disturb the main cart.
	$main0 = ce_wc_snap( $cart );
	$twin  = clone $cart;
	$twin->calculate_totals();
	$copy = new WC_Cart();
	$copy->set_cart_contents( $cart->get_cart_contents() );
	$copy->calculate_totals();
	ce_ok( 'cloned and copied carts total like the main cart', ce_minor( $twin->get_total( 'edit' ) ) === $main0['total'] && ce_minor( $copy->get_total( 'edit' ) ) === $main0['total'] );
	$twin->empty_cart( false );
	ce_ok( 'emptying a clone leaves the main cart\'s state', null !== CartApplier::stateFor( $cart ) );
	$cart->calculate_totals();
	$cart->calculate_totals();
	ce_ok( 'main cart unchanged after the other carts', ce_wc_snap( $cart ) === $main0 );

	// Another plugin setting prices in before_calculate_totals, earlier (10) and later (99) than our pass.
	foreach ( array( 10, 99 ) as $prio ) {
		$first = array();
		$cb    = function ( $c ) use ( &$first ) {
			foreach ( $c->get_cart() as $k => $it ) {
				if ( ! isset( $first[ $k ] ) ) {
					$first[ $k ] = (float) $it['data']->get_price( 'edit' );
				}
				$it['data']->set_price( round( $first[ $k ] * 0.9, 2 ) );
			}
		};
		add_action( 'woocommerce_before_calculate_totals', $cb, $prio );
		$hooks[] = array( 'woocommerce_before_calculate_totals', $cb, $prio );
		$cart    = ce_wc_fill( array( array( $p2, 2 ) ) );
		$runs    = array( ce_wc_snap( $cart ) );
		$cart->calculate_totals();
		$runs[] = ce_wc_snap( $cart );
		$cart->calculate_totals();
		$runs[] = ce_wc_snap( $cart );
		// 249.90 × 0.9 = 224.91 → line 449.82; 15 % = 67.473 → 67.48 → 382.34.
		ce_ok( "price plugin at before_calculate_totals $prio: 3 runs identical, discount on top of its price", $runs[0] === $runs[2] && $runs[1] === $runs[2] && 38234 === $runs[2]['lines'][ $p2 ] );
		remove_action( 'woocommerce_before_calculate_totals', $cb, $prio );
	}

	// A price filter before ours (role price) is the base; ours rides on top.
	$role = function ( $price, $product ) use ( $p2 ) {
		return $p2 === $product->get_id() ? '199.90' : $price;
	};
	add_filter( 'woocommerce_product_get_price', $role, 10, 2 );
	$hooks[] = array( 'woocommerce_product_get_price', $role, 10 );
	$cart    = ce_wc_fill( array( array( $p2, 1 ) ) );
	$cart->calculate_totals();
	// 199.90 × 15 % = 29.985 → 29.99 → 169.91.
	ce_ok( 'role-price filter before ours: discount on top of 199.90 -> 169.91', 16991 === ce_wc_lines( $cart )[ $p2 ] );
	remove_filter( 'woocommerce_product_get_price', $role, 10 );

	// A filter AFTER ours that replaces the price: the discount is withdrawn, never claimed.
	$hostile = function ( $price, $product ) use ( $p2 ) {
		return $p2 === $product->get_id() ? $product->get_regular_price() : $price;
	};
	add_filter( 'woocommerce_product_get_price', $hostile, CartApplier::pricePriority() + 1, 2 );
	$hooks[] = array( 'woocommerce_product_get_price', $hostile, CartApplier::pricePriority() + 1 );
	$cart    = ce_wc_fill( array( array( $p2, 1 ), array( $p1, 3 ) ) );
	$cart->calculate_totals();
	$r = CartApplier::resultFor( $cart );
	ce_ok( 'read-back: overridden line keeps its full price 249.90', 24990 === ce_wc_lines( $cart )[ $p2 ] );
	ce_ok( 'read-back: no adjustment claimed for the overridden line, the other line keeps its discount', 1500 === $r->adjustments->totalDiscount() && 8499 === ce_wc_lines( $cart )[ $p1 ] );
	ce_ok( 'read-back: WooCommerce subtotal = engine total', ce_wc_base_total( $cart ) - $r->adjustments->totalDiscount() === ce_minor( $cart->get_subtotal() ) );
	remove_filter( 'woocommerce_product_get_price', $hostile, CartApplier::pricePriority() + 1 );

	// A filter after ours that changes the price by itself (seen during the probe): line left alone.
	$convert = function ( $price, $product ) use ( $p2 ) {
		return $p2 === $product->get_id() ? (string) ( (float) $price + 1 ) : $price;
	};
	add_filter( 'woocommerce_product_get_price', $convert, CartApplier::pricePriority() + 1, 2 );
	$hooks[] = array( 'woocommerce_product_get_price', $convert, CartApplier::pricePriority() + 1 );
	$cart    = ce_wc_fill( array( array( $p2, 1 ) ) );
	$cart->calculate_totals();
	$r = CartApplier::resultFor( $cart );
	ce_ok( 'price changed after our priority: no discount, 250.90 charged and nothing claimed', 25090 === ce_wc_lines( $cart )[ $p2 ] && 0 === $r->adjustments->totalDiscount() );
	remove_filter( 'woocommerce_product_get_price', $convert, CartApplier::pricePriority() + 1 );

	// A plugin replacing the product objects after our pass: caught after the totals, corrected.
	$cloner = function ( $c ) {
		$contents = $c->get_cart_contents();
		foreach ( $contents as $k => $it ) {
			$contents[ $k ]['data'] = clone $it['data'];
		}
		$c->set_cart_contents( $contents );
	};
	add_action( 'woocommerce_before_calculate_totals', $cloner, PHP_INT_MAX );
	$hooks[] = array( 'woocommerce_before_calculate_totals', $cloner, PHP_INT_MAX );
	$cart    = ce_wc_fill( array( array( $p1, 3 ) ) );
	$runs    = array( ce_wc_snap( $cart ) );
	$cart->calculate_totals();
	$runs[] = ce_wc_snap( $cart );
	ce_ok( 'late object cloner: totals match the engine (nothing claimed that was not given), stable', $runs[0] === $runs[1] && ce_wc_base_total( $cart ) - $runs[1]['discount'] === $runs[1]['subtotal'] );
	remove_action( 'woocommerce_before_calculate_totals', $cloner, PHP_INT_MAX );

	// A cloner BEFORE our pass is harmless: the discount follows the new objects.
	add_action( 'woocommerce_before_calculate_totals', $cloner, 10 );
	$hooks[] = array( 'woocommerce_before_calculate_totals', $cloner, 10 );
	$cart    = ce_wc_fill( array( array( $p1, 3 ) ) );
	$cart->calculate_totals();
	$cart->calculate_totals();
	ce_ok( 'early object cloner: discount kept (84.99) and stable', 8499 === ce_wc_lines( $cart )[ $p1 ] );
	remove_action( 'woocommerce_before_calculate_totals', $cloner, 10 );

	// Recurring-cart pattern with a SUBSET: a clone without line B is calculated after the main cart.
	CampaignWriter::update( $c15, null, null, 'draft' );
	$pa  = ce_wc_product( 'CE Demo Alt A', '100.00' );
	$pb  = ce_wc_product( 'CE Demo Alt B', '100.00' );
	$sub = ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ),
			'targets' => array( 'include' => array( 'product' => array( $pa, $pb ) ) ),
			'rules'   => array(
				'op'       => 'and',
				'children' => array( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 15000 ) ),
			),
		),
		'CE Demo Alt Küme'
	);
	$cart     = ce_wc_fill( array( array( $pa, 1 ), array( $pb, 1 ) ) );
	ce_ok( 'subset clone set-up: main cart 180.00', 18000 === ce_minor( $cart->get_subtotal() ) );
	$clone_tot = null;
	$in_clone  = false;
	$recurring = function ( $c ) use ( &$clone_tot, &$in_clone, $pb ) {
		if ( $in_clone || WC()->cart !== $c ) {
			return;
		}
		$in_clone = true;
		$twin     = clone $c;
		$contents = $twin->get_cart_contents();
		foreach ( $contents as $k => $it ) {
			if ( $pb === $it['product_id'] ) {
				unset( $contents[ $k ] );
			}
		}
		$twin->set_cart_contents( $contents );
		$twin->calculate_totals();
		$clone_tot = ce_minor( $twin->get_subtotal() );
		$in_clone  = false;
	};
	add_action( 'woocommerce_after_calculate_totals', $recurring, 1000 );
	$hooks[] = array( 'woocommerce_after_calculate_totals', $recurring, 1000 );
	$cart->calculate_totals();
	ce_ok( 'subset clone calculated after the main cart: clone 100.00 (no discount), main stays 180.00', 10000 === $clone_tot && 18000 === ce_minor( $cart->get_subtotal() ) );
	$main_a = null;
	foreach ( $cart->get_cart() as $it ) {
		if ( $pa === $it['product_id'] ) {
			$main_a = $it['data'];
		}
	}
	ce_ok( 'subset clone: the main cart\'s product still reads its net price 90.00', abs( (float) $main_a->get_price() - 90.0 ) < 0.0000001 );
	remove_action( 'woocommerce_after_calculate_totals', $recurring, 1000 );
	$cart->calculate_totals();
	ce_ok( 'subset clone removed: main cart still 180.00 (nothing sticky)', 18000 === ce_minor( $cart->get_subtotal() ) );
	$copy = new WC_Cart();
	$cc   = $cart->get_cart_contents();
	foreach ( $cc as $k => $it ) {
		if ( $pb === $it['product_id'] ) {
			unset( $cc[ $k ] );
		}
	}
	$copy->set_cart_contents( $cc );
	$copy->calculate_totals();
	ce_ok( 'new WC_Cart + set_cart_contents subset: copy 100.00, main product still 90.00 without recalculating', 10000 === ce_minor( $copy->get_subtotal() ) && abs( (float) $main_a->get_price() - 90.0 ) < 0.0000001 );
	$cart->calculate_totals();
	ce_ok( 'main cart recalculated after the copy: 180.00', 18000 === ce_minor( $cart->get_subtotal() ) );

	// A withdrawn line comes back once the price plugin after us is gone (no sticky exclusion).
	add_action( 'woocommerce_before_calculate_totals', $cloner, PHP_INT_MAX );
	$cart = ce_wc_fill( array( array( $pa, 1 ), array( $pb, 1 ) ) );
	ce_ok( 'late cloner: discount withdrawn (200.00)', 20000 === ce_minor( $cart->get_subtotal() ) );
	remove_action( 'woocommerce_before_calculate_totals', $cloner, PHP_INT_MAX );
	$cart->calculate_totals();
	ce_ok( 'late cloner gone: discount back on the next calculation (180.00)', 18000 === ce_minor( $cart->get_subtotal() ) );
	CampaignWriter::update( $sub, null, null, 'draft' );

	// ------------------------------------------------------------------ (b) free shipping above an invented 900
	ce_ok( 'draft campaign is no longer active', 'inactive' === CampaignRepository::row( $c15 )['status'] );
	list( $zone, $rate_id ) = ce_wc_zone( '49.90' );
	$fs = ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'free_shipping' ) ),
			'rules'   => array(
				'op'       => 'and',
				'children' => array( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 90000 ) ),
			),
		),
		'CE Demo Kargo Bedava'
	);
	ce_wc_customer( ce_mail( 'a' ) );
	WC()->session->set( 'chosen_shipping_methods', array( $rate_id ) );
	$cart  = ce_wc_fill( array( array( $p2, 3 ) ) ); // 749.70
	$pkgs  = $cart->get_shipping_packages();
	$match = WC_Shipping_Zones::get_zone_matching_package( reset( $pkgs ) );
	ce_ok( '(b) the test\'s own zone is the one matched (zone ' . $match->get_id() . ' "' . $match->get_zone_name() . '")', (int) $zone === (int) $match->get_id() );
	$diag = function ( $c ) {
		$out = array();
		foreach ( WC()->shipping()->get_packages() as $i => $pk ) {
			foreach ( (array) ( $pk['rates'] ?? array() ) as $rid => $rate ) {
				$out[] = $rid . '=' . $rate->get_cost();
			}
			$out[] = 'fs:' . wp_json_encode( $pk['eru_ce_free_shipping'] ?? null );
		}
		$r = CartApplier::resultFor( $c );
		return ' [chosen ' . wp_json_encode( WC()->session->get( 'chosen_shipping_methods' ) ) . ' rates ' . implode( ' ', $out ) . ' applied ' . wp_json_encode( $r ? $r->appliedCampaignIds() : null ) . ']';
	};
	ce_ok( '(b) below 900: normal shipping 49.90' . ( 4990 === ce_minor( $cart->get_shipping_total() ) ? '' : $diag( $cart ) ), 4990 === ce_minor( $cart->get_shipping_total() ) );
	$cart = ce_wc_fill( array( array( $p2, 4 ) ) ); // 999.60
	ce_ok( '(b) above 900: shipping 0', 0 === ce_minor( $cart->get_shipping_total() ) && CartApplier::resultFor( $cart )->adjustments->freeShipping() );
	$cart->calculate_totals();
	ce_ok( '(b) recalculated: still 0, total 999.60', 0 === ce_minor( $cart->get_shipping_total() ) && 99960 === ce_minor( $cart->get_total( 'edit' ) ) );
	$cart->set_quantity( array_key_first( $cart->get_cart() ), 3 );
	$cart->calculate_totals();
	ce_ok( '(b) back under 900: shipping 49.90 again (package rates not stale)', 4990 === ce_minor( $cart->get_shipping_total() ) );
	CampaignWriter::update( $fs, null, null, 'draft' );

	// ------------------------------------------------------------------ carry-over: a bad stored row is skipped, not fatal
	$raw_id = ce_raw_id();
	$good   = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '10' ) ) ), 'CE Demo Sağlam' );
	$wpdb->insert(
		Schema::table( 'campaigns' ),
		array(
			'campaign_id' => $raw_id,
			'status'      => 'active',
			'priority'    => 50,
			'version'     => 3,
			'definition'  => wp_json_encode( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '40' ) ), 'targets' => array( 'include' => array( 'product' => array( 'abc' ) ) ) ) ),
			'updated_at'  => current_time( 'mysql', true ),
		)
	);
	CampaignRepository::flush();
	delete_option( 'eru_ce_config_errors' );
	$logged = 0;
	$logcb  = function ( $entry ) use ( &$logged, $raw_id ) {
		if ( $raw_id === (int) $entry['id'] ) {
			++$logged;
		}
	};
	add_action( 'eru_ce_config_error', $logcb, 10, 1 );
	$hooks[] = array( 'eru_ce_config_error', $logcb, 10 );
	$cart    = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( 'bad row skipped: the healthy 10 % campaign still applies (89.90 → 80.91)', 8091 === ce_wc_lines( $cart )[ $p3 ] );
	$errors = ConfigErrors::all();
	ce_ok( 'bad row recorded once for the admin notice (field + reason)', 1 === count( $errors ) && $raw_id === (int) reset( $errors )['id'] && 'targets.include.product' === reset( $errors )['field'] );
	CampaignRepository::flush();
	$cart->calculate_totals();
	$cart = ce_wc_fill( array( array( $p3, 2 ) ) );
	ce_ok( 'bad row: logged once only over several loads', 1 === count( ConfigErrors::all() ) && 1 === $logged );
	wp_set_current_user( 1 );
	ob_start();
	ConfigErrors::notice();
	$notice = ob_get_clean();
	wp_set_current_user( 0 );
	ce_ok( 'admin notice names the skipped campaign', false !== strpos( $notice, (string) $raw_id ) );
	$wpdb->delete( Schema::table( 'campaigns' ), array( 'campaign_id' => $raw_id ) );
	CampaignRepository::flush();

	// ------------------------------------------------------------------ carry-over: malformed settings fall back safely
	$big = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '70' ) ), 'priority' => 99 ), 'CE Demo Büyük İndirim' );
	delete_option( Options::LAST_GOOD_OPTION );
	update_option( Options::OPTION, array( 'stacking' => 'tuhaf', 'max_cart_discount_percent' => 'abc', 'min_unit_price_minor' => '-5' ), false );
	Options::flush();
	delete_option( 'eru_ce_config_errors' );
	$s = Options::engineSettings();
	ce_ok( 'settings fallback: stacking best, cart cap kept at the safe default (never "no cap")', 'best' === $s->stacking && Options::SAFE_CART_CAP === $s->max_cart_discount_percent );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( 'settings fallback: 70 % campaign capped at the safe cart cap (89.90 → 44.95)', 4495 === ce_wc_lines( $cart )[ $p3 ] );
	ce_ok( 'settings fallback: recorded for the admin notice', (bool) array_filter( ConfigErrors::all(), fn( $e ) => 'settings' === $e['type'] ) );

	// Fail closed: an unreadable floor keeps the last floor the merchant saved; with none on record the cart
	// cap is forced to the safe cap; a malformed with_coupons means "not with coupons"; a corrupted option
	// gives safe values and a notice.
	delete_option( Options::OPTION );
	delete_option( Options::LAST_GOOD_OPTION );
	Options::flush();
	ce_ok( 'settings: a valid save keeps a last-known-good copy', true === Options::update( array( 'min_unit_price_minor' => 8000 ) ) && 8000 === (int) ( get_option( Options::LAST_GOOD_OPTION )['min_unit_price_minor'] ?? 0 ) );
	update_option( Options::OPTION, array( 'min_unit_price_minor' => 'abc' ), false );
	Options::flush();
	$s = Options::engineSettings();
	ce_ok( 'settings: unreadable floor keeps the last saved floor (80.00), not 0', 8000 === $s->min_unit_price_minor );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( 'settings: 70 % campaign stops at the kept floor (89.90 → 80.00)', 8000 === ce_wc_lines( $cart )[ $p3 ] );
	delete_option( Options::LAST_GOOD_OPTION );
	update_option( Options::OPTION, array( 'min_unit_price_minor' => 'abc' ), false );
	Options::flush();
	$s = Options::engineSettings();
	ce_ok( 'settings: unreadable floor with no saved floor -> the safe cart cap is forced', Options::SAFE_CART_CAP === $s->max_cart_discount_percent );
	update_option( Options::OPTION, array( 'with_coupons' => 'belki' ), false );
	Options::flush();
	ce_ok( 'settings: malformed with_coupons -> campaigns do not stack with coupons', false === Options::engineSettings()->with_coupons );
	delete_option( 'eru_ce_config_errors' );
	update_option( Options::OPTION, 'bozuk', false );
	Options::flush();
	$s = Options::engineSettings();
	ce_ok( 'settings: corrupted (non-array) option -> safe values (best, safe cap, no coupon stacking)', 'best' === $s->stacking && Options::SAFE_CART_CAP === $s->max_cart_discount_percent && false === $s->with_coupons );
	ob_start();
	wp_set_current_user( 1 );
	ConfigErrors::notice();
	wp_set_current_user( 0 );
	$notice = ob_get_clean();
	ce_ok( 'settings: corrupted option is shown in the admin notice', false !== strpos( $notice, Options::OPTION ) );
	update_option( Options::OPTION, array( 'max_cart_discount_percent' => '20' ), false );
	Options::flush();
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( 'valid settings honoured: cart cap 20 % (89.90 → 71.92)', 7192 === ce_wc_lines( $cart )[ $p3 ] );
	$saved = Options::update( array( 'max_cart_discount_percent' => '120' ) );
	ce_ok( 'Options::update refuses a malformed cap with field + reason', is_wp_error( $saved ) && 'max_cart_discount_percent' === ( $saved->get_error_data()['field'] ?? '' ) );
	ce_ok( 'settings option is not autoloaded', 'no' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Options::OPTION ) ) || 'off' === $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Options::OPTION ) ) );
	CampaignWriter::update( $big, null, null, 'draft' );
	CampaignWriter::update( $good, null, null, 'draft' );

	// ------------------------------------------------------------------ cache adaptor: none / LiteSpeed hooks / custom action
	$purged = array();
	$spy    = function ( ...$args ) use ( &$purged ) {
		$purged[] = array( current_action(), $args );
	};
	foreach ( array( 'litespeed_purge_post', 'litespeed_purge_all', 'eru_ce_cache_purge' ) as $hook ) {
		add_action( $hook, $spy, 10, 2 );
		$hooks[] = array( $hook, $spy, 10 );
	}
	Options::update( array( 'cache_adaptor' => 'none' ) );
	$pc = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_fixed', 'amount' => 500 ) ), 'targets' => array( 'include' => array( 'product' => array( $p3 ) ) ) ), 'CE Demo Ürün Sabit', 'draft' );
	ce_ok( 'cache adaptor "none": nothing fired', array() === $purged );
	Options::update( array( 'cache_adaptor' => 'litespeed' ) );
	CampaignWriter::update( $pc, null, null, 'draft' );
	ce_ok( 'cache adaptor "litespeed": its public purge action for the targeted product', array( array( 'litespeed_purge_post', array( $p3 ) ) ) === $purged );
	$purged = array();
	Options::update( array( 'cache_adaptor' => 'custom' ) );
	CampaignWriter::update( $pc, array( 'actions' => array( array( 'type' => 'product_fixed', 'amount' => 500 ) ) ), null, 'draft' );
	ce_ok( 'cache adaptor "custom": eru_ce_cache_purge, everything for a storewide campaign', array( array( 'eru_ce_cache_purge', array( array(), true ) ) ) === $purged );
	Options::update( array( 'cache_adaptor' => 'none' ) );

	// ------------------------------------------------------------------ trash / delete keep the ledger, drop the compiled row
	wp_trash_post( $good );
	ce_ok( 'trashed campaign: compiled row gone', null === CampaignRepository::row( $good ) );
	$cart = ce_wc_fill( array( array( $p3, 1 ) ) );
	ce_ok( 'no active campaign left: plain price again', 8990 === ce_wc_lines( $cart )[ $p3 ] );
} catch ( RuntimeException $e ) {
	$fail = true;
	echo 'STOPPED at first failure: ' . $e->getMessage() . "\n";
} finally {
	foreach ( $hooks as $h ) {
		remove_filter( $h[0], $h[1], $h[2] );
	}
	ce_wc_purge();
	ce_wc_restore_options( $keep );
	WC()->session->set( 'chosen_shipping_methods', array() );
}

echo $fail ? "FAILED\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
