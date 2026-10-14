<?php
/**
 * Custom Order Statuses — slug rules, registration, e-mail, paid/stock semantics, delete/migrate, webhook module.
 *
 *   wp eval-file tests/php/cos-statuses.php
 */

$fail  = 0;
// Stops at the first failure; the finally block still cleans up (exit() would skip it).
$check = function ( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		throw new RuntimeException( $label );
	}
};

if ( ! class_exists( 'Eru_COS_Statuses' ) ) {
	echo "FAIL: Eru_COS_Statuses missing (plugin not active?)\n";
	exit( 1 );
}

$old_statuses = get_option( Eru_COS_Statuses::OPTION, null );
$old_settings = get_option( Eru_COS_Statuses::SETTINGS_OPTION, null );
$order_ids    = array();
$pid          = 0;
$mails        = array();
$mail_filter  = function ( $short, $atts ) use ( &$mails ) {
	$mails[] = $atts;
	return true; // Never send from a test.
};
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

try {
	delete_option( Eru_COS_Statuses::OPTION );

	// Slug rules.
	$s = Eru_COS_Statuses::make_slug( 'Kargoya Verildi Bugün Akşam' );
	$check( "slug from long Turkish label is valid ($s)", strlen( $s ) <= 17 && (bool) preg_match( '/^[a-z0-9-]+$/', $s ) );
	$check( 'slug is Turkish-folded and cut at a word boundary', 'kargoya-verildi' === $s );
	$check( 'ğ/ı/ş/ç/ö/ü folded', 'dagitim-sicak-ou' === Eru_COS_Statuses::make_slug( 'Dağıtım Şıcak ÖÜ' ) );
	$e = Eru_COS_Statuses::add( array( 'label' => 'İşleniyor', 'slug' => 'processing' ) );
	$check( 'core slug "processing" is rejected', is_wp_error( $e ) );
	$e = Eru_COS_Statuses::add( array( 'label' => 'Çok uzun', 'slug' => 'bu-slug-on-yedi-karakterden-uzun' ) );
	$check( 'slug longer than 17 chars is rejected', is_wp_error( $e ) );

	// Registration.
	$r = Eru_COS_Statuses::add(
		array(
			'label'          => 'Demo Paket',
			'slug'           => 'demo-paket',
			'color'          => '#1e6fd9',
			'is_paid'        => 1,
			'email_customer' => 1,
			'email_subject'  => '{site_title}: #{order_number} paketlendi',
		)
	);
	$check( 'status demo-paket added', ! is_wp_error( $r ) );
	$check( 'wc-demo-paket in wc_get_order_statuses()', isset( wc_get_order_statuses()['wc-demo-paket'] ) );
	$check( 'wc-demo-paket registered as post status', null !== get_post_status_object( 'wc-demo-paket' ) );
	$check( 'duplicate slug is rejected', is_wp_error( Eru_COS_Statuses::add( array( 'label' => 'Demo Paket' , 'slug' => 'demo-paket' ) ) ) );
	$keys = array_keys( wc_get_order_statuses() );
	$check( 'inserted right after wc-processing', array_search( 'wc-demo-paket', $keys, true ) === array_search( 'wc-processing', $keys, true ) + 1 );

	// Transition: hook fires once, one customer e-mail, paid.
	$fired = 0;
	add_action( 'woocommerce_order_status_demo-paket', $counter = function () use ( &$fired ) {
		$fired++;
	} );
	$o = wc_create_order();
	$o->set_billing_email( 'ornek@example.com' );
	$o->set_billing_first_name( 'Demo' );
	$o->save();
	$order_ids[] = $o->get_id();
	$mails       = array();
	$o->update_status( 'demo-paket' );
	remove_action( 'woocommerce_order_status_demo-paket', $counter );
	$check( 'woocommerce_order_status_demo-paket fired once', 1 === $fired );
	$to_customer = array_filter( $mails, fn( $m ) => str_contains( implode( ',', (array) $m['to'] ), 'ornek@example.com' ) );
	$check( 'exactly one customer e-mail sent (' . count( $to_customer ) . ')', 1 === count( $to_customer ) );
	$first = reset( $to_customer );
	$check( 'subject placeholders replaced', $first && str_contains( $first['subject'], '#' . $o->get_order_number() ) && ! str_contains( $first['subject'], '{' ) );
	$check( 'is_paid flag: $order->is_paid() true', wc_get_order( $o->get_id() )->is_paid() );
	$check( 'paid status listed in wc_get_is_paid_statuses()', in_array( 'demo-paket', wc_get_is_paid_statuses(), true ) );

	// E-mail off → no e-mail.
	Eru_COS_Statuses::update( 'demo-paket', array( 'email_customer' => 0 ) );
	$o2 = wc_create_order();
	$o2->set_billing_email( 'ornek@example.com' );
	$o2->save();
	$order_ids[] = $o2->get_id();
	$mails       = array();
	$o2->update_status( 'demo-paket' );
	$check( 'no e-mail when the customer e-mail is off', 0 === count( $mails ) );
	$o2->update_status( 'pending' );

	// Stock: reduces once.
	$p = new WC_Product_Simple();
	$p->set_name( 'COS Demo Ürün' );
	$p->set_regular_price( '100' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 10 );
	$p->set_status( 'publish' );
	$pid = $p->save();
	Eru_COS_Statuses::add( array( 'label' => 'Demo Stok', 'slug' => 'demo-stok', 'reduces_stock' => 1 ) );
	$o3 = wc_create_order();
	$o3->add_product( wc_get_product( $pid ), 1 );
	$o3->calculate_totals();
	$o3->save();
	$order_ids[] = $o3->get_id();
	$o3->update_status( 'demo-stok' );
	$check( 'reduces_stock: 10 → 9', 9 === (int) wc_get_product( $pid )->get_stock_quantity() );
	$check( '_order_stock_reduced set', (bool) wc_get_order( $o3->get_id() )->get_data_store()->get_stock_reduced( $o3->get_id() ) );
	wc_get_order( $o3->get_id() )->update_status( 'processing' );
	$check( 'moving on to processing does not reduce again', 9 === (int) wc_get_product( $pid )->get_stock_quantity() );

	// Slug cannot change once orders use it.
	$e = Eru_COS_Statuses::update( 'demo-paket', array( 'slug' => 'demo-paket2' ) );
	$check( 'slug immutable while orders use it', is_wp_error( $e ) );

	// Delete: refused while orders use it, migrates with a target.
	$e = Eru_COS_Statuses::delete( 'demo-paket' );
	$check( 'delete without target is refused while an order uses it', is_wp_error( $e ) && isset( Eru_COS_Statuses::all()['demo-paket'] ) );
	$res = WP_CLI::runcommand( 'eru-cos delete demo-paket --to=processing', array( 'launch' => false, 'return' => 'all', 'exit_error' => false ) );
	$check( 'wp eru-cos delete --to=processing exits 0', 0 === $res->return_code );
	$check( 'order migrated to processing', 'processing' === wc_get_order( $o->get_id() )->get_status() );
	$check( 'status removed', ! isset( Eru_COS_Statuses::all()['demo-paket'] ) && ! isset( wc_get_order_statuses()['wc-demo-paket'] ) );

	// Fix round 1: migration always terminates, background batches, pending guard, "%" labels, rename, dropdown.
	$mk = function ( string $status ) use ( &$order_ids ) {
		$ord = wc_create_order();
		$ord->set_billing_email( 'ornek@example.com' );
		$ord->save();
		$order_ids[] = $ord->get_id();
		$ord->update_status( $status );
		return $ord->get_id();
	};
	$st = fn( $id ) => wc_get_order( $id )->get_status();

	Eru_COS_Statuses::add( array( 'label' => 'Demo Takılı', 'slug' => 'demo-takili' ) );
	$ok_id   = $mk( 'demo-takili' );
	$bad_id  = $mk( 'demo-takili' );
	$thrower = function ( $ord ) use ( $bad_id ) {
		if ( $ord->get_id() === $bad_id && 'processing' === $ord->get_status() ) {
			throw new Exception( 'demo save failure' );
		}
	};
	add_action( 'woocommerce_before_order_object_save', $thrower );
	$e = Eru_COS_Statuses::delete( 'demo-takili', 'processing', true );
	$check( 'delete with an unsaveable order terminates with eru_cos_migrate_failed', is_wp_error( $e ) && 'eru_cos_migrate_failed' === $e->get_error_code() );
	$check( 'movable order moved, failing order and the status kept', 'processing' === $st( $ok_id ) && 'demo-takili' === $st( $bad_id ) && isset( Eru_COS_Statuses::raw()['demo-takili'] ) );
	$res = WP_CLI::runcommand( 'eru-cos migrate --from=demo-takili --to=processing', array( 'launch' => false, 'return' => 'all', 'exit_error' => false ) );
	$check( 'wp eru-cos migrate ends with an error instead of looping', 0 !== $res->return_code && str_contains( $res->stderr, 'eru_cos_migrate_failed' ) );
	remove_action( 'woocommerce_before_order_object_save', $thrower );
	$check( 'delete succeeds once the order can be saved', true === Eru_COS_Statuses::delete( 'demo-takili', 'processing', true ) );

	add_filter( 'eru_cos_migrate_batch_size', $one = fn() => 1 );
	Eru_COS_Statuses::add( array( 'label' => 'Demo Parti', 'slug' => 'demo-parti' ) );
	$parti = array( $mk( 'demo-parti' ), $mk( 'demo-parti' ), $mk( 'demo-parti' ) );
	$r     = Eru_COS_Statuses::delete( 'demo-parti', 'completed' );
	$check( 'more orders than a batch: deletion is scheduled', 'scheduled' === $r && 'completed' === Eru_COS_Statuses::raw()['demo-parti']['pending_delete'] );
	$e = Eru_COS_Statuses::delete( 'demo-parti', 'processing' );
	$check( 'second delete while pending is refused', is_wp_error( $e ) && 'eru_cos_pending' === $e->get_error_code() );

	require_once ABSPATH . 'wp-admin/includes/admin.php';
	set_current_screen( 'woocommerce_page_wc-orders' );
	$GLOBALS['theorder'] = wc_get_order( $ok_id ); // processing
	$check( 'order edit dropdown hides a status being deleted', ! isset( wc_get_order_statuses()['wc-demo-parti'] ) );
	$GLOBALS['theorder'] = wc_get_order( $parti[0] );
	$check( '...unless the order is in it', isset( wc_get_order_statuses()['wc-demo-parti'] ) );
	unset( $GLOBALS['theorder'] );
	$GLOBALS['current_screen'] = null;
	$check( 'other screens still list it', isset( wc_get_order_statuses()['wc-demo-parti'] ) );

	for ( $i = 0; $i < 6 && isset( Eru_COS_Statuses::raw()['demo-parti'] ); $i++ ) {
		Eru_COS_Statuses::run_batch( 'demo-parti', 'completed' );
	}
	$check( 'run_batch moves batch by batch and deletes the status at the end', ! isset( Eru_COS_Statuses::raw()['demo-parti'] ) && array( 'completed' ) === array_values( array_unique( array_map( $st, $parti ) ) ) );

	Eru_COS_Statuses::add( array( 'label' => 'Demo Dur', 'slug' => 'demo-dur' ) );
	$mk( 'demo-dur' );
	$mk( 'demo-dur' );
	Eru_COS_Statuses::delete( 'demo-dur', 'processing' );
	delete_transient( Eru_COS_Statuses::NOTICE );
	Eru_COS_Statuses::run_batch( 'demo-dur', 'olmayan-durum' );
	$check( 'run_batch with a missing target stops, clears pending and leaves a notice', '' === Eru_COS_Statuses::raw()['demo-dur']['pending_delete'] && is_string( get_transient( Eru_COS_Statuses::NOTICE ) ) );
	remove_filter( 'eru_cos_migrate_batch_size', $one );
	Eru_COS_Statuses::delete( 'demo-dur', 'processing', true );

	Eru_COS_Statuses::add( array( 'label' => 'Kısmi % ödeme' ) );
	$obj = get_post_status_object( 'wc-kismi-odeme' );
	try {
		$txt = sprintf( translate_nooped_plural( $obj->label_count, 3 ), number_format_i18n( 3 ) );
		$ok  = str_contains( $txt, 'Kısmi % ödeme' ) && str_contains( $txt, '(3)' );
	} catch ( Throwable $t ) {
		$ok = false;
	}
	$check( 'label with "%" renders label_count through sprintf', $ok );
	Eru_COS_Statuses::delete( 'kismi-odeme' );

	Eru_COS_Statuses::add( array( 'label' => 'Demo Ad', 'slug' => 'demo-ad', 'email_customer' => 1 ) );
	update_option( 'woocommerce_eru_cos_customer_demo_ad_settings', array( 'enabled' => 'yes', 'subject' => 'Demo konu' ) );
	Eru_COS_Statuses::update( 'demo-ad', array( 'slug' => 'demo-ad2' ) );
	$moved = get_option( 'woocommerce_eru_cos_customer_demo_ad2_settings' );
	$check( 'slug rename carries the WooCommerce e-mail settings over', is_array( $moved ) && 'Demo konu' === $moved['subject'] && false === get_option( 'woocommerce_eru_cos_customer_demo_ad_settings' ) );
	Eru_COS_Statuses::delete( 'demo-ad2' );

	// Webhook module.
	Eru_COS_Statuses::add( array( 'label' => 'Demo Paket', 'slug' => 'demo-paket' ) );
	update_option( Eru_COS_Statuses::SETTINGS_OPTION, array_merge( Eru_COS_Statuses::settings(), array( 'webhooks' => 0 ) ) );
	$topics = apply_filters( 'woocommerce_webhook_topics', array() );
	$check( 'webhook topic absent while module is off', ! isset( $topics['order.status_demo-paket'] ) && ! wc_is_webhook_valid_topic( 'order.status_demo-paket' ) );
	update_option( Eru_COS_Statuses::SETTINGS_OPTION, array_merge( Eru_COS_Statuses::settings(), array( 'webhooks' => 1 ) ) );
	$topics = apply_filters( 'woocommerce_webhook_topics', array() );
	$check( 'webhook topic order.status_demo-paket listed when module is on', isset( $topics['order.status_demo-paket'] ) && wc_is_webhook_valid_topic( 'order.status_demo-paket' ) );
	$hooks = apply_filters( 'woocommerce_webhook_topic_hooks', array(), new WC_Webhook() );
	$check( 'topic hooked to woocommerce_order_status_demo-paket', in_array( 'woocommerce_order_status_demo-paket', $hooks['order.status_demo-paket'] ?? array(), true ) );
	$check( 'core topic order.completed available', isset( $topics['order.completed'] ) && wc_is_webhook_valid_topic( 'order.completed' ) );
} catch ( Throwable $t ) {
	$fail = 1;
	if ( ! $t instanceof RuntimeException ) {
		echo 'FAIL: ' . $t->getMessage() . "\n";
	}
} finally {
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	foreach ( $order_ids as $id ) {
		$ord = wc_get_order( $id );
		if ( $ord ) {
			$ord->delete( true );
		}
	}
	if ( $pid ) {
		wp_delete_post( $pid, true );
	}
	null === $old_statuses ? delete_option( Eru_COS_Statuses::OPTION ) : update_option( Eru_COS_Statuses::OPTION, $old_statuses );
	null === $old_settings ? delete_option( Eru_COS_Statuses::SETTINGS_OPTION ) : update_option( Eru_COS_Statuses::SETTINGS_OPTION, $old_settings );
	delete_transient( 'eru_cos_group_counts' );
	delete_transient( 'eru_cos_notice' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'eru_cos_migrate_batch' );
	}
	unset( $GLOBALS['theorder'] );
}
exit( $fail ? 1 : 0 );
