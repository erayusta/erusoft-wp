<?php
/**
 * Device Trade-In — default condition ladder, shipping-instruction placeholders, retention purge without enabled
 * products, documented hooks.
 *
 *   wp eval-file tests/php/dti-maintenance.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_DTI_Settings' ) || ! function_exists( 'as_schedule_single_action' ) ) {
	echo "FAIL: Eru_DTI_Settings or Action Scheduler missing (plugin not active?)\n";
	exit( 1 );
}

add_filter( 'pre_wp_mail', '__return_true' );
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
$leads    = Eru_DTI_DB::table( 'leads' );
$tradeins = Eru_DTI_DB::table( 'tradeins' );
$old      = get_option( Eru_DTI_Settings::OPTION, null );
$old_flag = get_option( 'eru_dti_has_data', null );
$keep     = array(
	'leads'    => $wpdb->get_results( "SELECT * FROM {$leads}", ARRAY_A ),
	'tradeins' => $wpdb->get_results( "SELECT * FROM {$tradeins}", ARRAY_A ),
);
$set      = function ( array $over ) use ( $old ) {
	update_option( Eru_DTI_Settings::OPTION, array_merge( is_array( $old ) ? $old : array(), $over ), false );
	wp_cache_flush();
};
$daily    = fn() => as_get_scheduled_actions( array( 'hook' => 'eru_dti_daily', 'group' => 'eru-dti', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 5 ), 'ids' );
$code     = 1;

try {
	// 1. Neutral default condition ladder.
	$ladder = array_map( fn( $c ) => array( $c['key'], $c['label'], (int) $c['percent'] ), Eru_DTI_Settings::default_conditions() );
	$check(
		'default ladder is cok-iyi/Çok iyi 100, iyi/İyi 80, orta/Orta 60, kotu/Kötü 35',
		array( array( 'cok-iyi', 'Çok iyi', 100 ), array( 'iyi', 'İyi', 80 ), array( 'orta', 'Orta', 60 ), array( 'kotu', 'Kötü', 35 ) ) === $ladder
	);
	$check( 'no default level is named "Kusursuz"', false === stripos( wp_json_encode( Eru_DTI_Settings::default_conditions(), JSON_UNESCAPED_UNICODE ), 'kusursuz' ) );

	// 2. Shipping-instruction placeholders {days} {order} {reference}; the old set is not replaced any more.
	$set( array( 'ship_text' => 'Cihazı {days} gün içinde gönderin. Sipariş {order}, referans {reference}. {gun} {siparis} {kod}', 'ship_reference' => 'DEMO-REF', 'overdue_days' => '5' ) );
	$out = Eru_DTI_Overdue::instructions( '1234' );
	$check( '{days} {order} {reference} are filled', 0 === strpos( $out, 'Cihazı 5 gün içinde gönderin. Sipariş 1234, referans DEMO-REF.' ) );
	$check( 'old placeholders {gun} {siparis} {kod} are left as typed', false !== strpos( $out, '{gun} {siparis} {kod}' ) );
	$check( 'settings sanitise keeps ship_reference', 'DEMO-REF' === Eru_DTI_Settings::sanitize( array( 'ship_reference' => 'DEMO-REF' ) )['ship_reference'] );
	$src = (string) file_get_contents( ERU_DTI_PATH . 'includes/class-eru-dti-admin.php' ) . (string) file_get_contents( ERU_DTI_PATH . 'README.md' );
	$check( 'admin hint and README name only the new placeholders', false !== strpos( $src, '{reference}' ) && ! preg_match( '/\{(gun|siparis|kod)\}/', $src ) );

	// 3. Retention purge keeps running with no trade-in product enabled while data exists.
	$set( array( 'categories' => array(), 'products' => array(), 'retention_days' => '30' ) );
	as_unschedule_all_actions( 'eru_dti_daily', array(), 'eru-dti' );
	delete_option( 'eru_dti_has_data' );
	$wpdb->query( "DELETE FROM {$leads}" );
	$wpdb->query( "DELETE FROM {$tradeins}" );
	Eru_DTI_Overdue::maybe_schedule();
	$check( 'no targets and no data: no daily job (inert)', ! $daily() );
	$r = Eru_DTI_Lead::submit( array( 'name' => 'Demo Kişi', 'email' => 'dti-m@example.com', 'phone' => '0' . '555 000 00 00', 'device_text' => 'Demo Marka Model A' ), '127.0.0.1' );
	$check( 'a lead is stored with no product enabled', true === $r && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads}" ) );
	Eru_DTI_Overdue::maybe_schedule();
	$check( 'stored personal data keeps the daily retention job scheduled without targets', 1 === count( $daily() ) );
	$tid = Eru_DTI_DB::tradein_insert( array( 'order_id' => 0, 'device_id' => 0, 'condition_key' => 'iyi', 'serial' => 'DEMO-M1', 'value' => 1, 'bonus' => 0, 'status' => 'onaylandi', 'customer_email' => 'dti-m@example.com' ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$leads} SET created_at = %s", gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$tradeins} SET created_at = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ), $tid ) );
	Eru_DTI_Overdue::daily();
	$check( 'the daily job purges expired leads and decided trade-ins', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads}" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tradeins}" ) );
	Eru_DTI_Overdue::maybe_schedule();
	$check( 'once the tables are empty and no product is enabled the job is removed', ! $daily() );

	// 4. Hook docs.
	$readme = (string) file_get_contents( ERU_DTI_PATH . 'README.md' );
	$check( 'README documents eru_dti_client_ip and eru_dti_is_admin_change', false !== strpos( $readme, '`eru_dti_client_ip`' ) && false !== strpos( $readme, '`eru_dti_is_admin_change`' ) );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
} finally {
	$wpdb->query( "DELETE FROM {$leads}" );
	$wpdb->query( "DELETE FROM {$tradeins}" );
	foreach ( $keep as $t => $rows ) {
		foreach ( $rows as $row ) {
			$wpdb->insert( Eru_DTI_DB::table( $t ), $row );
		}
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_dti\_%' OR option_name LIKE '\_transient\_timeout\_eru\_dti\_%'" );
	if ( null === $old ) {
		delete_option( Eru_DTI_Settings::OPTION );
	} else {
		update_option( Eru_DTI_Settings::OPTION, $old, false );
	}
	if ( null === $old_flag ) {
		delete_option( 'eru_dti_has_data' );
	} else {
		update_option( 'eru_dti_has_data', $old_flag, false );
	}
	as_unschedule_all_actions( 'eru_dti_daily', array(), 'eru-dti' );
	wp_cache_flush();
}
exit( $code );
