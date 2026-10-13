<?php
/**
 * Order SMS & Delivery Codes — test mode logs without HTTP; live mode sends one request to the
 * İleti Merkezi host only; delivery code locks, verifies and unlocks.
 *
 *   wp eval-file tests/php/osms-sms.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_OSMS_Rules' ) ) {
	echo "FAIL: Eru_OSMS_Rules missing (plugin not active?)\n";
	exit( 1 );
}

global $wpdb;
$old_set   = get_option( Eru_OSMS_Settings::OPTION, null );
$old_cred  = get_option( Eru_OSMS_Settings::CREDENTIALS, null );
$old_rules = get_option( Eru_OSMS_Settings::RULES_OPTION, null );
$orders    = array();

// Count and capture every outbound HTTP request; answer İleti Merkezi-style success without network.
$http = array();
$mock = function ( $pre, $args, $url ) use ( &$http ) {
	$http[] = array( 'url' => $url, 'body' => (string) ( $args['body'] ?? '' ) );
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( array( 'response' => array( 'status' => array( 'code' => 200, 'message' => 'İşlem başarılı' ), 'order' => array( 'id' => 1 ) ) ) ),
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};
add_filter( 'pre_http_request', $mock, 10, 3 );
add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );

$ph        = fn( $n ) => str_replace( 'X', '', $n ); // assembled at run time: no phone-shaped literal in the repo.
$new_order = function () use ( &$orders, $ph ) {
	$o = wc_create_order();
	$o->set_billing_first_name( 'Demo' );
	$o->set_billing_last_name( 'Müşteri' );
	$o->set_billing_phone( $ph( '0X555 000 00 00' ) );
	$o->set_billing_email( 'ornek@example.com' );
	$o->save();
	$orders[] = $o->get_id();
	return $o;
};
$logs = fn( $oid ) => Eru_OSMS_Log::for_order( (int) $oid );

try {
	// Default state: master switch off → nothing logged, nothing sent.
	delete_option( Eru_OSMS_Settings::OPTION );
	delete_option( Eru_OSMS_Settings::CREDENTIALS );
	update_option( Eru_OSMS_Settings::RULES_OPTION, Eru_OSMS_Settings::sanitize_rules( array( array( 'id' => 'kargoda', 'name' => 'Kargoda', 'active' => 1, 'from' => '*', 'to' => 'completed', 'method' => '*', 'text' => 'Siparişiniz {siparis_no} kargoda' ) ) ) );
	$o = $new_order();
	$o->update_status( 'completed' );
	$check( 'default (switch off): no log entry, no HTTP', array() === $logs( $o->get_id() ) && 0 === count( $http ) );

	// Test mode: one log entry with the rendered text, no HTTP.
	Eru_OSMS_Settings::save( array( 'enabled' => 1, 'test_mode' => 1 ) );
	$o = $new_order();
	$o->update_status( 'processing' );
	$o->update_status( 'completed' );
	$l = $logs( $o->get_id() );
	$check( 'test mode: exactly one log entry', 1 === count( $l ) );
	$check( 'test mode: status "test"', 'test' === ( $l[0]['status'] ?? '' ) );
	$check( 'test mode: rendered text with Turkish characters intact', ( 'Siparişiniz ' . $o->get_order_number() . ' kargoda' ) === ( $l[0]['text'] ?? '' ) );
	$check( 'test mode: phone normalised to 905550000000', '905550000000' === ( $l[0]['phone'] ?? '' ) );
	$check( 'test mode: no HTTP request', 0 === count( $http ) );
	$o->update_status( 'processing' );
	$o->update_status( 'completed' );
	$check( 'same rule is not logged twice for the same order', 1 === count( $logs( $o->get_id() ) ) );
	$check( 'Turkish text counted as UCS-2 (unicode)', Eru_OSMS_Text::segments( 'Siparişiniz kargoda' )['unicode'] );

	// Live mode without credentials: nothing sent.
	Eru_OSMS_Settings::save( array( 'test_mode' => 0, 'sender' => 'DEMO' ) );
	$o = $new_order();
	$o->update_status( 'completed' );
	$l = $logs( $o->get_id() );
	$check( 'live mode, no credentials: logged as error, no HTTP', 'error' === ( $l[0]['status'] ?? '' ) && 0 === count( $http ) );

	// Fake credentials: one request to the İleti Merkezi API host.
	Eru_OSMS_Settings::set_credentials( 'demo-key', 'demo-hash' );
	$alloptions = wp_load_alloptions( true );
	$check( 'credentials are not autoloaded', ! isset( $alloptions[ Eru_OSMS_Settings::CREDENTIALS ] ) );
	Eru_OSMS_Settings::set_credentials( '', '' );
	$check( 'empty input keeps stored credentials', 'demo-hash' === Eru_OSMS_Settings::credentials()['hash'] );

	$o = $new_order();
	$o->update_status( 'completed' );
	$l = $logs( $o->get_id() );
	$check( 'live mode: queued', 'queued' === ( $l[0]['status'] ?? '' ) );
	Eru_OSMS_Rules::worker( (int) $l[0]['id'] );
	$check( 'live mode: exactly one HTTP request', 1 === count( $http ) );
	$check( 'request goes to https://api.iletimerkezi.com', 'api.iletimerkezi.com' === wp_parse_url( $http[0]['url'] ?? '', PHP_URL_HOST ) && 'https' === wp_parse_url( $http[0]['url'] ?? '', PHP_URL_SCHEME ) );
	$body = json_decode( $http[0]['body'] ?? '', true );
	$check( 'body: key/hash, sender, receipents number', 'demo-key' === ( $body['request']['authentication']['key'] ?? '' ) && 'DEMO' === ( $body['request']['order']['sender'] ?? '' ) && array( '905550000000' ) === ( $body['request']['order']['message']['receipents']['number'] ?? null ) );
	$check( 'body: text UTF-8 intact', ( 'Siparişiniz ' . $o->get_order_number() . ' kargoda' ) === ( $body['request']['order']['message']['text'] ?? '' ) );
	$check( 'log status "sent"', 'sent' === ( $logs( $o->get_id() )[0]['status'] ?? '' ) );

	// Host binding: a filtered endpoint on another host gets nothing.
	$evil = fn() => 'https://ornek.example.com/v1/send-sms/json';
	add_filter( 'eru_osms_endpoint', $evil );
	$r = Eru_OSMS_Client::send( '905550000000', 'Deneme' );
	remove_filter( 'eru_osms_endpoint', $evil );
	$check( 'credentials never sent to another host', 'final' === $r['class'] && 1 === count( $http ) );

	// Delivery code: lock → wrong code → right code → delivered.
	update_option(
		Eru_OSMS_Settings::RULES_OPTION,
		Eru_OSMS_Settings::sanitize_rules(
			array( array( 'id' => 'kod', 'name' => 'Kod', 'active' => 1, 'from' => '*', 'to' => 'processing', 'method' => '*', 'verify_target' => 'completed', 'text' => 'Teslimat kodunuz: {teslimat_kodu}' ) )
		)
	);
	$o = $new_order();
	$o->update_status( 'processing' );
	Eru_OSMS_Delivery::flush_cache();
	$check( 'code rule locks the order', Eru_OSMS_Delivery::blocks( $o->get_id(), 'completed' ) );
	$l = $logs( $o->get_id() );
	$check( 'stored text keeps the placeholder (no plain code in the log)', str_contains( $l[0]['text'] ?? '', '{teslimat_kodu}' ) );
	Eru_OSMS_Rules::worker( (int) $l[0]['id'] );
	$sent = json_decode( end( $http )['body'] ?? '', true )['request']['order']['message']['text'] ?? '';
	preg_match( '/(\d{6})/', $sent, $m );
	$code = $m[1] ?? '';
	$check( 'SMS carries a 6-digit code', 6 === strlen( $code ) );
	Eru_OSMS_Delivery::flush_cache();
	$check( 'only the hash is stored', '' !== (string) Eru_OSMS_Delivery::get( $o->get_id() )['code_hash'] && ! str_contains( (string) Eru_OSMS_Delivery::get( $o->get_id() )['code_hash'], $code ) );
	$wrong = '000000' === $code ? '111111' : '000000';
	$r     = Eru_OSMS_Delivery::verify( $o, $wrong, 'Demo Alıcı', 'self', '' );
	$check( 'wrong code rejected', is_wp_error( $r ) && 'wrong' === $r->get_error_code() );
	$r = Eru_OSMS_Delivery::verify( wc_get_order( $o->get_id() ), $code, 'Demo Alıcı', 'self', '' );
	$check( 'right code accepted', ! is_wp_error( $r ) );
	Eru_OSMS_Delivery::flush_cache();
	$check( 'order moved to the target status', 'completed' === wc_get_order( $o->get_id() )->get_status() );
	$check( 'delivery recorded "with_code" and unlocked', 'with_code' === Eru_OSMS_Delivery::get( $o->get_id() )['result'] && ! Eru_OSMS_Delivery::blocks( $o->get_id(), 'completed' ) );

	// Codeless transition (system context) is recorded, not blocked.
	$o = $new_order();
	$o->update_status( 'processing' );
	$o->update_status( 'completed' );
	Eru_OSMS_Delivery::flush_cache();
	$check( 'codeless transition recorded', 'without_code' === ( Eru_OSMS_Delivery::get( $o->get_id() )['result'] ?? '' ) );
	$check( 'staff form POST to a locked status is rewritten', Eru_OSMS_Decision::fix_form_post( array( 'order_status' => 'wc-completed' ), array( 'result' => 'pending', 'target_status' => 'completed' ), 'processing', array( 'completed' ) )['blocked'] );

	// Delivery code lifetime: 48 h by default, filterable.
	$o = $new_order();
	$o->update_status( 'processing' );
	Eru_OSMS_Delivery::flush_cache();
	$l = $logs( $o->get_id() );
	Eru_OSMS_Rules::worker( (int) $l[0]['id'] );
	preg_match( '/(\d{6})/', json_decode( end( $http )['body'] ?? '', true )['request']['order']['message']['text'] ?? '', $m );
	$code = $m[1] ?? '';
	Eru_OSMS_Delivery::update( $o->get_id(), array( 'code_created_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 49 * HOUR_IN_SECONDS ) ) );
	$r = Eru_OSMS_Delivery::verify( wc_get_order( $o->get_id() ), $code, 'Demo Alıcı', 'self', '' );
	$check( 'code older than 48 h is rejected as expired', is_wp_error( $r ) && 'expired' === $r->get_error_code() );
	Eru_OSMS_Delivery::flush_cache();
	$check( 'expired code does not use up an attempt', 0 === (int) Eru_OSMS_Delivery::get( $o->get_id() )['attempts'] );
	$ttl = fn() => 72 * HOUR_IN_SECONDS;
	add_filter( 'eru_osms_code_ttl', $ttl );
	$r = Eru_OSMS_Delivery::verify( wc_get_order( $o->get_id() ), $code, 'Demo Alıcı', 'self', '' );
	remove_filter( 'eru_osms_code_ttl', $ttl );
	$check( 'eru_osms_code_ttl filter extends the lifetime', ! is_wp_error( $r ) );

	// Customer-entered placeholders: no control characters, no links, at most 40 characters.
	$o = $new_order();
	$o->set_billing_first_name( "Demo\x07 https://ornek.example.com/kampanya www.ornek.example.com ornek.example.com" );
	$o->set_billing_last_name( str_repeat( 'Uzunsoyad', 8 ) );
	$o->save();
	$ad = Eru_OSMS_Order::variables( $o )['ad'];
	$check( '{ad}: control characters stripped', ! preg_match( '/[\x00-\x1F\x7F]/', $ad ) );
	$check( '{ad}: URLs and domains stripped', ! preg_match( '#https?:|www\.|example\.com#i', $ad ) );
	$check( '{ad}: truncated to 40 characters', mb_strlen( $ad ) <= 40 && str_starts_with( $ad, 'Demo' ) );

	// The worker re-checks the master switch before sending.
	update_option( Eru_OSMS_Settings::RULES_OPTION, Eru_OSMS_Settings::sanitize_rules( array( array( 'id' => 'beklemede', 'name' => 'Beklemede', 'active' => 1, 'from' => '*', 'to' => 'on-hold', 'method' => '*', 'text' => 'Siparişiniz {siparis_no} beklemede' ) ) ) );
	$o = $new_order();
	$o->update_status( 'on-hold' );
	$l = $logs( $o->get_id() );
	Eru_OSMS_Settings::save( array( 'enabled' => 0 ) );
	$n = count( $http );
	Eru_OSMS_Rules::worker( (int) ( $l[0]['id'] ?? 0 ) );
	$check( 'worker: master switch turned off after queueing → nothing sent, log "skipped"', 'queued' === ( $l[0]['status'] ?? '' ) && $n === count( $http ) && 'skipped' === ( $logs( $o->get_id() )[0]['status'] ?? '' ) );

	// Test mode is log-only for every number (no "test list" exception).
	Eru_OSMS_Settings::save( array( 'enabled' => 1, 'test_mode' => 1, 'test_phones' => array( $ph( '0X555 000 00 00' ) ) ) );
	$o = $new_order();
	$o->update_status( 'on-hold' );
	$l = $logs( $o->get_id() );
	$n = count( $http );
	Eru_OSMS_Rules::worker( (int) ( $l[0]['id'] ?? 0 ) );
	$check( 'test mode: even a number entered as test phone is only logged', 'test' === ( $logs( $o->get_id() )[0]['status'] ?? '' ) && $n === count( $http ) );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	foreach ( $orders as $id ) {
		$wpdb->delete( Eru_OSMS_Installer::log_table(), array( 'order_id' => $id ) );
		$wpdb->delete( Eru_OSMS_Installer::delivery_table(), array( 'order_id' => $id ) );
		$ord = wc_get_order( $id );
		if ( $ord ) {
			$ord->delete( true );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( Eru_OSMS_Rules::AS_HOOK );
	}
	null === $old_set ? delete_option( Eru_OSMS_Settings::OPTION ) : update_option( Eru_OSMS_Settings::OPTION, $old_set );
	delete_option( Eru_OSMS_Settings::CREDENTIALS );
	if ( null !== $old_cred ) {
		add_option( Eru_OSMS_Settings::CREDENTIALS, $old_cred, '', false );
	}
	null === $old_rules ? delete_option( Eru_OSMS_Settings::RULES_OPTION ) : update_option( Eru_OSMS_Settings::RULES_OPTION, $old_rules );
}
exit( $fail ? 1 : 0 );
