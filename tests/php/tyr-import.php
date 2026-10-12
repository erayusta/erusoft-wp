<?php
/**
 * Trendyol Reviews — store import, background queue, URL/CSV modes,
 * seller filter, masking, structured data and settings, with mocked HTTP.
 *
 * Run against the demo site with the plugin active:
 *   wp eval-file tests/php/tyr-import.php
 * The Seller API product list and the public review endpoint are answered
 * from tests/fixtures/tyr/ through `pre_http_request`; any other outbound
 * request fails the test. Prints PASS/FAIL per assertion and exits non-zero
 * on any failure.
 */

$eru_tyr_fail  = 0;
$eru_tyr_check = function ( $label, $ok ) use ( &$eru_tyr_fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$eru_tyr_fail++;
	}
};

if ( ! class_exists( 'Eru_TYR_Store_Import' ) ) {
	echo "FAIL: class Eru_TYR_Store_Import missing (plugin not active?)\n";
	exit( 1 );
}

$eru_tyr_fixtures    = dirname( __DIR__ ) . '/fixtures/tyr/';
$eru_tyr_requests    = array();
$eru_tyr_unmocked    = array();
$eru_tyr_bad_headers = array();
$eru_tyr_total_pages = 1;
$eru_tyr_force_code  = 0; // Non-zero: Seller API answers with this status (e.g. a redirect).
$eru_tyr_seller_args = array();
$eru_tyr_empty       = '{"isSuccess":true,"result":{"summary":{"totalPages":1},"reviews":[]}}';
// Placeholder credentials for the mock (not real ones): key, then the other half.
list( $eru_tyr_fake_key, $eru_tyr_fake_secret ) = array( 'test-key', 'test-secret' );

$eru_tyr_mock = function ( $pre, $args, $url ) use ( $eru_tyr_fixtures, $eru_tyr_empty, $eru_tyr_fake_key, $eru_tyr_fake_secret, &$eru_tyr_requests, &$eru_tyr_unmocked, &$eru_tyr_bad_headers, &$eru_tyr_total_pages, &$eru_tyr_force_code, &$eru_tyr_seller_args ) {
	$eru_tyr_requests[] = $url;
	$headers            = (array) ( $args['headers'] ?? array() );
	$body               = null;
	if ( str_contains( $url, '/integration/product/sellers/123456/products' ) ) {
		$eru_tyr_seller_args = $args;
		if ( $eru_tyr_force_code ) {
			return array( 'headers' => array( 'location' => 'https://example.com/' ), 'body' => '', 'response' => array( 'code' => $eru_tyr_force_code, 'message' => 'Redirect' ), 'cookies' => array(), 'filename' => null );
		}
		if ( ( $headers['Authorization'] ?? '' ) !== 'Basic ' . base64_encode( $eru_tyr_fake_key . ':' . $eru_tyr_fake_secret ) ) {
			$eru_tyr_bad_headers[] = 'seller api auth';
			return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'cookies' => array(), 'filename' => null );
		}
		$data               = json_decode( file_get_contents( $eru_tyr_fixtures . 'seller-products.json' ), true );
		$data['totalPages'] = $eru_tyr_total_pages;
		$body               = wp_json_encode( $data );
	} elseif ( preg_match( '#review-read/product-reviews/detailed\?contentId=(\d+)&page=(\d+)#', $url, $m ) ) {
		if ( ! str_starts_with( (string) ( $headers['User-Agent'] ?? '' ), 'Trendyol Reviews for WooCommerce/' ) ) {
			$eru_tyr_bad_headers[] = 'review user-agent';
		}
		$file = 'reviews-' . $m[1] . ( '0' === $m[2] ? '' : '-p' . $m[2] ) . '.json';
		$body = is_readable( $eru_tyr_fixtures . $file ) ? file_get_contents( $eru_tyr_fixtures . $file ) : $eru_tyr_empty;
	}
	if ( null === $body ) {
		$eru_tyr_unmocked[] = $url;
		return new WP_Error( 'eru_tyr_test_unmocked', 'unmocked ' . $url );
	}
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => $body,
		'response' => array( 'code' => 200, 'message' => 'OK' ),
		'cookies'  => array(),
		'filename' => null,
	);
};

$eru_tyr_backup = array();
foreach ( array( 'eru_tyr_settings', 'eru_tyr_api_secret', 'eru_tyr_map', 'eru_tyr_unmatched', 'eru_tyr_log', 'eru_tyr_run' ) as $eru_tyr_opt ) {
	$eru_tyr_backup[ $eru_tyr_opt ] = get_option( $eru_tyr_opt, null );
}
$eru_tyr_ids = array();
$eru_tyr_as  = array();

/**
 * Client whose products template points at another host (simulates a
 * tampered or mistyped Seller API URL).
 */
class Eru_TYR_Test_Foreign_Client extends Eru_TYR_Client {
	const ENDPOINTS = array(
		'products' => 'https://seller-api.example.com/integration/product/sellers/%1$s/products?page=%2$d&size=%3$d',
		'reviews'  => parent::ENDPOINTS['reviews'],
	);
}

add_filter( 'pre_http_request', $eru_tyr_mock, 10, 3 );
add_filter( 'eru_tyr_request_delay', '__return_zero' );

$eru_tyr_process = function ( $hook ) use ( &$eru_tyr_as ) {
	$ids = as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending', 'group' => 'eru-tyr', 'per_page' => 100 ), 'ids' );
	foreach ( $ids as $id ) {
		$eru_tyr_as[] = $id;
		ActionScheduler::runner()->process_action( $id, 'eru-tyr-test' );
	}
	return $ids;
};

try {
	foreach ( array_keys( $eru_tyr_backup ) as $eru_tyr_opt ) {
		delete_option( $eru_tyr_opt );
	}

	// Settings: defaults and sanitising.
	$defaults = Eru_TYR_Settings::defaults();
	$eru_tyr_check( 'photos off by default', 0 === $defaults['import_photos'] );
	$eru_tyr_check( 'structured data off by default', 0 === $defaults['structured_data'] );
	$s = Eru_TYR_Settings::update( array( 'barcode_meta' => ' My_GTIN:x<b>-1 ' ) );
	$eru_tyr_check( 'barcode meta keeps case, strips other chars (got ' . $s['barcode_meta'] . ')', 'My_GTIN:x-1' === $s['barcode_meta'] );

	$no_creds = Eru_TYR_Store_Import::run( '123456' );
	$eru_tyr_check( 'missing API credentials -> error, no request', ! empty( $no_creds['error'] ) && ! $eru_tyr_requests );

	Eru_TYR_Settings::update( array( 'barcode_meta' => '_global_unique_id', 'seller_id' => '123456', 'api_key' => $eru_tyr_fake_key, 'import_photos' => 0, 'min_rating' => 1, 'max_reviews' => 100 ) );
	Eru_TYR_Settings::set_secret( $eru_tyr_fake_secret );
	global $wpdb;
	$eru_tyr_check( 'secret not in the settings array', ! isset( get_option( 'eru_tyr_settings' )['api_secret'] ) );
	$eru_tyr_check(
		'secret stored with autoload off',
		$eru_tyr_fake_secret === Eru_TYR_Settings::get_secret()
		&& in_array( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'eru_tyr_api_secret' ) ), array( 'no', 'off' ), true )
	);

	$eru_tyr_check( 'secret sanitising keeps printable chars, strips control chars + whitespace', 'a%b1<c>"d' === Eru_TYR_Settings::sanitize_secret( " a%b1<c>\"d\n\t\x00 " ) );

	$by_barcode = new WC_Product_Simple();
	$by_barcode->set_name( 'TYR Test Tişört' );
	$by_barcode->set_status( 'publish' );
	$by_barcode->set_reviews_allowed( true );
	$eru_tyr_ids['barcode'] = $by_barcode->save();
	update_post_meta( $eru_tyr_ids['barcode'], '_global_unique_id', '8680000000012' ); // The L variant's barcode.

	$by_sku = new WC_Product_Simple();
	$by_sku->set_name( 'TYR Test Kupa' );
	$by_sku->set_status( 'publish' );
	$by_sku->set_regular_price( '10' );
	$by_sku->set_sku( 'DM-KP-02' );
	$by_sku->set_reviews_allowed( true );
	$eru_tyr_ids['sku'] = $by_sku->save();

	$first = Eru_TYR_Store_Import::run( '123456' );
	$eru_tyr_check( 'archived listing ignored, variants grouped: 3 products (got ' . ( $first['products'] ?? '?' ) . ')', 3 === ( $first['products'] ?? null ) );
	$eru_tyr_check( 'first run imports 5 reviews incl. page 2 (got ' . ( $first['imported'] ?? '?' ) . ')', 5 === ( $first['imported'] ?? null ) );
	$eru_tyr_check( 'first run skips empty + other-seller review (got ' . ( $first['skipped'] ?? '?' ) . ')', 2 === ( $first['skipped'] ?? null ) );
	$eru_tyr_check( 'first run reports 1 unmatched product (got ' . ( $first['unmatched'] ?? '?' ) . ')', 1 === ( $first['unmatched'] ?? null ) );
	$eru_tyr_check( 'single page -> next_page null', array_key_exists( 'next_page', $first ) && null === $first['next_page'] );
	$eru_tyr_check( 'Seller API called with Basic auth, review endpoint with plugin User-Agent', ! $eru_tyr_bad_headers );
	$eru_tyr_check( 'no storefront listing or product-detail scraping', ! preg_grep( '#infinite-scroll|productDetail#', $eru_tyr_requests ) );

	$unmatched = (array) get_option( 'eru_tyr_unmatched', array() );
	$eru_tyr_check( 'unmatched list holds content 900003 only', isset( $unmatched[900003] ) && 1 === count( $unmatched ) );

	$reviews     = get_comments( array( 'post_id' => $eru_tyr_ids['barcode'], 'type' => 'review', 'status' => 'approve' ) );
	$reviews_sku = get_comments( array( 'post_id' => $eru_tyr_ids['sku'], 'type' => 'review', 'status' => 'approve' ) );
	$eru_tyr_check( 'barcode product got 3 reviews (two pages)', 3 === count( $reviews ) );
	$eru_tyr_check( 'sku product got 2 reviews', 2 === count( $reviews_sku ) );

	$authors = array();
	$ok_meta = true;
	foreach ( array_merge( $reviews, $reviews_sku ) as $c ) {
		$authors[ (int) get_comment_meta( $c->comment_ID, '_eru_tyr_review_id', true ) ] = $c->comment_author;
		$rating  = (int) get_comment_meta( $c->comment_ID, 'rating', true );
		$ok_meta = $ok_meta && $rating >= 1 && $rating <= 5 && 'review' === $c->comment_type && '' === $c->comment_author_email;
	}
	$eru_tyr_check( 'rating meta + review type on every comment', $ok_meta );
	$eru_tyr_check( 'review from another seller not imported', ! isset( $authors[70000005] ) );
	$eru_tyr_check( 'name masked: Ayşe Yılmaz -> A*** Y***', 'A*** Y***' === ( $authors[70000001] ?? '' ) );
	$eru_tyr_check( 'name masked: Can Öztürk -> C*** Ö***', 'C*** Ö***' === ( $authors[70000012] ?? '' ) );
	$eru_tyr_check( 'Turkish i: ilker şen -> İ*** Ş*** (got ' . ( $authors[70000004] ?? '' ) . ')', 'İ*** Ş***' === ( $authors[70000004] ?? '' ) );
	$eru_tyr_check( 'mask_name("ılgın") -> I***', 'I***' === Eru_TYR_Importer::mask_name( 'ılgın' ) );
	$eru_tyr_check( 'hidden name gets invented initials', (bool) preg_match( '/^\p{Lu}\*\*\* \p{Lu}\*\*\*$/u', $authors[70000002] ?? '' ) );
	$leak = false;
	foreach ( $authors as $a ) {
		foreach ( array( 'Ayşe', 'Yılmaz', 'Zeynep', 'Demir', 'Öztürk', 'ilker' ) as $r ) {
			$leak = $leak || str_contains( $a, $r );
		}
	}
	$eru_tyr_check( 'no raw reviewer name stored', ! $leak );

	$product = wc_get_product( $eru_tyr_ids['sku'] );
	$eru_tyr_check( 'WooCommerce review count updated', 2 === (int) $product->get_review_count() );

	// Structured data: imported reviews excluded by default, included when enabled.
	$markup   = array(
		'@type'           => 'Product',
		'aggregateRating' => array( '@type' => 'AggregateRating', 'ratingValue' => '3.5', 'reviewCount' => 2 ),
		'review'          => array( array( '@type' => 'Review' ), array( '@type' => 'Review' ) ),
	);
	$filtered = apply_filters( 'woocommerce_structured_data_product', $markup, $product );
	$eru_tyr_check( 'structured data: imported-only product has no aggregateRating/review', ! isset( $filtered['aggregateRating'] ) && empty( $filtered['review'] ) );
	$review_markup = apply_filters( 'woocommerce_structured_data_review', array( '@type' => 'Review' ), $reviews_sku[0] );
	$eru_tyr_check( 'structured data: single imported review markup dropped', empty( $review_markup['@type'] ) );
	Eru_TYR_Settings::update( array( 'structured_data' => 1 ) );
	$eru_tyr_check( 'structured data: kept when the setting is on', $markup === apply_filters( 'woocommerce_structured_data_product', $markup, $product ) );
	Eru_TYR_Settings::update( array( 'structured_data' => 0 ) );

	// Photos: Trendyol CDN only.
	$eru_tyr_check( 'photo host allow-list', Eru_TYR_Importer::photo_allowed( 'https://cdn.dsmcdn.com/ty1/x.jpg' ) && ! Eru_TYR_Importer::photo_allowed( 'https://example.com/x.jpg' ) && ! Eru_TYR_Importer::photo_allowed( 'https://cdn.dsmcdn.com.example.com/x.jpg' ) );

	$second = Eru_TYR_Store_Import::run( '123456' );
	$eru_tyr_check( 'second run imports 0 (dedupe)', 0 === ( $second['imported'] ?? null ) );
	$total = count( get_comments( array( 'post__in' => array_values( $eru_tyr_ids ), 'type' => 'review', 'status' => 'any' ) ) );
	$eru_tyr_check( 'still 5 reviews in total after second run', 5 === $total );

	$eru_tyr_total_pages = 2;
	$paged               = Eru_TYR_Store_Import::run( '123456', 0 );
	$eru_tyr_check( 'totalPages 2 -> next_page 1', 1 === ( $paged['next_page'] ?? null ) );
	$eru_tyr_total_pages = 1;

	// Seller API credentials are bound to https://apigw.trendyol.com.
	$eru_tyr_check( 'Seller API request does not follow redirects', 0 === ( $eru_tyr_seller_args['redirection'] ?? null ) );
	$eru_tyr_before = count( $eru_tyr_requests );
	$eru_tyr_foreign = ( new Eru_TYR_Test_Foreign_Client() )->get_seller_products_page( '123456', $eru_tyr_fake_key, $eru_tyr_fake_secret, 0 );
	$eru_tyr_check( 'products URL on another host -> WP_Error, zero HTTP calls', is_wp_error( $eru_tyr_foreign ) && 'eru_tyr_seller_host' === $eru_tyr_foreign->get_error_code() && count( $eru_tyr_requests ) === $eru_tyr_before );
	$eru_tyr_redirect_endpoint = function ( $template, $type ) {
		return 'products' === $type ? 'https://seller-api.example.com/sellers/%1$s/products?page=%2$d&size=%3$d' : $template;
	};
	add_filter( 'eru_tyr_endpoint', $eru_tyr_redirect_endpoint, 10, 2 );
	$eru_tyr_before   = count( $eru_tyr_requests );
	$eru_tyr_filtered = ( new Eru_TYR_Client() )->get_seller_products_page( '123456', $eru_tyr_fake_key, $eru_tyr_fake_secret, 0 );
	remove_filter( 'eru_tyr_endpoint', $eru_tyr_redirect_endpoint, 10 );
	$eru_tyr_new = array_slice( $eru_tyr_requests, $eru_tyr_before );
	$eru_tyr_check( 'eru_tyr_endpoint cannot move the products request off apigw.trendyol.com', ! is_wp_error( $eru_tyr_filtered ) && 1 === count( $eru_tyr_new ) && str_starts_with( $eru_tyr_new[0], 'https://apigw.trendyol.com/' ) );
	$eru_tyr_force_code = 302;
	$eru_tyr_redirect   = ( new Eru_TYR_Client() )->get_seller_products_page( '123456', $eru_tyr_fake_key, $eru_tyr_fake_secret, 0 );
	$eru_tyr_force_code = 0;
	$eru_tyr_check( 'Seller API 3xx -> WP_Error', is_wp_error( $eru_tyr_redirect ) && 'eru_tyr_seller_redirect' === $eru_tyr_redirect->get_error_code() );

	$bad = Eru_TYR_Store_Import::run( 'abc' );
	$eru_tyr_check( 'invalid seller ID -> no import, error reported', 0 === $bad['imported'] && null === $bad['next_page'] && ! empty( $bad['error'] ) );

	Eru_TYR_Matcher::set_mapping( 900003, $eru_tyr_ids['sku'] );
	$mapped = Eru_TYR_Store_Import::run( '123456' );
	$eru_tyr_check( 'after manual mapping 900003 is no longer unmatched', 0 === ( $mapped['unmatched'] ?? null ) && ! isset( get_option( 'eru_tyr_unmatched', array() )[900003] ) );

	// Background run: the page action lists + matches and queues one action per matched product.
	$started = Eru_TYR_Store_Import::start( '123456' );
	$pages   = $eru_tyr_process( 'eru_tyr_store_page' );
	$eru_tyr_check( 'start() queues exactly one page action', true === $started && 1 === count( $pages ) );
	$queued = as_get_scheduled_actions( array( 'hook' => 'eru_tyr_import_product', 'status' => 'pending', 'group' => 'eru-tyr', 'per_page' => 100 ) );
	$pairs  = array();
	foreach ( $queued as $eru_tyr_action ) {
		$pairs[] = array_slice( $eru_tyr_action->get_args(), 0, 2 );
	}
	sort( $pairs );
	$expect = array( array( $eru_tyr_ids['barcode'], 900001 ), array( $eru_tyr_ids['sku'], 900002 ), array( $eru_tyr_ids['sku'], 900003 ) );
	sort( $expect );
	$eru_tyr_check( 'page action queued one import per matched product', $expect === $pairs );
	$state = Eru_TYR_Store_Import::get_run();
	$eru_tyr_check( 'run is importing after the last page', 'importing' === ( $state['status'] ?? '' ) && 3 === ( $state['queued'] ?? 0 ) );
	$eru_tyr_process( 'eru_tyr_import_product' );
	$state = Eru_TYR_Store_Import::get_run();
	$eru_tyr_check( 'run done after product actions', 'done' === ( $state['status'] ?? '' ) && 3 === ( $state['processed'] ?? 0 ) && 0 === ( $state['imported'] ?? -1 ) );

	// Staleness: no progress for 30 minutes -> error, restart allowed.
	update_option( 'eru_tyr_run', array_merge( $state, array( 'status' => 'running', 'updated' => time() - 3600 ) ), false );
	$stale = Eru_TYR_Store_Import::get_run();
	$eru_tyr_check( 'stale run marked error with a message', 'error' === ( $stale['status'] ?? '' ) && '' !== ( $stale['message'] ?? '' ) );
	$eru_tyr_check( 'restart allowed after a stale run', true === Eru_TYR_Store_Import::start( '123456' ) && 'queued' === Eru_TYR_Store_Import::get_run()['status'] );
	as_unschedule_all_actions( '', array(), 'eru-tyr' );

	// URL mode: maps and imports one product (already imported -> 0 new).
	$url = Eru_TYR_Url_Import::import_url( 'https://www.trendyol.com/demo-marka/seramik-kupa-p-900002?boutiqueId=1', $eru_tyr_ids['sku'] );
	$eru_tyr_check( 'URL mode maps content 900002 and dedupes', ! is_wp_error( $url ) && 0 === $url['imported'] && Eru_TYR_Matcher::get_map()[900002] === $eru_tyr_ids['sku'] );
	$eru_tyr_check( 'URL mode rejects a non-Trendyol URL', is_wp_error( Eru_TYR_Url_Import::import_url( 'https://example.com/x-p-1', $eru_tyr_ids['sku'] ) ) );

	// CSV parsing: header ignored, semicolon delimiter, ID / SKU / URL identifiers, bad row reported.
	$csv = wp_tempnam( 'eru-tyr.csv' );
	file_put_contents( $csv, "urun;trendyol\n" . $eru_tyr_ids['barcode'] . ";https://www.trendyol.com/demo-marka/pamuklu-tisort-p-900001\nDM-KP-02;900002\nyok-boyle-urun;900003\n" );
	$parsed = Eru_TYR_Url_Import::parse_csv( $csv );
	unlink( $csv );
	$eru_tyr_check(
		'CSV: 2 valid rows, line 4 invalid',
		! is_wp_error( $parsed ) && 2 === count( $parsed['rows'] ) && array( 4 ) === $parsed['invalid']
		&& $parsed['rows'][0] === array( 'product_id' => $eru_tyr_ids['barcode'], 'content_id' => 900001 )
		&& $parsed['rows'][1] === array( 'product_id' => $eru_tyr_ids['sku'], 'content_id' => 900002 )
	);

	$eru_tyr_check( 'no unmocked outbound request', ! $eru_tyr_unmocked );
	if ( $eru_tyr_unmocked ) {
		echo '  unmocked: ' . implode( ', ', $eru_tyr_unmocked ) . "\n";
	}
} finally {
	remove_filter( 'pre_http_request', $eru_tyr_mock, 10 );
	as_unschedule_all_actions( '', array(), 'eru-tyr' );
	foreach ( array_unique( array_merge( $eru_tyr_as, as_get_scheduled_actions( array( 'group' => 'eru-tyr', 'status' => '', 'per_page' => 200 ), 'ids' ) ) ) as $eru_tyr_action ) {
		try {
			ActionScheduler::store()->delete_action( $eru_tyr_action );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}
	foreach ( $eru_tyr_ids as $eru_tyr_id ) {
		wp_delete_post( $eru_tyr_id, true );
	}
	foreach ( $eru_tyr_backup as $eru_tyr_opt => $eru_tyr_val ) {
		if ( null === $eru_tyr_val ) {
			delete_option( $eru_tyr_opt );
		} else {
			update_option( $eru_tyr_opt, $eru_tyr_val, false );
		}
	}
}

echo $eru_tyr_fail ? "RESULT: FAIL ({$eru_tyr_fail})\n" : "RESULT: PASS\n";
exit( $eru_tyr_fail ? 1 : 0 );
