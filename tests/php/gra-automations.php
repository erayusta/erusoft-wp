<?php
/**
 * Growth Automations — scheduling, backlog batches, win-back coupons, variation alerts, privacy, retention.
 *
 *   wp eval-file tests/php/gra-automations.php
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
add_filter( 'wc_tax_enabled', '__return_false' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

global $wpdb;
$ctable = $wpdb->prefix . 'eru_gra_carts';
$ltable = $wpdb->prefix . 'eru_gra_log';

$subject_mails = function ( $needle ) use ( &$mails ) {
	return array_values( array_filter( $mails, fn( $m ) => false !== strpos( (string) $m['subject'], $needle ) ) );
};
$drain = function () {
	for ( $i = 0; $i < 20; $i++ ) {
		ActionScheduler_QueueRunner::instance()->run();
	}
};
$pending = function ( $hook = '' ) {
	return count( as_get_scheduled_actions( array( 'hook' => $hook, 'group' => 'eru-gra', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500 ), 'ids' ) );
};

$purge = function () use ( $wpdb, $ctable ) {
	Eru_GRA_DB::install();
	$wpdb->query( "DELETE FROM {$ctable} WHERE email LIKE 'gra-t%'" );
	foreach ( get_posts( array( 'post_type' => Eru_GRA_Alerts::CPT, 'post_status' => 'any', 'numberposts' => -1, 's' => 'gra-t' ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND ( post_title LIKE 'gra%' OR post_title LIKE 'wbt-%' OR post_title LIKE 'bct-%' )" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE 'ERU-GRA-%'" ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
	delete_option( Eru_GRA_Consent::OPTION );
	delete_option( Eru_GRA_Settings::OPTION );
	delete_option( 'eru_gra_has_data' );
	as_unschedule_all_actions( '', array(), 'eru-gra' );
	delete_transient( Eru_GRA_Alerts::rate_key() );
	delete_transient( Eru_GRA_Alerts::global_key() );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\_gra\_lock\_%'" );
};
$orders = array();
$del_orders = function () use ( &$orders ) {
	foreach ( $orders as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	$orders = array();
};

$purge();
wc_load_cart();

// (i) Inert after activation: nothing scheduled while every automation is off.
Eru_GRA_Plugin::activate();
Eru_GRA_Settings::sync_schedules();
$check( '(i) all off: no Action Scheduler actions after activation', 0 === $pending() );
$check( '(i) all off: nothing running either', 0 === count( as_get_scheduled_actions( array( 'group' => 'eru-gra', 'status' => ActionScheduler_Store::STATUS_RUNNING, 'per_page' => 50 ), 'ids' ) ) );
Eru_GRA_Settings::save_for_test( array( 'bacs' => array( 'enabled' => 'yes' ) ) );
$check( 'enabling one automation schedules its recurring run', 1 === $pending( 'eru_gra_run' ) );
Eru_GRA_Settings::save_for_test( array( 'bacs' => array( 'enabled' => 'no' ) ) );
$check( 'disabling it unschedules again', 0 === $pending( 'eru_gra_run' ) );

$simple = new WC_Product_Simple();
$simple->set_name( 'GRA Demo Ürün' );
$simple->set_sku( 'ERU-GRA-S2' );
$simple->set_regular_price( '40' );
$simple->save();
$simple_id = $simple->get_id();

$mk_order = function ( $email, $status, $created_ago = 0, $completed_days = null, $consent = false, $pm = 'bacs' ) use ( $simple_id, &$orders ) {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $simple_id ), 1 );
	$o->set_billing_email( $email );
	$o->set_billing_first_name( 'Demo' );
	$o->set_payment_method( $pm );
	$o->calculate_totals();
	$o->set_status( $status );
	if ( $created_ago ) {
		$o->set_date_created( time() - $created_ago );
	}
	if ( $consent ) {
		$o->update_meta_data( '_eru_gra_marketing_consent', 'yes' );
	}
	$o->save();
	if ( null !== $completed_days ) {
		$o->set_date_completed( time() - (int) ( $completed_days * DAY_IN_SECONDS ) );
		$o->set_date_created( time() - (int) ( $completed_days * DAY_IN_SECONDS ) - HOUR_IN_SECONDS );
		$o->save();
	}
	$orders[] = $o->get_id();
	return $o->get_id();
};

// (c) Backlog of 120 on-hold BACS orders, batch size 50.
Eru_GRA_Settings::save_for_test(
	array(
		'bacs' => array(
			'enabled'    => 'yes',
			'delay'      => '3',
			'batch_size' => '50',
			'subject'    => 'GRA-T bacs {order_number}',
		),
	)
);
$due = array();
for ( $i = 1; $i <= 120; $i++ ) {
	$due[] = $mk_order( sprintf( 'gra-t-b%03d@example.com', $i ), 'on-hold', 5 * HOUR_IN_SECONDS );
}
$fresh = $mk_order( 'gra-t-fresh@example.com', 'on-hold', 10 * MINUTE_IN_SECONDS );
$card  = $mk_order( 'gra-t-card@example.com', 'on-hold', 5 * HOUR_IN_SECONDS, null, false, 'cod' );
$paid  = $mk_order( 'gra-t-paid@example.com', 'processing', 5 * HOUR_IN_SECONDS );
$bacs_gw  = WC()->payment_gateways()->payment_gateways()['bacs'];
$old_acct = $bacs_gw->account_details;
$bacs_gw->account_details = array( array( 'account_name' => 'Demo Hesap', 'account_number' => '0000000', 'bank_name' => 'Demo Banka', 'sort_code' => '', 'iban' => '', 'bic' => '' ) );
$mails = array();
as_schedule_single_action( time() - 1, 'eru_gra_run', array( 'bacs' ), 'eru-gra' );
$drain();
$bacs_gw->account_details = $old_acct;
$sent = $subject_mails( 'GRA-T bacs' );
$check( '(c) 120 reminders sent from a backlog bigger than one batch', 120 === count( $sent ) );
$rcpt = array_unique( array_map( fn( $m ) => is_array( $m['to'] ) ? $m['to'][0] : $m['to'], $sent ) );
$check( '(c) every due order got exactly one mail', 120 === count( $rcpt ) );
$flagged = 0;
foreach ( $due as $oid ) {
	$flagged += '' !== (string) wc_get_order( $oid )->get_meta( '_eru_gra_bacs_reminded' ) ? 1 : 0;
}
$check( '(c) each due order is flagged once', 120 === $flagged );
$check( '(c) fresh, non-BACS and paid orders are not reminded', '' === (string) wc_get_order( $fresh )->get_meta( '_eru_gra_bacs_reminded' ) && '' === (string) wc_get_order( $card )->get_meta( '_eru_gra_bacs_reminded' ) && '' === (string) wc_get_order( $paid )->get_meta( '_eru_gra_bacs_reminded' ) );
$mails = array();
as_schedule_single_action( time() - 1, 'eru_gra_run', array( 'bacs' ), 'eru-gra' );
$drain();
$check( '(c) a re-run sends 0', 0 === count( $subject_mails( 'GRA-T bacs' ) ) );
$check( '(c) the follow-up run does not stay scheduled once drained', 0 === $pending( 'eru_gra_run_more' ) );
$check( '(c) the reminder carries the bank details from WooCommerce', false !== strpos( $sent[0]['message'], 'Demo Banka' ) );

// Self re-arm must look at pending actions only (a running action also counts for as_has_scheduled_action()).
as_unschedule_all_actions( '', array(), 'eru-gra' );
$running = as_schedule_single_action( time() - 5, 'eru_gra_run_more', array( 'bacs' ), 'eru-gra' );
ActionScheduler::store()->log_execution( $running );
$check( 'simulated action is in-progress, not pending', 0 === $pending( 'eru_gra_run_more' ) && ActionScheduler_Store::STATUS_RUNNING === ActionScheduler::store()->get_status( $running ) );
Eru_GRA_Runner::rearm( 'bacs' );
$check( 're-arm schedules a new pending run while the current one is still in progress', 1 === $pending( 'eru_gra_run_more' ) );
Eru_GRA_Runner::rearm( 'bacs' );
$check( 're-arm does not stack duplicates', 1 === $pending( 'eru_gra_run_more' ) );
ActionScheduler::store()->mark_complete( $running );
as_unschedule_all_actions( '', array(), 'eru-gra' );

// Dry run changes nothing.
$d1 = $mk_order( 'gra-t-dry@example.com', 'on-hold', 5 * HOUR_IN_SECONDS );
$mails = array();
$res = Eru_GRA_Runner::run( 'bacs', true );
$check( 'dry run reports due items but sends and flags nothing', $res['processed'] >= 1 && 0 === count( $subject_mails( 'GRA-T bacs' ) ) && '' === (string) wc_get_order( $d1 )->get_meta( '_eru_gra_bacs_reminded' ) );
$res = Eru_GRA_Runner::run( 'bacs' );
$check( 'a real run then handles it', 1 === count( $subject_mails( 'GRA-T bacs' ) ) );
$del_orders();

// Log screen data: masked e-mail, capped.
$rows = Eru_GRA_DB::log_rows( 200 );
$leak = false;
foreach ( $rows as $row ) {
	if ( false !== strpos( (string) $row['email_masked'], 'gra-t-b' ) ) {
		$leak = true;
	}
}
$check( 'log rows exist and never hold a full e-mail address', count( $rows ) > 0 && count( $rows ) <= 200 && ! $leak );
$check( 'masking keeps domain, hides the local part', 'g***@example.com' === Eru_GRA_DB::mask( 'gra-t-b001@example.com' ) );

// (d) Win-back with generated coupons.
Eru_GRA_Settings::save_for_test(
	array(
		'bacs'    => array( 'enabled' => 'no' ),
		'winback' => array(
			'enabled'       => 'yes',
			'delay'         => '60',
			'subject'       => 'GRA-T winback',
			'body'          => 'Merhaba {customer_name}, kodunuz {coupon_code}, son kullanım {coupon_expiry}.',
			'coupon_mode'   => 'generate',
			'coupon_prefix' => 'WBT',
			'coupon_type'   => 'percent',
			'coupon_amount' => '12',
			'coupon_min'    => '250',
			'coupon_days'   => '14',
			'batch_size'    => '50',
		),
	)
);
$w1 = $mk_order( 'gra-t-w1@example.com', 'completed', 0, 90, true );
$w2 = $mk_order( 'gra-t-w2@example.com', 'completed', 0, 95, true );
$w3 = $mk_order( 'gra-t-w3@example.com', 'completed', 0, 90, false );
$w4 = $mk_order( 'gra-t-w4@example.com', 'completed', 0, 90, true );
$w4b = $mk_order( 'gra-t-w4@example.com', 'completed', 0, 10, true );
$mails = array();
as_schedule_single_action( time() - 1, 'eru_gra_run', array( 'winback' ), 'eru-gra' );
$drain();
$wb = $subject_mails( 'GRA-T winback' );
$to = array_map( fn( $m ) => is_array( $m['to'] ) ? $m['to'][0] : $m['to'], $wb );
sort( $to );
$check( '(d) win-back goes to consented customers with an old last order only', array( 'gra-t-w1@example.com', 'gra-t-w2@example.com' ) === $to );
$codes = array();
foreach ( $wb as $m ) {
	$email = is_array( $m['to'] ) ? $m['to'][0] : $m['to'];
	$check( '(d) mail body contains a generated code (' . $email . ')', 1 === preg_match( '/WBT-[A-Z0-9]{8}/', $m['message'], $cm ) );
	$c = new WC_Coupon( $cm[0] );
	$check( '(d) the code exists in WooCommerce', $c->get_id() > 0 );
	$check( '(d) usage_limit 1, per-user 1', 1 === (int) $c->get_usage_limit() && 1 === (int) $c->get_usage_limit_per_user() );
	$exp = $c->get_date_expires() ? $c->get_date_expires()->getTimestamp() : 0;
	$check( '(d) date_expires about 14 days ahead', $exp > time() + 13 * DAY_IN_SECONDS && $exp < time() + 15 * DAY_IN_SECONDS );
	$check( '(d) bound to the recipient e-mail', array( $email ) === $c->get_email_restrictions() );
	$check( '(d) 12 percent, minimum 250, generated flag', '12' === (string) (float) $c->get_amount() || 12.0 === (float) $c->get_amount() );
	$check( '(d) minimum cart and flag set', 250.0 === (float) $c->get_minimum_amount() && '1' === (string) $c->get_meta( '_eru_gra_generated' ) );
	$codes[] = $cm[0];
}
$check( '(d) codes are unique across customers', count( $codes ) === count( array_unique( $codes ) ) );
$mails = array();
Eru_GRA_Runner::run( 'winback' );
$check( '(d) win-back sends nothing again within the period', 0 === count( $subject_mails( 'GRA-T winback' ) ) );

// Coupon the mail promises but that cannot be created: no mail, logged, not retried forever.
$gc = new WC_Coupon();
$gc->set_code( 'GRAFIXED1' );
$gc->save();
add_filter( 'eru_gra_coupon_candidate', $force = fn() => 'GRAFIXED1' );
$mk_order( 'gra-t-w5@example.com', 'completed', 0, 90, true );
$mails = array();
Eru_GRA_Runner::run( 'winback' );
$check( 'no mail when the promised coupon cannot be created', 0 === count( $subject_mails( 'GRA-T winback' ) ) );
$last = Eru_GRA_DB::log_rows( 5 )[0] ?? array();
$check( 'the failure is logged', 'coupon-failed' === ( $last['result'] ?? '' ) );
$mails = array();
Eru_GRA_Runner::run( 'winback' );
$check( 'the failed customer is not retried in a loop', 0 === count( $subject_mails( 'GRA-T winback' ) ) );
remove_filter( 'eru_gra_coupon_candidate', $force );
// Existing code mode with a missing code.
Eru_GRA_Settings::save_for_test( array( 'winback' => array( 'coupon_mode' => 'existing', 'coupon_code' => 'NOSUCHCODE' ) ) );
$mk_order( 'gra-t-w6@example.com', 'completed', 0, 90, true );
$mails = array();
Eru_GRA_Runner::run( 'winback' );
$check( 'no mail when the configured existing coupon does not exist', 0 === count( $subject_mails( 'GRA-T winback' ) ) );
// Mode none but body promises {coupon_code}.
Eru_GRA_Settings::save_for_test( array( 'winback' => array( 'coupon_mode' => 'none' ) ) );
$mk_order( 'gra-t-w7@example.com', 'completed', 0, 90, true );
$mails = array();
Eru_GRA_Runner::run( 'winback' );
$check( 'no mail when the text mentions a coupon but none is configured', 0 === count( $subject_mails( 'GRA-T winback' ) ) );

// Coupon clean-up: expired unused generated coupons go, used ones stay.
$mkc = function ( $code, $used ) {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_date_expires( time() - DAY_IN_SECONDS );
	$c->add_meta_data( '_eru_gra_generated', 1, true );
	$c->save();
	if ( $used ) {
		update_post_meta( $c->get_id(), 'usage_count', 1 );
	}
	return $c->get_id();
};
$unused = $mkc( 'gra-old-unused', false );
$usedc  = $mkc( 'gra-old-used', true );
Eru_GRA_Coupons::cleanup();
$check( 'expired unused generated coupons are purged, used ones kept', null === get_post( $unused ) && null !== get_post( $usedc ) );
wp_delete_post( $usedc, true );
$gc->delete( true );
$del_orders();

// Review request: completed orders after the delay, once per order, links to the review anchor.
Eru_GRA_Settings::save_for_test( array( 'review' => array( 'enabled' => 'yes', 'delay' => '7', 'max_age_days' => '30', 'subject' => 'GRA-T review {order_number}' ) ) );
$r1 = $mk_order( 'gra-t-r1@example.com', 'completed', 0, 10 );
$r2 = $mk_order( 'gra-t-r2@example.com', 'completed', 0, 3 );
$r3 = $mk_order( 'gra-t-r3@example.com', 'completed', 0, 100 );
$r4 = $mk_order( 'gra-t-r4@example.com', 'completed', 0, 10 );
Eru_GRA_Consent::suppress( 'gra-t-r4@example.com' );
$mails = array();
Eru_GRA_Runner::run( 'review' );
$rv = $subject_mails( 'GRA-T review' );
$check( 'review request goes to the eligible order only', 1 === count( $rv ) && false !== strpos( $rv[0]['message'], '#reviews' ) );
$check( 'review request is flagged once per order', '' !== (string) wc_get_order( $r1 )->get_meta( '_eru_gra_review_requested' ) && '' === (string) wc_get_order( $r2 )->get_meta( '_eru_gra_review_requested' ) && '' === (string) wc_get_order( $r3 )->get_meta( '_eru_gra_review_requested' ) );
$mails = array();
Eru_GRA_Runner::run( 'review' );
$check( 'review re-run sends nothing', 0 === count( $subject_mails( 'GRA-T review' ) ) );
Eru_GRA_Consent::remove_suppression( 'gra-t-r4@example.com' );
Eru_GRA_Settings::save_for_test( array( 'review' => array( 'enabled' => 'no' ) ) );
$del_orders();

// (g) Variation alerts.
Eru_GRA_Settings::save_for_test( array( 'winback' => array( 'enabled' => 'no' ), 'alerts' => array( 'enabled' => 'yes', 'subject' => 'GRA-T notify {product_name}', 'confirm_subject' => 'GRA-T confirm', 'batch_size' => '2' ) ) );
$var = new WC_Product_Variable();
$var->set_name( 'GRA Demo Varyasyonlu' );
$var->set_sku( 'ERU-GRA-V2' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Beden' );
$attr->set_options( array( 'S', 'M' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$var->set_attributes( array( $attr ) );
$var_id = $var->save();
$vars   = array();
foreach ( array( 'S', 'M' ) as $size ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $var_id );
	$v->set_attributes( array( 'beden' => $size ) );
	$v->set_regular_price( '55' );
	$v->set_sku( 'ERU-GRA-V2-' . $size );
	$v->set_manage_stock( true );
	$v->set_stock_quantity( 0 );
	$v->save();
	$vars[ $size ] = $v->get_id();
}
WC_Product_Variable::sync( $var_id );

$sub = function ( $email, $variation ) use ( &$mails, $var_id, $simple_id ) {
	$r = Eru_GRA_Alerts::subscribe( array( 'email' => $email, 'product_id' => $variation ? $var_id : $simple_id, 'variation_id' => $variation ) );
	if ( ! $r['ok'] ) {
		throw new RuntimeException( 'subscribe failed ' . $email . ' ' . $r['code'] );
	}
	foreach ( array_reverse( $mails ) as $m ) {
		if ( in_array( $email, (array) $m['to'], true ) && preg_match( '/eru_gra_confirm=(\d+)\.([A-Za-z0-9]+)/', $m['message'], $x ) ) {
			$res = Eru_GRA_Alerts::handle_confirm( 'POST', $x[1] . '.' . $x[2] );
			if ( 200 !== $res['status'] ) {
				throw new RuntimeException( 'confirm failed ' . $email );
			}
			return (int) $x[1];
		}
	}
	throw new RuntimeException( 'no confirm mail ' . $email );
};
delete_transient( Eru_GRA_Alerts::rate_key() );
$sub( 'gra-t-a1@example.com', $vars['S'] );
$sub( 'gra-t-a2@example.com', $vars['S'] );
$sub( 'gra-t-a3@example.com', $vars['S'] );
$sub( 'gra-t-a4@example.com', $vars['M'] );
$simple_out = wc_get_product( $simple_id );
$simple_out->set_stock_status( 'outofstock' );
$simple_out->save();
$sub( 'gra-t-a5@example.com', 0 );
$mails = array();
$vS = wc_get_product( $vars['S'] );
$vS->set_stock_quantity( 5 );
$vS->save();
$check( 'stock change schedules a notification job for that variation only', 1 === $pending( 'eru_gra_notify' ) );
$drain();
$notified = array();
foreach ( $subject_mails( 'GRA-T notify' ) as $m ) {
	$notified[] = is_array( $m['to'] ) ? $m['to'][0] : $m['to'];
}
sort( $notified );
$check( '(g) variation back in stock notifies only that variation\'s subscribers (batch of 2 drained fully)', array( 'gra-t-a1@example.com', 'gra-t-a2@example.com', 'gra-t-a3@example.com' ) === $notified );
$vS->set_stock_quantity( 0 );
$vS->save();
$mails = array();
$vS->set_stock_quantity( 7 );
$vS->save();
$drain();
$check( '(g) a second restock notifies nobody again', 0 === count( $subject_mails( 'GRA-T notify' ) ) );
$check( '(g) other size and simple-product subscribers were not touched', 'active' === get_post_meta( Eru_GRA_Alerts::find( 'gra-t-a4@example.com', $vars['M'] ), '_eru_gra_state', true ) );
// Simple product alert: product-level event.
$simple_obj = wc_get_product( $simple_id );
$simple_obj->set_manage_stock( true );
$simple_obj->set_stock_quantity( 0 );
$simple_obj->save();
$mails = array();
$simple_obj->set_stock_quantity( 4 );
$simple_obj->save();
$drain();
$check( '(g) simple product back in stock notifies its subscriber', 1 === count( $subject_mails( 'GRA-T notify' ) ) );

// Form output on the variation data.
$vM = wc_get_product( $vars['M'] );
$data = wc_get_product( $var_id )->get_available_variation( $vM );
$check( 'out-of-stock variation data carries the alert form', false !== strpos( (string) $data['availability_html'], 'eru-gra-alert' ) && false !== strpos( (string) $data['availability_html'], 'data-variation="' . $vars['M'] . '"' ) );
$vM->set_stock_quantity( 3 );
$vM->save();
$data = wc_get_product( $var_id )->get_available_variation( wc_get_product( $vars['M'] ) );
$check( 'in-stock variation data has no alert form', false === strpos( (string) $data['availability_html'], 'eru-gra-alert' ) );
Eru_GRA_Settings::save_for_test( array( 'alerts' => array( 'enabled' => 'no' ) ) );
$vM->set_stock_quantity( 0 );
$vM->save();
$data = wc_get_product( $var_id )->get_available_variation( wc_get_product( $vars['M'] ) );
$check( 'alerts off: no form', false === strpos( (string) $data['availability_html'], 'eru-gra-alert' ) );
Eru_GRA_Settings::save_for_test( array( 'alerts' => array( 'enabled' => 'yes' ) ) );

// Privacy: exporter, eraser, retention.
$wpdb->insert(
	$ctable,
	array(
		'email' => 'gra-t-p1@example.com', 'user_id' => 0, 'cart' => '[]', 'token' => '', 'consent_source' => 'checkout', 'status' => 'open',
		'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	)
);
$pc = new WC_Coupon();
$pc->set_code( 'GRA-PRIVACY1' );
$pc->set_email_restrictions( array( 'gra-t-p1@example.com' ) );
$pc->add_meta_data( '_eru_gra_generated', 1, true );
$pc->save();
$exp = Eru_GRA_Privacy::export( 'gra-t-p1@example.com' );
$check( 'exporter returns the cart row and the generated coupon', count( $exp['data'] ) >= 2 );
$exp = Eru_GRA_Privacy::export( 'gra-t-a1@example.com' );
$check( 'exporter returns alerts', count( $exp['data'] ) >= 1 );
$er = Eru_GRA_Privacy::erase( 'gra-t-p1@example.com' );
$check( 'eraser removes the cart row and the unused coupon', true === $er['items_removed'] && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email = 'gra-t-p1@example.com'" ) && ! wc_get_coupon_id_by_code( 'GRA-PRIVACY1' ) );
$er = Eru_GRA_Privacy::erase( 'gra-t-a1@example.com' );
$check( 'eraser removes alerts', 0 === Eru_GRA_Alerts::find( 'gra-t-a1@example.com', $vars['S'] ) );

// Retention purge in batches.
Eru_GRA_Settings::save_for_test( array( 'general' => array( 'retention_days' => '30' ) ) );
add_filter( 'eru_gra_batch_size', $bs = fn() => 2 );
for ( $i = 1; $i <= 5; $i++ ) {
	$wpdb->insert(
		$ctable,
		array(
			'email' => "gra-t-old{$i}@example.com", 'user_id' => 0, 'cart' => '[]', 'token' => '', 'consent_source' => 'checkout', 'status' => 'sent',
			'created_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ),
		)
	);
}
$wpdb->insert(
	$ctable,
	array(
		'email' => 'gra-t-new@example.com', 'user_id' => 0, 'cart' => '[]', 'token' => '', 'consent_source' => 'checkout', 'status' => 'sent',
		'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	)
);
$old_active = wp_insert_post( array( 'post_type' => Eru_GRA_Alerts::CPT, 'post_status' => 'publish', 'post_title' => 'gra-t-oldactive@example.com', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
update_post_meta( $old_active, '_eru_gra_state', 'active' );
$old_alert = wp_insert_post( array( 'post_type' => Eru_GRA_Alerts::CPT, 'post_status' => 'publish', 'post_title' => 'gra-t-oldalert@example.com', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
update_post_meta( $old_alert, '_eru_gra_state', 'sent' );
Eru_GRA_Privacy::purge();
$check( 'one purge run handles one batch only', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email LIKE 'gra-t-old%'" ) );
$check( 'the purge re-arms itself while work remains', $pending( 'eru_gra_purge_more' ) >= 1 );
$drain();
$check( 'draining Action Scheduler removes the whole backlog, keeps recent rows', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email LIKE 'gra-t-old%'" ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ctable} WHERE email = 'gra-t-new@example.com'" ) );
$check( 'old finished alerts are purged, confirmed waiting ones are kept', null === get_post( $old_alert ) && null !== get_post( $old_active ) );
remove_filter( 'eru_gra_batch_size', $bs );

// Eraser reports done=false while coupons remain beyond one batch.
for ( $i = 0; $i < 101; $i++ ) {
	$bc = new WC_Coupon();
	$bc->set_code( 'gra-bulk-' . $i );
	$bc->set_email_restrictions( array( 'gra-t-bulk@example.com' ) );
	$bc->add_meta_data( '_eru_gra_generated', 1, true );
	$bc->save();
}
$er1 = Eru_GRA_Privacy::erase( 'gra-t-bulk@example.com' );
$er2 = Eru_GRA_Privacy::erase( 'gra-t-bulk@example.com' );
$check( 'eraser asks to be called again while more coupons remain, then finishes', false === $er1['done'] && true === $er2['done'] );

// Cleanup.
$del_orders();
$purge();
wp_delete_post( $var_id, true );
foreach ( $vars as $vid ) {
	wp_delete_post( $vid, true );
}
wp_delete_post( $simple_id, true );
$wpdb->query( "DELETE FROM {$ltable} WHERE 1 = 1" );
// Uninstall keeps the suppression list unless ERU_GRA_REMOVE_DATA is set.
Eru_GRA_Consent::suppress( 'gra-t-keep@example.com' );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'x' );
}
include ERU_GRA_PATH . 'uninstall.php';
$check( 'uninstall keeps the opt-out list by default', is_array( get_option( Eru_GRA_Consent::OPTION ) ) && Eru_GRA_Consent::is_suppressed( 'gra-t-keep@example.com' ) );
define( 'ERU_GRA_REMOVE_DATA', true );
include ERU_GRA_PATH . 'uninstall.php';
$check( 'uninstall removes it with ERU_GRA_REMOVE_DATA', false === get_option( Eru_GRA_Consent::OPTION, false ) );
Eru_GRA_DB::install();
echo "DONE gra-automations\n";
