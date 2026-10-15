<?php
/**
 * Product Push for Trendyol — category data in page requests: cache-only validation with a background
 * refresh job, negative caching of failed fetches, and no caching of partial attribute value lists.
 * Every HTTP call is answered by the router below from invented fixtures; anything else exits 1.
 *
 *   wp plugin activate product-push-for-trendyol
 *   wp eval-file tests/php/ppt-cache.php
 */

$check = function ( $label, $ok, $extra = '' ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
};

foreach ( array( 'Eru_PPT_Settings', 'Eru_PPT_Categories', 'Eru_PPT_Builder', 'Eru_PPT_Bulk' ) as $eru_ppt_class ) {
	if ( ! class_exists( $eru_ppt_class ) ) {
		echo "FAIL: {$eru_ppt_class} missing (plugin not active?)\n";
		exit( 1 );
	}
}

$demo_key    = 'k' . wp_generate_password( 12, false );
$demo_secret = 's' . wp_generate_password( 16, false );

$reqs       = array();
$tree_mode  = 'ok';    // ok | fail
$value_mode = 'ok';    // ok (2 pages) | fail (page 1 fails) | huge (99 pages)
$router     = function ( $pre, $args, $url ) use ( &$reqs, &$tree_mode, &$value_mode ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( wp_parse_url( home_url(), PHP_URL_HOST ) === $host && false !== strpos( $url, 'wp-cron.php' ) ) {
		return new WP_Error( 'eru_ppt_test', 'loopback cron blocked in tests' );
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	$json = fn( $data, $code = 200 ) => array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	if ( 'apigw.trendyol.com' !== $host ) {
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	}
	$reqs[] = $path;
	if ( '/integration/product/product-categories' === $path ) {
		if ( 'fail' === $tree_mode ) {
			return $json( array( 'message' => 'Demo bakım' ), 503 );
		}
		return $json( array( 'categories' => array( array( 'id' => 7000, 'name' => 'Demo Ev', 'subCategories' => array( array( 'id' => 7001, 'name' => 'Demo Havlu', 'subCategories' => array() ) ) ) ) ) );
	}
	if ( '/integration/product/categories/7001/attributes' === $path ) {
		return $json(
			array(
				'categoryAttributes' => array(
					array( 'allowCustom' => false, 'attribute' => array( 'id' => 47, 'name' => 'Renk' ), 'attributeValues' => array(), 'required' => true, 'varianter' => false ),
				),
			)
		);
	}
	if ( '/integration/product/categories/7001/attributes/47/values' === $path ) {
		$pg = (int) ( $q['page'] ?? 0 );
		if ( 'fail' === $value_mode && 1 === $pg ) {
			return $json( array( 'message' => 'Demo geçici hata' ), 500 );
		}
		$total = 'huge' === $value_mode ? 99 : 2;
		return $json(
			array(
				'content'    => array( array( 'attributeValueId' => 7100 + $pg, 'attributeValue' => 'Demo Renk ' . $pg ) ),
				'page'       => $pg,
				'totalPages' => $total,
			)
		);
	}
	echo 'FAIL unmocked HTTP ' . $url . "\n";
	exit( 1 );
};
add_filter( 'pre_http_request', $router, 10, 3 );

$cleanup = function () {
	foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_eru_ppt_test', 'meta_value' => 'cache' ) ) as $id ) { // phpcs:ignore
		wc_get_product( $id )->delete( true );
	}
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_eru_ppt_test', 'meta_value' => 'cache' ) ) as $id ) { // phpcs:ignore
		wp_delete_attachment( $id, true );
	}
	$t = get_term_by( 'slug', 'demo-ppt-cache', 'product_cat' );
	if ( $t ) {
		wp_delete_term( $t->term_id, 'product_cat' );
	}
	as_unschedule_all_actions( 'eru_ppt_refresh', null, 'eru-ppt' );
	as_unschedule_all_actions( 'eru_ppt_poll', null, 'eru-ppt' );
	delete_option( 'eru_ppt_settings' );
	delete_option( 'eru_ppt_log' );
	Eru_PPT_Categories::flush();
	Eru_PPT_Settings::flush();
};
$cleanup();

$cat = wp_insert_term( 'Demo PPT Cache', 'product_cat', array( 'slug' => 'demo-ppt-cache' ) )['term_id'];
Eru_PPT_Settings::update(
	array(
		'enabled'          => 'yes',
		'seller_id'        => '100001',
		'api_key'          => $demo_key,
		'api_secret'       => $demo_secret,
		'brand_id'         => 900,
		'cargo_company_id' => 11,
		'category_map'     => array( array( 'wc' => $cat, 'ty' => 7001, 'name' => 'Demo Ev > Demo Havlu' ) ),
	)
);
add_filter( 'wp_get_attachment_url', fn( $u ) => preg_replace( '#^http://#', 'https://', (string) $u ) );
$img = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'demo-ppt-cache', 'post_status' => 'inherit' ), 'demo-ppt-cache.jpg' );
update_post_meta( $img, '_eru_ppt_test', 'cache' );
$p = new WC_Product_Simple();
$p->set_name( 'Demo Havlu' );
$p->set_sku( 'DEMO-HV-' . strtoupper( wp_generate_password( 4, false ) ) );
$p->set_regular_price( '120' );
$p->set_description( 'Demo açıklama.' );
$p->set_image_id( $img );
$p->set_category_ids( array( $cat ) );
$p->update_meta_data( '_eru_ppt_test', 'cache' );
$p->update_meta_data( '_eru_ppt_attributes', array( 47 => array( 'v' => 7101 ) ) );
$p->save();
$pid     = $p->get_id();
$refresh = fn() => as_get_scheduled_actions( array( 'hook' => 'eru_ppt_refresh', 'group' => 'eru-ppt', 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
$has     = function ( array $errors, $needle ) {
	foreach ( $errors as $e ) {
		if ( false !== mb_stripos( $e, $needle ) ) {
			return true;
		}
	}
	return false;
};

// ---------------------------------------------------------------------------------------------
// Page requests are cache-only: a miss queues a refresh job and says "loading".
// ---------------------------------------------------------------------------------------------
$reqs = array();
$r    = Eru_PPT_Builder::build( wc_get_product( $pid ), false );
$check( 'cache-only build on an empty cache makes no HTTP call', 0 === count( $reqs ) );
$check( 'cache-only build reports "kategori verisi yükleniyor"', $has( $r['errors'], 'yükleniyor' ), implode( ' | ', $r['errors'] ) );
$check( 'a refresh job is queued once (Action Scheduler)', 1 === count( $refresh() ) );
Eru_PPT_Builder::build( wc_get_product( $pid ), false );
$check( 'a second miss does not queue a duplicate job', 1 === count( $refresh() ) );

wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'bulk-posts' );
$url                  = Eru_PPT_Bulk::handle( 'edit.php?post_type=product', 'eru_ppt_validate', array( $pid ) );
unset( $_REQUEST['_wpnonce'] );
$res = get_transient( 'eru_ppt_bulk_' . get_current_user_id() );
delete_transient( 'eru_ppt_bulk_' . get_current_user_id() );
$check( 'bulk validate is cache-only (no HTTP, "yükleniyor" shown)', 0 === count( $reqs ) && $has( (array) ( $res['rows'][0]['errors'] ?? array() ), 'yükleniyor' ) );

$args = ActionScheduler::store()->fetch_action( $refresh()[0] )->get_args();
do_action_ref_array( 'eru_ppt_refresh', array_values( $args ) );
$check( 'the refresh job fetches tree, attributes and all value pages', in_array( '/integration/product/product-categories', $reqs, true ) && 2 === count( array_filter( $reqs, fn( $x ) => false !== strpos( $x, '/values' ) ) ) );
as_unschedule_all_actions( 'eru_ppt_refresh', null, 'eru-ppt' );
$reqs = array();
$r    = Eru_PPT_Builder::build( wc_get_product( $pid ), false );
$check( 'after the refresh, cache-only validation passes without HTTP', array() === $r['errors'] && 0 === count( $reqs ), implode( ' | ', $r['errors'] ) );

// ---------------------------------------------------------------------------------------------
// Failed fetches are negatively cached (no repeated calls during an outage).
// ---------------------------------------------------------------------------------------------
Eru_PPT_Categories::flush();
$tree_mode = 'fail';
$reqs      = array();
$t1        = Eru_PPT_Categories::tree();
$t2        = Eru_PPT_Categories::tree();
$r         = Eru_PPT_Builder::build( wc_get_product( $pid ) );
$check( 'tree failure → WP_Error, then served from the negative cache (1 HTTP call for 3 lookups)', is_wp_error( $t1 ) && is_wp_error( $t2 ) && 1 === count( $reqs ) && ! empty( $r['errors'] ), count( $reqs ) . ' calls' );
do_action( 'eru_ppt_refresh', 7001 );
$check( 'the refresh job also respects the negative cache', 1 === count( $reqs ) );
as_unschedule_all_actions( 'eru_ppt_refresh', null, 'eru-ppt' );
$r = Eru_PPT_Builder::build( wc_get_product( $pid ), false );
$check( 'cache-only + stored failure → the real error, not "yükleniyor"', ! $has( $r['errors'], 'yükleniyor' ) && $has( $r['errors'], 'Demo bakım' ) && 1 === count( $reqs ), implode( ' | ', $r['errors'] ) );
$check( '… and no refresh job is queued while the failure is cached', 0 === count( $refresh() ) );
$tree_mode = 'ok';
Eru_PPT_Categories::flush();
$check( 'the refresh button (flush) clears the negative cache', is_array( Eru_PPT_Categories::tree() ) && 2 === count( $reqs ) );

// ---------------------------------------------------------------------------------------------
// Partial value lists are never cached as complete.
// ---------------------------------------------------------------------------------------------
$value_mode = 'fail';
$reqs       = array();
$a          = Eru_PPT_Categories::attributes( 7001 );
$check( 'a failed value page → WP_Error', is_wp_error( $a ) );
$check( '… and nothing cached for the category', false === get_transient( 'eru_ppt_attr_live_7001' ) );
$n = count( $reqs );
$check( '… and the failure is negatively cached (no immediate retry)', is_wp_error( Eru_PPT_Categories::attributes( 7001 ) ) && count( $reqs ) === $n );
$a2 = Eru_PPT_Categories::attributes( 7001, false, false );
$check( 'cache-only attributes return the stored failure, not "loading"', is_wp_error( $a2 ) && 'eru_ppt_loading' !== $a2->get_error_code() && false !== strpos( $a2->get_error_message(), 'Demo geçici hata' ) && count( $reqs ) === $n && 0 === count( $refresh() ) );
Eru_PPT_Categories::flush();
Eru_PPT_Categories::tree();

$value_mode = 'huge';
$a          = Eru_PPT_Categories::attributes( 7001 );
$check( 'hitting the page cap caches the list flagged incomplete', is_array( $a ) && false === $a[0]['complete'] && is_array( get_transient( 'eru_ppt_attr_live_7001' ) ) );
$p->update_meta_data( '_eru_ppt_attributes', array( 47 => array( 'v' => 7999 ) ) );
$p->save_meta_data();
$r = Eru_PPT_Builder::build( wc_get_product( $pid ) );
$check( 'a value missing from an incomplete list is not called invalid', ! $has( $r['errors'], 'geçersiz değer' ), implode( ' | ', $r['errors'] ) );
$check( '… but it gets a non-blocking warning', $has( (array) ( $r['warnings'] ?? array() ), 'doğrulanamadı' ) && ! $has( $r['errors'], 'doğrulanamadı' ), wp_json_encode( $r['warnings'] ?? null ) );

Eru_PPT_Categories::flush();
Eru_PPT_Categories::tree();
$value_mode = 'ok';
$a          = Eru_PPT_Categories::attributes( 7001 );
$check( 'a fully paged list is cached as complete', is_array( $a ) && true === $a[0]['complete'] && 2 === count( $a[0]['values'] ) );
$r = Eru_PPT_Builder::build( wc_get_product( $pid ) );
$check( 'with a complete list, an unknown value is invalid', $has( $r['errors'], 'geçersiz değer' ), implode( ' | ', $r['errors'] ) );

$cleanup();
remove_filter( 'pre_http_request', $router, 10 );
echo "ALL PASS\n";
