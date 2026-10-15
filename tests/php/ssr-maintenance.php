<?php
/**
 * Server-Side Refunds — atomic queue lock, pending-only scheduling, deactivation clean-up, README personal-data section.
 *
 *   wp eval-file tests/php/ssr-maintenance.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_SSR_Queue' ) || ! function_exists( 'as_schedule_single_action' ) ) {
	echo "FAIL: Eru_SSR_Queue or Action Scheduler missing (plugin not active?)\n";
	exit( 1 );
}

$sent = 0;
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$sent ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'www.google-analytics.com', 'analytics.example.com' ), true ) ) {
			++$sent;
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 204, 'message' => 'No Content' ), 'cookies' => array(), 'filename' => null );
		}
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_ssr_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

global $wpdb;
$hooks   = array( 'eru_ssr_process', 'eru_ssr_sweep', 'eru_ssr_purge' );
$t       = Eru_SSR_Queue::table();
$opt     = Eru_SSR_Settings::OPTION;
$old_opt = get_option( $opt, null );
$pending = fn( $hook ) => as_get_scheduled_actions( array( 'hook' => $hook, 'group' => 'eru-ssr', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' );
$purge   = function () use ( $wpdb, $t, $hooks ) {
	$wpdb->query( "DELETE FROM {$t} WHERE dedupe_key LIKE 'ssrmtest:%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\_ssr\_lock%'" );
	wp_cache_flush();
	foreach ( $hooks as $h ) {
		as_unschedule_all_actions( $h, array(), 'eru-ssr' );
		foreach ( as_get_scheduled_actions( array( 'hook' => $h, 'group' => 'eru-ssr', 'status' => ActionScheduler_Store::STATUS_RUNNING, 'per_page' => 50 ), 'ids' ) as $aid ) {
			ActionScheduler::store()->mark_complete( $aid );
		}
	}
};
$purge();
$code = 1;

try {
	update_option(
		$opt,
		array(
			'ga4_enabled' => 'yes', 'ga4_measurement_id' => 'G-DEMO000000', 'ga4_api_secret' => 'demo-' . 'secret-xyz',
			'matomo_enabled' => 'no', 'ev_partial' => 'yes', 'ev_full' => 'yes', 'ev_cancelled' => 'yes', 'ev_failed' => 'yes',
			'send_user_id' => 'no', 'fallback_client_id' => 'yes', 'retention_days' => '30',
		),
		false
	);

	// 1. Atomic, owner-checked queue lock.
	$check( 'Eru_SSR_Lock exists', class_exists( 'Eru_SSR_Lock' ) );
	$check( 'the queue no longer uses an add_option() lock', false === strpos( (string) file_get_contents( ERU_SSR_PATH . 'includes/class-eru-ssr-queue.php' ), 'add_option(' ) );
	$p = array( 'eru_ssr_event_type' => 'partial', 'transaction_id' => '981', 'order_number' => '981', 'currency' => 'TRY', 'value' => 300, 'client_id' => '111.222', 'user_id' => '', 'ts' => time(), 'refund_id' => 981 );
	Eru_SSR_Queue::insert( 'ga4', 'ssrmtest:1', 981000, $p );
	$wpdb->query( "UPDATE {$t} SET next_attempt_at = '2000-01-01 00:00:00' WHERE dedupe_key = 'ssrmtest:1'" );
	$held = Eru_SSR_Lock::acquire( 'run', 600 );
	$r    = Eru_SSR_Queue::run();
	$check( 'run() sends nothing while another worker holds the lock', 0 === $sent && 0 === array_sum( $r ) );
	$check( 'run() does not release a lock it does not own', false === Eru_SSR_Lock::acquire( 'run', 600 ) );
	Eru_SSR_Lock::release( 'run', $held );
	$r = Eru_SSR_Queue::run();
	$check( 'run() sends once the lock is free', 1 === $sent && 1 === $r['sent'] );
	$check( 'run() releases its own lock', is_string( $mine = Eru_SSR_Lock::acquire( 'run', 600 ) ) );
	Eru_SSR_Lock::release( 'run', $mine );
	$wpdb->insert( $wpdb->options, array( 'option_name' => Eru_SSR_Lock::option( 'run' ), 'option_value' => ( time() - 3600 ) . '|stale', 'autoload' => 'no' ) );
	wp_cache_flush();
	$check( 'a stale lock (crashed worker) is taken over', is_string( $mine = Eru_SSR_Lock::acquire( 'run', 600 ) ) );
	Eru_SSR_Lock::release( 'run', $mine );

	// 2. schedule() queues a new process run even while one is running.
	foreach ( $hooks as $h ) {
		as_unschedule_all_actions( $h, array(), 'eru-ssr' );
	}
	$running = as_schedule_single_action( time() - 10, 'eru_ssr_process', array(), 'eru-ssr' );
	ActionScheduler::store()->log_execution( $running );
	Eru_SSR_Queue::schedule();
	$ids = $pending( 'eru_ssr_process' );
	$check( 'schedule() while eru_ssr_process is in progress queues one pending run', 1 === count( $ids ) && ! in_array( (int) $running, array_map( 'intval', $ids ), true ) );
	Eru_SSR_Queue::schedule();
	$check( 'schedule() does not duplicate pending runs', 1 === count( $pending( 'eru_ssr_process' ) ) && 1 === count( $pending( 'eru_ssr_sweep' ) ) && 1 === count( $pending( 'eru_ssr_purge' ) ) );
	ActionScheduler::store()->mark_complete( $running );

	// 3. Deactivation unschedules every pending job.
	do_action( 'deactivate_' . plugin_basename( ERU_SSR_FILE ), false );
	$left = array_sum( array_map( fn( $h ) => count( $pending( $h ) ), $hooks ) );
	$check( 'deactivation unschedules eru_ssr_process, eru_ssr_sweep and eru_ssr_purge', 0 === $left );
	$check( 'deactivation leaves a resume flag', (bool) get_option( 'eru_ssr_resume' ) );
	Eru_SSR_Queue::insert( 'ga4', 'ssrmtest:2', 982000, array_merge( $p, array( 'transaction_id' => '982', 'refund_id' => 982 ) ) );
	foreach ( $hooks as $h ) {
		as_unschedule_all_actions( $h, array(), 'eru-ssr' );
	}
	Eru_SSR_Queue::resume();
	$check( 're-activation resumes the jobs while queue rows remain', 1 === count( $pending( 'eru_ssr_sweep' ) ) && ! get_option( 'eru_ssr_resume' ) );

	// 4. Docs: personal data.
	$readme = (string) file_get_contents( ERU_SSR_PATH . 'README.md' );
	$check( 'README has a "Kişisel veri" section naming client_id and user_id', false !== strpos( $readme, '## Kişisel veri' ) && false !== strpos( $readme, 'client_id' ) && false !== strpos( $readme, 'user_id' ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	$purge();
	delete_option( 'eru_ssr_resume' );
	if ( null === $old_opt ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $old_opt, false );
	}
}
exit( $code );
