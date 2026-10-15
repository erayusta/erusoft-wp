<?php
/**
 * Product Push for Trendyol — client and push flow: credentials filter / empty credentials, exact float in
 * the request body, routing by approval state (create / approved content+variant / unapproved update),
 * idempotency after an ambiguous create, batch result mapping per barcode, approval refresh, poll schedule.
 * Every HTTP call is answered by the router below from invented fixtures; anything else exits 1.
 *
 *   wp plugin activate product-push-for-trendyol
 *   wp eval-file tests/php/ppt-api.php
 */

$check = function ( $label, $ok, $extra = '' ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

// Invented credentials, generated per run (never literals).
$demo_key    = 'k' . wp_generate_password( 12, false );
$demo_secret = 's' . wp_generate_password( 16, false );
$f_key       = 'k' . wp_generate_password( 12, false );
$f_secret    = 's' . wp_generate_password( 16, false );

foreach ( array( 'Eru_PPT_Settings', 'Eru_PPT_Client', 'Eru_PPT_Builder', 'Eru_PPT_Pusher', 'Eru_PPT_Poller', 'Eru_PPT_Cron', 'Eru_PPT_Log' ) as $eru_ppt_class ) {
	if ( ! class_exists( $eru_ppt_class ) ) {
		echo "FAIL: {$eru_ppt_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

// ---------------------------------------------------------------------------------------------
// HTTP router. State is set by the test: which barcodes Trendyol "has" approved / unapproved,
// how create answers, what a batch returns.
// ---------------------------------------------------------------------------------------------
$reqs     = array();
$approved = array();   // barcode => contentId
$pending  = array();   // barcodes in the unapproved list
$create   = 'ok';      // ok | timeout | 500 | 400
$lookup   = 'ok';      // ok | 500
$batches  = array();   // batchRequestId => response
$seq      = 0;
$on_poll  = null;     // callable run once when a batch status is requested (interleaving)
$router   = function ( $pre, $args, $url ) use ( &$reqs, &$approved, &$pending, &$create, &$lookup, &$batches, &$seq, &$on_poll ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( wp_parse_url( home_url(), PHP_URL_HOST ) === $host && false !== strpos( $url, 'wp-cron.php' ) ) {
		return new WP_Error( 'eru_ppt_test', 'loopback cron blocked in tests' );
	}
	$method = strtoupper( $args['method'] ?? 'GET' );
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	$reqs[] = array(
		'method' => $method,
		'url'    => $url,
		'path'   => $path,
		'auth'   => $args['headers']['Authorization'] ?? '',
		'ua'     => $args['headers']['User-Agent'] ?? '',
		'body'   => (string) ( $args['body'] ?? '' ),
	);
	$json = fn( $data, $code = 200 ) => array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	if ( ! in_array( $host, array( 'apigw.trendyol.com', 'stageapigw.trendyol.com' ), true ) ) {
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	}
	if ( preg_match( '#^/integration/sellers/(\d+)/addresses$#', $path ) ) {
		return $json( array( 'supplierAddresses' => array( array( 'id' => 31, 'addressType' => 'Shipment', 'fullAddress' => 'Demo Sokak 1, Çankaya / Ankara', 'isDefault' => true ) ) ) );
	}
	if ( '/integration/product/product-categories' === $path ) {
		return $json( array( 'categories' => array( array( 'id' => 6000, 'name' => 'Demo Ev', 'subCategories' => array( array( 'id' => 6001, 'name' => 'Demo Kupa', 'subCategories' => array() ) ) ) ) ) );
	}
	if ( '/integration/product/categories/6001/attributes' === $path ) {
		return $json(
			array(
				'id'                 => 6001,
				'categoryAttributes' => array(
					array( 'allowCustom' => false, 'attribute' => array( 'id' => 47, 'name' => 'Renk' ), 'attributeValues' => array( array( 'id' => 701, 'name' => 'Siyah' ) ), 'required' => true, 'varianter' => false ),
					array( 'allowCustom' => false, 'attribute' => array( 'id' => 338, 'name' => 'Boyut' ), 'attributeValues' => array( array( 'id' => 801, 'name' => 'S' ), array( 'id' => 802, 'name' => 'M' ) ), 'required' => false, 'varianter' => true ),
				),
			)
		);
	}
	if ( preg_match( '#^/integration/product/sellers/\d+/products/(approved|unapproved)$#', $path, $m ) ) {
		if ( 'ok' !== $lookup ) {
			return $json( array( 'message' => 'Demo sunucu hatası' ), 500 );
		}
		$bc      = (string) ( $q['barcode'] ?? '' );
		$content = array();
		if ( 'approved' === $m[1] && isset( $approved[ $bc ] ) ) {
			$content[] = array( 'contentId' => $approved[ $bc ], 'productMainId' => 'X', 'variants' => array( array( 'barcode' => $bc, 'onSale' => true ) ) );
		}
		if ( 'unapproved' === $m[1] && in_array( $bc, $pending, true ) ) {
			$content[] = array( 'barcode' => $bc, 'productMainId' => 'X', 'status' => 'pending' );
		}
		// A sloppy filter answer (other barcode) must not count as a match.
		$content[] = array( 'contentId' => 1, 'variants' => array( array( 'barcode' => 'DEMO-BASKA-' . $bc ) ) );
		return $json( array( 'content' => $content, 'page' => 0, 'size' => 50, 'totalElements' => count( $content ), 'totalPages' => 1 ) );
	}
	if ( 'POST' === $method && preg_match( '#^/integration/product/sellers/\d+/(v2/products|products/content-bulk-update|products/variant-bulk-update|products/unapproved-bulk-update)$#', $path, $m ) ) {
		if ( 'v2/products' === $m[1] ) {
			if ( 'timeout' === $create ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 40001 milliseconds' );
			}
			if ( '500' === $create ) {
				return $json( array( 'message' => 'Demo geçici hata' ), 500 );
			}
			if ( '400' === $create ) {
				return $json( array( 'errors' => array( array( 'message' => 'Demo doğrulama hatası' ) ) ), 400 );
			}
		}
		++$seq;
		return $json( array( 'batchRequestId' => 'demo-batch-' . $seq ) );
	}
	if ( preg_match( '#^/integration/product/sellers/\d+/products/batch-requests/([A-Za-z0-9-]+)$#', $path, $m ) ) {
		if ( $on_poll ) {
			$fn      = $on_poll;
			$on_poll = null;
			$fn( $m[1] );
		}
		return $json( $batches[ $m[1] ] ?? array( 'batchRequestId' => $m[1], 'status' => 'IN_PROGRESS', 'items' => array() ) );
	}
	echo 'FAIL unmocked HTTP ' . $method . ' ' . $url . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $router, 10, 3 );

$hits = function ( $needle, $method = 'POST' ) use ( &$reqs ) {
	return array_values( array_filter( $reqs, fn( $r ) => $r['method'] === $method && false !== strpos( $r['path'], $needle ) ) );
};

// ---------------------------------------------------------------------------------------------
// Clean leftovers, fixtures.
// ---------------------------------------------------------------------------------------------
$cleanup = function () {
	foreach ( get_posts( array( 'post_type' => array( 'product', 'product_variation' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_eru_ppt_test', 'meta_value' => 'api' ) ) as $id ) { // phpcs:ignore
		$p = wc_get_product( $id );
		if ( $p ) {
			$p->delete( true );
		}
	}
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_eru_ppt_test', 'meta_value' => 'api' ) ) as $id ) { // phpcs:ignore
		wp_delete_attachment( $id, true );
	}
	$t = get_term_by( 'slug', 'demo-ppt-api', 'product_cat' );
	if ( $t ) {
		wp_delete_term( $t->term_id, 'product_cat' );
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'eru_ppt_push', 'eru_ppt_poll' ) as $h ) {
			as_unschedule_all_actions( $h, null, 'eru-ppt' );
		}
	}
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'eru_ppt_batch_' ) . '%' ) ); // phpcs:ignore
	wp_cache_flush();
	foreach ( array( 'eru_ppt_settings', 'eru_ppt_batches', 'eru_ppt_log', 'eru_ppt_notice', 'eru_ppt_lock_push', 'eru_ppt_lock_poll' ) as $o ) {
		delete_option( $o );
	}
	Eru_PPT_Categories::flush();
	Eru_PPT_Settings::flush();
};
$cleanup();

$cat = wp_insert_term( 'Demo PPT API', 'product_cat', array( 'slug' => 'demo-ppt-api' ) )['term_id'];

// ---------------------------------------------------------------------------------------------
// (g) Credentials: empty → no HTTP and a clear error; filter overrides settings.
// ---------------------------------------------------------------------------------------------
Eru_PPT_Settings::update( array( 'enabled' => 'yes' ) );
$n = count( $reqs );
$r = Eru_PPT_Client::request( 'GET', '/sellers/{sellerId}/addresses' );
$check( '(g) empty credentials → no HTTP', count( $reqs ) === $n );
$check( '(g) empty credentials → clear Turkish error', ! $r['ok'] && false !== mb_stripos( $r['error'], 'API bilgileri eksik' ), $r['error'] );

Eru_PPT_Settings::update(
	array(
		'seller_id'         => '100001',
		'api_key'           => $demo_key,
		'api_secret'        => $demo_secret,
		'integration_ref'   => 'SelfIntegration',
		'brand_id'          => 900,
		'cargo_company_id'  => 11,
		'category_map'      => array( array( 'wc' => $cat, 'ty' => 6001, 'name' => 'Demo Ev > Demo Kupa' ) ),
		'barcode_source'    => 'sku',
	)
);
Eru_PPT_Settings::update( array( 'enabled' => 'no' ) );
$n = count( $reqs );
$r = Eru_PPT_Client::request( 'GET', '/sellers/{sellerId}/addresses' );
$check( '(g) master switch off → no HTTP', count( $reqs ) === $n && ! $r['ok'] );
Eru_PPT_Settings::update( array( 'enabled' => 'yes' ) );

$r    = Eru_PPT_Client::request( 'GET', '/sellers/{sellerId}/addresses' );
$last = end( $reqs );
$check( '(g) settings credentials used: seller id in path, Basic auth, seller User-Agent', $r['ok'] && false !== strpos( $last['url'], '/sellers/100001/addresses' ) && 'Basic ' . base64_encode( $demo_key . ':' . $demo_secret ) === $last['auth'] && '100001 - SelfIntegration' === $last['ua'] );
$check( '(g) credentials never in the query string', false === strpos( $last['url'], $demo_key ) && false === strpos( $last['url'], $demo_secret ) );

$filter = fn( $c ) => array_merge( $c, array( 'seller_id' => '100002', 'api_key' => $f_key, 'api_secret' => $f_secret ) );
add_filter( 'eru_ppt_credentials', $filter );
Eru_PPT_Client::request( 'GET', '/sellers/{sellerId}/addresses' );
$last = end( $reqs );
remove_filter( 'eru_ppt_credentials', $filter );
$check( '(g) eru_ppt_credentials filter overrides the settings', false !== strpos( $last['url'], '/sellers/100002/' ) && 'Basic ' . base64_encode( $f_key . ':' . $f_secret ) === $last['auth'] );
$empty = fn( $c ) => array_merge( $c, array( 'api_secret' => '' ) );
add_filter( 'eru_ppt_credentials', $empty );
$n = count( $reqs );
$r = Eru_PPT_Client::request( 'GET', '/sellers/{sellerId}/addresses' );
remove_filter( 'eru_ppt_credentials', $empty );
$check( '(g) filter emptying a credential → no HTTP', count( $reqs ) === $n && ! $r['ok'] );

Eru_PPT_Settings::update( array( 'environment' => 'stage' ) );
Eru_PPT_Client::request( 'GET', '/sellers/{sellerId}/addresses' );
$last = end( $reqs );
$check( '(g) stage environment uses the stage base URL', 'stageapigw.trendyol.com' === wp_parse_url( $last['url'], PHP_URL_HOST ) );
Eru_PPT_Settings::update( array( 'environment' => 'live' ) );
$san = Eru_PPT_Settings::sanitize( array( 'base_live' => 'https://api.example.com/integration', 'base_stage' => 'https://stageapigw.trendyol.com/integration' ) );
$check( '(g) a non-trendyol.com API host is refused on save (credentials never leave for it)', Eru_PPT_Settings::BASE_LIVE === $san['values']['base_live'] && 1 === count( $san['errors'] ) && 'https://stageapigw.trendyol.com/integration' === $san['values']['base_stage'] );

// ---------------------------------------------------------------------------------------------
// Fixtures for the push flow.
// ---------------------------------------------------------------------------------------------
$img = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'demo-ppt-api', 'post_status' => 'inherit' ), 'demo-ppt-api.jpg' );
update_post_meta( $img, '_eru_ppt_test', 'api' );
add_filter( 'wp_get_attachment_url', fn( $u ) => preg_replace( '#^http://#', 'https://', (string) $u ) );
$simple = function ( $sku, $price = '100' ) use ( $img, $cat ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Demo Ürün ' . $sku );
	$p->set_sku( $sku );
	$p->set_regular_price( $price );
	$p->set_image_id( $img );
	$p->set_category_ids( array( $cat ) );
	$p->set_description( 'Demo açıklama.' );
	$p->update_meta_data( '_eru_ppt_test', 'api' );
	$p->update_meta_data( '_eru_ppt_attributes', array( 47 => array( 'v' => 701 ) ) );
	$p->save();
	return $p->get_id();
};
$meta = fn( $id, $k ) => wc_get_product( $id )->get_meta( '_eru_ppt_' . $k );
$set  = function ( $id, array $kv ) {
	$o = wc_get_product( $id );
	foreach ( $kv as $k => $v ) {
		$o->update_meta_data( '_eru_ppt_' . $k, $v );
	}
	$o->save_meta_data();
};
$sfx = strtoupper( wp_generate_password( 4, false ) );

// ---------------------------------------------------------------------------------------------
// (c) Exact float in the real request body.
// ---------------------------------------------------------------------------------------------
$a = $simple( 'DEMO-A-' . $sfx, '194.35' );
ini_set( 'serialize_precision', '17' ); // phpcs:ignore WordPress.PHP.IniSet
$res = Eru_PPT_Pusher::push( array( $a ) );
$check( '(c) serialize_precision restored after the request', '17' === ini_get( 'serialize_precision' ) );
ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet
$c = $hits( '/v2/products' );
$check( '(d) new product → POST …/v2/products (create)', 1 === count( $c ) && false !== strpos( $c[0]['path'], '/product/sellers/100001/v2/products' ), wp_json_encode( $res ) );
$check( '(c) request body carries 194.35 exactly', false !== strpos( $c[0]['body'], '"salePrice":194.35' ) && false === strpos( $c[0]['body'], '194.3499' ) );
$check( '(d) new product: no listing lookup before the first create', 0 === count( $hits( '/products/approved', 'GET' ) ) );
$check( '(d) created item is pending on its batch', 'pending' === $meta( $a, 'status' ) && 'demo-batch-1' === $meta( $a, 'batch' ) );
$stored = Eru_PPT_Pusher::batches();
$check( '(e) batch stored with barcode → object map', isset( $stored['demo-batch-1']['items'][ 'DEMO-A-' . $sfx ] ) && 'create' === $stored['demo-batch-1']['op'] );
$log = wp_json_encode( get_option( 'eru_ppt_log' ) );
$check( '(g) request log has no secret or Authorization header', false === strpos( $log, $demo_secret ) && false === strpos( $log, base64_encode( $demo_key . ':' . $demo_secret ) ) );

// Pending → a second push sends nothing (no duplicate create while the batch is open).
$reqs = array();
$res  = Eru_PPT_Pusher::push( array( $a ) );
$check( '(d) pending item is not sent again', 0 === count( $hits( '/v2/products' ) ) && ! empty( $res['skipped'][ $a ] ), wp_json_encode( $res ) );

// ---------------------------------------------------------------------------------------------
// (d) Routing by approval state.
// ---------------------------------------------------------------------------------------------
$b  = $simple( 'DEMO-B-' . $sfx );
$cc = $simple( 'DEMO-C-' . $sfx );
$set( $b, array( 'remote' => 'approved', 'status' => 'success' ) );
$set( $cc, array( 'remote' => 'unapproved', 'status' => 'success' ) );
$approved[ 'DEMO-B-' . $sfx ] = 9001;
$pending[]                    = 'DEMO-C-' . $sfx;
$reqs                         = array();
$res                          = Eru_PPT_Pusher::push( array( $b, $cc ) );
$content = $hits( '/products/content-bulk-update' );
$variant = $hits( '/products/variant-bulk-update' );
$unappr  = $hits( '/products/unapproved-bulk-update' );
$check( '(d) approved → content-bulk-update + variant-bulk-update', 1 === count( $content ) && 1 === count( $variant ), wp_json_encode( $res ) );
$check( '(d) unapproved → unapproved-bulk-update', 1 === count( $unappr ) );
$check( '(d) no create call for known products', 0 === count( $hits( '/v2/products' ) ) );
$cb = json_decode( $content[0]['body'], true );
$vb = json_decode( $variant[0]['body'], true );
$ub = json_decode( $unappr[0]['body'], true );
$check( '(d) content update carries the contentId from the approved listing', 9001 === ( $cb['items'][0]['contentId'] ?? 0 ) && 1 === count( $cb['items'] ) );
$check( '(d) variant update keyed by barcode', 'DEMO-B-' . $sfx === ( $vb['items'][0]['barcode'] ?? '' ) );
$check( '(d) unapproved update keyed by barcode, only the unapproved product', 'DEMO-C-' . $sfx === ( $ub['items'][0]['barcode'] ?? '' ) && 1 === count( $ub['items'] ) );
$check( '(d) updates never carry price or quantity (that is stock/price sync)', ! isset( $vb['items'][0]['salePrice'] ) && ! isset( $ub['items'][0]['quantity'] ) && ! isset( $ub['items'][0]['listPrice'] ) );
$check( '(d) stored content id', 9001 === (int) $meta( $b, 'content_id' ) );

// Stored "approved" but the listing now says unapproved → routed by the refreshed state.
$set( $b, array( 'remote' => 'approved', 'status' => 'success' ) );
unset( $approved[ 'DEMO-B-' . $sfx ] );
$pending[] = 'DEMO-B-' . $sfx;
$reqs      = array();
Eru_PPT_Pusher::push( array( $b ) );
$check( '(d) state refreshed from the listings before routing', 1 === count( $hits( '/products/unapproved-bulk-update' ) ) && 0 === count( $hits( '/products/content-bulk-update' ) ) && 'unapproved' === $meta( $b, 'remote' ) );

// Batch ≤ 100 items per request.
$check( '(d) batch size is at most 100', Eru_PPT_Pusher::BATCH <= 100 );

// ---------------------------------------------------------------------------------------------
// Idempotency after an ambiguous create.
// ---------------------------------------------------------------------------------------------
$d      = $simple( 'DEMO-D-' . $sfx );
$create = 'timeout';
$reqs   = array();
Eru_PPT_Pusher::push( array( $d ) );
$check( '(d) timeout on create → remote "unknown", not pending', 'unknown' === $meta( $d, 'remote' ) && 'failed' === $meta( $d, 'status' ) );
$create = 'ok';
$reqs   = array();
$res    = Eru_PPT_Pusher::push( array( $d ) );
$check( '(d) right after an ambiguous create: lookup, not found yet → no re-create', 2 === count( $hits( '/products/', 'GET' ) ) && 0 === count( $hits( '/v2/products' ) ) && 'unknown' === $meta( $d, 'remote' ), wp_json_encode( $res ) );
$pending[] = 'DEMO-D-' . $sfx;
$reqs      = array();
Eru_PPT_Pusher::push( array( $d ) );
$check( '(d) ambiguous create later found unapproved → unapproved update, never a 2nd create', 0 === count( $hits( '/v2/products' ) ) && 1 === count( $hits( '/products/unapproved-bulk-update' ) ) && 'unapproved' === $meta( $d, 'remote' ) );

$e      = $simple( 'DEMO-E-' . $sfx );
$create = '500';
Eru_PPT_Pusher::push( array( $e ) );
$check( '(d) HTTP 500 on create is ambiguous too', 'unknown' === $meta( $e, 'remote' ) );
$create = 'ok';
$lookup = '500';
$reqs   = array();
Eru_PPT_Pusher::push( array( $e ) );
$check( '(d) ambiguous + lookup error → needs_review, nothing sent', 'needs_review' === $meta( $e, 'status' ) && 0 === count( $hits( '/v2/products' ) ) );
$lookup = 'ok';
$reqs   = array();
Eru_PPT_Pusher::push( array( $e ) );
$check( '(d) needs_review stays put until reset (no HTTP)', 0 === count( $reqs ) && 'needs_review' === $meta( $e, 'status' ) );
Eru_PPT_Pusher::reset( $e );
$check( '(d) reset turns needs_review back into "unknown" (lookup first next time)', 'unknown' === $meta( $e, 'remote' ) && '' === $meta( $e, 'status' ) );
$set( $e, array( 'attempt_at' => time() - 2 * HOUR_IN_SECONDS ) );
$reqs = array();
Eru_PPT_Pusher::push( array( $e ) );
$check( '(d) explicit not-found in both listings after the wait → create once', 1 === count( $hits( '/v2/products' ) ) && 'pending' === $meta( $e, 'status' ) );

$f      = $simple( 'DEMO-F-' . $sfx );
$create = '400';
Eru_PPT_Pusher::push( array( $f ) );
$check( '(d) definite 4xx on create → failed, remote stays empty (safe to retry)', 'failed' === $meta( $f, 'status' ) && '' === $meta( $f, 'remote' ) && false !== strpos( implode( ' ', (array) $meta( $f, 'errors' ) ), 'Demo doğrulama hatası' ) );
$create = 'ok';

// Concurrency: a held push lock refuses a second push.
Eru_PPT_Lock::acquire( 'push' );
$reqs = array();
$res  = Eru_PPT_Pusher::push( array( $f ) );
Eru_PPT_Lock::release( 'push' );
$check( '(d) a held push lock refuses a parallel push', 0 === count( $reqs ) && ! empty( $res['locked'] ) );

// Validation errors stop the product before any HTTP.
$g = $simple( 'DEMO-G-' . $sfx, '0' );
$reqs = array();
$res  = Eru_PPT_Pusher::push( array( $g ) );
$check( '(d) invalid product: nothing sent, errors stored', 0 === count( $hits( '/v2/products' ) ) && 'invalid' === $meta( $g, 'status' ) && ! empty( $res['errors'][ $g ] ) );

// ---------------------------------------------------------------------------------------------
// (e) Batch result: one success + one failure → only the failing variation gets errors.
// ---------------------------------------------------------------------------------------------
$var = new WC_Product_Variable();
$var->set_name( 'Demo Varyantlı' );
$var->set_sku( 'DEMO-V-' . $sfx );
$var->set_image_id( $img );
$var->set_category_ids( array( $cat ) );
$var->set_description( 'Demo açıklama.' );
$var->update_meta_data( '_eru_ppt_test', 'api' );
$var->update_meta_data( '_eru_ppt_attributes', array( 47 => array( 'v' => 701 ) ) );
$wa = new WC_Product_Attribute();
$wa->set_name( 'Demo Boyut' );
$wa->set_options( array( 'S', 'M' ) );
$wa->set_visible( true );
$wa->set_variation( true );
$var->set_attributes( array( $wa ) );
$var->save();
$vids = array();
foreach ( array( 'S', 'M' ) as $size ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var->get_id() );
	$v->set_attributes( array( 'demo-boyut' => $size ) );
	$v->set_sku( 'DEMO-V-' . $sfx . '-' . $size );
	$v->set_regular_price( '150' );
	$v->update_meta_data( '_eru_ppt_test', 'api' );
	$v->save();
	$vids[ $size ] = $v->get_id();
}
WC_Product_Variable::sync( $var->get_id() );
$reqs = array();
Eru_PPT_Pusher::push( array( $var->get_id() ) );
$cr   = $hits( '/v2/products' );
$body = json_decode( $cr[0]['body'] ?? '{}', true );
$check( '(b) variable push: one create with 2 items sharing productMainId', 1 === count( $cr ) && 2 === count( $body['items'] ?? array() ) && 1 === count( array_unique( array_column( $body['items'], 'productMainId' ) ) ) );
$batch = $meta( $vids['S'], 'batch' );
$check( '(e) both variations pending on the same batch', '' !== $batch && $batch === $meta( $vids['M'], 'batch' ) && 'pending' === $meta( $vids['M'], 'status' ) );

$batches[ $batch ] = array(
	'batchRequestId' => $batch,
	'status'         => 'COMPLETED',
	'items'          => array(
		array( 'requestItem' => array( 'product' => array( 'barcode' => 'DEMO-V-' . $sfx . '-S' ) ), 'status' => 'SUCCESS', 'failureReasons' => array() ),
		array( 'requestItem' => array( 'barcode' => 'DEMO-V-' . $sfx . '-M' ), 'status' => 'FAILED', 'failureReasons' => array( 'Demo hata: görsel çözünürlüğü düşük', array( 'message' => 'Demo hata: <b>etiket</b>' ) ) ),
	),
);
Eru_PPT_Poller::run();
$se = $meta( $vids['S'], 'errors' );
$me = (array) $meta( $vids['M'], 'errors' );
$check( '(e) successful variation: success, no errors, remote unapproved', 'success' === $meta( $vids['S'], 'status' ) && empty( $se ) && 'unapproved' === $meta( $vids['S'], 'remote' ) );
$check( '(e) failing variation alone gets _eru_ppt_errors', 'failed' === $meta( $vids['M'], 'status' ) && 2 === count( $me ) && 'Demo hata: görsel çözünürlüğü düşük' === $me[0] && '' === $meta( $vids['M'], 'remote' ) );
$check( '(e) parent product carries no item errors', empty( $meta( $var->get_id(), 'errors' ) ) );
$check( '(e) completed batch removed from the queue', ! isset( Eru_PPT_Pusher::batches()[ $batch ] ) );
$notice = get_option( 'eru_ppt_notice' );
$check( '(e) admin notice summary stored (1 ok, 1 failed)', is_array( $notice ) && 1 === (int) $notice['ok'] && 1 === (int) $notice['failed'] );

// An in-progress batch stays queued.
$batch_a = $meta( $a, 'batch' );
$check( '(e) in-progress batch stays queued, item still pending', isset( Eru_PPT_Pusher::batches()[ $batch_a ] ) && 'pending' === $meta( $a, 'status' ) );

// Approval refresh: an unapproved object found in the approved list becomes approved.
$approved[ 'DEMO-V-' . $sfx . '-S' ] = 9100;
$set( $vids['S'], array( 'checked_at' => 0 ) );
Eru_PPT_Poller::run();
$check( '(e) poll refreshes approval (unapproved → approved, content id kept)', 'approved' === $meta( $vids['S'], 'remote' ) && 9100 === (int) $meta( $vids['S'], 'content_id' ) );

// Lost-update guard: a push that opens a new batch while the poller is between reading the open
// batches and writing its results must not be dropped.
$hh      = $simple( 'DEMO-H-' . $sfx );
$hit     = array();
$batches[ $batch_a ] = array(
	'batchRequestId' => $batch_a,
	'status'         => 'COMPLETED',
	'items'          => array( array( 'requestItem' => array( 'product' => array( 'barcode' => 'DEMO-A-' . $sfx ) ), 'status' => 'SUCCESS', 'failureReasons' => array() ) ),
);
$on_poll = function ( $polled ) use ( $hh, &$hit ) {
	$hit[] = $polled;
	Eru_PPT_Pusher::push( array( $hh ) ); // Runs inside the poller's HTTP call: between its read and its save.
};
Eru_PPT_Poller::run();
$batch_h = $meta( $hh, 'batch' );
$open    = Eru_PPT_Pusher::batches();
$check( '(e) interleaving push ran during the poll', ! empty( $hit ) && '' !== $batch_h );
$check( '(e) batch opened during the poll survives the poller\'s save', isset( $open[ $batch_h ] ) && 'pending' === $meta( $hh, 'status' ), wp_json_encode( array_keys( $open ) ) );
$check( '(e) the poller still removed the batch it completed', ! isset( $open[ $batch_a ] ) && 'success' === $meta( $a, 'status' ) );
$reqs = array();
Eru_PPT_Pusher::push( array( $hh ) );
$check( '(e) the surviving batch keeps its item from being re-sent', 0 === count( $hits( '/v2/products' ) ) );

// A held poll lock: the run does nothing.
Eru_PPT_Lock::acquire( 'poll' );
$reqs = array();
Eru_PPT_Poller::run();
Eru_PPT_Lock::release( 'poll' );
$check( '(e) poll takes a lock', 0 === count( $reqs ) );

// ---------------------------------------------------------------------------------------------
// Poll schedule: recurring every 10 min while enabled, none while off; pending-only re-arm.
// ---------------------------------------------------------------------------------------------
$pend = fn() => as_get_scheduled_actions( array( 'hook' => 'eru_ppt_poll', 'group' => 'eru-ppt', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
as_unschedule_all_actions( 'eru_ppt_poll', null, 'eru-ppt' );
Eru_PPT_Cron::sync();
$ids = $pend();
$check( '(e) enabled → one recurring poll action', 1 === count( $ids ) );
$action = ActionScheduler::store()->fetch_action( $ids[0] );
$check( '(e) poll interval is 10 minutes', 600 === (int) $action->get_schedule()->get_recurrence() );
Eru_PPT_Cron::sync();
$check( '(e) sync is idempotent', 1 === count( $pend() ) );
ActionScheduler::store()->log_execution( $ids[0] ); // Simulate the recurring action running right now.
Eru_PPT_Cron::sync();
$check( '(e) running poll action is not duplicated by a sync (pending-only check + running guard)', 0 === count( $pend() ) );
ActionScheduler::store()->mark_complete( $ids[0] );
Eru_PPT_Cron::sync();
$check( '(e) after completion a sync re-arms exactly one', 1 === count( $pend() ) );
Eru_PPT_Settings::update( array( 'enabled' => 'no' ) );
$check( '(e) disabled → no poll action', 0 === count( $pend() ) );

$cleanup();
remove_filter( 'pre_http_request', $router, 10 );
echo "ALL PASS\n";
