<?php
/**
 * Exit-Intent Coupons — front markup and generated coupon check.
 *   wp eval-file tests/php/eic-popup.php   (plugin active; restores the options it touches)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_EIC_Settings' ) ) {
	echo "FAIL: class Eru_EIC_Settings missing (plugin not active?)\n";
	exit( 1 );
}

$old      = get_option( 'eru_eic_settings', null );
$old_stat = get_option( 'eru_eic_stats', null );
$made     = array();
$page     = function () {
	$res = wp_remote_get( home_url( '/?x=' . wp_rand() ), array( 'timeout' => 20, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
	return is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
};

try {
	delete_option( 'eru_eic_settings' );
	$html = $page();
	$check( 'default (disabled): no modal, no code', '' !== $html && false === strpos( $html, 'id="eru-eic"' ) && false === strpos( $html, 'DEMO10' ) );

	update_option( 'eru_eic_settings', Eru_EIC_Settings::sanitize( array( 'enabled' => 1, 'mode' => 'fixed', 'code' => 'demo10' ) ) );
	$html = $page();
	$check( 'enabled + code DEMO10: modal markup and code present', false !== strpos( $html, 'id="eru-eic"' ) && false !== strpos( $html, '>DEMO10<' ) );
	$check( 'modal is hidden until triggered', (bool) preg_match( '/<div id="eru-eic" class="eru-eic" hidden/', $html ) );
	$seen = wp_remote_get( home_url( '/?x=' . wp_rand() ), array( 'timeout' => 20, 'cookies' => array( Eru_EIC_Front::COOKIE => '1' ) ) );
	$check( 'seen-cookie is checked in the browser, not on the server (cache-safe HTML)', false !== strpos( (string) wp_remote_retrieve_body( $seen ), 'id="eru-eic"' ) );

	update_option( 'eru_eic_settings', Eru_EIC_Settings::sanitize( array( 'enabled' => 1, 'mode' => 'fixed', 'code' => '' ) ) );
	$check( 'enabled but no code: nothing output', false === strpos( $page(), 'id="eru-eic"' ) );

	update_option( 'eru_eic_settings', Eru_EIC_Settings::sanitize( array( 'enabled' => 0, 'mode' => 'fixed', 'code' => 'DEMO10' ) ) );
	$html = $page();
	$check( 'disabled again: markup and code absent', false === strpos( $html, 'id="eru-eic"' ) && false === strpos( $html, 'DEMO10' ) );

	// Generated mode.
	$s = Eru_EIC_Settings::sanitize( array( 'enabled' => 1, 'mode' => 'generated', 'discount_type' => 'percent', 'amount' => '15', 'valid_hours' => 24, 'min_spend' => '100', 'prefix' => 'dm', 'daily_limit' => 2, 'ip_hourly' => 50 ) );
	update_option( 'eru_eic_settings', $s );
	delete_option( 'eru_eic_stats' );
	$html = $page();
	$check( 'generated mode: modal present, no code in HTML', false !== strpos( $html, 'id="eru-eic"' ) && (bool) preg_match( '/data-eru-eic-code><\/code>/', $html ) );

	$a = Eru_EIC_Coupon::issue( $s );
	$made[] = $a['code'] ?? '';
	$c = new WC_Coupon( $a['code'] ?? 'x' );
	$check( 'issued coupon: prefix, 15%, min 100, single use, ~24h', $a && 0 === strpos( $a['code'], 'DM' ) && 'percent' === $c->get_discount_type() && 15.0 === (float) $c->get_amount() && 100.0 === (float) $c->get_minimum_amount() && 1 === (int) $c->get_usage_limit() && abs( $c->get_date_expires()->getTimestamp() - ( time() + DAY_IN_SECONDS ) ) < 120 );
	$b = Eru_EIC_Coupon::issue( $s );
	$made[] = $b['code'] ?? '';
	$check( 'second coupon has a different code', $a && $b && $a['code'] !== $b['code'] );
	$check( 'daily budget (2) is enforced', null === Eru_EIC_Coupon::issue( $s ) );

	// REST endpoint obeys the same budget.
	$req = new WP_REST_Request( 'POST', '/eru-eic/v1/coupon' );
	$res = rest_do_request( $req );
	$check( 'REST: budget used up -> ok=false', 200 === $res->get_status() && empty( $res->get_data()['ok'] ) );

	// Clean-up removes only expired generated coupons.
	$old_c = new WC_Coupon();
	$old_c->set_code( 'DMOLD' . wp_rand( 100, 999 ) );
	$old_c->set_amount( '5' );
	$old_c->set_date_expires( time() - 3 * DAY_IN_SECONDS );
	$old_c->add_meta_data( '_eru_eic_generated', 1, true );
	$old_id = $old_c->save();
	$keep_c = new WC_Coupon();
	$keep_c->set_code( 'DMKEEP' . wp_rand( 100, 999 ) );
	$keep_c->set_amount( '5' );
	$keep_c->set_date_expires( time() - 3 * DAY_IN_SECONDS );
	$keep_id = $keep_c->save();
	// Scheduling is observed through the pre_* cron filters, so a concurrent WP-Cron process cannot race the check.
	$sched  = array();
	$pre_s  = function ( $pre, $event ) use ( &$sched ) {
		if ( 'eru_eic_cleanup' === $event->hook ) {
			$sched[] = 'schedule';
			return true;
		}
		return $pre;
	};
	$pre_c  = function ( $pre, $hook ) use ( &$sched ) {
		if ( 'eru_eic_cleanup' === $hook ) {
			$sched[] = 'clear';
			return 1;
		}
		return $pre;
	};
	$exists = null;
	$pre_g  = function ( $pre, $hook ) use ( &$exists ) {
		return 'eru_eic_cleanup' === $hook ? $exists : $pre;
	};
	add_filter( 'pre_schedule_event', $pre_s, 10, 2 );
	add_filter( 'pre_clear_scheduled_hook', $pre_c, 10, 2 );
	add_filter( 'pre_get_scheduled_event', $pre_g, 10, 2 );
	update_option( Eru_EIC_Settings::OPTION, array_merge( (array) get_option( Eru_EIC_Settings::OPTION, array() ), array( 'enabled' => 0 ) ) );
	$exists = (object) array( 'hook' => 'eru_eic_cleanup', 'timestamp' => time() + 60, 'schedule' => 'daily', 'args' => array(), 'interval' => DAY_IN_SECONDS );
	Eru_EIC_Coupon::schedule();
	$check( 'popup disabled: cleanup cron is unscheduled, never scheduled', array( 'clear' ) === $sched );
	$sched  = array();
	update_option( Eru_EIC_Settings::OPTION, array_merge( (array) get_option( Eru_EIC_Settings::OPTION, array() ), array( 'enabled' => 1 ) ) );
	$exists = false;
	Eru_EIC_Coupon::schedule();
	$check( 'popup enabled: cleanup cron is scheduled', array( 'schedule' ) === $sched );
	remove_filter( 'pre_schedule_event', $pre_s, 10 );
	remove_filter( 'pre_clear_scheduled_hook', $pre_c, 10 );
	remove_filter( 'pre_get_scheduled_event', $pre_g, 10 );
	Eru_EIC_Coupon::cleanup();
	$check( 'cleanup deletes expired generated coupon, keeps others', ! get_post( $old_id ) && get_post( $keep_id ) );
	wp_delete_post( $keep_id, true );

	// Sanitiser.
	$clean = Eru_EIC_Settings::sanitize( array( 'amount' => '250', 'discount_type' => 'percent', 'prefix' => 'a-b c!d', 'accent' => 'nope', 'cookie_days' => '9999' ) );
	$check( 'sanitiser clamps percent/prefix/accent/cookie days', 100.0 === (float) $clean['amount'] && 'ABCD' === $clean['prefix'] && '#1f6feb' === $clean['accent'] && 365 === $clean['cookie_days'] );
} finally {
	foreach ( $made as $code ) {
		$id = $code ? wc_get_coupon_id_by_code( $code ) : 0;
		if ( $id ) {
			wp_delete_post( $id, true );
		}
	}
	foreach ( array( 'eru_eic_settings' => $old, 'eru_eic_stats' => $old_stat ) as $opt => $val ) {
		if ( null === $val ) {
			delete_option( $opt );
		} else {
			update_option( $opt, $val );
		}
	}
}

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
