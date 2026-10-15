<?php
/**
 * Conversion Boosters — draining jobs re-arm while running, deactivation clean-up, atomic locks, consent filter
 * arity, shortcode name, uninstall keeps promised coupons.
 *
 *   wp eval-file tests/php/cb-maintenance.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_CB_Settings' ) || ! function_exists( 'as_schedule_single_action' ) ) {
	echo "FAIL: Eru_CB_Settings or Action Scheduler missing (plugin not active?)\n";
	exit( 1 );
}

add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_cb_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

global $wpdb;
$hooks = array( 'eru_cb_daily', 'eru_cb_cleanup_more', 'eru_cb_purge_more' );
$purge = function () use ( $wpdb, $hooks ) {
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'cbmtest%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( get_posts( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'any', 'numberposts' => -1, 's' => 'cbm-' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\_cb\_lock\_%' OR option_name LIKE '\_transient\_eru\_cb\_%' OR option_name LIKE '\_transient\_timeout\_eru\_cb\_%'" );
	wp_cache_flush();
	foreach ( $hooks as $h ) {
		as_unschedule_all_actions( $h, array(), 'eru-cb' );
		// Actions this test marked in progress (an aborted run never completes them).
		foreach ( as_get_scheduled_actions( array( 'hook' => $h, 'group' => 'eru-cb', 'status' => ActionScheduler_Store::STATUS_RUNNING, 'per_page' => 50 ), 'ids' ) as $aid ) {
			ActionScheduler::store()->mark_complete( $aid );
		}
	}
};
$purge();
$old_settings = get_option( Eru_CB_Settings::OPTION, null );
$old_data     = get_option( 'eru_cb_has_data', null );
$pending      = fn( $hook ) => as_get_scheduled_actions( array( 'hook' => $hook, 'group' => 'eru-cb', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' );
$mk_coupon    = function ( $code, $expires, $used ) {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_amount( '5' );
	$c->set_date_expires( $expires );
	$c->add_meta_data( '_eru_cb_generated', 1, true );
	$c->set_usage_count( $used );
	return $c->save();
};
$code = 1;

try {
	// 1. A draining job that is running right now must still queue its follow-up.
	add_filter( 'eru_cb_batch_size', $bs = fn() => 2 );
	foreach ( range( 1, 5 ) as $i ) {
		$mk_coupon( "cbmtest-run{$i}", time() - 3600, 0 );
	}
	foreach ( array( 'eru_cb_cleanup_more', 'eru_cb_purge_more' ) as $hook ) {
		$running = as_schedule_single_action( time() - 10, $hook, array(), 'eru-cb' );
		ActionScheduler::store()->log_execution( $running );
		Eru_CB_Coupons::rearm( $hook );
		$ids = $pending( $hook );
		$check( "rearm() while {$hook} is in progress queues exactly one pending follow-up", 1 === count( $ids ) && ! in_array( (int) $running, array_map( 'intval', $ids ), true ) );
		Eru_CB_Coupons::rearm( $hook );
		$check( "a second rearm() does not queue a duplicate ({$hook})", 1 === count( $pending( $hook ) ) );
		ActionScheduler::store()->mark_complete( $running );
		as_unschedule_all_actions( $hook, array(), 'eru-cb' );
	}
	$running = as_schedule_single_action( time() - 10, 'eru_cb_cleanup_more', array(), 'eru-cb' );
	ActionScheduler::store()->log_execution( $running );
	Eru_CB_Coupons::cleanup();
	$check( 'cleanup() run from inside its own running action re-arms while work remains', 1 === count( $pending( 'eru_cb_cleanup_more' ) ) );
	ActionScheduler::store()->mark_complete( $running );
	remove_filter( 'eru_cb_batch_size', $bs );
	as_unschedule_all_actions( 'eru_cb_cleanup_more', array(), 'eru-cb' );

	// 2. Deactivation removes every pending job, not only the daily one.
	foreach ( $hooks as $h ) {
		as_schedule_single_action( time() + 3600, $h, array(), 'eru-cb' );
	}
	do_action( 'deactivate_' . plugin_basename( ERU_CB_FILE ), false );
	$left = array_sum( array_map( fn( $h ) => count( $pending( $h ) ), $hooks ) );
	$check( 'deactivation unschedules eru_cb_daily, eru_cb_cleanup_more and eru_cb_purge_more', 0 === $left );

	// 3. Atomic, owner-checked locks.
	$check( 'Eru_CB_Lock exists', class_exists( 'Eru_CB_Lock' ) );
	$t1 = Eru_CB_Lock::acquire( 'cbmtest', 60 );
	$check( 'first acquire wins', is_string( $t1 ) && '' !== $t1 );
	$check( 'second acquire while held fails', false === Eru_CB_Lock::acquire( 'cbmtest', 60 ) );
	Eru_CB_Lock::release( 'cbmtest', 'not-the-owner' );
	$check( 'release with a foreign token keeps the lock', false === Eru_CB_Lock::acquire( 'cbmtest', 60 ) );
	Eru_CB_Lock::release( 'cbmtest', $t1 );
	$t2 = Eru_CB_Lock::acquire( 'cbmtest', 60 );
	$check( 'released lock can be taken again', is_string( $t2 ) );
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 3600 ) . '|stale' ), array( 'option_name' => Eru_CB_Lock::option( 'cbmtest' ) ) );
	wp_cache_delete( Eru_CB_Lock::option( 'cbmtest' ), 'options' );
	$t3 = Eru_CB_Lock::acquire( 'cbmtest', 60 );
	$check( 'a stale lock is taken over', is_string( $t3 ) && $t3 !== $t2 );
	Eru_CB_Lock::release( 'cbmtest', $t2 );
	$check( 'the old holder cannot release the taken-over lock', false === Eru_CB_Lock::acquire( 'cbmtest', 60 ) );
	Eru_CB_Lock::release( 'cbmtest', $t3 );
	$check( 'no add_option() lock left in the plugin', ! preg_match( '/add_option\(\s*\$lock/', (string) file_get_contents( ERU_CB_PATH . 'includes/class-eru-cb-newsletter.php' ) ) );

	// Subscribe is refused while another request holds the mailbox lock, and works once it is free.
	Eru_CB_Settings::save_for_test( array( 'nl_enabled' => 'yes' ) );
	$held = Eru_CB_Lock::acquire( 's' . md5( Eru_CB_Newsletter::email_key( 'cbm-lock@example.com' ) ), 60 );
	$r    = Eru_CB_Newsletter::subscribe( array( 'email' => 'cbm-lock@example.com', 'consent' => '1', 'source' => 'test' ) );
	$check( 'subscribe refused while the mailbox lock is held', false === $r['ok'] && ! Eru_CB_Newsletter::find_exact( 'cbm-lock@example.com' ) );
	Eru_CB_Lock::release( 's' . md5( Eru_CB_Newsletter::email_key( 'cbm-lock@example.com' ) ), $held );
	$r = Eru_CB_Newsletter::subscribe( array( 'email' => 'cbm-lock@example.com', 'consent' => '1', 'source' => 'test' ) );
	$check( 'subscribe works once the lock is free, and releases it', true === $r['ok'] && is_string( Eru_CB_Lock::acquire( 's' . md5( Eru_CB_Newsletter::email_key( 'cbm-lock@example.com' ) ), 60 ) ) );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\_cb\_lock\_%'" );

	// 4. Consent filter takes (has, email, context).
	global $wp_filter;
	$args = null;
	foreach ( $wp_filter['eru_gra_has_marketing_consent']->callbacks as $prio => $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && 'Eru_CB_Newsletter' === $cb['function'][0] ) {
				$args = $cb['accepted_args'];
			}
		}
	}
	$check( 'cb registers its consent callback with 3 arguments', 3 === $args );
	$sid = Eru_CB_Newsletter::find_exact( 'cbm-lock@example.com' );
	$check( 'pending subscriber: no consent (3-arg call)', false === apply_filters( 'eru_gra_has_marketing_consent', false, 'cbm-lock@example.com', 'send' ) );
	update_post_meta( $sid, '_eru_cb_status', 'confirmed' );
	update_post_meta( $sid, '_eru_cb_consent_at', gmdate( 'Y-m-d H:i:s' ) );
	$check( 'confirmed subscriber: consent with context "send"', true === apply_filters( 'eru_gra_has_marketing_consent', false, 'cbm-lock@example.com', 'send' ) );
	$check( 'callback still works with its default context', true === Eru_CB_Newsletter::consent_filter( false, 'cbm-lock@example.com' ) );

	// 5. Shortcode name carries the plugin abbreviation.
	$check( '[eru_cb_newsletter] is registered', shortcode_exists( 'eru_cb_newsletter' ) );
	$check( 'generic [eru_newsletter] is not registered', ! shortcode_exists( 'eru_newsletter' ) );

	// 6. Uninstall keeps coupons customers may still hold unless ERU_CB_REMOVE_DATA is true.
	$expired = $mk_coupon( 'cbmtest-u-expired', time() - 3600, 0 );
	$live    = $mk_coupon( 'cbmtest-u-live', time() + 3600, 0 );
	$used    = $mk_coupon( 'cbmtest-u-used', time() - 3600, 1 );
	$check( 'ERU_CB_REMOVE_DATA is not defined for this test', ! defined( 'ERU_CB_REMOVE_DATA' ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', plugin_basename( ERU_CB_FILE ) );
	}
	include ERU_CB_PATH . 'uninstall.php';
	$check( 'uninstall deletes an expired, unused generated coupon', false === get_post_status( $expired ) );
	$check( 'uninstall keeps an unexpired generated coupon (already promised)', 'publish' === get_post_status( $live ) );
	$check( 'uninstall keeps a used generated coupon (order record)', 'publish' === get_post_status( $used ) );
	$src = (string) file_get_contents( ERU_CB_PATH . 'uninstall.php' );
	$check( 'uninstall removes every generated coupon only with ERU_CB_REMOVE_DATA', false !== strpos( $src, 'ERU_CB_REMOVE_DATA' ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	$purge();
	if ( null === $old_settings ) {
		delete_option( Eru_CB_Settings::OPTION );
	} else {
		update_option( Eru_CB_Settings::OPTION, $old_settings, false );
	}
	if ( null === $old_data ) {
		delete_option( 'eru_cb_has_data' );
	} else {
		update_option( 'eru_cb_has_data', $old_data, false );
	}
}
exit( $code );
