<?php
/**
 * AI Post Generator — mocked provider calls create a draft; no key means no HTTP.
 *   wp eval-file tests/php/apg-generate.php   (plugin active; HTTP is mocked; creates and removes its own data)
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_APG_Generator' ) ) {
	echo "FAIL: class Eru_APG_Generator missing (plugin not active?)\n";
	exit( 1 );
}

$opts  = array( 'eru_apg_settings' => get_option( 'eru_apg_settings', false ), 'eru_apg_keys' => get_option( 'eru_apg_keys', false ), 'eru_apg_usage' => get_option( 'eru_apg_usage', false ) );
$ids   = array();
$calls = array();
$mock  = function ( $pre, $args, $url ) use ( &$calls ) {
	$calls[] = array( 'url' => $url, 'args' => $args );
	if ( false !== strpos( $url, 'api.anthropic.com' ) ) {
		$text = wp_json_encode( array( 'title' => 'Demo: WordPress güvenliği için 5 ipucu', 'excerpt' => 'Kısa özet.', 'content' => '<h2>İpucu 1</h2><p>Güçlü parola.</p><script>alert(1)</script>' ), JSON_UNESCAPED_UNICODE );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => "```json\n" . $text . "\n```" ) ), 'usage' => array() ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( false !== strpos( $url, 'api.openai.com' ) ) {
		$text = wp_json_encode( array( 'title' => 'OpenAI Demo Başlık', 'content' => '<p>OpenAI gövde.</p>' ), JSON_UNESCAPED_UNICODE );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $text ) ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	if ( false !== strpos( $url, 'api.perplexity.ai' ) ) {
		$text = wp_json_encode( array( 'title' => 'Perplexity Demo', 'content' => '<p>PPLX gövde.</p>' ), JSON_UNESCAPED_UNICODE );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => $text ) ) ), 'citations' => array( 'https://example.com/kaynak', 'javascript:alert(1)' ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return new WP_Error( 'unexpected', 'unexpected host ' . $url );
};
add_filter( 'pre_http_request', $mock, 10, 3 );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$prev  = get_current_user_id();
$uids  = array();
try {
	wp_set_current_user( $admin->ID );
	delete_option( 'eru_apg_keys' );
	delete_option( 'eru_apg_usage' );
	update_option( 'eru_apg_settings', array( 'provider' => 'anthropic', 'max_tokens' => 1500, 'daily_cap' => 5, 'status' => 'draft' ), false );

	$r = Eru_APG_Generator::generate( array( 'topic' => 'WordPress güvenliği için 5 ipucu' ) );
	$check( 'no key -> WP_Error no_key', is_wp_error( $r ) && 'eru_apg_no_key' === $r->get_error_code() );
	$check( 'no key -> zero HTTP requests', 0 === count( $calls ) );

	Eru_APG_Settings::save_key( 'anthropic', 'sk-ant-demo-0000' );
	$check( 'key stored with autoload=no', 'no' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name=%s", 'eru_apg_keys' ) ) || 'off' === $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name=%s", 'eru_apg_keys' ) ) );
	$check( 'key masked for display', false === strpos( Eru_APG_Settings::masked( 'anthropic' ), 'sk-ant-demo' ) && '' !== Eru_APG_Settings::masked( 'anthropic' ) );

	$r = Eru_APG_Generator::generate( array( 'topic' => 'WordPress güvenliği için 5 ipucu', 'keywords' => 'güvenlik, parola' ) );
	$check( 'draft created', is_int( $r ) && $r > 0 );
	if ( is_int( $r ) ) {
		$ids[] = $r;
		$p = get_post( $r );
		$check( 'post is a draft', 'draft' === $p->post_status );
		$check( 'canned title used (fences tolerated)', 'Demo: WordPress güvenliği için 5 ipucu' === $p->post_title );
		$check( 'content sanitised with wp_kses_post', false === strpos( $p->post_content, '<script' ) && false !== strpos( $p->post_content, 'Güçlü parola' ) );
		$check( 'author is current user', (int) $p->post_author === $admin->ID );
	}
	$c = $calls[0] ?? array( 'url' => '', 'args' => array() );
	$check( 'request went to api.anthropic.com/v1/messages', 'https://api.anthropic.com/v1/messages' === $c['url'] );
	$check( 'x-api-key + anthropic-version headers', 'sk-ant-demo-0000' === ( $c['args']['headers']['x-api-key'] ?? '' ) && '2023-06-01' === ( $c['args']['headers']['anthropic-version'] ?? '' ) );
	$body = json_decode( $c['args']['body'] ?? '{}', true );
	$check( 'default model claude-sonnet-5-5 and max_tokens 1500', 'claude-sonnet-5-5' === ( $body['model'] ?? '' ) && 1500 === ( $body['max_tokens'] ?? 0 ) );

	// Key is bound to its provider: the anthropic key never goes to another host.
	$calls = array();
	update_option( 'eru_apg_settings', array( 'provider' => 'openai', 'status' => 'draft', 'daily_cap' => 5 ), false );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'x konusu' ) );
	$check( 'openai without own key -> no_key, no HTTP, anthropic key not reused', is_wp_error( $r ) && 'eru_apg_no_key' === $r->get_error_code() && 0 === count( $calls ) );
	Eru_APG_Settings::save_key( 'openai', 'sk-demo-1111' );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'x konusu' ) );
	$ids[] = is_int( $r ) ? $r : 0;
	$check( 'openai draft + bearer header only to api.openai.com', is_int( $r ) && 'https://api.openai.com/v1/chat/completions' === $calls[0]['url'] && 'Bearer sk-demo-1111' === $calls[0]['args']['headers']['Authorization'] && ! isset( $calls[0]['args']['headers']['x-api-key'] ) );
	$check( 'host binding rejects foreign url', is_wp_error( Eru_APG_Client::assert_host( 'openai', 'https://evil.example.net/v1/chat/completions' ) ) );

	$calls = array();
	Eru_APG_Settings::save_key( 'perplexity', 'pplx-demo-2222' );
	update_option( 'eru_apg_settings', array( 'provider' => 'perplexity', 'status' => 'draft', 'daily_cap' => 5 ), false );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'y konusu' ) );
	$ids[] = is_int( $r ) ? $r : 0;
	$check( 'perplexity draft; only https citations kept', is_int( $r ) && false !== strpos( get_post( $r )->post_content, 'https://example.com/kaynak' ) && false === strpos( get_post( $r )->post_content, 'javascript:' ) );

	// Daily cap.
	$calls = array();
	update_option( 'eru_apg_usage', array( 'date' => wp_date( 'Y-m-d' ), 'count' => 5 ), false );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'z konusu' ) );
	$check( 'daily cap reached -> error, no HTTP', is_wp_error( $r ) && 'eru_apg_cap' === $r->get_error_code() && 0 === count( $calls ) );

	// Capability.
	update_option( 'eru_apg_usage', array( 'date' => wp_date( 'Y-m-d' ), 'count' => 0 ), false );
	$sub = wp_insert_user( array( 'user_login' => 'eru_apg_demo', 'user_pass' => wp_generate_password(), 'user_email' => 'ornek-apg@example.com', 'role' => 'subscriber' ) );
	$uids[] = $sub;
	wp_set_current_user( $sub );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'w konusu' ) );
	$check( 'subscriber cannot generate', is_wp_error( $r ) && 'eru_apg_cap_denied' === $r->get_error_code() && 0 === count( $calls ) );
	wp_set_current_user( $admin->ID );
	$r = Eru_APG_Generator::generate( array( 'topic' => '   ' ) );
	$check( 'empty topic rejected, no HTTP', is_wp_error( $r ) && 0 === count( $calls ) );

	// Fix round 1: capability, generic errors, image byte cap.
	$contrib = wp_insert_user( array( 'user_login' => 'eru_apg_contrib', 'user_pass' => wp_generate_password(), 'user_email' => 'ornek-apg2@example.com', 'role' => 'contributor' ) );
	$uids[] = $contrib;
	update_option( 'eru_apg_usage', array( 'date' => wp_date( 'Y-m-d' ), 'count' => 0 ), false );
	update_option( 'eru_apg_settings', array( 'provider' => 'anthropic', 'status' => 'draft', 'daily_cap' => 5 ), false );
	$calls = array();
	wp_set_current_user( $contrib );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'katkı konusu' ) );
	$check( 'contributor cannot generate by default (publish_posts)', is_wp_error( $r ) && 'eru_apg_cap_denied' === $r->get_error_code() && 0 === count( $calls ) );
	$allow = function () { return 'edit_posts'; };
	add_filter( 'eru_apg_capability', $allow );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'katkı konusu' ) );
	$check( 'capability is filterable', is_int( $r ) );
	$ids[] = is_int( $r ) ? $r : 0;
	remove_filter( 'eru_apg_capability', $allow );
	wp_set_current_user( $admin->ID );
	$err_mock = function ( $pre, $args, $url ) {
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'error' => array( 'message' => 'Invalid key sk-ant-demo-0000 for org' ) ) ), 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $err_mock, 12, 3 );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'hata konusu' ) );
	remove_filter( 'pre_http_request', $err_mock, 12 );
	$check( '401 -> generic error without provider body or key fragment', is_wp_error( $r ) && false === strpos( $r->get_error_message(), 'sk-ant' ) && false === strpos( $r->get_error_message(), 'Invalid key' ) && false !== strpos( $r->get_error_message(), '401' ) );
	update_option( 'eru_apg_settings', array( 'provider' => 'openai', 'status' => 'draft', 'daily_cap' => 5 ), false );
	$tiny = function () { return 10; };
	add_filter( 'eru_apg_max_image_bytes', $tiny );
	$big_png = imagecreatetruecolor( 64, 64 );
	ob_start();
	imagepng( $big_png );
	$big_b64 = base64_encode( ob_get_clean() );
	$cap_mock = function ( $pre, $args, $url ) use ( $big_b64 ) {
		return false !== strpos( $url, '/images/generations' ) ? array( 'headers' => array(), 'body' => wp_json_encode( array( 'data' => array( array( 'b64_json' => $big_b64 ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null ) : $pre;
	};
	add_filter( 'pre_http_request', $cap_mock, 11, 3 );
	$img = Eru_APG_Client::image( 'x' );
	remove_filter( 'pre_http_request', $cap_mock, 11 );
	remove_filter( 'eru_apg_max_image_bytes', $tiny );
	$check( 'decoded image above byte cap is rejected', is_wp_error( $img ) );

	// Optional featured image: OpenAI b64_json only, generated PNG is local.
	$png = imagecreatetruecolor( 8, 8 );
	ob_start();
	imagepng( $png );
	$b64 = base64_encode( ob_get_clean() );
	imagedestroy( $png );
	$img_mock = function ( $pre, $args, $url ) use ( $b64 ) {
		if ( false !== strpos( $url, '/v1/images/generations' ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'data' => array( array( 'b64_json' => $b64 ) ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $img_mock, 11, 3 );
	update_option( 'eru_apg_usage', array( 'date' => wp_date( 'Y-m-d' ), 'count' => 0 ), false );
	update_option( 'eru_apg_settings', array( 'provider' => 'openai', 'status' => 'draft', 'daily_cap' => 5, 'image' => 1 ), false );
	$r = Eru_APG_Generator::generate( array( 'topic' => 'görsel konusu' ) );
	$ids[] = is_int( $r ) ? $r : 0;
	$thumb = is_int( $r ) ? get_post_thumbnail_id( $r ) : 0;
	$check( 'featured image attached from provider b64 response', $thumb > 0 );
	if ( $thumb ) {
		wp_delete_attachment( $thumb, true );
	}
	remove_filter( 'pre_http_request', $img_mock, 11 );
} finally {
	remove_filter( 'pre_http_request', $mock, 10 );
	foreach ( array_filter( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $uids as $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u );
	}
	wp_set_current_user( $prev );
	foreach ( $opts as $k => $v ) {
		false === $v ? delete_option( $k ) : update_option( $k, $v, false );
	}
}
echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
