<?php
/**
 * Campaign Engine — shared helpers for the WooCommerce integration tests (ce-cart, ce-orders, ce-rewards):
 * the HTTP and mail guards, invented products, terms, campaigns, a shipping zone and tax rate, a classic
 * checkout, and a clean-up that removes every leftover of an aborted run.
 *
 * Every e-mail is …@example.com, every name "CE Demo …". The plugin must be active. Not a test on its own.
 */

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Database\Schema;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;

if ( defined( 'CE_WC_HELPERS' ) ) {
	return;
}
define( 'CE_WC_HELPERS', 1 );

/** Prints PASS/FAIL; a failure throws so the clean-up in `finally` still runs. */
function ce_ok( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
}

/** Minor units of a WooCommerce decimal (string or float). */
function ce_minor( $amount ) {
	return Money::of( is_float( $amount ) ? (string) round( $amount, 6 ) : (string) $amount, wc_get_price_decimals() );
}

/** Stops the run unless the plugin and its WooCommerce layer are loaded. */
function ce_wc_require() {
	if ( ! class_exists( CartApplier::class ) || ! did_action( 'eru_ce_loaded' ) ) {
		echo "FAIL: Campaign Engine WooCommerce layer not loaded (plugin not active, or src/Infrastructure missing)\n";
		exit( 1 );
	}
}

/** Mail trap and HTTP guard; returns a reference holder for captured mails. */
function ce_wc_guards( array &$mails ) {
	add_filter(
		'pre_wp_mail',
		function ( $short, $atts ) use ( &$mails ) {
			$mails[] = $atts;
			return true;
		},
		10,
		2
	);
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) {
			if ( false !== strpos( $url, 'wp-cron.php' ) || false !== strpos( $url, 'admin-ajax.php' ) ) {
				return new WP_Error( 'ce_test', 'spawn blocked' );
			}
			echo 'FAIL unmocked HTTP ' . $url . "\n";
			exit( 1 );
		},
		10,
		3
	);
	// Core order e-mails are not under test.
	foreach ( array( 'new_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'cancelled_order', 'failed_order', 'customer_failed_order' ) as $id ) {
		add_filter( 'woocommerce_email_enabled_' . $id, '__return_false' );
	}
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

/** Options the tests change; restored by ce_wc_restore_options(). */
function ce_wc_snapshot_options() {
	$keep = array();
	foreach ( array( 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_display_cart', 'woocommerce_tax_display_shop', 'woocommerce_coupons_enabled', Options::OPTION, Options::LAST_GOOD_OPTION, 'eru_ce_config_errors' ) as $o ) {
		$keep[ $o ] = get_option( $o, null );
	}
	return $keep;
}

function ce_wc_restore_options( array $keep ) {
	foreach ( $keep as $o => $v ) {
		null === $v ? delete_option( $o ) : update_option( $o, $v, false );
	}
	Options::flush();
}

/**
 * Per-run tag: every fixture of this process carries it (meta `_ce_test`, e-mail, term, zone and tax
 * names, tokens), so a run cleans only its own data plus leftovers of aborted runs older than
 * CE_STALE seconds, and never another process's fixtures.
 */
define( 'CE_RUN', 'r' . base_convert( (string) time(), 10, 36 ) . substr( md5( uniqid( '', true ) ), 0, 3 ) );
define( 'CE_STALE', 1800 );

/** Start time of a run tag, or 0 for legacy/unknown tags. */
function ce_run_time( $tag ) {
	return 1 === preg_match( '/^r([0-9a-z]{6})[0-9a-f]{3}$/', (string) $tag, $m ) ? (int) base_convert( $m[1], 36, 10 ) : 0;
}

/** Whether a tag belongs to this run ($stale = false) or to an aborted older run ($stale = true). */
function ce_run_match( $tag, $stale ) {
	if ( ! $stale ) {
		return CE_RUN === (string) $tag;
	}
	return CE_RUN !== (string) $tag && ce_run_time( $tag ) < time() - CE_STALE;
}

/** The run tag inside a fixture name/e-mail ("… r…"), or ''. */
function ce_tag_in( $text ) {
	return 1 === preg_match( '/(r[0-9a-z]{6}[0-9a-f]{3})(?:@|$)/', (string) $text, $m ) ? $m[1] : '';
}

/** Invented test e-mail of this run. */
function ce_mail( $who ) {
	return 'ce-demo-' . $who . '-' . CE_RUN . '@example.com';
}

/** A 32-character test token of this run. */
function ce_token( $what ) {
	return substr( str_pad( 'cetest' . CE_RUN . $what, 32, '0' ), 0, 32 );
}

/** Raw campaign row id for this run (990000+). */
function ce_raw_id() {
	static $id = null;
	if ( null === $id ) {
		$id = 990000 + random_int( 1, 9999 );
	}
	return $id;
}

/**
 * Removes this run's fixtures ($stale = false, at the end) or leftovers of aborted runs ($stale = true,
 * at the start): orders, coupons, products, terms, campaigns, rows, zones, tax rates.
 */
function ce_wc_purge( $stale = false ) {
	global $wpdb;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		WC()->cart->empty_cart();
	}
	$mine = fn( $tag ) => ce_run_match( $tag, $stale ) || ( $stale && '1' === (string) $tag );
	foreach ( wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'search' => 'ce-demo-' ) ) as $o ) {
		if ( $mine( ce_tag_in( $o->get_billing_email() ) ) || ( $stale && '' === ce_tag_in( $o->get_billing_email() ) ) ) {
			$o->delete( true );
		}
	}
	foreach ( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_ce_test', 'meta_compare' => 'EXISTS' ) ) as $o ) {
		if ( $mine( $o->get_meta( '_ce_test' ) ) ) {
			$o->delete( true );
		}
	}
	$tables = Schema::tables();
	$exists = fn( $t ) => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	if ( $exists( $tables['rewards'] ) ) {
		foreach ( $wpdb->get_results( "SELECT id, email, coupon_id FROM {$tables['rewards']} WHERE email LIKE 'ce-demo-%'", ARRAY_A ) as $r ) {
			if ( $mine( ce_tag_in( $r['email'] ) ) ) {
				if ( (int) $r['coupon_id'] > 0 ) {
					wp_delete_post( (int) $r['coupon_id'], true );
				}
				$wpdb->delete( $tables['rewards'], array( 'id' => (int) $r['id'] ) );
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( 'eru_ce_reward_mature', array( (int) $r['id'] ), 'eru-ce' );
				}
			}
		}
	}
	foreach ( get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => -1, 's' => 'CEDEMO' ) ) as $c ) {
		if ( $mine( strtolower( substr( $c->post_title, 6, 10 ) ) ) ) {
			wp_delete_post( $c->ID, true );
		}
	}
	$campaigns = array();
	foreach ( get_posts( array( 'post_type' => 'eru_ce_campaign', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_ce_test', 'meta_compare' => 'EXISTS' ) ) as $id ) {
		if ( $mine( get_post_meta( $id, '_ce_test', true ) ) ) {
			$campaigns[] = (int) $id;
			wp_delete_post( (int) $id, true );
		}
	}
	if ( $exists( $tables['campaigns'] ) ) {
		if ( $stale ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['campaigns']} WHERE campaign_id >= 990000 AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - CE_STALE ) ) );
		} else {
			$campaigns[] = ce_raw_id();
		}
		if ( $campaigns ) {
			$in = implode( ',', array_map( 'intval', $campaigns ) );
			foreach ( array( 'campaigns', 'targets', 'usage', 'rewards' ) as $t ) {
				$wpdb->query( "DELETE FROM {$tables[$t]} WHERE campaign_id IN ($in)" );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['usage']} WHERE token LIKE %s", 'cetest' . ( $stale ? '' : CE_RUN ) . '%' ) );
		$wpdb->query( "DELETE FROM {$tables['customer_metrics']} WHERE email_hash IN (" . implode( ',', array_map( fn( $e ) => "'" . hash( 'sha256', $e ) . "'", ce_wc_emails() ) ) . ')' );
	}
	foreach ( get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_ce_test', 'meta_compare' => 'EXISTS' ) ) as $id ) {
		if ( $mine( get_post_meta( $id, '_ce_test', true ) ) ) {
			wp_delete_post( (int) $id, true );
		}
	}
	foreach ( array( 'product_cat', 'product_tag' ) as $tax ) {
		foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'search' => 'CE Demo' ) ) as $t ) {
			if ( $mine( ce_tag_in( $t->name ) ) || ( $stale && '' === ce_tag_in( $t->name ) ) ) {
				wp_delete_term( $t->term_id, $tax );
			}
		}
	}
	foreach ( WC_Shipping_Zones::get_zones() as $z ) {
		if ( 0 === strpos( $z['zone_name'], 'CE Demo' ) && ( $mine( ce_tag_in( $z['zone_name'] ) ) || ( $stale && '' === ce_tag_in( $z['zone_name'] ) ) ) ) {
			WC_Shipping_Zones::delete_zone( $z['id'] );
		}
	}
	foreach ( $wpdb->get_results( "SELECT tax_rate_id, tax_rate_name FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name LIKE 'CE Demo%'", ARRAY_A ) as $r ) {
		if ( $mine( ce_tag_in( $r['tax_rate_name'] ) ) || ( $stale && '' === ce_tag_in( $r['tax_rate_name'] ) ) ) {
			WC_Tax::_delete_tax_rate( (int) $r['tax_rate_id'] );
		}
	}
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	// Plugin-wide jobs go only when no open work of anyone is left.
	if ( function_exists( 'as_unschedule_all_actions' ) && ! \Erusoft\CampaignEngine\Infrastructure\Jobs::workRemains() ) {
		as_unschedule_all_actions( '', array(), 'eru-ce' );
	}
}

function ce_wc_emails() {
	return array_map( 'ce_mail', array( 'a', 'b', 'c', 'd', 'sapi' ) );
}

/** An invented product category. */
function ce_wc_term( $name ) {
	$t = wp_insert_term( $name . ' ' . CE_RUN, 'product_cat' );
	return is_wp_error( $t ) ? (int) $t->get_error_data() : (int) $t['term_id'];
}

/** An invented simple product. */
function ce_wc_product( $name, $price, array $cats = array(), $sale = '' ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( (string) $price );
	if ( '' !== $sale ) {
		$p->set_sale_price( (string) $sale );
	}
	$p->set_category_ids( $cats );
	$p->set_manage_stock( false );
	$p->set_tax_class( '' );
	$p->set_status( 'publish' );
	$p->update_meta_data( '_ce_test', CE_RUN );
	return $p->save();
}

/** A published campaign through the writer (fails the test on a validation error). */
function ce_wc_campaign( array $definition, $title, $status = 'publish' ) {
	$id = CampaignWriter::create( $definition, $title, $status );
	if ( is_wp_error( $id ) ) {
		ce_ok( 'campaign "' . $title . '" saved: ' . $id->get_error_message(), false );
	}
	update_post_meta( $id, '_ce_test', CE_RUN );
	return (int) $id;
}

/** Empties the cart and adds [product_id, qty] lines; returns the cart. */
function ce_wc_fill( array $lines ) {
	WC()->cart->empty_cart();
	foreach ( $lines as $l ) {
		WC()->cart->add_to_cart( $l[0], $l[1] );
	}
	WC()->cart->calculate_totals();
	return WC()->cart;
}

/** Line subtotal (minor) per product id. */
function ce_wc_lines( WC_Cart $cart ) {
	$out = array();
	foreach ( $cart->get_cart() as $item ) {
		$out[ $item['product_id'] ] = ce_minor( $item['line_subtotal'] );
	}
	return $out;
}

/** The numbers that must not move between recalculations. */
function ce_wc_snap( WC_Cart $cart ) {
	$r = CartApplier::resultFor( $cart );
	return array(
		'subtotal' => ce_minor( $cart->get_subtotal() ),
		'total'    => ce_minor( $cart->get_total( 'edit' ) ),
		'lines'    => ce_wc_lines( $cart ),
		'discount' => $r ? $r->adjustments->totalDiscount() : 0,
		'lineDisc' => $r ? $r->adjustments->lineDiscounts() : array(),
	);
}

/** Σ base × qty (minor) as the applier saw the lines. */
function ce_wc_base_total( WC_Cart $cart ) {
	$state = CartApplier::stateFor( $cart );
	$sum   = 0;
	foreach ( (array) ( $state['lines'] ?? array() ) as $line ) {
		$sum += $line['base_minor'] * $line['qty'];
	}
	return $sum;
}

/** A shipping zone for TR with one flat rate (cost as a decimal string). */
function ce_wc_zone( $cost ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'CE Demo Bölge ' . CE_RUN );
	$zone->set_zone_order( -1000 );
	$zone->add_location( 'TR', 'country' );
	$zone->add_location( '06420', 'postcode' );
	$zone->save();
	$iid    = $zone->add_shipping_method( 'flat_rate' );
	$method = WC_Shipping_Zones::get_shipping_method( $iid );
	update_option( $method->get_instance_option_key(), array( 'title' => 'CE Demo Kargo', 'cost' => (string) $cost, 'tax_status' => 'none' ), false );
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	return array( $zone->get_id(), 'flat_rate:' . $iid );
}

/** Sets the guest customer's address (Ankara/Çankaya, invented) and e-mail. */
function ce_wc_customer( $email ) {
	$c = WC()->customer;
	$c->set_billing_email( $email );
	$c->set_billing_country( 'TR' );
	$c->set_shipping_country( 'TR' );
	$c->set_billing_state( 'TR06' );
	$c->set_shipping_state( 'TR06' );
	$c->set_billing_city( 'Çankaya' );
	$c->set_shipping_city( 'Çankaya' );
	$c->set_billing_postcode( '06420' );
	$c->set_shipping_postcode( '06420' );
	$c->set_billing_address_1( 'Demo Sokak 1' );
	$c->set_shipping_address_1( 'Demo Sokak 1' );
	$c->set_billing_first_name( 'Demo' );
	$c->set_billing_last_name( 'Alıcı' );
}

/**
 * Classic checkout of the current cart: WC_Checkout::create_order() with invented posted data, so every
 * checkout hook runs. Returns the order or WP_Error. Clears `order_awaiting_payment` first unless $resume.
 */
function ce_wc_checkout( $email, $resume = false ) {
	if ( ! $resume ) {
		WC()->session->set( 'order_awaiting_payment', 0 );
	}
	ce_wc_customer( $email );
	WC()->cart->calculate_totals();
	$data = array(
		'billing_email'      => $email,
		'billing_first_name' => 'Demo',
		'billing_last_name'  => 'Alıcı',
		'billing_country'    => 'TR',
		'billing_state'      => 'TR06',
		'billing_city'       => 'Çankaya',
		'billing_postcode'   => '06420',
		'billing_address_1'  => 'Demo Sokak 1',
		'payment_method'     => 'bacs',
		'order_comments'     => '',
	);
	$id = WC()->checkout()->create_order( $data );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$order = wc_get_order( $id );
	$order->update_meta_data( '_ce_test', CE_RUN );
	$order->save();
	WC()->session->set( 'order_awaiting_payment', $id );
	return wc_get_order( $id );
}

/** Pending Action Scheduler ids of a hook in the plugin's group. */
function ce_wc_pending( $hook, $args = null ) {
	$q = array(
		'hook'     => $hook,
		'group'    => 'eru-ce',
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 50,
	);
	if ( null !== $args ) {
		$q['args'] = $args;
	}
	return as_get_scheduled_actions( $q, 'ids' );
}
