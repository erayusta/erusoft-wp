<?php
/**
 * Conversion Boosters — threshold, gap suggestions, newsletter coupon, timed cart coupon, clean-up.
 *
 *   wp eval-file tests/php/cb-boosters.php
 */

$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_CB_Settings' ) ) {
	echo "FAIL: Eru_CB_Settings missing (plugin not active?)\n";
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
			return new WP_Error( 'eru_cb_test', 'cron spawn blocked' );
		}
		echo 'FAIL unmocked HTTP ' . $url . "\n";
		exit( 1 );
	},
	10,
	3
);
add_filter( 'wc_tax_enabled', '__return_false' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

global $wpdb;
$purge = function () use ( $wpdb ) {
	// Leftovers of an aborted run: test coupons (prefix CBTEST), subscribers, zones, products.
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'cbtest%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( get_posts( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'any', 'numberposts' => -1, 's' => 'cbt-' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	foreach ( WC_Shipping_Zones::get_zones() as $z ) {
		if ( 'ERU CB TEST' === $z['zone_name'] ) {
			WC_Shipping_Zones::get_zone( $z['id'] )->delete();
		}
	}
	foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE 'ERU-CB-%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'name' => array( 'Demo CB Kategori', 'Demo CB Diger' ) ) ) as $t ) {
		wp_delete_term( $t->term_id, 'product_cat' );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_eru\_cb\_%' OR option_name LIKE '\_transient\_timeout\_eru\_cb\_%'" );
};
$purge();
delete_option( 'eru_cb_has_data' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( 'eru_cb_daily', 'eru_cb_cleanup_more', 'eru_cb_purge_more' ) as $eru_cb_h ) {
		as_unschedule_all_actions( $eru_cb_h, array(), 'eru-cb' );
	}
}

$old_settings = get_option( Eru_CB_Settings::OPTION, null );
$cart_reset   = function () {
	wc_load_cart();
	WC()->cart->empty_cart();
	WC()->cart->remove_coupons();
	WC()->session->set( 'eru_cb_timed_coupon', null );
	WC()->session->set( 'eru_cb_timed_cooldown', null );
};
$mk_product   = function ( $name, $price, $cat, $stock = 'instock' ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( 'ERU-CB-' . sanitize_title( $name ) );
	$p->set_regular_price( (string) $price );
	$p->set_stock_status( $stock );
	$p->set_category_ids( (array) $cat );
	$p->set_status( 'publish' );
	$p->save();
	return $p->get_id();
};

try {
	delete_option( Eru_CB_Settings::OPTION );
	$cart_reset();

	// (a) Threshold from the customer's zone free_shipping method.
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'ERU CB TEST' );
	$zone->set_zone_order( -5 );
	$zone->add_location( 'TR', 'country' );
	$zone->save();
	$zone->add_shipping_method( 'flat_rate' );
	$free_iid = $zone->add_shipping_method( 'free_shipping' );
	update_option( 'woocommerce_free_shipping_' . $free_iid . '_settings', array( 'title' => 'Demo', 'requires' => 'min_amount', 'min_amount' => '900', 'ignore_discounts' => 'no' ) );
	WC()->customer->set_shipping_country( 'TR' );
	WC()->customer->set_billing_country( 'TR' );
	Eru_CB_Settings::save_for_test( array( 'ship_threshold_mode' => 'zone', 'ship_threshold_manual' => '' ) );
	$check( 'zone mode: threshold from free_shipping min_amount = 900', 900.0 === Eru_CB_Shipping::threshold() );
	Eru_CB_Settings::save_for_test( array( 'ship_threshold_mode' => 'manual', 'ship_threshold_manual' => '1250' ) );
	$check( 'manual mode overrides the zone value', 1250.0 === Eru_CB_Shipping::threshold() );
	Eru_CB_Settings::save_for_test( array( 'ship_threshold_mode' => 'manual', 'ship_threshold_manual' => '' ) );
	$check( 'manual mode with an empty value: no threshold', null === Eru_CB_Shipping::threshold() );
	Eru_CB_Settings::save_for_test( array( 'ship_threshold_mode' => 'zone' ) );

	// (b) Gap suggestions.
	$cat_a = wp_insert_term( 'Demo CB Kategori', 'product_cat' )['term_id'];
	$cat_b = wp_insert_term( 'Demo CB Diger', 'product_cat' )['term_id'];
	$p_cart  = $mk_product( 'Sepet Urunu', 700, array( $cat_a, $cat_b ) );
	$p_in    = $mk_product( 'Aralikta Bir', 250, $cat_a );
	$p_edge  = $mk_product( 'Aralikta Iki', 340, $cat_b );
	$p_low   = $mk_product( 'Cok Ucuz', 150, $cat_a );
	$p_high  = $mk_product( 'Cok Pahali', 400, $cat_a );
	$p_oos   = $mk_product( 'Stokta Yok', 260, $cat_a, 'outofstock' );
	$p_excl  = $mk_product( 'Haric Tutulan', 270, $cat_a );
	$p_inex  = $mk_product( 'Haric Kategori', 280, $cat_b );
	Eru_CB_Settings::save_for_test(
		array(
			'ship_enabled'        => 'yes',
			'ship_gap_extra'      => '150',
			'ship_gap_count'      => '5',
			'ship_exclude_products' => array( $p_excl ),
			'ship_exclude_cats'   => array(),
		)
	);
	WC()->cart->add_to_cart( $p_cart );
	WC()->cart->calculate_totals();
	$check( 'cart subtotal 700 -> gap 200', 200.0 === round( Eru_CB_Shipping::gap(), 2 ) );
	Eru_CB_Shipping::flush_cache();
	$ids = array_map( fn( $p ) => $p->get_id(), Eru_CB_Shipping::suggestions() );
	sort( $ids );
	$check( 'suggestions only priced 200-350, in stock, not in cart, not excluded', array_values( array( $p_in, $p_edge, $p_inex ) ) === array_values( array_intersect( array( $p_in, $p_edge, $p_inex ), $ids ) ) && ! array_intersect( $ids, array( $p_cart, $p_low, $p_high, $p_oos, $p_excl ) ) );
	$check( 'exactly the three matching products', 3 === count( $ids ) );
	Eru_CB_Settings::save_for_test( array( 'ship_exclude_cats' => array( $cat_b ) ) );
	Eru_CB_Shipping::flush_cache();
	$ids = array_map( fn( $p ) => $p->get_id(), Eru_CB_Shipping::suggestions() );
	$check( 'excluded category is left out', ! in_array( $p_edge, $ids, true ) && ! in_array( $p_inex, $ids, true ) && in_array( $p_in, $ids, true ) );
	Eru_CB_Settings::save_for_test( array( 'ship_exclude_cats' => array(), 'ship_gap_count' => '1' ) );
	Eru_CB_Shipping::flush_cache();
	$check( 'count limit honoured', 1 === count( Eru_CB_Shipping::suggestions() ) );
	Eru_CB_Settings::save_for_test( array( 'ship_gap_extra' => '0', 'ship_gap_count' => '3' ) );
	$check( 'max_extra 0 = suggestions off', array() === Eru_CB_Shipping::suggestions() );

	// Bar markup.
	Eru_CB_Settings::save_for_test( array( 'ship_gap_extra' => '150' ) );
	$html = Eru_CB_Shipping::bar_html( true );
	$check( 'bar shows progress and remaining amount', false !== strpos( $html, 'eru-cb-bar__fill' ) && false !== strpos( $html, wc_price( 200 ) ) );

	// (i) All modules off -> no front-end output.
	$cart_reset();
	WC()->cart->add_to_cart( $p_cart );
	delete_option( Eru_CB_Settings::OPTION );
	ob_start();
	do_action( 'woocommerce_before_cart' );
	do_action( 'woocommerce_after_cart' );
	do_action( 'woocommerce_before_checkout_form', WC()->checkout() );
	$off_html = ob_get_clean();
	$check( 'all modules off: no plugin output on cart/checkout hooks', false === strpos( $off_html, 'eru-cb' ) );
	$check( 'all modules off: shortcode is empty', '' === trim( do_shortcode( '[eru_free_shipping_bar]' ) ) );
	$check( 'all modules off: no daily job scheduled', ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'eru_cb_daily', null, 'eru-cb' ) );

	// (c) Newsletter without consent.
	$count_subs = fn() => (int) ( new WP_Query( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 's' => 'cbt-' ) ) )->found_posts;
	$count_coupons = fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'cbtest%'" );
	Eru_CB_Settings::save_for_test( array( 'nl_enabled' => 'yes', 'nl_prefix' => 'CBTEST', 'nl_amount' => '8', 'nl_type' => 'percent', 'nl_days' => '30', 'nl_confirm_hours' => '48', 'global_hourly' => '60' ) );
	$check( 'default newsletter prefix is neutral', 'BULTEN' === Eru_CB_Settings::defaults()['nl_prefix'] );
	$clear_rl = function () {
		delete_transient( Eru_CB_Newsletter::rl_key() );
		delete_transient( Eru_CB_Newsletter::global_key() );
		delete_transient( Eru_CB_Timed::global_key() );
	};
	$clear_rl();
	$r = Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-a1@example.com', 'consent' => '', 'source' => 'test' ) );
	$check( 'no consent: refused', false === $r['ok'] && 'consent' === $r['code'] );
	$check( 'no consent: nothing stored', 0 === $count_subs() );
	$r = Eru_CB_Newsletter::subscribe( array( 'email' => 'not-an-email', 'consent' => '1', 'source' => 'test' ) );
	$check( 'invalid e-mail refused', 'email' === $r['code'] && 0 === $count_subs() );
	$r = Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-a1@example.com', 'consent' => '1', 'hp' => 'bot', 'source' => 'test' ) );
	$check( 'honeypot filled: refused, nothing stored', false === $r['ok'] && 0 === $count_subs() );
	$check( 'same_origin: missing headers fail, own host passes, foreign host fails', ! Eru_CB_Coupons::same_origin() && ( $_SERVER['HTTP_ORIGIN'] = home_url() ) && Eru_CB_Coupons::same_origin() && ( $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.org' ) && ! Eru_CB_Coupons::same_origin() );
	unset( $_SERVER['HTTP_ORIGIN'] );

	// (d) Double opt-in: signup -> pending + confirmation mail, no coupon, no code in the response.
	$clear_rl();
	$mails = array();
	$r     = Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-a1@example.com', 'consent' => '1', 'source' => 'footer' ) );
	$check( 'signup accepted as pending', true === $r['ok'] && 'pending' === $r['code'] );
	$check( 'response carries no coupon code', ! isset( $r['coupon'] ) && false === stripos( wp_json_encode( $r ), 'CBTEST' ) );
	$subs = get_posts( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'any', 's' => 'cbt-a1@example.com', 'numberposts' => 5 ) );
	$check( 'pending subscriber stored', 1 === count( $subs ) && 'cbt-a1@example.com' === $subs[0]->post_title );
	$sid = $subs[0]->ID;
	$check( 'status pending, consent_at still empty, source/ip-hash/consent hash/text stored, no raw ip', 'pending' === get_post_meta( $sid, '_eru_cb_status', true ) && '' === (string) get_post_meta( $sid, '_eru_cb_consent_at', true ) && 'footer' === get_post_meta( $sid, '_eru_cb_source', true ) && 64 === strlen( (string) get_post_meta( $sid, '_eru_cb_consent_hash', true ) ) && '' !== get_post_meta( $sid, '_eru_cb_consent_text', true ) && 64 === strlen( (string) get_post_meta( $sid, '_eru_cb_ip_hash', true ) ) && false === strpos( (string) get_post_meta( $sid, '_eru_cb_ip_hash', true ), '127.0.0' ) );
	$check( 'no coupon exists before confirmation', 0 === $count_coupons() );
	$check( 'one confirmation mail with a link and no coupon', 1 === count( $mails ) && 'cbt-a1@example.com' === ( (array) $mails[0]['to'] )[0] && false !== strpos( $mails[0]['message'], 'eru_cb_confirm=' ) && false === stripos( $mails[0]['message'], 'CBTEST' ) );
	preg_match( '/eru_cb_confirm=(\d+)\.([A-Za-z0-9]+)/', $mails[0]['message'], $m );
	$token = $m[2] ?? '';
	$check( 'link carries the subscriber id and a token that is not stored in clear', (int) $m[1] === $sid && strlen( $token ) >= 24 && $token !== get_post_meta( $sid, '_eru_cb_token', true ) );
	$again = Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-a1@example.com', 'consent' => '1', 'source' => 'footer' ) );
	$check( 'repeat while pending: no second mail, no second row', 'pending' === $again['code'] && false === $again['ok'] && 1 === count( $mails ) && 1 === $count_subs() );

	// Token checks.
	$bad = Eru_CB_Newsletter::confirm( $sid, 'wrong-token' );
	$check( 'invalid token: refused, still pending, no coupon', 'invalid' === $bad['code'] && 'pending' === get_post_meta( $sid, '_eru_cb_status', true ) && 0 === $count_coupons() );
	update_post_meta( $sid, '_eru_cb_token_expires', time() - 10 );
	$exp = Eru_CB_Newsletter::confirm( $sid, $token );
	$check( 'expired token: refused, still pending, no coupon', 'expired' === $exp['code'] && 'pending' === get_post_meta( $sid, '_eru_cb_status', true ) && 0 === $count_coupons() );
	update_post_meta( $sid, '_eru_cb_token_expires', time() + 3600 );
	$mails = array();
	$ok    = Eru_CB_Newsletter::confirm( $sid, $token );
	$check( 'valid token confirms', true === $ok['ok'] && 'ok' === $ok['code'] && 0 === strpos( $ok['coupon'], 'CBTEST' ) && 12 === strlen( $ok['coupon'] ) );
	$check( 'confirmed: status, consent_at set', 'confirmed' === get_post_meta( $sid, '_eru_cb_status', true ) && '' !== (string) get_post_meta( $sid, '_eru_cb_consent_at', true ) );
	$cid = wc_get_coupon_id_by_code( $ok['coupon'] );
	$c   = new WC_Coupon( $cid );
	$exp = $c->get_date_expires() ? $c->get_date_expires()->getTimestamp() : 0;
	$check( 'coupon: usage_limit 1, date_expires about +30 days, e-mail restricted', $cid > 0 && 1 === $c->get_usage_limit() && abs( $exp - ( time() + 30 * DAY_IN_SECONDS ) ) < 120 && array( 'cbt-a1@example.com' ) === $c->get_email_restrictions() );
	$check( 'coupon: percent 8 and generated flag', 'percent' === $c->get_discount_type() && 8.0 === (float) $c->get_amount() && '1' === (string) $c->get_meta( '_eru_cb_generated' ) );
	$check( 'welcome mail with the code sent after confirmation', 1 === count( $mails ) && false !== strpos( $mails[0]['message'], $ok['coupon'] ) );
	$check( 'token is single use', 'invalid' === Eru_CB_Newsletter::confirm( $sid, $token )['code'] && 1 === $count_coupons() );

	$clear_rl();
	// Duplicates: same address, case, and +tag all map to one subscriber.
	foreach ( array( 'cbt-A1@example.com', 'cbt-a1+promo@example.com', 'cbt-a1+other@example.com' ) as $variant ) {
		$dup = Eru_CB_Newsletter::subscribe( array( 'email' => $variant, 'consent' => '1', 'source' => 'footer' ) );
		$check( "duplicate '$variant' refused (zaten abonesiniz)", false === $dup['ok'] && 'exists' === $dup['code'] );
	}
	$check( 'duplicates created no second subscriber or coupon', 1 === $count_subs() && 1 === $count_coupons() );
	$check( 'e-mail key strips +tag and lower-cases', 'cbt-a1@example.com' === Eru_CB_Newsletter::email_key( 'cbt-A1+Promo@example.com' ) );

	// Consent filter: confirmed only.
	Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-b1@example.com', 'consent' => '1', 'source' => 'form' ) );
	$bid = get_posts( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'any', 's' => 'cbt-b1@example.com', 'fields' => 'ids' ) )[0];
	$check( 'consent filter: true for confirmed, false for pending and unknown', true === apply_filters( 'eru_gra_has_marketing_consent', false, 'cbt-a1@example.com' ) && false === apply_filters( 'eru_gra_has_marketing_consent', false, 'cbt-b1@example.com' ) && false === apply_filters( 'eru_gra_has_marketing_consent', false, 'cbt-zz@example.com' ) );

	// Unconfirmed rows are purged after expiry; confirmed ones stay.
	update_post_meta( $bid, '_eru_cb_token_expires', time() - 10 );
	Eru_CB_Newsletter::purge();
	$check( 'expired pending purged, confirmed kept', null === get_post( $bid ) && null !== get_post( $sid ) );

	// Fixed existing coupon mode.
	$fx = new WC_Coupon();
	$fx->set_code( 'cbtest-fixed' );
	$fx->set_amount( '5' );
	$fx->save();
	Eru_CB_Settings::save_for_test( array( 'nl_mode' => 'fixed', 'nl_fixed_code' => 'cbtest-fixed' ) );
	$clear_rl();
	$mails = array();
	Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-a2@example.com', 'consent' => '1', 'source' => 'form' ) );
	preg_match( '/eru_cb_confirm=(\d+)\.([A-Za-z0-9]+)/', $mails[0]['message'], $m2 );
	$r3 = Eru_CB_Newsletter::confirm( (int) $m2[1], $m2[2] );
	$check( 'fixed mode returns the chosen coupon, generates none', true === $r3['ok'] && 'cbtest-fixed' === strtolower( $r3['coupon'] ) && 2 === $count_coupons() );
	Eru_CB_Settings::save_for_test( array( 'nl_mode' => 'generated' ) );

	// Privacy: exporter lists coupons; eraser removes unused generated coupons and strips used ones.
	$ex    = Eru_CB_Privacy::export( 'cbt-a1@example.com' );
	$flat  = wp_json_encode( $ex['data'] );
	$check( 'export lists the subscriber and the generated coupon', count( $ex['data'] ) >= 2 && false !== strpos( $flat, $ok['coupon'] ) && false !== strpos( $flat, 'confirmed' ) );
	$er = Eru_CB_Privacy::erase( 'cbt-a1@example.com' );
	$check( 'erase removes the subscriber and the unused generated coupon', true === $er['items_removed'] );
	$check( 'subscriber a1 gone', null === get_post( $sid ) );
	$check( 'unused generated coupon of a1 deleted', 0 === wc_get_coupon_id_by_code( $ok['coupon'] ) );
	$ucoupon = new WC_Coupon();
	$ucoupon->set_code( 'cbtest-usedone' );
	$ucoupon->set_amount( '5' );
	$ucoupon->set_email_restrictions( array( 'cbt-a3@example.com' ) );
	$ucoupon->set_usage_count( 1 );
	$ucoupon->set_used_by( array( 'cbt-a3@example.com' ) );
	$ucoupon->add_meta_data( '_eru_cb_generated', 1, true );
	$ucid = $ucoupon->save();
	$usub = wp_insert_post( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'publish', 'post_title' => 'cbt-a3@example.com' ) );
	update_post_meta( $usub, '_eru_cb_status', 'confirmed' );
	update_post_meta( $usub, '_eru_cb_coupon', 'CBTEST-USEDONE' );
	Eru_CB_Privacy::erase( 'cbt-a3@example.com' );
	$after = new WC_Coupon( $ucid );
	$check( 'used coupon kept but e-mail restriction and used-by e-mail stripped', 'publish' === get_post_status( $ucid ) && array() === $after->get_email_restrictions() && false === strpos( wp_json_encode( get_post_meta( $ucid ) ), 'cbt-a3@' ) );

	// (g) Rate limit: 5 per hour per IP hash.
	$clear_rl();
	$okc = 0;
	for ( $i = 1; $i <= 5; $i++ ) {
		$x    = Eru_CB_Newsletter::subscribe( array( 'email' => "cbt-rl{$i}@example.com", 'consent' => '1', 'source' => 'rl' ) );
		$okc += $x['ok'] ? 1 : 0;
	}
	$sixth = Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-rl6@example.com', 'consent' => '1', 'source' => 'rl' ) );
	$check( 'five signups pass, the sixth is rate limited', 5 === $okc && false === $sixth['ok'] && 'rate' === $sixth['code'] );
	$check( 'rate-limited request stored nothing', 0 === count( get_posts( array( 'post_type' => 'eru_cb_subscriber', 'post_status' => 'any', 's' => 'cbt-rl6@example.com' ) ) ) );

	// Global hourly cap on new signups (distributed abuse breaker).
	$clear_rl();
	Eru_CB_Settings::save_for_test( array( 'global_hourly' => '2' ) );
	$g = array();
	for ( $i = 1; $i <= 3; $i++ ) {
		$g[] = Eru_CB_Newsletter::subscribe( array( 'email' => "cbt-gl{$i}@example.com", 'consent' => '1', 'source' => 'gl' ) );
	}
	$check( 'global cap: two signups pass, third refused as busy', $g[0]['ok'] && $g[1]['ok'] && false === $g[2]['ok'] && 'busy' === $g[2]['code'] );
	$clear_rl();
	Eru_CB_Settings::save_for_test( array( 'global_hourly' => '60' ) );

	// Confirmation link: GET only shows a button, only the same-origin POST confirms.
	$clear_rl();
	$mails = array();
	Eru_CB_Newsletter::subscribe( array( 'email' => 'cbt-c1@example.com', 'consent' => '1', 'source' => 'form' ) );
	preg_match( '/eru_cb_confirm=(\d+\.[A-Za-z0-9]+)/', $mails[0]['message'], $mc );
	$craw = $mc[1];
	$cid1 = (int) explode( '.', $craw )[0];
	$before = $count_coupons();
	$get    = Eru_CB_Newsletter::handle_confirm( 'GET', $craw );
	$check( 'GET shows the confirm button and leaves the subscriber pending', 200 === $get['status'] && false !== strpos( $get['html'], 'Aboneliğimi onayla' ) && false !== strpos( $get['html'], 'method="post"' ) && 'pending' === get_post_meta( $cid1, '_eru_cb_status', true ) && $before === $count_coupons() );
	$check( 'GET page does not leak a coupon code', false === stripos( $get['html'], 'CBTEST' ) );
	$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.org';
	$foreign = Eru_CB_Newsletter::handle_confirm( 'POST', $craw );
	unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'] );
	$check( 'POST from a foreign origin is refused and stays pending', 403 === $foreign['status'] && 'pending' === get_post_meta( $cid1, '_eru_cb_status', true ) );
	// Browsers under a no-referrer policy send neither header (or Origin: null); the token alone must confirm.
	$_SERVER['HTTP_ORIGIN'] = 'null';
	$post = Eru_CB_Newsletter::handle_confirm( 'POST', $craw );
	unset( $_SERVER['HTTP_ORIGIN'] );
	$check( 'POST without usable Origin/Referer confirms and issues the coupon', 200 === $post['status'] && 'confirmed' === get_post_meta( $cid1, '_eru_cb_status', true ) && $before + 1 === $count_coupons() );
	$_SERVER['HTTP_ORIGIN'] = home_url();
	$replay = Eru_CB_Newsletter::handle_confirm( 'POST', $craw );
	unset( $_SERVER['HTTP_ORIGIN'] );
	$check( 'replaying the POST is refused', 400 === $replay['status'] && $before + 1 === $count_coupons() );

	// (e) Timed cart coupon.
	$cart_reset();
	Eru_CB_Settings::save_for_test( array( 'tc_enabled' => 'yes', 'tc_prefix' => 'CBTEST', 'tc_min_cart' => '600', 'tc_validity' => '30', 'tc_cooldown_hours' => '24', 'tc_amount' => '5', 'tc_type' => 'percent' ) );
	delete_transient( Eru_CB_Timed::rl_key() );
	WC()->cart->add_to_cart( $p_cart );
	WC()->cart->calculate_totals();
	$check( 'state offers a coupon when cart >= minimum', 'offer' === Eru_CB_Timed::state()['state'] );
	$cl = Eru_CB_Timed::claim();
	$coupons_after_claim = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'cbtest%'" );
	$check( 'claim ok with a code', true === $cl['ok'] && 0 === strpos( $cl['code'], 'CBTEST' ) );
	$tc = new WC_Coupon( wc_get_coupon_id_by_code( $cl['code'] ) );
	$te = $tc->get_date_expires() ? $tc->get_date_expires()->getTimestamp() : 0;
	$check( 'timed coupon: date_expires about now + 30 minutes, usage_limit 1', abs( $te - ( time() + 1800 ) ) < 30 && 1 === $tc->get_usage_limit() );
	$check( 'timed coupon applied to the cart', WC()->cart->has_discount( $cl['code'] ) );
	$st = Eru_CB_Timed::state();
	$check( 'active state remaining seconds come from the coupon expiry', 'active' === $st['state'] && abs( $st['remaining'] - 1800 ) < 30 && $st['expires'] === $te );
	$tc->set_date_expires( time() - 60 );
	$tc->save();
	$valid = ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $cl['code'] ) );
	$check( 'expired timed coupon is rejected by WC_Discounts::is_coupon_valid', is_wp_error( $valid ) );
	$check( 'state after expiry: expired', 'expired' === Eru_CB_Timed::state()['state'] );

	// (f) Cool-down.
	$again = Eru_CB_Timed::claim();
	$check( 'same session cannot claim again inside the cool-down', false === $again['ok'] && 'cooldown' === $again['reason'] );
	$check( 'no second coupon was created', $coupons_after_claim === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'cbtest%'" ) );
	// Box rendering in the expired state offers nothing new.
	ob_start();
	do_action( 'woocommerce_before_cart' );
	$box = ob_get_clean();
	$check( 'expired box says "süresi doldu" and has no claim button', false !== strpos( $box, 'süresi doldu' ) && false === strpos( $box, 'data-eru-cb-claim' ) );

	// Below the minimum: no offer.
	$cart_reset();
	$cheap = $mk_product( 'Ucuz Sepet', 100, $cat_b );
	WC()->cart->add_to_cart( $cheap );
	WC()->cart->calculate_totals();
	$check( 'cart below the minimum: no offer', 'none' === Eru_CB_Timed::state()['state'] );
	$below = Eru_CB_Timed::claim();
	$check( 'cart below the minimum: claim refused', false === $below['ok'] );

	// Per-IP hourly limit on claims.
	$cart_reset();
	WC()->cart->add_to_cart( $p_cart );
	WC()->cart->calculate_totals();
	set_transient( Eru_CB_Timed::rl_key(), 99, HOUR_IN_SECONDS );
	$lim = Eru_CB_Timed::claim();
	$check( 'claim refused over the per-IP hourly limit', false === $lim['ok'] && 'rate' === $lim['reason'] );
	delete_transient( Eru_CB_Timed::rl_key() );
	set_transient( Eru_CB_Timed::global_key(), 999, HOUR_IN_SECONDS );
	$glob = Eru_CB_Timed::claim();
	$check( 'claim refused over the global hourly cap', false === $glob['ok'] && 'busy' === $glob['reason'] );
	delete_transient( Eru_CB_Timed::global_key() );

	// (h) Clean-up deletes only expired, generated, unused coupons.
	$mk_coupon = function ( $code, $generated, $expires, $used ) {
		$c = new WC_Coupon();
		$c->set_code( $code );
		$c->set_amount( '5' );
		$c->set_date_expires( $expires );
		if ( $generated ) {
			$c->add_meta_data( '_eru_cb_generated', 1, true );
		}
		$c->set_usage_count( $used );
		return $c->save();
	};
	$del   = $mk_coupon( 'cbtest-del', true, time() - 3600, 0 );
	$used  = $mk_coupon( 'cbtest-used', true, time() - 3600, 1 );
	$fresh = $mk_coupon( 'cbtest-fresh', true, time() + 3600, 0 );
	$own   = $mk_coupon( 'cbtest-own', false, time() - 3600, 0 );
	Eru_CB_Coupons::cleanup();
	$check( 'cleanup removed the expired unused generated coupon', false === get_post_status( $del ) );
	$check( 'cleanup kept used, unexpired and non-generated coupons', 'publish' === get_post_status( $used ) && 'publish' === get_post_status( $fresh ) && 'publish' === get_post_status( $own ) );

	// Clean-up drains in batches and re-arms through Action Scheduler while work remains.
	add_filter( 'eru_cb_batch_size', $bs = fn() => 2 );
	foreach ( range( 1, 5 ) as $i ) {
		$mk_coupon( "cbtest-batch{$i}", true, time() - 3600, 0 );
	}
	$left = fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'cbtest-batch%'" );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_cb_cleanup_more', array(), 'eru-cb' );
	}
	Eru_CB_Coupons::cleanup();
	$check( 'first batch deletes only the batch size', 3 === $left() );
	$check( 'a follow-up batch is scheduled while work remains', ! function_exists( 'as_has_scheduled_action' ) || as_has_scheduled_action( 'eru_cb_cleanup_more', array(), 'eru-cb' ) );
	$guard = 0;
	while ( $left() > 0 && $guard++ < 10 ) {
		do_action( 'eru_cb_cleanup_more' );
	}
	$check( 'repeated batches drain everything', 0 === $left() );
	if ( function_exists( 'as_has_scheduled_action' ) ) {
		as_unschedule_all_actions( 'eru_cb_cleanup_more', array(), 'eru-cb' );
		Eru_CB_Coupons::cleanup();
		$check( 'no follow-up scheduled once drained', ! as_has_scheduled_action( 'eru_cb_cleanup_more', array(), 'eru-cb' ) );
	}
	remove_filter( 'eru_cb_batch_size', $bs );

	// Scheduling: while a coupon module is on, or while subscriber data exists (retention must run even if modules are off).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_cb_daily', array(), 'eru-cb' );
		delete_option( 'eru_cb_has_data' );
		Eru_CB_Settings::save_for_test( array( 'nl_enabled' => 'no', 'tc_enabled' => 'no' ) );
		Eru_CB_Coupons::maybe_schedule( true );
		$check( 'no daily job while modules are off and no data exists', ! as_has_scheduled_action( 'eru_cb_daily', array(), 'eru-cb' ) );
		update_option( 'eru_cb_has_data', 1, false );
		Eru_CB_Coupons::maybe_schedule( true );
		$check( 'retention job stays scheduled while subscriber data exists, even with modules off', as_has_scheduled_action( 'eru_cb_daily', array(), 'eru-cb' ) );
		delete_option( 'eru_cb_has_data' );
		Eru_CB_Settings::save_for_test( array( 'tc_enabled' => 'yes' ) );
		as_unschedule_all_actions( 'eru_cb_daily', array(), 'eru-cb' );
		Eru_CB_Coupons::maybe_schedule( true );
		$check( 'daily job scheduled once a coupon module is on', as_has_scheduled_action( 'eru_cb_daily', array(), 'eru-cb' ) );
		as_unschedule_all_actions( 'eru_cb_daily', array(), 'eru-cb' );
	}

	// Cart recommendations when there are no cross-sells.
	$cart_reset();
	Eru_CB_Settings::save_for_test( array( 'recs_enabled' => 'yes', 'recs_count' => '4' ) );
	WC()->cart->add_to_cart( $p_cart );
	$rec_ids = array_map( fn( $p ) => $p->get_id(), Eru_CB_Shipping::recommendations() );
	$check( 'recommendations: same category, not in cart, in stock', $rec_ids && ! in_array( $p_cart, $rec_ids, true ) && ! in_array( $p_oos, $rec_ids, true ) && count( $rec_ids ) <= 4 );

	echo "ALL PASS\n";
	$code = 0;
} catch ( Throwable $e ) {
	echo 'ABORT: ' . $e->getMessage() . "\n";
	$code = 1;
} finally {
	$cart_reset();
	$purge();
	if ( null === $old_settings ) {
		delete_option( Eru_CB_Settings::OPTION );
	} else {
		update_option( Eru_CB_Settings::OPTION, $old_settings, false );
	}
	delete_option( 'eru_cb_has_data' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( array( 'eru_cb_daily', 'eru_cb_cleanup_more', 'eru_cb_purge_more' ) as $eru_cb_h ) {
			as_unschedule_all_actions( $eru_cb_h, array(), 'eru-cb' );
		}
	}
}
exit( $code );
