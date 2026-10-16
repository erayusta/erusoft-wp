<?php
/**
 * Marketplace Orders for WooCommerce — shared test helpers: assertions, a fake adapter registered through
 * eru_mpo_adapters that returns invented DTOs from tests/php/mpo-fixtures/*.json, demo products, a
 * catch-all pre_http_request that exits on any outbound URL, runtime identity numbers and clean-up.
 *
 * Included by tests/php/mpo-*.php; not a test on its own.
 */

if ( defined( 'MPO_TEST_HELPERS' ) ) {
	return;
}
define( 'MPO_TEST_HELPERS', 1 );

/** Prints PASS/FAIL; throws on failure so the caller's finally block can clean up before exit(1). */
function mpo_check( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
}

/** Exits when the plugin is not loaded. */
function mpo_require_plugin() {
	foreach ( array( 'Eru_MPO_Plugin', 'Eru_MPO_Settings', 'Eru_MPO_Installer', 'Eru_MPO_Adapter_Base', 'Eru_MPO_Order_DTO', 'Eru_MPO_Registry', 'Eru_MPO_Importer', 'Eru_MPO_Isolated_Save', 'Eru_MPO_Statuses', 'Eru_MPO_Lock', 'Eru_MPO_Map', 'Eru_MPO_Raw', 'Eru_MPO_Logger', 'Eru_MPO_Stock', 'Eru_MPO_Lifecycle', 'Eru_MPO_Migrator', 'Eru_MPO_Cron' ) as $c ) {
		if ( ! class_exists( $c ) ) {
			echo "FAIL: {$c} missing (plugin not active?)\n";
			exit( 1 );
		}
	}
	if ( ! interface_exists( 'Eru_MPO_Adapter' ) ) {
		echo "FAIL: Eru_MPO_Adapter missing\n";
		exit( 1 );
	}
}

/** No outbound HTTP in these suites: any request exits 1 (a WP-Cron spawn is refused quietly). */
function mpo_no_http() {
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) {
			if ( false !== strpos( (string) $url, 'wp-cron.php' ) ) {
				return new WP_Error( 'mpo_test', 'cron spawn blocked' );
			}
			echo 'FAIL unmocked HTTP ' . $url . "\n";
			exit( 1 );
		},
		10,
		3
	);
}

/** The documented test phone, assembled at runtime. */
function mpo_phone() {
	return '0' . implode( ' ', array( '555', '000', '00', '00' ) );
}

/** A random identity number with a valid checksum (generated here, never a literal). */
function mpo_fake_tckn() {
	$d = array( random_int( 1, 9 ) );
	for ( $i = 1; $i < 9; $i++ ) {
		$d[] = random_int( 0, 9 );
	}
	$odd   = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
	$even  = $d[1] + $d[3] + $d[5] + $d[7];
	$d[9]  = ( ( ( $odd * 7 - $even ) % 10 ) + 10 ) % 10;
	$d[10] = array_sum( $d ) % 10;
	return implode( '', $d );
}

/** Per-run tag so parallel or aborted runs never clean each other's rows. */
function mpo_run_tag() {
	return strtoupper( substr( md5( uniqid( '', true ) ), 0, 6 ) );
}

/**
 * Loads a fixture, replaces placeholders and builds an order DTO.
 *
 * @param string $name Fixture file.
 * @param array  $repl Placeholder => value.
 * @param array  $over Top-level overrides applied after decoding.
 * @return Eru_MPO_Order_DTO
 */
function mpo_dto( $name, array $repl = array(), array $over = array() ) {
	$repl += array( '{{PHONE}}' => mpo_phone() );
	$data  = json_decode( strtr( (string) file_get_contents( __DIR__ . '/mpo-fixtures/' . $name ), $repl ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'fixture ' . $name );
	}
	return Eru_MPO_Order_DTO::from_array( array_replace( $data, $over ) );
}

/**
 * Fake marketplace adapter. Orders and lifecycle DTOs are whatever the test puts in $orders / $lifecycle.
 */
if ( class_exists( 'Eru_MPO_Adapter_Base' ) ) { // Declared only when the plugin is loaded (mpo_require_plugin() reports it otherwise).
	class MPO_Fake_Adapter extends Eru_MPO_Adapter_Base {
		/** @var string */
		public $k;
		/** @var Eru_MPO_Order_DTO[] */
		public $orders = array();
		/** @var Eru_MPO_Order_DTO[] */
		public $lifecycle = array();
		/** @var bool */
		public $configured = true;
		/** @var int Calls of fetch_orders(). */
		public $fetches = 0;

		public function __construct( $key = 'demo' ) {
			$this->k = $key;
		}

		public function key() {
			return $this->k;
		}

		public function label() {
			return 'Demo Pazaryeri';
		}

		public function is_configured() {
			return $this->configured;
		}

		public function fetch_orders( $since, $until ) {
			++$this->fetches;
			return $this->orders;
		}

		public function fetch_lifecycle( $since, $until ) {
			return $this->lifecycle;
		}
	}
}

/** Registers a fake adapter under its key. @return MPO_Fake_Adapter */
function mpo_fake_adapter( $key = 'demo' ) {
	$fake = new MPO_Fake_Adapter( $key );
	add_filter(
		'eru_mpo_adapters',
		function ( $adapters ) use ( $fake ) {
			$adapters[ $fake->key() ] = $fake;
			return $adapters;
		}
	);
	Eru_MPO_Registry::reset();
	return $fake;
}

/** Saves working settings over the defaults (master on, observe off) and enables the demo marketplace. */
function mpo_settings( array $over = array(), $key = 'demo' ) {
	Eru_MPO_Settings::update( array_merge( Eru_MPO_Settings::defaults(), array( 'enabled' => 'yes', 'observe' => 'no' ), $over ) );
	Eru_MPO_Settings::mp_update( $key, array( 'enabled' => 'yes' ) );
}

/** Creates a simple product with managed stock. @return int */
function mpo_product( $sku, $stock = 10, $price = '149.90' ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Tişört ' . $sku );
	$p->set_sku( $sku );
	$p->set_regular_price( $price );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $stock );
	$p->set_status( 'publish' );
	return (int) $p->save();
}

/** Stock of a product, fresh from the database. */
function mpo_stock( $pid ) {
	wc_delete_product_transients( $pid );
	WC_Cache_Helper::invalidate_cache_group( 'product_' . $pid );
	clean_post_cache( $pid );
	$p = wc_get_product( $pid );
	return $p ? (int) $p->get_stock_quantity() : null;
}

/** Order ids carrying a remote reference, read from the order tables (cache-free). @return int[] */
function mpo_order_ids( $ref, $mp = 'demo' ) {
	global $wpdb;
	if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$sql = "SELECT m.order_id FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id JOIN {$wpdb->prefix}wc_orders_meta k ON k.order_id = m.order_id AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s WHERE m.meta_key = '_eru_mpo_remote_ref' AND m.meta_value = %s AND o.type = 'shop_order' ORDER BY m.order_id";
	} else {
		$sql = "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} o ON o.ID = m.post_id JOIN {$wpdb->postmeta} k ON k.post_id = m.post_id AND k.meta_key = '_eru_mpo_marketplace' AND k.meta_value = %s WHERE m.meta_key = '_eru_mpo_remote_ref' AND m.meta_value = %s AND o.post_type = 'shop_order' ORDER BY m.post_id";
	}
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $mp, $ref ) ) ); // phpcs:ignore
}

/** Map row of a reference. */
function mpo_map_row( $ref, $mp = 'demo' ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eru_mpo_orders WHERE marketplace = %s AND remote_ref = %s", $mp, $ref ) ); // phpcs:ignore
}

/** Pending actions in the plugin's group. @return int[] */
function mpo_pending_group() {
	return as_get_scheduled_actions(
		array(
			'group'    => 'eru-mpo',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		),
		'ids'
	);
}

/** Pending actions of a hook. @return int[] */
function mpo_pending( $hook ) {
	return as_get_scheduled_actions(
		array(
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		),
		'ids'
	);
}

/** Deletes orders with a given test tag (meta) or marketplace, HPOS-safe. */
function mpo_delete_orders( array $ids ) {
	foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
	}
}

/** Every order of the test-only "demo" marketplace (leftovers of an aborted run included). @return int[] */
function mpo_demo_order_ids() {
	global $wpdb;
	if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
		return array_map( 'intval', $wpdb->get_col( "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_eru_mpo_marketplace' AND meta_value = 'demo'" ) ); // phpcs:ignore
	}
	return array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_eru_mpo_marketplace' AND meta_value = 'demo'" ) ); // phpcs:ignore
}

/** Removes products whose SKU starts with the test prefix. */
function mpo_delete_products() {
	global $wpdb;
	foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE 'MPO-TEST-%'" ) as $pid ) { // phpcs:ignore
		$p = wc_get_product( (int) $pid );
		if ( $p ) {
			$p->delete( true );
		}
	}
}

/** Empties the demo marketplace's rows, locks and jobs (re-runnable after an aborted run). */
function mpo_reset() {
	global $wpdb;
	Eru_MPO_Installer::maybe_install();
	mpo_delete_orders( mpo_demo_order_ids() );
	foreach ( array( 'orders', 'raw', 'log', 'listings', 'sync_queue' ) as $t ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}eru_mpo_{$t} WHERE marketplace = 'demo'" ); // phpcs:ignore
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_mpo\\_lock\\_%'" ); // phpcs:ignore
	wp_cache_flush();
	Eru_MPO_Cron::unschedule_all();
	Eru_MPO_Settings::reset_cache();
}

/** Adds a TR 20 % rate to the standard class (removed by the caller). @return int Rate id. */
function mpo_tax_rate( $percent = 20 ) {
	return (int) WC_Tax::_insert_tax_rate(
		array(
			'tax_rate_country'  => 'TR',
			'tax_rate'          => (string) $percent,
			'tax_rate_name'     => 'Demo KDV',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 0,
			'tax_rate_order'    => 0,
			'tax_rate_class'    => '',
		)
	);
}

/** Captures outgoing mail (pre_wp_mail). @return ArrayObject */
function mpo_mail_trap() {
	$box = new ArrayObject();
	add_filter(
		'pre_wp_mail',
		function ( $pre, $atts ) use ( $box ) {
			$box[] = $atts;
			return true;
		},
		10,
		2
	);
	return $box;
}

/**
 * Signature of the hooks the isolated save touches: hook => sorted "priority:idx" list.
 *
 * @return array<string,string[]>
 */
function mpo_hook_signature() {
	global $wp_filter;
	$sig = array();
	foreach ( $wp_filter as $hook => $obj ) {
		if ( 'woocommerce_new_order' !== $hook && 'woocommerce_order_edit_status' !== $hook && 0 !== strpos( (string) $hook, 'woocommerce_order_status_' ) ) {
			continue;
		}
		$list = array();
		foreach ( $obj->callbacks as $prio => $cbs ) {
			foreach ( array_keys( $cbs ) as $idx ) {
				$list[] = $prio . ':' . $idx;
			}
		}
		sort( $list );
		$sig[ $hook ] = $list;
	}
	ksort( $sig );
	return $sig;
}

/** Lets a failed package be retried now (clears the map row's backoff). */
function mpo_clear_backoff( $ref, $mp = 'demo' ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_mpo_orders SET next_try_at = NULL WHERE marketplace = %s AND remote_ref = %s", $mp, $ref ) ); // phpcs:ignore
}

/** Log rows of a reference whose message contains a fragment. */
function mpo_log_count( $ref, $fragment, $mp = 'demo' ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = %s AND remote_ref = %s AND message LIKE %s", $mp, $ref, '%' . $wpdb->esc_like( $fragment ) . '%' ) ); // phpcs:ignore
}

/** Total sales counter of a product, fresh. */
function mpo_total_sales( $pid ) {
	clean_post_cache( $pid );
	return (int) get_post_meta( $pid, 'total_sales', true );
}
