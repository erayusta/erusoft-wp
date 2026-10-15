<?php
/**
 * Product Push for Trendyol — a queued chunk that finds the push lock held is re-queued once: no duplicate pending
 * chunk, while a running identical chunk does not block the re-queue (pending-only check).
 *
 *   wp eval-file tests/php/ppt-queue.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_PPT_Pusher' ) || ! function_exists( 'as_schedule_single_action' ) ) {
	echo "FAIL: Eru_PPT_Pusher missing (plugin not active?)\n";
	exit( 1 );
}
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_ppt_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

global $wpdb;
$old   = get_option( Eru_PPT_Settings::OPTION, null );
$hooks = array( 'eru_ppt_push', 'eru_ppt_poll', 'eru_ppt_refresh' );
$args  = array( 'ids' => array( 990001, 990002 ) );
$clean = function () use ( $hooks ) {
	foreach ( $hooks as $h ) {
		as_unschedule_all_actions( $h, null, 'eru-ppt' );
		foreach ( as_get_scheduled_actions( array( 'hook' => $h, 'group' => 'eru-ppt', 'status' => ActionScheduler_Store::STATUS_RUNNING, 'per_page' => 50 ), 'ids' ) as $aid ) {
			ActionScheduler::store()->mark_complete( $aid );
		}
	}
	Eru_PPT_Lock::release( 'push' );
};
$pending = fn() => as_get_scheduled_actions( array( 'hook' => 'eru_ppt_push', 'args' => $args, 'group' => 'eru-ppt', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' );
$clean();
$code = 1;

try {
	Eru_PPT_Settings::update( array( 'enabled' => 'yes', 'seller_id' => '100001', 'api_key' => 'demo-' . 'key-q', 'api_secret' => 'demo-' . 'secret-q' ) );
	$check( 'integration enabled for the test', Eru_PPT_Settings::is_enabled() );
	$check( 'push lock held by another worker', Eru_PPT_Lock::acquire( 'push', 15 * MINUTE_IN_SECONDS ) );

	Eru_PPT_Pusher::run_queued( $args['ids'] );
	$check( 'a locked chunk is re-queued once', 1 === count( $pending() ) );
	Eru_PPT_Pusher::run_queued( $args['ids'] );
	Eru_PPT_Pusher::run_queued( $args['ids'] );
	$check( 'repeated locked runs do not stack duplicate pending chunks', 1 === count( $pending() ) );

	as_unschedule_all_actions( 'eru_ppt_push', null, 'eru-ppt' );
	$running = as_schedule_single_action( time() - 10, 'eru_ppt_push', $args, 'eru-ppt' );
	ActionScheduler::store()->log_execution( $running );
	Eru_PPT_Pusher::run_queued( $args['ids'] );
	$ids = $pending();
	$check( 'the running identical chunk does not block its own re-queue (pending-only)', 1 === count( $ids ) && ! in_array( (int) $running, array_map( 'intval', $ids ), true ) );
	ActionScheduler::store()->mark_complete( $running );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	$clean();
	if ( null === $old ) {
		delete_option( Eru_PPT_Settings::OPTION );
	} else {
		update_option( Eru_PPT_Settings::OPTION, $old, false );
	}
	foreach ( $hooks as $h ) {
		as_unschedule_all_actions( $h, null, 'eru-ppt' );
	}
}
exit( $code );
