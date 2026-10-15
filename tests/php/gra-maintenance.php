<?php
/**
 * Growth Automations — atomic locks, consent filter context, opt-out lifted only by fresh consent, 6 h cart delay,
 * uninstall keeps promised coupons.
 *
 *   wp eval-file tests/php/gra-maintenance.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_GRA_Settings' ) ) {
	echo "FAIL: Eru_GRA_Settings missing (plugin not active?)\n";
	exit( 1 );
}

$mails = array();
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
			return new WP_Error( 'eru_gra_test', 'spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);
foreach ( array( 'new_order', 'customer_on_hold_order', 'customer_processing_order', 'customer_completed_order' ) as $eru_gra_id ) {
	add_filter( 'woocommerce_email_enabled_' . $eru_gra_id, '__return_false' );
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

global $wpdb;
$emails = array( 'gra-m-guest@example.com', 'gra-m-owner@example.com', 'gra-m-other@example.com', 'gra-m-none@example.com' );
$purge  = function () use ( $wpdb, $emails ) {
	foreach ( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'search' => 'gra-m-' ) ) as $oid ) {
		wc_get_order( $oid )->delete( true );
	}
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'gramtest%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$u = get_user_by( 'login', 'gra-m-owner' );
	if ( $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u->ID );
	}
	foreach ( $emails as $e ) {
		Eru_GRA_Consent::remove_suppression( $e );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\_gra\_lock\_%' OR option_name LIKE '\_transient\_eru\_gra\_rc\_%' OR option_name LIKE '\_transient\_timeout\_eru\_gra\_rc\_%'" );
	delete_option( 'eru_gra_reconsent' );
	wp_cache_flush();
};
$purge();
$old_settings = get_option( Eru_GRA_Settings::OPTION, null );
$old_supp     = get_option( Eru_GRA_Consent::OPTION, null );
$code         = 1;

$checkout = function ( $email, $ticked ) {
	$o = wc_create_order();
	$o->set_billing_email( $email );
	$o->set_billing_first_name( 'Demo' );
	$o->set_payment_method( 'bacs' );
	if ( $ticked ) {
		$_POST['eru_gra_marketing'] = '1';
	} else {
		unset( $_POST['eru_gra_marketing'] );
	}
	Eru_GRA_Cart::save_order_consent( $o, array( 'billing_email' => $email ) );
	unset( $_POST['eru_gra_marketing'] );
	$o->set_status( 'on-hold' );
	$o->save();
	return $o;
};

try {
	// 1. Atomic, owner-checked locks.
	$check( 'Eru_GRA_Lock exists', class_exists( 'Eru_GRA_Lock' ) );
	$t1 = Eru_GRA_Lock::acquire( 'gramtest', 60 );
	$check( 'first acquire wins, second fails', is_string( $t1 ) && false === Eru_GRA_Lock::acquire( 'gramtest', 60 ) );
	Eru_GRA_Lock::release( 'gramtest', 'foreign' );
	$check( 'a foreign token cannot release', false === Eru_GRA_Lock::acquire( 'gramtest', 60 ) );
	$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 3600 ) . '|stale' ), array( 'option_name' => Eru_GRA_Lock::option( 'gramtest' ) ) );
	wp_cache_delete( Eru_GRA_Lock::option( 'gramtest' ), 'options' );
	$t2 = Eru_GRA_Lock::acquire( 'gramtest', 60 );
	$check( 'a stale lock is taken over', is_string( $t2 ) );
	Eru_GRA_Lock::release( 'gramtest', $t2 );
	foreach ( array( 'class-eru-gra-runner.php', 'class-eru-gra-alerts.php' ) as $f ) {
		$check( "no add_option() lock in {$f}", ! preg_match( '/add_option\(\s*\$(lock|name)/', (string) file_get_contents( ERU_GRA_PATH . 'includes/' . $f ) ) );
	}
	Eru_GRA_Settings::save_for_test( array( 'bacs' => array( 'enabled' => 'yes' ) ) );
	$held = Eru_GRA_Lock::acquire( 'bacs', 600 );
	$r    = Eru_GRA_Runner::run( 'bacs', true );
	$check( 'a run is skipped while another worker holds its lock', 0 === $r['processed'] && false === Eru_GRA_Lock::acquire( 'bacs', 600 ) );
	Eru_GRA_Lock::release( 'bacs', $held );
	Eru_GRA_Runner::run( 'bacs', true );
	$check( 'a run releases its lock', is_string( $t3 = Eru_GRA_Lock::acquire( 'bacs', 600 ) ) );
	Eru_GRA_Lock::release( 'bacs', $t3 );

	// 2. Consent filter gets (has, email, context).
	$seen = array();
	$spy  = function ( $has, $email, $context = null ) use ( &$seen ) {
		$seen[] = array( func_num_args(), $context );
		return $has;
	};
	add_filter( 'eru_gra_has_marketing_consent', $spy, 99, 3 );
	Eru_GRA_Consent::has( 'gra-m-none@example.com' );
	Eru_GRA_Consent::row_consent( array( 'email' => 'gra-m-none@example.com', 'consent_source' => 'filter', 'user_id' => 0 ) );
	remove_filter( 'eru_gra_has_marketing_consent', $spy, 99 );
	$check( 'filter called with 3 arguments', $seen && 3 === $seen[0][0] && 3 === $seen[1][0] );
	$check( 'send-time re-check passes context "send"', 'send' === $seen[1][1] && is_string( $seen[0][1] ) && '' !== $seen[0][1] );

	// 3. A checkout tick lifts an opt-out at once only for the signed-in owner of the address. Anyone else gets one
	// re-consent e-mail per 24 h; only its confirmed POST lifts the opt-out, payment never does.
	Eru_GRA_Settings::save_for_test( array( 'cart' => array( 'enabled' => 'yes' ) ) );
	$to    = function ( $e ) use ( &$mails ) {
		return array_values( array_filter( $mails, fn( $m ) => in_array( $e, (array) $m['to'], true ) ) );
	};
	$token = function ( $e ) use ( $to ) {
		$m = $to( $e );
		$m = end( $m );
		return ( $m && preg_match( '/eru_gra_reconsent=([A-Za-z0-9_.\-]+)/', (string) $m['message'], $x ) ) ? $x[1] : '';
	};
	Eru_GRA_Consent::suppress( 'gra-m-guest@example.com' );
	$o = $checkout( 'gra-m-guest@example.com', false );
	$o->payment_complete();
	$check( 'checkout without the box keeps the opt-out and sends nothing', Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) && 0 === count( $to( 'gra-m-guest@example.com' ) ) );
	$o = $checkout( 'gra-m-guest@example.com', true );
	$check( 'guest tick: opt-out kept, one re-consent e-mail with a link', Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) && 1 === count( $to( 'gra-m-guest@example.com' ) ) && '' !== $token( 'gra-m-guest@example.com' ) );
	$o = wc_get_order( $o->get_id() );
	$o->payment_complete();
	$check( 'paying the non-owner order does not lift the opt-out', Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) );
	$checkout( 'gra-m-guest@example.com', true );
	$check( 'a second tick within 24 h sends no second e-mail', 1 === count( $to( 'gra-m-guest@example.com' ) ) );
	$raw  = $token( 'gra-m-guest@example.com' );
	$page = Eru_GRA_Consent::handle_reconsent( 'GET', $raw );
	$check( 'GET shows the button and changes nothing', 200 === $page['status'] && false !== strpos( $page['html'], '<form' ) && false !== strpos( $page['html'], 'E-postaları yeniden almak istiyorum' ) && Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) );
	$check( 'a forged token is refused', 400 === Eru_GRA_Consent::handle_reconsent( 'POST', $raw . 'x' )['status'] && Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) );
	$_SERVER['HTTP_ORIGIN'] = 'https://elsewhere.example.com';
	$check( 'a cross-site POST is refused', 403 === Eru_GRA_Consent::handle_reconsent( 'POST', $raw )['status'] && Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) );
	unset( $_SERVER['HTTP_ORIGIN'] );
	$ok = Eru_GRA_Consent::handle_reconsent( 'POST', $raw );
	$check( 'POST with the valid token lifts the opt-out', 200 === $ok['status'] && ! Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) );
	Eru_GRA_Consent::suppress( 'gra-m-guest@example.com' );
	$check( 'the used token is refused (single use)', 400 === Eru_GRA_Consent::handle_reconsent( 'POST', $raw )['status'] && Eru_GRA_Consent::is_suppressed( 'gra-m-guest@example.com' ) );

	Eru_GRA_Consent::suppress( 'gra-m-none@example.com' );
	$checkout( 'gra-m-none@example.com', true );
	$raw2 = $token( 'gra-m-none@example.com' );
	$list = get_option( Eru_GRA_Consent::RECONSENT_OPTION, array() );
	foreach ( $list as $k => $row ) {
		$list[ $k ]['e'] = time() - 60;
	}
	update_option( Eru_GRA_Consent::RECONSENT_OPTION, $list, false );
	$check( 'an expired token is refused', '' !== $raw2 && 400 === Eru_GRA_Consent::handle_reconsent( 'POST', $raw2 )['status'] && Eru_GRA_Consent::is_suppressed( 'gra-m-none@example.com' ) );

	$uid = wp_insert_user( array( 'user_login' => 'gra-m-owner', 'user_email' => 'gra-m-owner@example.com', 'user_pass' => wp_generate_password() ) );
	$was = get_current_user_id();
	wp_set_current_user( $uid );
	Eru_GRA_Consent::suppress( 'gra-m-owner@example.com' );
	Eru_GRA_Consent::suppress( 'gra-m-other@example.com' );
	$checkout( 'gra-m-owner@example.com', true );
	$check( 'account owner ticking the box with their own address lifts the opt-out at once', ! Eru_GRA_Consent::is_suppressed( 'gra-m-owner@example.com' ) );
	$o = $checkout( 'gra-m-other@example.com', true );
	$o->payment_complete();
	$check( 'logged-in user ticking for another address: opt-out kept even when paid, re-consent e-mail sent', Eru_GRA_Consent::is_suppressed( 'gra-m-other@example.com' ) && 1 === count( $to( 'gra-m-other@example.com' ) ) );
	wp_set_current_user( $was );

	// 4. Abandoned-cart default delay.
	$check( 'abandoned-cart default delay is 6 hours', '6' === (string) Eru_GRA_Settings::defaults()['cart']['delay'] );

	// 5. Uninstall keeps coupons a customer may still hold.
	$mk = function ( $code, $expires, $used ) {
		$c = new WC_Coupon();
		$c->set_code( $code );
		$c->set_amount( '5' );
		$c->set_date_expires( $expires );
		$c->add_meta_data( '_eru_gra_generated', 1, true );
		$c->set_usage_count( $used );
		return $c->save();
	};
	$expired = $mk( 'gramtest-expired', time() - 3600, 0 );
	$live    = $mk( 'gramtest-live', time() + 3600, 0 );
	$used    = $mk( 'gramtest-used', time() - 3600, 1 );
	$tables  = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}eru\\_gra\\_%'" );
	$check( 'ERU_GRA_REMOVE_DATA is not defined for this test', ! defined( 'ERU_GRA_REMOVE_DATA' ) );
	$check( 'uninstall removes every generated coupon only with ERU_GRA_REMOVE_DATA', 2 <= substr_count( (string) file_get_contents( ERU_GRA_PATH . 'uninstall.php' ), 'ERU_GRA_REMOVE_DATA' ) );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', plugin_basename( ERU_GRA_FILE ) );
	}
	// Uninstall drops the tables: keep the demo site's existing rows.
	$keep = array();
	foreach ( array( 'eru_gra_carts', 'eru_gra_log' ) as $t ) {
		$keep[ $t ] = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}{$t}", ARRAY_A );
	}
	$keep_data = get_option( 'eru_gra_has_data', null );
	include ERU_GRA_PATH . 'uninstall.php';
	delete_option( 'eru_gra_db_version' );
	Eru_GRA_DB::install();
	foreach ( $keep as $t => $rows ) {
		foreach ( $rows as $row ) {
			$wpdb->insert( $wpdb->prefix . $t, $row );
		}
	}
	if ( null !== $keep_data ) {
		update_option( 'eru_gra_has_data', $keep_data, false );
	}
	$check( 'uninstall deletes an expired, unused generated coupon', false === get_post_status( $expired ) );
	$check( 'uninstall keeps an unexpired generated coupon (already e-mailed)', 'publish' === get_post_status( $live ) );
	$check( 'uninstall keeps a used generated coupon', 'publish' === get_post_status( $used ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	$purge();
	if ( null === $old_settings ) {
		delete_option( Eru_GRA_Settings::OPTION );
	} else {
		update_option( Eru_GRA_Settings::OPTION, $old_settings, false );
	}
	if ( null === $old_supp ) {
		delete_option( Eru_GRA_Consent::OPTION );
	} else {
		update_option( Eru_GRA_Consent::OPTION, $old_supp, false );
	}
	Eru_GRA_Settings::sync_schedules();
}
exit( $code );
