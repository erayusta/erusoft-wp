<?php
/**
 * VakıfBank Vinov Integration — shared test helpers: assertions, runtime TCKNs with a valid checksum,
 * invented credentials, a fake bank (pre_http_request) with a catch-all that exits on any unmocked URL,
 * a log capture, invented products, a classic checkout and a clean-up of this run's leftovers.
 *
 * Included by tests/php/vnv-*.php; not a test on its own. The plugin must be active.
 */

if ( defined( 'VNV_TEST_HELPERS' ) ) {
	return;
}
define( 'VNV_TEST_HELPERS', 1 );

/** Per-run tag carried by every fixture this process creates. */
define( 'VNV_RUN', 'vnv' . substr( md5( uniqid( '', true ) ), 0, 8 ) );

/**
 * Prints PASS/FAIL; a failure throws so the caller's finally block can clean up before exit(1). With the
 * environment variable VNV_SOFT=1 the run continues after a failure (to list every red check at once) and
 * still exits 1 at the end.
 */
function vnv_check( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		if ( '1' === getenv( 'VNV_SOFT' ) ) {
			$GLOBALS['vnv_soft_failed'] = true;
			return;
		}
		throw new RuntimeException( $label );
	}
}

/** Exits when the plugin is not loaded. */
function vnv_require_plugin() {
	foreach ( array( 'Eru_VNV_Settings', 'Eru_VNV_Identity', 'Eru_VNV_Logger', 'Eru_VNV_API_Client', 'Eru_VNV_Installments', 'Eru_VNV_Session', 'Eru_VNV_Rate_Limit', 'Eru_VNV_OTP_Controller', 'Eru_VNV_Payment', 'Eru_VNV_Gateway' ) as $c ) {
		if ( ! class_exists( $c ) ) {
			echo "FAIL: {$c} missing (plugin not active?)\n";
			exit( 1 );
		}
	}
}

/** A random TCKN with a valid checksum, generated at runtime (never a literal in any file). */
function vnv_fake_tckn() {
	$d    = array( random_int( 1, 9 ) );
	for ( $i = 1; $i < 9; $i++ ) {
		$d[] = random_int( 0, 9 );
	}
	$odd  = $d[0] + $d[2] + $d[4] + $d[6] + $d[8];
	$even = $d[1] + $d[3] + $d[5] + $d[7];
	$d[]  = ( ( $odd * 7 - $even ) % 10 + 10 ) % 10;
	$d[]  = array_sum( $d ) % 10;
	return implode( '', $d );
}

/** The documented test phone, assembled at runtime. */
function vnv_phone() {
	return '0' . implode( ' ', array( '555', '000', '00', '00' ) );
}

/** Invented client secret per environment, assembled so no literal looks like a credential. */
function vnv_secret( $env ) {
	return 'demo-' . $env . '-' . 'sec' . 'ret-' . '0000';
}

/** Invented client id per environment. */
function vnv_client( $env ) {
	return 'demo-' . $env . '-client-' . '0001';
}

/** @param string $name Fixture file. @param array $repl Placeholder => value. @return string */
function vnv_fixture( $name, array $repl = array() ) {
	return strtr( (string) file_get_contents( __DIR__ . '/vnv-fixtures/' . $name ), $repl );
}

/** Saves a working, enabled test-mode configuration over the defaults. @param array $over Overrides. */
function vnv_settings( array $over = array() ) {
	Eru_VNV_Settings::update(
		array_merge(
			Eru_VNV_Settings::defaults(),
			array(
				'enabled'              => 'yes',
				'testmode'             => 'yes',
				'test_client_id'       => vnv_client( 'test' ),
				'test_client_secret'   => vnv_secret( 'test' ),
				'live_client_id'       => vnv_client( 'live' ),
				'live_client_secret'   => vnv_secret( 'live' ),
				'test_base_url'        => VNV_Fake_Bank::BASE['test'],
				'live_base_url'        => VNV_Fake_Bank::BASE['live'],
				'workplace_id'         => '1',
				'consent_id'           => 'demo-consent-0001',
				'payment_purpose'      => '3',
				'otp_transaction_code' => 'DEMOOTP',
				'max_installments'     => '6',
				'installment_rates'    => array(
					2 => array( 'enabled' => 'yes', 'rate' => '2' ),
					3 => array( 'enabled' => 'yes', 'rate' => '4' ),
					4 => array( 'enabled' => 'no', 'rate' => '5' ),
					6 => array( 'enabled' => 'yes', 'rate' => '6.5' ),
					12 => array( 'enabled' => 'yes', 'rate' => '12' ),
				),
				'log_level'            => 'debug',
			),
			$over
		)
	);
}

/**
 * Fake bank. Every request is recorded; any other host or path exits 1 ("FAIL unmocked HTTP").
 * Per-path queues override the default answers once per queued item.
 */
class VNV_Fake_Bank {

	/** Invented API hosts per environment (the plugin ships no default host). */
	const BASE = array(
		'test' => 'https://vinov-api.example.test',
		'live' => 'https://vinov-api-live.example.test',
	);

	/** @var string[] Hosts of the recorded requests, in order. */
	public $hosts = array();

	/** @var array[] Recorded API requests: path, body (decoded), auth, url. */
	public $reqs = array();

	/** @var array[] Token grants: decoded form body. */
	public $grants = array();

	/** @var array<string, array> Path => queued answers (callable|array{0:int,1:string}|WP_Error). */
	public $queue = array();

	/** @var int Next OTP transaction id. */
	public $otp_txn = 880000;

	/** @var int Next instruction id. */
	public $instruction = 700000;

	/** @var int Tokens minted. */
	public $minted = 0;

	/** @var array<int,int> Instruction id => InstructionStatusCode answered for listInstruction by Id. */
	public $statuses = array();

	/** @var array[] Items answered for listInstruction without an Id filter (date-window lookups). */
	public $window = array();

	/** @var string Identity number echoed in movement/detail answers (runtime value, never a literal). */
	public $identity = '';

	/** Queues one answer for a path. */
	public function push( $path, $answer ) {
		$this->queue[ $path ][] = $answer;
	}

	/** @return int Requests to a path. */
	public function count( $path ) {
		return count( $this->calls( $path ) );
	}

	/** @return array[] Requests to a path. */
	public function calls( $path ) {
		return array_values( array_filter( $this->reqs, fn( $r ) => $r['path'] === $path ) );
	}

	/** @return int Every API request plus every grant. */
	public function total() {
		return count( $this->reqs ) + count( $this->grants );
	}

	/** Forgets the recorded requests and queued answers. */
	public function reset() {
		$this->reqs   = array();
		$this->grants = array();
		$this->queue  = array();
		$this->hosts  = array();
	}

	/** @return int[] Ids asked for by listInstruction calls, in order. */
	public function listed_ids() {
		return array_map( fn( $r ) => (int) ( $r['body']['Id'] ?? 0 ), $this->calls( '/vinov/listInstruction' ) );
	}

	/** @return array A WP HTTP response. */
	public static function resp( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 'X',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** pre_http_request handler. */
	public function handle( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'vnv_test', 'loopback cron blocked in tests' ); // Not an outbound call.
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! in_array( 'https://' . $host, self::BASE, true ) || ! in_array( $path, array( '/auth/oauth/v2/token', '/sendOTP', '/verifyOTP', '/vinov/checkDebtor', '/vinov/createInstruction', '/vinov/listInstruction', '/vinov/cancelInstruction', '/vinov/listInstructionDetail', '/vinov/listCampaignReceiver', '/vinov/receiverTransactionList', '/vinov/debtorTransactionList' ), true ) ) {
			echo 'FAIL unmocked HTTP ' . $url . "\n";
			exit( 1 );
		}
		if ( false !== strpos( (string) wp_parse_url( $url, PHP_URL_QUERY ), 'secret' ) ) {
			echo 'FAIL credential in query string ' . $url . "\n";
			exit( 1 );
		}
		$this->hosts[] = $host;
		if ( '/auth/oauth/v2/token' === $path ) {
			$body           = is_array( $args['body'] ) ? $args['body'] : wp_parse_args( (string) $args['body'] );
			$this->grants[] = $body;
			if ( ! empty( $this->queue[ $path ] ) ) {
				return $this->answer( array_shift( $this->queue[ $path ] ), $body, $args );
			}
			++$this->minted;
			return self::resp( 200, vnv_fixture( 'token.json', array( '{{T}}' => 'demo-tok-' . $this->minted . '-' . (string) ( $body['scope'] ?? '' ), '{{SCOPE}}' => (string) ( $body['scope'] ?? '' ) ) ) );
		}
		$body         = json_decode( (string) $args['body'], true );
		$this->reqs[] = array(
			'path' => $path,
			'body' => is_array( $body ) ? $body : array(),
			'auth' => (string) ( $args['headers']['Authorization'] ?? '' ),
			'url'  => $url,
		);
		if ( ! empty( $this->queue[ $path ] ) ) {
			return $this->answer( array_shift( $this->queue[ $path ] ), $body, $args );
		}
		switch ( $path ) {
			case '/sendOTP':
				++$this->otp_txn;
				return self::resp( 200, vnv_fixture( 'send-otp-ok.json', array( '{{OTP_TXN}}' => (string) $this->otp_txn ) ) );
			case '/verifyOTP':
				return self::resp( 200, vnv_fixture( 'verify-otp-ok.json' ) );
			case '/vinov/checkDebtor':
				return self::resp( 200, vnv_fixture( 'check-debtor-ok.json' ) );
			case '/vinov/createInstruction':
				++$this->instruction;
				return self::resp( 200, vnv_fixture( 'create-instruction-ok.json', array( '{{ID}}' => (string) $this->instruction ) ) );
			case '/vinov/listInstruction':
				$id = (int) ( $body['Id'] ?? 0 );
				if ( $id > 0 && isset( $this->statuses[ $id ] ) ) {
					return self::resp( 200, self::list_body( array( array( 'Id' => $id, 'InvoiceNumber' => 'DEMO-' . $id, 'InstructionDescription' => 'Demo talimat', 'InstructionAmount' => 10.0, 'InstructionStatusCode' => $this->statuses[ $id ], 'DebtorName' => 'Demo Borçlu Kişi', 'IdentityTaxNumber' => $this->identity ) ) ) );
				}
				if ( 0 === $id && $this->window ) {
					return self::resp( 200, self::list_body( $this->window ) );
				}
				return self::resp( 200, vnv_fixture( 'list-instruction-empty.json' ) );
			case '/vinov/cancelInstruction':
				return self::resp( 200, vnv_fixture( 'cancel-instruction-ok.json' ) );
			case '/vinov/listInstructionDetail':
				return self::resp( 200, vnv_fixture( 'instruction-detail.json', array( '{{ID}}' => (string) (int) ( $body['Id'] ?? 0 ), '{{TCKN}}' => $this->identity ) ) );
			case '/vinov/listCampaignReceiver':
				return self::resp( 200, vnv_fixture( 'campaigns.json' ) );
			case '/vinov/receiverTransactionList':
			case '/vinov/debtorTransactionList':
				return self::resp( 200, vnv_fixture( 'transactions.json', array( '{{TCKN}}' => $this->identity, '{{PHONE}}' => preg_replace( '/\D+/', '', vnv_phone() ) ) ) );
		}
		return self::resp( 200, vnv_fixture( 'error-generic.json' ) );
	}

	/** @param array[] $items Items. @return string A listInstruction success body. */
	public static function list_body( array $items ) {
		return (string) wp_json_encode(
			array(
				'Header' => array(
					'StatusCode'        => 'APIGW000000',
					'StatusDescription' => 'Başarılı',
					'ObjectID'          => 'demo-030',
				),
				'Data'   => array( 'WorkPlaceInstruction' => $items ),
			)
		);
	}

	/** Resolves a queued answer. */
	private function answer( $a, $body, $args ) {
		if ( is_callable( $a ) ) {
			return $a( $body, $args );
		}
		if ( $a instanceof WP_Error ) {
			return $a;
		}
		return self::resp( (int) $a[0], $a[1] );
	}
}

/** Installs the fake bank (and the mail guard). @return VNV_Fake_Bank */
function vnv_install_bank() {
	$bank = new VNV_Fake_Bank();
	add_filter( 'pre_http_request', array( $bank, 'handle' ), 10, 3 );
	add_filter( 'pre_wp_mail', '__return_true' );
	foreach ( array( 'new_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order', 'cancelled_order', 'failed_order', 'customer_failed_order' ) as $id ) {
		add_filter( 'woocommerce_email_enabled_' . $id, '__return_false' );
	}
	$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	return $bank;
}

/** Captures every log message the plugin writes through the WooCommerce logger. @return ArrayObject */
function vnv_capture_logs() {
	$logs = new ArrayObject();
	add_filter(
		'woocommerce_logger_log_message',
		function ( $message, $level, $context ) use ( $logs ) {
			if ( 'eru-vnv' === ( $context['source'] ?? '' ) ) {
				$logs[] = $level . ' ' . $message . ' ' . wp_json_encode( $context );
			}
			return $message;
		},
		PHP_INT_MAX,
		3
	);
	return $logs;
}

/** Fingerprint override for the rate limiter (127.0.0.1 stays the only IP). */
function vnv_fingerprint( $fp ) {
	remove_all_filters( 'eru_vnv_fingerprint' );
	if ( null !== $fp ) {
		add_filter( 'eru_vnv_fingerprint', fn() => $fp );
	}
}

/** Clears session state, counters, cached tokens, locks and notices (re-runnable after an aborted run). */
function vnv_reset() {
	global $wpdb;
	if ( WC()->session ) {
		WC()->session->set( Eru_VNV_Session::KEY, null );
		WC()->session->set( 'eru_vnv_installments', null );
		WC()->session->set( 'order_awaiting_payment', 0 );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eru\\_vnv\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eru\\_vnv\\_%' OR option_name LIKE 'eru\\_vnv\\_lock\\_%' OR option_name LIKE 'eru\\_vnv\\_vused\\_%' OR option_name = 'eru_vnv_rl'" ); // phpcs:ignore
	wp_cache_flush();
	Eru_VNV_Rate_Limit::reset();
	wc_clear_notices();
	vnv_fingerprint( 'fp-' . VNV_RUN . '-' . wp_generate_password( 6, false ) );
}

/** An invented virtual, untaxed product of this run. @return int */
function vnv_product( $price, $name = 'Demo Ürün' ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name . ' ' . VNV_RUN );
	$p->set_regular_price( (string) $price );
	$p->set_virtual( true );
	$p->set_tax_status( 'none' );
	$p->set_status( 'publish' );
	$p->update_meta_data( '_vnv_test', VNV_RUN );
	return $p->save();
}

/** Fills the cart with the given products (id => qty) and selects the gateway. */
function vnv_cart( array $items, $installments = 1 ) {
	WC()->cart->empty_cart();
	foreach ( $items as $id => $qty ) {
		WC()->cart->add_to_cart( $id, $qty );
	}
	WC()->session->set( 'chosen_payment_method', 'eru_vnv' );
	WC()->session->set( 'eru_vnv_installments', $installments );
	WC()->cart->calculate_totals();
}

/** Classic checkout of the current cart with the gateway. @return WC_Order */
function vnv_checkout_order() {
	WC()->session->set( 'order_awaiting_payment', 0 );
	WC()->cart->calculate_totals();
	$id = WC()->checkout()->create_order(
		array(
			'billing_email'      => 'vnv-demo-' . VNV_RUN . '@example.com',
			'billing_first_name' => 'Demo',
			'billing_last_name'  => 'Alıcı',
			'billing_country'    => 'TR',
			'billing_state'      => 'TR06',
			'billing_city'       => 'Çankaya',
			'billing_postcode'   => '06420',
			'billing_address_1'  => 'Demo Sokak 1',
			'billing_phone'      => vnv_phone(),
			'payment_method'     => 'eru_vnv',
			'order_comments'     => '',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'create_order: ' . $id->get_error_message() );
	}
	$o = wc_get_order( $id );
	$o->update_meta_data( '_vnv_test', VNV_RUN );
	$o->save();
	return wc_get_order( $id );
}

/** Sends a code and verifies it against the fake bank for the current cart. @return array verify() result */
function vnv_verify( $tckn, $installments = 1, $phone = null ) {
	$s = Eru_VNV_OTP_Controller::send(
		array(
			'tckn'  => $tckn,
			'phone' => $phone ?? vnv_phone(),
		)
	);
	if ( empty( $s['ok'] ) ) {
		return $s;
	}
	return Eru_VNV_OTP_Controller::verify(
		array(
			'tckn'         => $tckn,
			'otp'          => '123456',
			'installments' => (string) $installments,
		)
	);
}

/** Posts the checkout fields for process_payment. */
function vnv_post( $tckn, $installments = 1, array $extra = array() ) {
	$_POST = array_merge(
		array(
			'payment_method'       => 'eru_vnv',
			'eru_vnv_tckn'         => $tckn,
			'eru_vnv_phone'        => vnv_phone(),
			'eru_vnv_installments' => (string) $installments,
		),
		$extra
	);
}

/** @return string[] Error notices (and clears them). */
function vnv_errors() {
	$n = wc_get_notices( 'error' );
	wc_clear_notices();
	return array_map( fn( $x ) => is_array( $x ) ? (string) $x['notice'] : (string) $x, $n );
}

/** Every order note text of an order. */
function vnv_notes( $order_id ) {
	return implode( "\n", array_map( fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $order_id ) ) ) );
}

/** Every meta value of an order, serialised. */
function vnv_meta_dump( $order_id ) {
	$o = wc_get_order( $order_id );
	return $o ? wp_json_encode( array_map( fn( $m ) => $m->get_data(), $o->get_meta_data() ) ) : '';
}

/** Deletes the plugin's WooCommerce log files, so a run's log checks see only this run. */
function vnv_clear_log_files() {
	$dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '';
	foreach ( (array) glob( trailingslashit( $dir ) . 'eru-vnv-*.log' ) as $f ) {
		wp_delete_file( $f );
	}
}

/** The text of the plugin's WooCommerce log files of today. */
function vnv_log_files_text() {
	$dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : '';
	$out = '';
	foreach ( (array) glob( trailingslashit( $dir ) . 'eru-vnv-*.log' ) as $f ) {
		$out .= (string) file_get_contents( $f );
	}
	return $out;
}

/**
 * Removes this run's fixtures ($stale = false) or leftovers of aborted runs ($stale = true): orders and
 * products tagged `_vnv_test`.
 */
function vnv_purge( $stale = false ) {
	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
	global $wpdb;
	$mine = fn( $tag ) => $stale ? ( '' !== (string) $tag && VNV_RUN !== (string) $tag ) : VNV_RUN === (string) $tag;
	foreach ( wc_get_orders( array( 'limit' => -1, 'meta_key' => '_vnv_test', 'meta_compare' => 'EXISTS', 'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ) ) ) as $o ) {
		if ( $mine( $o->get_meta( '_vnv_test' ) ) ) {
			// Test fixtures: drop the instruction row first, so deleting the order queues no bank cancel.
			if ( class_exists( 'Eru_VNV_Instructions' ) ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_VNV_Instructions::table() . ' WHERE order_id = %d', $o->get_id() ) ); // phpcs:ignore
			}
			$o->delete( true );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'eru_vnv_bank_cancel', 'eru_vnv_sync', 'eru_vnv_daily', 'eru_vnv_conn_test' ) as $hook ) {
			as_unschedule_all_actions( $hook, null, 'eru-vnv' );
		}
	}
	foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_vnv_test', 'meta_compare' => 'EXISTS' ) ) as $id ) {
		if ( $mine( get_post_meta( $id, '_vnv_test', true ) ) ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
