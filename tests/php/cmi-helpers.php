<?php
/**
 * Consent Manager for İYS — shared test helpers: assertions, invented credentials, a fake İYS API
 * (pre_http_request) with a catch-all that exits on any unmocked URL, settings and clean-up.
 *
 * Included by tests/php/cmi-*.php; not a test on its own.
 */

if ( defined( 'CMI_TEST_HELPERS' ) ) {
	return;
}
define( 'CMI_TEST_HELPERS', 1 );

/** Prints PASS/FAIL; throws on failure so the caller's finally block can clean up before exit(1). */
function cmi_check( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
}

/** Exits when the plugin is not loaded. */
function cmi_require_plugin() {
	foreach ( array( 'Eru_CMI_Plugin', 'Eru_CMI_Settings', 'Eru_CMI_Repository', 'Eru_CMI_History', 'Eru_CMI_Rules', 'Eru_CMI_Client', 'Eru_CMI_Queue', 'Eru_CMI_Scheduler', 'Eru_CMI_Logger', 'Eru_CMI_Pull', 'Eru_CMI_Recipient' ) as $c ) {
		if ( ! class_exists( $c ) ) {
			echo "FAIL: {$c} missing (plugin not active?)\n";
			exit( 1 );
		}
	}
}

/** Invented password, assembled so no literal looks like a credential. */
function cmi_secret() {
	return 'demo-' . 'iys-' . 'pass-' . '0000';
}

/** The documented test phone, assembled at runtime. */
function cmi_phone() {
	return '0' . implode( ' ', array( '555', '000', '00', '00' ) );
}

/** The same phone in E.164. */
function cmi_phone_e164() {
	return '+' . '90' . '555' . '000' . '0000';
}

/** @param string $name Fixture file. @param array $repl Placeholder => value. @return string */
function cmi_fixture( $name, array $repl = array() ) {
	return strtr( (string) file_get_contents( __DIR__ . '/cmi-fixtures/' . $name ), $repl );
}

/** Saves working sandbox settings over the defaults. @param array $over Overrides. */
function cmi_settings( array $over = array() ) {
	Eru_CMI_Settings::update(
		array_merge(
			Eru_CMI_Settings::defaults(),
			array(
				'enabled'            => 'yes',
				'environment'        => 'sandbox',
				'sandbox_username'   => 'demo-user',
				'sandbox_password'   => cmi_secret(),
				'sandbox_iys_code'   => '000000',
				'sandbox_brand_code' => '000000',
				'live_username'      => 'demo-user',
				'live_password'      => cmi_secret(),
				'live_iys_code'      => '000000',
				'live_brand_code'    => '000000',
				'batch_size'         => '500',
			),
			$over
		)
	);
}

/** Pending actions of a hook. @param string $hook Hook. @return int[] */
function cmi_pending( $hook ) {
	return as_get_scheduled_actions(
		array(
			'hook'     => $hook,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		),
		'ids'
	);
}

/** Pending actions in the plugin's group. @return int[] */
function cmi_pending_group() {
	return as_get_scheduled_actions(
		array(
			'group'    => 'eru-cmi',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 50,
		),
		'ids'
	);
}

/** Empties the plugin's tables, caches, locks and jobs (re-runnable after an aborted run). */
function cmi_reset() {
	global $wpdb;
	Eru_CMI_Installer::maybe_install();
	foreach ( array( 'consents', 'history', 'queue', 'log' ) as $t ) {
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'eru_cmi_' . $t ); // phpcs:ignore
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_eru\\_cmi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_eru\\_cmi\\_%' OR option_name LIKE 'eru\\_cmi\\_rt\\_%' OR option_name LIKE 'eru\\_cmi\\_lock\\_%' OR option_name LIKE 'eru\\_cmi\\_pull\\_cursor%' OR option_name LIKE 'eru\\_cmi\\_reconcile\\_%' OR option_name IN ('eru_cmi_pull_last','eru_cmi_table_job')" ); // phpcs:ignore
	wp_cache_flush();
	Eru_CMI_Scheduler::unschedule_all();
	Eru_CMI_Scheduler::reset_memo();
}

/**
 * Fake İYS API. Every request is recorded; any other host or path exits 1 ("FAIL unmocked HTTP").
 */
class CMI_Fake_IYS {

	/** @var array[] Recorded requests: method, path, query, body (decoded), auth. */
	public $reqs = array();
	public $password_grants = 0;
	public $refresh_grants  = 0;
	public $batch_mode      = 'ok';   // ok | 503 | timeout | timeout-after | index-error | index-nomatch | poison
	/** @var string A recipient containing this makes the whole batch fail with an unindexed 400 (mode poison). */
	public $poison          = 'bad';
	/** @var array<string,array> Records İYS holds, keyed "recipientType|type|recipient". */
	public $store           = array();
	public $index_error     = 1;
	public $status_mode     = 'ok';   // ok | pending
	public $pull_mode       = 'ok';   // ok | 500 | h509
	public $lookup_mode     = 'ok';   // ok | 429 | 500 (POST /consents/status)
	public $pull_cursor     = 'demo-cursor-1';
	public $refresh_fails   = false;
	/** @var string ok | 400 (the password grant is refused with OAuth2 400 invalid_grant, RFC 6749 §5.2). */
	public $password_mode   = 'ok';
	/** @var string[] Access tokens the server now rejects with 401. */
	public $expired = array();
	/** @var array<string,array<int,string>> requestId => [index => error code]. */
	public $failures = array();
	/** @var array<string,int> requestId => record count. */
	public $batches = array();
	private $seq    = 0;
	private $tokens = 0;

	/** @return array WP HTTP response. */
	private function resp( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'response' => array( 'code' => $code, 'message' => 'X' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** Paths of recorded requests matching a fragment. @return array[] */
	public function find( $fragment ) {
		return array_values( array_filter( $this->reqs, fn( $r ) => false !== strpos( $r['path'], $fragment ) ) );
	}

	/** pre_http_request callback. */
	public function route( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'api.sandbox.iys.org.tr', 'api.iys.org.tr' ), true ) ) {
			echo 'FAIL unmocked HTTP ' . $url . "\n";
			exit( 1 );
		}
		$method = strtoupper( $args['method'] ?? 'GET' );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$body = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
		$auth = (string) ( $args['headers']['Authorization'] ?? '' );
		$this->reqs[] = compact( 'method', 'path', 'query', 'body', 'auth', 'url' );

		if ( 'POST' === $method && '/oauth2/token' === $path ) {
			$grant = $body['grant_type'] ?? '';
			if ( 'refresh_token' === $grant ) {
				++$this->refresh_grants;
				if ( $this->refresh_fails ) {
					return $this->resp( 400, array( 'error' => 'invalid_grant' ) );
				}
			} elseif ( 'password' === $grant ) {
				++$this->password_grants;
				if ( '400' === $this->password_mode ) {
					return $this->resp( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Demo: oturum açılamadı.' ) );
				}
				if ( cmi_secret() !== ( $body['password'] ?? '' ) ) {
					return $this->resp( 401, array( 'error' => 'invalid_grant' ) );
				}
			} else {
				echo "FAIL unmocked HTTP grant {$grant}\n";
				exit( 1 );
			}
			++$this->tokens;
			return $this->resp( 200, cmi_fixture( 'token.json', array( '{{A}}' => 'demo-acc-' . $this->tokens, '{{R}}' => 'demo-ref-' . $this->tokens ) ) );
		}

		if ( 0 !== strpos( $path, '/sps/000000/brands/000000/consents' ) ) {
			echo 'FAIL unmocked HTTP ' . $method . ' ' . $url . "\n";
			exit( 1 );
		}
		$token = preg_replace( '/^Bearer\s+/', '', $auth );
		if ( '' === $token || in_array( $token, $this->expired, true ) ) {
			return $this->resp( 401, array( 'error' => 'invalid_token' ) );
		}
		$rest = substr( $path, strlen( '/sps/000000/brands/000000/consents' ) );

		if ( 'POST' === $method && '/request' === $rest ) {
			if ( 'timeout' === $this->batch_mode ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
			if ( '403' === $this->batch_mode ) {
				return $this->resp( 403, array( 'errors' => array( array( 'code' => 'H403', 'message' => 'Demo: yetkisiz marka.' ) ) ) );
			}
			if ( '503' === $this->batch_mode ) {
				return $this->resp( 503, cmi_fixture( 'error-generic.json' ) );
			}
			if ( 'index-error' === $this->batch_mode ) {
				return $this->resp( 422, cmi_fixture( 'batch-index-error.json', array( '{{I}}' => (string) $this->index_error ) ) );
			}
			if ( 'index-nomatch' === $this->batch_mode ) {
				return $this->resp( 422, cmi_fixture( 'batch-index-error.json', array( '{{I}}' => '999' ) ) );
			}
			if ( 'poison' === $this->batch_mode ) {
				foreach ( (array) $body as $rec ) {
					if ( false !== strpos( (string) ( $rec['recipient'] ?? '' ), $this->poison ) ) {
						return $this->resp( 400, array( 'errors' => array( array( 'code' => 'H120', 'message' => 'Demo: alıcı biçimi geçersiz.' ) ) ) );
					}
				}
			}
			++$this->seq;
			$id                   = 'demo-req-' . $this->seq;
			$this->batches[ $id ] = is_array( $body ) ? count( $body ) : 0;
			foreach ( (array) $body as $rec ) {
				$this->store[ strtolower( $rec['recipientType'] . '|' . $rec['type'] . '|' . $rec['recipient'] ) ] = $rec;
			}
			if ( 'timeout-after' === $this->batch_mode ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ); // Processed, answer lost.
			}
			return $this->resp( 200, cmi_fixture( 'batch-accepted.json', array( '{{REQ}}' => $id ) ) );
		}

		if ( 'GET' === $method && 0 === strpos( $rest, '/request/' ) ) {
			$id = rawurldecode( substr( $rest, strlen( '/request/' ) ) );
			if ( 'pending' === $this->status_mode ) {
				return $this->resp( 202, array() );
			}
			if ( ! isset( $this->batches[ $id ] ) ) {
				return $this->resp( 404, array( 'errors' => array( array( 'code' => 'H404', 'message' => 'Demo: bulunamadı' ) ) ) );
			}
			$items = array();
			for ( $i = 0; $i < $this->batches[ $id ]; $i++ ) {
				$item = array(
					'index'         => $i,
					'requestId'     => $id,
					'subRequestId'  => $id . '-' . $i,
					'transactionId' => 'demo-tx-' . $i,
					'status'        => 'success',
				);
				if ( isset( $this->failures[ $id ][ $i ] ) ) {
					$item['status'] = 'failure';
					$item['error']  = array( array( 'code' => $this->failures[ $id ][ $i ], 'message' => 'Demo: reddedildi.' ) );
				}
				$items[] = $item;
			}
			return $this->resp( 200, $items );
		}

		if ( 'POST' === $method && '/status' === $rest ) {
			if ( '429' === $this->lookup_mode ) {
				return $this->resp( 429, array( 'errors' => array( array( 'code' => 'H429', 'message' => 'Demo: çok fazla istek.' ) ) ) );
			}
			if ( '500' === $this->lookup_mode ) {
				return $this->resp( 500, cmi_fixture( 'error-generic.json' ) );
			}
			$k = strtolower( ( $body['recipientType'] ?? '' ) . '|' . ( $body['type'] ?? '' ) . '|' . ( $body['recipient'] ?? '' ) );
			if ( ! isset( $this->store[ $k ] ) ) {
				return $this->resp( 404, array( 'errors' => array( array( 'code' => 'H404', 'message' => 'Demo: izin bulunamadı.' ) ) ) );
			}
			return $this->resp( 200, $this->store[ $k ] + array( 'creationDate' => $this->store[ $k ]['consentDate'] ) );
		}

		if ( 'GET' === $method && '/changes' === $rest ) {
			if ( '500' === $this->pull_mode ) {
				return $this->resp( 500, cmi_fixture( 'error-generic.json' ) );
			}
			if ( 'h509' === $this->pull_mode ) {
				return $this->resp( 422, array( 'errors' => array( array( 'code' => 'H509', 'message' => 'Demo: imleç geçersiz.' ) ) ) );
			}
			$date = ( new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Istanbul' ) ) )->format( 'Y-m-d H:i:s' );
			return $this->resp( 200, cmi_fixture( 'changes-page.json', array( '{{C}}' => $this->pull_cursor, '{{D}}' => $date ) ) );
		}

		echo 'FAIL unmocked HTTP ' . $method . ' ' . $url . "\n";
		exit( 1 );
	}
}

/** Installs the fake İYS (also the catch-all for every other URL). @return CMI_Fake_IYS */
function cmi_fake_iys() {
	$fake = new CMI_Fake_IYS();
	add_filter( 'pre_http_request', array( $fake, 'route' ), 10, 3 );
	return $fake;
}

/** Queue rows. @param string $where SQL (trusted, test only). @return array */
function cmi_queue_rows( $where = '1=1' ) {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . 'eru_cmi_queue WHERE ' . $where . ' ORDER BY id ASC' ); // phpcs:ignore
}

/** Count of rows in a plugin table. */
function cmi_count( $table, $where = '1=1' ) {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'eru_cmi_' . $table . ' WHERE ' . $where ); // phpcs:ignore
}

/** Moves every queued row's next try into the past. */
function cmi_make_due() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . $wpdb->prefix . "eru_cmi_queue SET next_try_at = %s WHERE status = 'queued'", gmdate( 'Y-m-d H:i:s', time() - 5 ) ) ); // phpcs:ignore
}

/** One REST call to eru-cmi/v1/consent. @return array{0:int,1:mixed,2:string} status, data, JSON body. */
function cmi_rest( $method, array $params = array() ) {
	$req = new WP_REST_Request( $method, '/eru-cmi/v1/consent' );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	return array( (int) $res->get_status(), $res->get_data(), (string) wp_json_encode( $res->get_data() ) );
}

/** Creates (or re-creates) a demo customer. @return int User id. */
function cmi_user( $email, $name = 'Demo Müşteri' ) {
	$old = get_user_by( 'email', $email );
	if ( $old ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $old->ID );
	}
	$id = wp_insert_user(
		array(
			'user_login'   => 'cmi_' . substr( md5( $email ), 0, 10 ),
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 20 ),
			'display_name' => $name,
			'role'         => 'customer',
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'user: ' . $id->get_error_message() );
	}
	return (int) $id;
}

/** Deletes demo users and orders of the given addresses (leftovers of an aborted run). */
function cmi_cleanup_people( array $emails ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $emails as $e ) {
		$u = get_user_by( 'email', $e );
		if ( $u ) {
			wp_delete_user( $u->ID );
		}
		foreach ( wc_get_orders( array( 'billing_email' => $e, 'limit' => 50, 'return' => 'ids', 'status' => 'any' ) ) as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$o->delete( true );
			}
		}
	}
}

/** Captures outgoing mail (pre_wp_mail). @return ArrayObject Captured wp_mail() argument arrays. */
function cmi_mail_trap() {
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

/** Rotates the rate-limit fingerprint (through the plugin's filter) and clears the counters. */
function cmi_fingerprint( $name ) {
	$GLOBALS['cmi_fp'] = 'cmi-test-' . $name;
	if ( ! has_filter( 'eru_cmi_fingerprint', 'cmi_fp_filter' ) ) {
		add_filter( 'eru_cmi_fingerprint', 'cmi_fp_filter' );
	}
	Eru_CMI_Rate_Limit::reset();
}

/** @return string Current test fingerprint. */
function cmi_fp_filter() {
	return (string) ( $GLOBALS['cmi_fp'] ?? 'cmi-test' );
}

/** Saves capture settings (Toplama tab keys) over the current settings. */
function cmi_collect( array $over = array() ) {
	Eru_CMI_Settings::update(
		array_merge(
			array(
				'channels'         => 'EPOSTA,MESAJ',
				'checkout_box'     => 'yes',
				'checkout_label'   => 'Demo kampanya iletileri almak istiyorum.',
				'policy_page'      => '0',
				'register_box'     => 'yes',
				'account_tab'      => 'yes',
				'account_endpoint' => 'iletisim-tercihleri',
				'token_days'       => '30',
			),
			$over
		)
	);
}
