<?php
/**
 * Content Automation — the pipeline tick turns one queued topic into one draft; no key, no HTTP.
 *   wp eval-file tests/php/ca-pipeline.php   (plugin active; HTTP is mocked; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_CA_Runner' ) ) {
	echo "FAIL: class Eru_CA_Runner missing (plugin not active?)\n";
	exit( 1 );
}

$opts  = array();
foreach ( array( 'eru_ca_settings', 'eru_ca_keys', 'eru_ca_queue', 'eru_ca_usage', 'eru_ca_lock' ) as $o ) {
	$opts[ $o ] = get_option( $o, false );
}
$ids   = array();
$calls = array();
$mock  = function ( $pre, $args, $url ) use ( &$calls ) {
	$calls[] = array( 'url' => $url, 'args' => $args );
	if ( false !== strpos( $url, 'api.anthropic.com' ) ) {
		$text = wp_json_encode( array( 'title' => 'Otomasyon Demo Yazısı', 'excerpt' => 'Özet.', 'content' => '<h2>Bölüm</h2><p>İçerik.</p><iframe src="x"></iframe>' ), JSON_UNESCAPED_UNICODE );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return new WP_Error( 'unexpected', 'unexpected host' );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
try {
	delete_option( 'eru_ca_keys' );
	delete_option( 'eru_ca_lock' );
	delete_option( 'eru_ca_usage' );
	update_option( 'eru_ca_settings', array( 'enabled' => 1, 'provider' => 'anthropic', 'per_tick' => 1, 'publish_rule' => 'draft', 'daily_cap' => 10 ), false );
	update_option( 'eru_ca_queue', array(), false );
	Eru_CA_Queue::add( "WordPress güvenliği için 5 ipucu | güvenlik, parola\nİkinci demo konusu" );
	$check( 'queue holds 2 pending topics', 2 === count( Eru_CA_Queue::all() ) && 2 === Eru_CA_Queue::count( 'pending' ) );

	Eru_CA_Runner::tick();
	$check( 'no key -> tick does nothing (no HTTP)', 0 === count( $calls ) && 2 === Eru_CA_Queue::count( 'pending' ) );

	Eru_CA_Settings::save_key( 'anthropic', 'sk-ant-demo-0000' );
	Eru_CA_Runner::tick();
	$check( 'exactly one HTTP request (batch bounded)', 1 === count( $calls ) );
	$check( 'one done, one still pending', 1 === Eru_CA_Queue::count( 'done' ) && 1 === Eru_CA_Queue::count( 'pending' ) );
	$done = array_values( array_filter( Eru_CA_Queue::all(), fn( $i ) => 'done' === $i['status'] ) )[0] ?? null;
	$pid  = $done['post_id'] ?? 0;
	$ids[] = $pid;
	$p = $pid ? get_post( $pid ) : null;
	$check( 'draft created with canned title', $p && 'draft' === $p->post_status && 'Otomasyon Demo Yazısı' === $p->post_title );
	$check( 'content sanitised (iframe removed)', $p && false === strpos( $p->post_content, '<iframe' ) );
	$check( 'queue item linked to post', $p && (int) get_post_meta( $pid, '_eru_ca_item', true ) > 0 || ( $p && '' !== (string) get_post_meta( $pid, '_eru_ca_item', true ) ) );

	// Lock against overlap.
	$calls = array();
	update_option( 'eru_ca_lock', (string) time(), false );
	Eru_CA_Runner::tick();
	$check( 'held lock -> tick skipped', 0 === count( $calls ) && 1 === Eru_CA_Queue::count( 'pending' ) );
	delete_option( 'eru_ca_lock' );

	// Schedule rule.
	update_option( 'eru_ca_settings', array( 'enabled' => 1, 'provider' => 'anthropic', 'per_tick' => 1, 'publish_rule' => 'schedule', 'schedule_delay_hours' => 24, 'daily_cap' => 10 ), false );
	Eru_CA_Runner::tick();
	$sched = array_values( array_filter( Eru_CA_Queue::all(), fn( $i ) => 'done' === $i['status'] && (int) $i['post_id'] !== (int) $pid ) )[0] ?? null;
	$ids[] = $sched['post_id'] ?? 0;
	$sp    = $sched ? get_post( $sched['post_id'] ) : null;
	$check( 'schedule rule -> future post', $sp && 'future' === $sp->post_status );

	// Disabled + daily cap.
	$calls = array();
	Eru_CA_Queue::add( 'Üçüncü demo konusu' );
	update_option( 'eru_ca_settings', array( 'enabled' => 0, 'provider' => 'anthropic' ), false );
	Eru_CA_Runner::tick();
	$check( 'disabled -> tick does nothing', 0 === count( $calls ) );
	update_option( 'eru_ca_settings', array( 'enabled' => 1, 'provider' => 'anthropic', 'daily_cap' => 1, 'per_tick' => 3 ), false );
	update_option( 'eru_ca_usage', array( 'date' => wp_date( 'Y-m-d' ), 'count' => 1 ), false );
	Eru_CA_Runner::tick();
	$check( 'daily cap -> no HTTP', 0 === count( $calls ) );
	// Fix round 1: atomic token lock.
	delete_option( 'eru_ca_lock' );
	$t1 = Eru_CA_Runner::acquire_lock();
	$t2 = Eru_CA_Runner::acquire_lock();
	$check( 'first acquire returns a token, second fails', '' !== $t1 && '' === $t2 );
	Eru_CA_Runner::release_lock( 'wrong-token' );
	$check( 'release with a foreign token keeps the lock', '' === Eru_CA_Runner::acquire_lock() );
	Eru_CA_Runner::release_lock( $t1 );
	$t3 = Eru_CA_Runner::acquire_lock();
	$check( 'release with own token frees it', '' !== $t3 );
	Eru_CA_Runner::release_lock( $t3 );
	update_option( 'eru_ca_lock', 'old-token|' . ( time() - 3600 ), false );
	$t4 = Eru_CA_Runner::acquire_lock();
	$check( 'stale lock is taken over once', '' !== $t4 && '' === Eru_CA_Runner::acquire_lock() );
	Eru_CA_Runner::release_lock( $t4 );
	$check( 'no lock row left behind', false === get_option( 'eru_ca_lock', false ) );

	// Fix round 1: external <img> stripped when posts go live automatically.
	$img_mock = function ( $pre, $args, $url ) {
		$text = wp_json_encode( array( 'title' => 'Görsel Testi', 'content' => '<p>Metin</p><img src="https://example.com/x.png" alt=""><img src="' . home_url( '/yerel.png' ) . '" alt="">' ), JSON_UNESCAPED_UNICODE );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => $text ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $img_mock, 12, 3 );
	foreach ( array( 'publish' => false, 'draft' => true ) as $rule => $keeps ) {
		update_option( 'eru_ca_queue', array(), false );
		delete_option( 'eru_ca_usage' );
		Eru_CA_Queue::add( 'Görsel konusu ' . $rule );
		update_option( 'eru_ca_settings', array( 'enabled' => 1, 'provider' => 'anthropic', 'per_tick' => 1, 'publish_rule' => $rule, 'daily_cap' => 10 ), false );
		Eru_CA_Runner::tick();
		$it = Eru_CA_Queue::all()[0] ?? array( 'post_id' => 0 );
		$ids[] = $it['post_id'];
		$pc = $it['post_id'] ? get_post( $it['post_id'] )->post_content : '';
		$check( "rule $rule: external img " . ( $keeps ? 'kept' : 'stripped' ), $keeps ? false !== strpos( $pc, 'example.com/x.png' ) : ( '' !== $pc && false === strpos( $pc, 'example.com/x.png' ) && false !== strpos( $pc, '/yerel.png' ) ) );
	}
	remove_filter( 'pre_http_request', $img_mock, 12 );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	foreach ( array_filter( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $opts as $k => $v ) {
		false === $v ? delete_option( $k ) : update_option( $k, $v, false );
	}
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
