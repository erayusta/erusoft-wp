<?php
/**
 * Marketplace Orders for WooCommerce — helpers for the Trendyol and Hepsiburada suites: an HTTP router on
 * pre_http_request that answers invented fixture bodies and exits on any URL it does not know, fixture loading
 * with runtime placeholders, invented ids, and clean-up of the suites' own orders, rows, options and jobs.
 *
 * Included by tests/php/mpo-trendyol.php and mpo-hepsiburada.php; not a test on its own.
 */

require_once __DIR__ . '/mpo-helpers.php';

if ( defined( 'MPO_ADAPTER_HELPERS' ) ) {
	return;
}
define( 'MPO_ADAPTER_HELPERS', 1 );

/**
 * Routes outbound requests to handlers. Every request is recorded (method, URL, headers, body). A request no route
 * matches ends the run: "FAIL unmocked HTTP <url>" and exit(1). A WP-Cron spawn is refused quietly.
 */
class MPO_Router {
	/** @var array[] [method, regex, callable( array $req ): array response] */
	public $routes = array();
	/** @var array[] Recorded requests. */
	public $calls = array();

	public function __construct() {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 1, 3 );
	}

	/** @param string $method Method. @param string $regex URL regex. @param callable $fn Handler. */
	public function on( $method, $regex, callable $fn ) {
		array_unshift( $this->routes, array( strtoupper( $method ), $regex, $fn ) );
	}

	public function reset() {
		$this->routes = array();
		$this->calls  = array();
	}

	public function handle( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'mpo_test', 'cron spawn blocked' );
		}
		$req           = array(
			'method'  => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
			'url'     => (string) $url,
			'headers' => (array) ( $args['headers'] ?? array() ),
			'body'    => $args['body'] ?? null,
		);
		$q             = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$req['query']  = $q;
		$this->calls[] = $req;
		foreach ( $this->routes as $r ) {
			if ( $r[0] === $req['method'] && preg_match( $r[1], (string) $url ) ) {
				return call_user_func( $r[2], $req );
			}
		}
		echo 'FAIL unmocked HTTP ' . $req['method'] . ' ' . $url . "\n";
		exit( 1 );
	}

	/** @param string $method Method. @param string $regex URL regex. @return array[] Matching recorded calls. */
	public function count( $method, $regex ) {
		return count( array_filter( $this->calls, fn( $c ) => $c['method'] === strtoupper( $method ) && preg_match( $regex, $c['url'] ) ) );
	}
}

/** @return array A pre_http_request response. */
function mpo_resp( $code, $body = '', array $headers = array() ) {
	return array(
		'headers'  => array_change_key_case( $headers, CASE_LOWER ),
		'body'     => is_array( $body ) ? wp_json_encode( $body ) : (string) $body,
		'response' => array(
			'code'    => (int) $code,
			'message' => (string) $code,
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Loads a fixture with placeholders replaced (values JSON-encoded where the placeholder is unquoted).
 *
 * @param string $name File in tests/php/mpo-fixtures/.
 * @param array  $repl Placeholder => value.
 * @return array
 */
function mpo_fixture( $name, array $repl = array() ) {
	$repl += array( '{{PHONE}}' => mpo_phone() );
	$data  = json_decode( strtr( (string) file_get_contents( __DIR__ . '/mpo-fixtures/' . $name ), array_map( 'strval', $repl ) ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'fixture ' . $name . ': ' . json_last_error_msg() );
	}
	return $data;
}

/** An invented 9-digit id (packages, lines, order numbers). */
function mpo_id() {
	return (string) random_int( 300000000, 899999999 );
}

/** An invented 10-digit tax number (not a real one: random digits). */
function mpo_tax_number() {
	return (string) random_int( 1000000000, 1999999999 );
}

/** Orders of the adapter suites (billing e-mail of the fixtures), HPOS-safe. @return int[] */
function mpo_adapter_order_ids( $email ) {
	return array_map( 'intval', wc_get_orders( array( 'billing_email' => $email, 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) + array( 99 => 'trash' ) ) ) );
}

/**
 * Removes what an adapter suite left: its orders (by fixture e-mail, map rows go with them), raw and log rows of
 * the marketplace whose reference no longer maps to an order, log rows written with the test seller/merchant ids,
 * acknowledgement and sync jobs of the marketplace, lock rows and the run state options.
 */
function mpo_adapter_cleanup( $mp, $email ) {
	global $wpdb;
	Eru_MPO_Installer::maybe_install();
	foreach ( mpo_adapter_order_ids( $email ) as $id ) {
		$o = wc_get_order( $id );
		if ( $o ) {
			$o->delete( true );
		}
	}
	$p = $wpdb->prefix . 'eru_mpo_';
	$wpdb->query( $wpdb->prepare( "DELETE r FROM {$p}raw r LEFT JOIN {$p}orders m ON m.marketplace = r.marketplace AND m.remote_ref = r.remote_ref WHERE r.marketplace = %s AND m.id IS NULL", $mp ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE l FROM {$p}log l LEFT JOIN {$p}orders m ON m.marketplace = l.marketplace AND m.remote_ref = l.remote_ref WHERE l.marketplace = %s AND ( l.remote_ref = '' OR m.id IS NULL )", $mp ) ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}orders WHERE marketplace = %s AND ( wc_order_id IS NULL OR status <> 'done' )", $mp ) ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_mpo\\_lock\\_%'" ); // phpcs:ignore
	foreach ( array( 'orders', 'lifecycle', 'endpoints' ) as $k ) {
		delete_option( 'eru_mpo_sync_' . $k . '_' . $mp );
	}
	wp_cache_flush();
	Eru_MPO_Cron::unschedule_all();
	Eru_MPO_Settings::reset_cache();
	Eru_MPO_Registry::reset();
}

/** Runs one Action Scheduler action by id (as the queue runner would). */
function mpo_run_action( $id ) {
	ActionScheduler::runner()->process_action( (int) $id, 'mpo-test' );
}

/** Scheduled date (UTC timestamp) of an action. */
function mpo_action_time( $id ) {
	$a = ActionScheduler::store()->fetch_action( (int) $id );
	$d = $a->get_schedule() ? $a->get_schedule()->get_date() : null;
	return $d ? $d->getTimestamp() : 0;
}

/** Pending actions of a hook with exactly these args. @return int[] */
function mpo_pending_args( $hook, array $args ) {
	return as_get_scheduled_actions(
		array(
			'hook'     => $hook,
			'args'     => $args,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		),
		'ids'
	);
}

/** Log rows of a marketplace written since a UTC datetime. @return object[] */
function mpo_log_rows( $mp, $since ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = %s AND created_at >= %s ORDER BY id", $mp, $since ) ); // phpcs:ignore
}

/** Every option value and log/raw text of the plugin, to search for leaked secrets. */
function mpo_all_stored_text( $mp ) {
	global $wpdb;
	$t  = implode( "\n", $wpdb->get_col( $wpdb->prepare( "SELECT message FROM {$wpdb->prefix}eru_mpo_log WHERE marketplace = %s", $mp ) ) ); // phpcs:ignore
	$t .= implode( "\n", $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}eru_mpo_raw WHERE marketplace = %s", $mp ) ) ); // phpcs:ignore
	return $t;
}
