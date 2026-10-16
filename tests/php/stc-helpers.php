<?php
/**
 * Sales Team Commissions — shared test helpers: assertions, guards (mail trap, HTTP catch-all), invented
 * users, agents, products, terms, rules and hand-built orders, settings snapshot and a clean-up that
 * removes this run's fixtures and leftovers of aborted runs.
 *
 * Every e-mail is stc-demo-…@example.com, every name "STC Demo …". The plugin must be active.
 * Included by tests/php/stc-*.php; not a test on its own.
 */

if ( defined( 'STC_TEST_HELPERS' ) ) {
	return;
}
define( 'STC_TEST_HELPERS', 1 );

require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Per-run tag carried by every fixture (e-mails, names, meta `_stc_test`), so a run removes its own data
 * and leftovers of aborted runs older than STC_STALE seconds, never another live run's fixtures.
 */
define( 'STC_RUN', 'r' . base_convert( (string) time(), 10, 36 ) . substr( md5( uniqid( '', true ) ), 0, 3 ) );
define( 'STC_STALE', 1800 );

/** Prints PASS/FAIL; a failure throws so the caller's `finally` still cleans up before exit(1). */
function stc_check( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
}

/** Exits unless the plugin's domain classes are loaded. */
function stc_require_plugin() {
	foreach ( array( 'Eru_STC_Plugin', 'Eru_STC_Installer', 'Eru_STC_Settings', 'Eru_STC_Money', 'Eru_STC_Agents', 'Eru_STC_Links', 'Eru_STC_Rules', 'Eru_STC_Commissions', 'Eru_STC_Payouts', 'Eru_STC_Calculator', 'Eru_STC_Refunds', 'Eru_STC_Eligibility', 'Eru_STC_Referral' ) as $c ) {
		if ( ! class_exists( $c ) ) {
			echo "FAIL: {$c} missing (plugin not active?)\n";
			exit( 1 );
		}
	}
}

/** Start time of a run tag, or 0. */
function stc_run_time( $tag ) {
	return 1 === preg_match( '/^r([0-9a-z]{6})[0-9a-f]{3}$/', (string) $tag, $m ) ? (int) base_convert( $m[1], 36, 10 ) : 0;
}

/** Whether a tag is this run's ($stale false) or an aborted older run's ($stale true). */
function stc_mine( $tag, $stale ) {
	if ( ! $stale ) {
		return STC_RUN === (string) $tag;
	}
	return '' !== (string) $tag && STC_RUN !== (string) $tag && stc_run_time( $tag ) < time() - STC_STALE;
}

/** The run tag inside a fixture e-mail or name, or ''. */
function stc_tag_in( $text ) {
	return 1 === preg_match( '/(r[0-9a-z]{6}[0-9a-f]{3})(?:@|$)/', (string) $text, $m ) ? $m[1] : '';
}

/** Invented e-mail of this run. */
function stc_mail( $who ) {
	return 'stc-demo-' . $who . '-' . STC_RUN . '@example.com';
}

/** Mail trap, HTTP catch-all and quiet WooCommerce e-mails. @param array $mails Captured mails. */
function stc_guards( array &$mails ) {
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
				return new WP_Error( 'stc_test', 'spawn blocked' );
			}
			echo 'FAIL unmocked HTTP ' . $url . "\n";
			exit( 1 );
		},
		10,
		3
	);
	foreach ( array( 'new_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'customer_refunded_order', 'customer_partially_refunded_order', 'cancelled_order', 'failed_order', 'customer_failed_order', 'customer_new_account' ) as $id ) {
		add_filter( 'woocommerce_email_enabled_' . $id, '__return_false' );
	}
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

/** Saves the settings over the defaults (module on). @param array $over Overrides. */
function stc_settings( array $over = array() ) {
	Eru_STC_Settings::update( array_merge( Eru_STC_Settings::defaults(), array( 'enabled' => 'yes' ), $over ) );
}

/** Snapshot of the options the tests change. @return array */
function stc_snapshot() {
	$keep = array();
	foreach ( array( Eru_STC_Settings::OPTION, 'eru_stc_rr_pointer', 'eru_stc_watchdog_at' ) as $o ) {
		$keep[ $o ] = get_option( $o, null );
	}
	return $keep;
}

/** Restores a snapshot. @param array $keep Snapshot. */
function stc_restore( array $keep ) {
	foreach ( $keep as $o => $v ) {
		null === $v ? delete_option( $o ) : update_option( $o, $v );
	}
	Eru_STC_Settings::flush();
}

/** An invented customer user of this run. @return int User id. */
function stc_user( $who, $role = 'customer' ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'stc-demo-' . $who . '-' . STC_RUN,
			'user_email' => stc_mail( $who ),
			'user_pass'  => wp_generate_password( 20 ),
			'first_name' => 'STC Demo',
			'last_name'  => ucfirst( $who ),
			'role'       => $role,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'user: ' . $id->get_error_message() );
	}
	return (int) $id;
}

/** An agent (with its own invented user). @return int Agent id. */
function stc_agent( $who, $parent = null, $status = 'active' ) {
	$id = Eru_STC_Agents::create(
		stc_user( $who ),
		array(
			'parent_agent_id' => $parent,
			'status'          => $status,
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'agent: ' . $id->get_error_message() );
	}
	return (int) $id;
}

/** An invented term of this run. @return int Term id. */
function stc_term( $taxonomy, $name ) {
	$t = wp_insert_term( 'STC Demo ' . $name . ' ' . STC_RUN, $taxonomy );
	if ( is_wp_error( $t ) ) {
		throw new RuntimeException( 'term: ' . $t->get_error_message() );
	}
	return (int) $t['term_id'];
}

/** An invented simple product of this run. @return WC_Product_Simple */
function stc_product( $name, $price, array $cats = array(), array $brands = array() ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'STC Demo ' . $name . ' ' . STC_RUN );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	if ( $cats ) {
		$p->set_category_ids( $cats );
	}
	$p->update_meta_data( '_stc_test', STC_RUN );
	$p->save();
	if ( $brands ) {
		wp_set_object_terms( $p->get_id(), $brands, 'product_brand' );
	}
	return $p;
}

/** An invented rule of this run. @return int Rule id. */
function stc_rule( array $args ) {
	$id = Eru_STC_Rules::create( array_merge( array( 'name' => 'STC Demo kural ' . STC_RUN ), $args ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'rule: ' . $id->get_error_message() );
	}
	return (int) $id;
}

/**
 * A hand-built order (totals set explicitly, no tax engine involved).
 *
 * @param int   $customer_id Customer (0 = guest).
 * @param array $lines       [ [ WC_Product, qty, line_total_ex_tax, line_tax ], … ].
 * @param array $shipping    [ total_ex_tax, tax ] or [].
 * @return WC_Order
 */
function stc_order( $customer_id, array $lines, array $shipping = array() ) {
	$order = wc_create_order( array( 'customer_id' => $customer_id ) );
	$sum   = 0.0;
	foreach ( $lines as $l ) {
		list( $product, $qty, $total, $tax ) = $l;
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $qty );
		$item->set_subtotal( $total );
		$item->set_total( $total );
		// One invented tax line (rate id 1) so the item's tax totals really hold $tax (set_taxes() recomputes them).
		$item->set_taxes( (float) $tax > 0 ? array( 'total' => array( 1 => $tax ), 'subtotal' => array( 1 => $tax ) ) : array() );
		$order->add_item( $item );
		$sum += (float) $total + (float) $tax;
	}
	if ( $shipping ) {
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( 'STC Demo kargo' );
		$ship->set_method_id( 'flat_rate' );
		$ship->set_total( $shipping[0] );
		$ship->set_taxes( array() );
		$order->add_item( $ship );
		$order->set_shipping_total( $shipping[0] );
		$order->set_shipping_tax( $shipping[1] );
		$sum += (float) $shipping[0] + (float) $shipping[1];
	}
	$order->set_billing_email( $customer_id ? get_userdata( $customer_id )->user_email : stc_mail( 'guest' ) );
	$order->set_billing_first_name( 'STC Demo' );
	$order->set_cart_tax( (string) array_sum( array_map( fn( $l ) => (float) $l[3], $lines ) ) );
	$order->set_total( (string) round( $sum, 2 ) );
	$order->update_meta_data( '_stc_test', STC_RUN );
	$order->save();
	return wc_get_order( $order->get_id() );
}

/** Moves an order to a status (fresh object, hooks fire). */
function stc_status( $order_id, $status ) {
	$o = wc_get_order( $order_id );
	$o->update_status( $status );
	return wc_get_order( $order_id );
}

/** Commission rows of an order, ordered by item, level, entry. @return object[] */
function stc_rows( $order_id ) {
	global $wpdb;
	$t = $wpdb->prefix . 'eru_stc_commissions';
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY item_id, level, entry_key, id", $order_id ) ); // phpcs:ignore
}

/** Rows of one agent among a set. @return object[] */
function stc_rows_of( array $rows, $agent_id ) {
	return array_values( array_filter( $rows, fn( $r ) => (int) $r->agent_id === (int) $agent_id ) );
}

/** Sum of the amounts (as a 2-decimal string). */
function stc_sum( array $rows ) {
	$m = 0;
	foreach ( $rows as $r ) {
		$m += Eru_STC_Money::to_minor( $r->amount );
	}
	return Eru_STC_Money::from_minor( $m );
}

/** Pending actions of a hook. @return int[] */
function stc_pending( $hook ) {
	return as_get_scheduled_actions(
		array(
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		),
		'ids'
	);
}

/**
 * A TCKN-shaped number with a valid checksum, generated at run time (never a literal in a file).
 *
 * @param int[]|null $first9 First nine digits (default: random, first digit 1–9).
 * @return string
 */
function stc_fake_tckn( $first9 = null ) {
	$d     = $first9 ? array_map( 'intval', $first9 ) : array_merge( array( random_int( 1, 9 ) ), array_map( fn() => random_int( 0, 9 ), range( 1, 8 ) ) );
	$d[9]  = ( ( ( $d[0] + $d[2] + $d[4] + $d[6] + $d[8] ) * 7 - ( $d[1] + $d[3] + $d[5] + $d[7] ) ) % 10 + 10 ) % 10;
	$d[10] = array_sum( array_slice( $d, 0, 10 ) ) % 10;
	return implode( '', $d );
}

/**
 * A TR IBAN with valid check digits and the unassigned bank code 99999, generated at run time, so it can
 * never be a real account.
 *
 * @return string 26 characters, no spaces.
 */
function stc_fake_iban() {
	$bban = '99999' . '0';
	for ( $i = 0; $i < 16; $i++ ) {
		$bban .= (string) random_int( 0, 9 );
	}
	$mod = 0;
	foreach ( str_split( $bban . '292700' ) as $c ) { // T = 29, R = 27, check "00".
		$mod = ( $mod * 10 + (int) $c ) % 97;
	}
	return 'TR' . str_pad( (string) ( 98 - $mod ), 2, '0', STR_PAD_LEFT ) . $bban;
}

/** Defines the encryption key for this process only (random, never written anywhere). */
function stc_define_test_key() {
	if ( ! defined( 'ERU_STC_ENCRYPTION_KEY' ) ) {
		define( 'ERU_STC_ENCRYPTION_KEY', base64_encode( random_bytes( 32 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}

/** Thrown by the wp_die / redirect traps so a handler stops without ending the process. */
class STC_Stop extends RuntimeException {
	/** @var int HTTP status of the die, 0 for a redirect. */
	public $status = 0;
	/** @var string Redirect target. */
	public $location = '';
}

/** wp_die() and redirects throw STC_Stop instead of ending the process. */
function stc_trap_exits() {
	$handler = function () {
		return function ( $message, $title = '', $args = array() ) {
			$e         = new STC_Stop( is_string( $message ) ? wp_strip_all_tags( $message ) : 'die' );
			$e->status = (int) ( is_array( $args ) && isset( $args['response'] ) ? $args['response'] : 500 );
			throw $e;
		};
	};
	add_filter( 'wp_die_handler', $handler );
	add_filter( 'wp_die_ajax_handler', $handler );
	add_filter( 'wp_die_json_handler', $handler );
	add_filter(
		'wp_redirect',
		function ( $location ) {
			$e           = new STC_Stop( 'redirect' );
			$e->location = (string) $location;
			throw $e;
		},
		1
	);
}

/**
 * Runs a handler that ends in wp_die / redirect / wp_send_json.
 *
 * @param callable $fn Handler.
 * @return array{stop:?STC_Stop,output:string,status:int}
 */
function stc_call( callable $fn ) {
	$status = 0;
	$hook   = function ( $header, $code ) use ( &$status ) {
		$status = (int) $code;
		return $header;
	};
	add_filter( 'status_header', $hook, 10, 2 );
	ob_start();
	$stop = null;
	try {
		$fn();
	} catch ( STC_Stop $e ) {
		$stop = $e;
	} finally {
		$out = (string) ob_get_clean();
		remove_filter( 'status_header', $hook, 10 );
	}
	if ( $stop && $stop->status && ! $status ) {
		$status = $stop->status;
	}
	return array(
		'stop'   => $stop,
		'output' => $out,
		'status' => $status,
	);
}

/** A second MySQL connection (another "request") for lock tests. @return mysqli */
function stc_second_connection() {
	global $wpdb;
	list( $host, $port, $socket ) = $wpdb->parse_db_host( DB_HOST );
	$db = mysqli_init();
	if ( ! $db->real_connect( $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : null, $socket ? $socket : null ) ) {
		throw new RuntimeException( 'second connection failed' );
	}
	return $db;
}

/** Removes this run's fixtures ($stale false) or aborted runs' leftovers ($stale true). */
function stc_purge( $stale = false ) {
	global $wpdb;
	$p = $wpdb->prefix . 'eru_stc_';
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'commissions' ) ) ) {
		return;
	}
	$orders = array();
	foreach ( wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'meta_key' => '_stc_test', 'meta_compare' => 'EXISTS' ) ) as $o ) {
		if ( stc_mine( $o->get_meta( '_stc_test' ), $stale ) ) {
			$orders[] = $o->get_id();
			$o->delete( true );
		}
	}
	if ( $orders ) {
		$in = implode( ',', array_map( 'intval', $orders ) );
		$wpdb->query( "DELETE FROM {$p}commissions WHERE order_id IN ($in)" ); // phpcs:ignore
	}
	foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_stc_test', 'meta_compare' => 'EXISTS' ) ) as $id ) {
		if ( stc_mine( get_post_meta( $id, '_stc_test', true ), $stale ) ) {
			wp_delete_post( (int) $id, true );
		}
	}
	foreach ( array( 'product_cat', 'product_brand' ) as $tax ) {
		foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'search' => 'STC Demo' ) ) as $t ) {
			if ( stc_mine( stc_tag_in( $t->name ), $stale ) ) {
				wp_delete_term( $t->term_id, $tax );
			}
		}
	}
	foreach ( $wpdb->get_results( "SELECT id, name FROM {$p}rules WHERE name LIKE 'STC Demo kural %'" ) as $r ) { // phpcs:ignore
		if ( stc_mine( stc_tag_in( $r->name ), $stale ) ) {
			$wpdb->delete( $p . 'rules', array( 'id' => (int) $r->id ) );
		}
	}
	$users = get_users( array( 'search' => 'stc-demo-*', 'search_columns' => array( 'user_email' ), 'fields' => array( 'ID', 'user_email' ) ) );
	foreach ( $users as $u ) {
		if ( ! stc_mine( stc_tag_in( $u->user_email ), $stale ) ) {
			continue;
		}
		$agent = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}agents WHERE user_id = %d", $u->ID ) ); // phpcs:ignore
		if ( $agent ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}commissions WHERE agent_id = %d", $agent ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}payouts WHERE agent_id = %d", $agent ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}links WHERE agent_id = %d", $agent ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}rules WHERE scope = 'agent' AND target_id = %d", $agent ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}agents SET parent_agent_id = NULL WHERE parent_agent_id = %d", $agent ) ); // phpcs:ignore
			$wpdb->delete( $p . 'agents', array( 'id' => (int) $agent ) );
		}
		$wpdb->delete( $p . 'links', array( 'customer_id' => (int) $u->ID ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}commissions WHERE customer_id = %d", $u->ID ) ); // phpcs:ignore
		wp_delete_user( (int) $u->ID );
	}
	unset( $_COOKIE['eru_stc_ref'] );
	wp_cache_flush();
}
