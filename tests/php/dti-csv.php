<?php
/**
 * Device Trade-In — CSV import/export, lead rate limit, privacy eraser/exporter, overdue reminder.
 *
 *   wp eval-file tests/php/dti-csv.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_DTI_CSV' ) ) {
	echo "FAIL: Eru_DTI_CSV missing (plugin not active?)\n";
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
		if ( false !== strpos( $url, 'wp-cron.php' ) ) {
			return new WP_Error( 'eru_dti_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);

global $wpdb;
$old_settings = get_option( Eru_DTI_Settings::OPTION, null );
$order_ids    = array();
$code         = 0;
$cleanup_db   = function () use ( $wpdb ) {
	$wpdb->query( "DELETE FROM {$wpdb->prefix}eru_dti_devices WHERE level1 = 'Demo CSV'" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->prefix}eru_dti_leads WHERE email LIKE '%@example.com'" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->prefix}eru_dti_tradeins WHERE customer_email LIKE '%@example.com' OR serial LIKE 'DEMO-%'" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_dti\_rl\_%' OR option_name LIKE '\_transient\_timeout\_eru\_dti\_rl\_%'" ); // phpcs:ignore
};

try {
	$cleanup_db();

	// Amount parsing.
	$check( '1.234,56 -> 1234.56', 1234.56 === Eru_DTI_CSV::parse_amount( '1.234,56' ) );
	$check( '1234.56 -> 1234.56', 1234.56 === Eru_DTI_CSV::parse_amount( '1234.56' ) );
	$check( '1.234 (TR thousands) -> 1234', 1234.0 === Eru_DTI_CSV::parse_amount( '1.234' ) );
	$check( '8500,5 -> 8500.5', 8500.5 === Eru_DTI_CSV::parse_amount( '8500,5' ) );
	$check( '12.345.678,9 -> 12345678.9', 12345678.9 === Eru_DTI_CSV::parse_amount( '12.345.678,9' ) );
	$check( 'abc rejected', null === Eru_DTI_CSV::parse_amount( 'abc' ) );
	$check( 'negative rejected', null === Eru_DTI_CSV::parse_amount( '-5' ) );

	// (g) import with a bad row.
	$csv = "level1,level2,level3,base_value,active\n"
		. "Demo CSV,Model A,64 GB,\"1.234,56\",1\n"
		. "Demo CSV,Model B,128 GB,abc,1\n"
		. "Demo CSV,Model C,256 GB,5000.50,0\n";
	$res = Eru_DTI_CSV::import( $csv );
	$check( '(g) two rows imported', 2 === $res['added'] );
	$check( '(g) bad row rejected with line number 3', 1 === count( $res['errors'] ) && false !== strpos( $res['errors'][0], '3' ) );
	$row = Eru_DTI_DB::device_find( 'Demo CSV', 'Model A', '64 GB' );
	$check( '(g) 1.234,56 stored as 1234.56', $row && abs( (float) $row['base_value'] - 1234.56 ) < 0.001 );
	$row3 = Eru_DTI_DB::device_find( 'Demo CSV', 'Model C', '256 GB' );
	$check( '(g) active=0 stored', $row3 && 0 === (int) $row3['active'] );
	$res2 = Eru_DTI_CSV::import( "level1;level2;level3;base_value;active\nDemo CSV;Model A;64 GB;2000;1\n" );
	$check( 'semicolon file re-import updates instead of duplicating', 1 === $res2['updated'] && 0 === $res2['added'] );

	// Export formula escaping.
	$check( "(g) export cell =SUM(1) -> '=SUM(1)", "'=SUM(1)" === Eru_DTI_CSV::export_cell( '=SUM(1)' ) );
	$check( 'export cell +, -, @ escaped', "'+1" === Eru_DTI_CSV::export_cell( '+1' ) && "'-1" === Eru_DTI_CSV::export_cell( '-1' ) && "'@x" === Eru_DTI_CSV::export_cell( '@x' ) );
	$check( 'plain cell untouched', 'Model A' === Eru_DTI_CSV::export_cell( 'Model A' ) );
	Eru_DTI_DB::device_insert( array( 'level1' => 'Demo CSV', 'level2' => '=SUM(1)', 'level3' => '', 'base_value' => 10, 'active' => 1 ) );
	$out = Eru_DTI_CSV::export();
	$check( 'export has header and escaped formula cell', 0 === strpos( ltrim( $out, "\xEF\xBB\xBF" ), 'level1,level2,level3,base_value,active' ) && false !== strpos( $out, "'=SUM(1)" ) );

	// (h) lead rate limit.
	Eru_DTI_Settings::save_for_test( array( 'leads_enabled' => 'yes', 'notify_email' => 'ekip@example.com' ) );
	$lead = function ( $n ) {
		return Eru_DTI_Lead::submit( array( 'name' => 'Demo Kişi', 'email' => "lead{$n}@example.com", 'phone' => '0000', 'device_text' => 'Demo Marka Model Z', 'hp' => '' ), 'demo-client-a' );
	};
	$check( '(h) lead 1 accepted', true === $lead( 1 ) );
	$check( '(h) lead 2 accepted', true === $lead( 2 ) );
	$check( '(h) lead 3 accepted', true === $lead( 3 ) );
	$check( '(h) lead 4 refused (3/hour)', is_wp_error( $lead( 4 ) ) );
	$check( 'other IP is not limited', true === Eru_DTI_Lead::submit( array( 'name' => 'Demo', 'email' => 'lead9@example.com', 'phone' => '', 'device_text' => 'Demo', 'hp' => '' ), 'demo-client-b' ) );
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_dti_leads" ); // phpcs:ignore
	Eru_DTI_Lead::submit( array( 'name' => 'Bot', 'email' => 'bot@example.com', 'phone' => '', 'device_text' => 'x', 'hp' => 'http://spam' ), 'demo-client-c' );
	$check( 'honeypot hit stores nothing', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_dti_leads" ) === $before ); // phpcs:ignore
	$check( 'team notification e-mail sent', ! empty( $mails ) && 'ekip@example.com' === ( is_array( $mails[0]['to'] ) ? $mails[0]['to'][0] : $mails[0]['to'] ) );

	// Public options endpoint: unknown values create no transient.
	Eru_DTI_DB::device_insert( array( 'level1' => 'Demo CSV', 'level2' => 'Model K', 'level3' => '', 'base_value' => 100, 'active' => 1 ) );
	$count_t = fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_dti\_opt\_%'" ); // phpcs:ignore
	$before_t = $count_t();
	$die_h    = fn() => function () { throw new RuntimeException( 'die' ); };
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die_h );
	foreach ( array( array( 'zzz-unknown', '' ), array( 'Demo CSV', 'nope' ), array( 'zzz', 'yyy' ) ) as $q ) {
		$_GET['l1'] = $q[0];
		$_GET['l2'] = $q[1];
		ob_start();
		try {
			Eru_DTI_Front::ajax_options();
		} catch ( Throwable $e ) {
			unset( $e );
		}
		ob_end_clean();
	}
	$check( 'unknown l1/l2 create no transient', $count_t() === $before_t );
	$_GET['l1'] = 'Demo CSV';
	$_GET['l2'] = 'Model K';
	ob_start();
	try {
		Eru_DTI_Front::ajax_options();
	} catch ( Throwable $e ) {
		unset( $e );
	}
	ob_end_clean();
	$check( 'known values are cached', $count_t() === $before_t + 1 );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	remove_filter( 'wp_die_ajax_handler', $die_h );
	unset( $_GET['l1'], $_GET['l2'] );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_dti\_opt\_%' OR option_name LIKE '\_transient\_timeout\_eru\_dti\_opt\_%'" ); // phpcs:ignore

	// (i) privacy exporter / eraser.
	$exp = Eru_DTI_Privacy::export_leads( 'lead1@example.com' );
	$check( 'exporter returns the lead', ! empty( $exp['data'] ) );
	$er = Eru_DTI_Privacy::erase_leads( 'lead1@example.com' );
	$check( '(i) eraser removes the lead by e-mail', true === $er['items_removed'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_dti_leads WHERE email = 'lead1@example.com'" ) ); // phpcs:ignore
	$tid = Eru_DTI_DB::tradein_insert( array( 'order_id' => 0, 'device_id' => 0, 'condition_key' => 'iyi', 'serial' => 'DEMO-9', 'value' => 1, 'bonus' => 0, 'status' => 'onaylandi', 'customer_email' => 'trade1@example.com' ) );
	Eru_DTI_Privacy::erase_tradeins( 'trade1@example.com' );
	$t = Eru_DTI_DB::tradein_get( $tid );
	$check( 'trade-in eraser clears e-mail and serial', '' === $t['customer_email'] && '' === $t['serial'] );
	$wpdb->delete( $wpdb->prefix . 'eru_dti_tradeins', array( 'id' => $tid ) ); // The erased row matches no clean-up pattern any more.

	// Retention purge.
	Eru_DTI_Settings::save_for_test( array( 'retention_days' => '30' ) );
	$wpdb->query( "UPDATE {$wpdb->prefix}eru_dti_leads SET created_at = '2020-01-01 00:00:00' WHERE email = 'lead2@example.com'" ); // phpcs:ignore
	Eru_DTI_Overdue::purge();
	$check( 'retention purge deletes old leads', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}eru_dti_leads WHERE email = 'lead2@example.com'" ) ); // phpcs:ignore

	// Overdue reminder: once per row, customer + admin mail.
	Eru_DTI_Settings::save_for_test( array( 'overdue_days' => '7', 'notify_email' => 'ekip@example.com' ) );
	$o = wc_create_order();
	$o->set_billing_email( 'overdue@example.com' );
	$o->save();
	$order_ids[] = $o->get_id();
	$old = Eru_DTI_DB::tradein_insert( array( 'order_id' => $o->get_id(), 'device_id' => 0, 'condition_key' => 'iyi', 'serial' => 'DEMO-8', 'value' => 100, 'bonus' => 0, 'status' => 'beklemede', 'customer_email' => 'overdue@example.com' ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_dti_tradeins SET created_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ), $old ) ); // phpcs:ignore
	$mails = array();
	Eru_DTI_Overdue::run();
	$check( 'overdue: customer and team mailed', 2 === count( $mails ) );
	$mails = array();
	Eru_DTI_Overdue::run();
	$check( 'overdue: second run sends nothing (reminded_at set)', 0 === count( $mails ) );
	Eru_DTI_Settings::save_for_test( array( 'overdue_days' => '0' ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}eru_dti_tradeins SET reminded_at = NULL WHERE id = %d", $old ) ); // phpcs:ignore
	Eru_DTI_Overdue::run();
	$check( 'overdue off (0 days): nothing sent', 0 === count( $mails ) );
} catch ( Throwable $e ) {
	echo 'ERROR: ' . $e->getMessage() . "\n";
	$code = 1;
} finally {
	foreach ( $order_ids as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	$cleanup_db();
	if ( null === $old_settings ) {
		delete_option( Eru_DTI_Settings::OPTION );
	} else {
		update_option( Eru_DTI_Settings::OPTION, $old_settings );
	}
}
if ( $code ) {
	exit( 1 );
}
echo "OK dti-csv\n";
