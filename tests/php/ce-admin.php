<?php
/**
 * Campaign Engine — admin and storefront: the wizard round-trip with arrays (target ids, conditions
 * JSON), capability and nonce refusals of the wizard, preview, bulk, template and settings handlers
 * (and a shop manager passing through the meta capability), the preview agreeing with the cart, the
 * public read-only clock route, storefront modules off by default (real HTTP), the overlap notice,
 * templates, trace texts, the exclusive warning, slug settings and Hesabım → Ödüllerim.
 *
 *   wp eval-file tests/php/ce-admin.php   (plugin active; creates and removes its own data)
 */

use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;
use Erusoft\CampaignEngine\Infrastructure\WooCommerce\CartApplier;
use Erusoft\CampaignEngine\Presentation\Account\RewardsEndpoint;
use Erusoft\CampaignEngine\Presentation\Admin\BulkActions;
use Erusoft\CampaignEngine\Presentation\Admin\ConflictNotice;
use Erusoft\CampaignEngine\Presentation\Admin\Doctor;
use Erusoft\CampaignEngine\Presentation\Admin\PreviewController;
use Erusoft\CampaignEngine\Presentation\Admin\SettingsScreen;
use Erusoft\CampaignEngine\Presentation\Admin\TemplateGallery;
use Erusoft\CampaignEngine\Presentation\Admin\TraceText;
use Erusoft\CampaignEngine\Presentation\Admin\WizardForm;
use Erusoft\CampaignEngine\Templates\Definitions;

require __DIR__ . '/ce-wc-helpers.php';
ce_wc_require();

foreach ( array( WizardForm::class, PreviewController::class, BulkActions::class, ConflictNotice::class, Doctor::class, TemplateGallery::class, SettingsScreen::class, TraceText::class, Definitions::class, StorefrontOptions::class, RewardsEndpoint::class ) as $ce_class ) {
	if ( ! class_exists( $ce_class ) ) {
		echo "FAIL: {$ce_class} missing\n";
		exit( 1 );
	}
}

/** Thrown by the wp_die handler so AJAX / admin-post handlers return to the test. */
class Ce_Admin_Die extends Exception {}

$mails = array();
ce_wc_guards( $mails );
wc_load_cart();
$keep         = ce_wc_snapshot_options();
$keep_front   = get_option( StorefrontOptions::OPTION, null );
$keep_plugins = get_option( 'active_plugins', array() );
$keep_soon    = get_option( 'woocommerce_coming_soon', null );
$keep_post    = $_POST;
$keep_request = $_REQUEST;
$fail         = false;
$users        = array();
$http_campaigns = array();
ce_wc_purge( true );
foreach ( get_users( array( 'search' => 'ce-demo-*', 'search_columns' => array( 'user_email' ) ) ) as $u ) {
	$tag = ce_tag_in( $u->user_email );
	if ( ce_run_match( $tag, true ) || '' === $tag ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u->ID );
	}
}
update_option( 'woocommerce_calc_taxes', 'no' );
delete_option( Options::OPTION );
Options::flush();
StorefrontOptions::update( StorefrontOptions::defaults() );

/** Calls an AJAX-style handler and returns [status, decoded JSON]. */
function ce_admin_ajax( callable $handler ) {
	$die = function () {
		return function ( $message = '' ) {
			throw new Ce_Admin_Die( is_string( $message ) ? $message : '' );
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );
	add_filter( 'wp_die_handler', $die );
	ob_start();
	try {
		$handler();
	} catch ( Ce_Admin_Die $e ) {
		unset( $e );
	}
	$out = (string) ob_get_clean();
	remove_filter( 'wp_doing_ajax', '__return_true' );
	remove_filter( 'wp_die_ajax_handler', $die );
	remove_filter( 'wp_die_handler', $die );
	return json_decode( $out, true );
}

/** A user of this run with a role. */
function ce_admin_user( $who, $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'ce-demo-' . $who . '-' . CE_RUN,
			'user_email' => ce_mail( $who ),
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $role,
			'first_name' => 'Demo',
			'last_name'  => ucfirst( $who ),
		)
	);
	if ( is_wp_error( $id ) ) {
		ce_ok( 'user ' . $who . ' created: ' . $id->get_error_message(), false );
	}
	return (int) $id;
}

/** Runs the wizard save through wp_update_post with the given POST body as the given user. */
function ce_admin_save( $post_id, $user_id, array $input, $nonce = true, $status = 'publish' ) {
	wp_set_current_user( $user_id );
	$_POST = array( 'eru_ce' => wp_slash( $input ) );
	if ( true === $nonce ) {
		$_POST['eru_ce_wizard_nonce'] = wp_create_nonce( 'eru_ce_wizard_' . $post_id );
	} elseif ( is_string( $nonce ) ) {
		$_POST['eru_ce_wizard_nonce'] = $nonce;
	}
	$_REQUEST = $_POST;
	wp_update_post(
		array(
			'ID'          => $post_id,
			'post_title'  => 'Demo Hafta Sonu',
			'post_status' => $status,
		)
	);
	$_POST    = array();
	$_REQUEST = array();
	return get_post_meta( $post_id, CampaignWriter::META, true );
}

/** GET over real HTTP against the demo site; optional cookie jar. */
function ce_admin_http( $url, $jar = '' ) {
	$cmd = 'curl -s -L --max-time 30 ' . ( '' !== $jar ? '-b ' . escapeshellarg( $jar ) . ' -c ' . escapeshellarg( $jar ) . ' ' : '' ) . escapeshellarg( $url );
	return (string) shell_exec( $cmd );
}

try {
	$cat   = ce_wc_term( 'CE Demo Koşu' );
	$p1    = ce_wc_product( 'CE Demo Ürün A', '64.90', array( $cat ) );
	$p2    = ce_wc_product( 'CE Demo Ürün B', '39.90', array( $cat ) );
	$p3    = ce_wc_product( 'CE Demo Ürün C', '129.90' );
	$admin = 1;
	$sm    = ce_admin_user( 'sm', 'shop_manager' );
	$sub   = ce_admin_user( 'sub', 'subscriber' );
	$users = array( $sm, $sub );

	// ------------------------------------------------------------------ (a) wizard save with arrays round-trips
	$input = array(
		'type'             => 'product_percent',
		'percent'          => '12,5',
		'include_products' => array( (string) $p1, (string) $p2 ),
		'include_terms'    => array( (string) $cat ),
		'exclude_products' => (string) $p3,
		'sku'              => 'demo-sku-1, DEMO-SKU-2',
		'basis'            => 'current',
		'rules_json'       => wp_json_encode(
			array(
				'op'       => 'and',
				'children' => array(
					array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => '150,00' ),
					array(
						'op'       => 'or',
						'children' => array(
							array( 'condition' => 'date.weekday', 'cmp' => 'in', 'value' => array( 'SA', 'SU' ) ),
							array( 'condition' => 'customer.role', 'cmp' => 'in', 'value' => array( 'customer' ) ),
							array( 'condition' => 'item.price', 'cmp' => 'between', 'value' => array( '10', '99,90' ) ),
						),
					),
				),
			)
		),
		'priority'         => '20',
		'max_discount'     => '50',
		'usage_limit'      => '100',
		'usage_limit_per_customer' => '',
		'starts_at'        => '2026-10-01T09:00',
		'ends_at'          => '2027-12-31T23:59',
		'recurrence'       => array( 'freq' => 'weekly', 'days' => array( 'SA', 'SU' ), 'start' => '00:00', 'end' => '23:59' ),
		'badge'            => 'Hafta sonu',
		'public'           => 'yes',
		'description'      => "Demo \"tırnak\" \\ ters bölü\nikinci satır",
	);
	$id = ce_wc_campaign( Definitions::get( 'percent_off' )['definition'], 'Demo Taslak', 'draft' );
	$def = ce_admin_save( $id, $admin, $input );
	$tz  = wp_timezone();
	ce_ok( '(a) saved definition is an array', is_array( $def ) );
	ce_ok( '(a) target product ids kept as a list', array( $p1, $p2 ) === ( $def['targets']['include']['product'] ?? null ) );
	ce_ok( '(a) target category ids kept', array( $cat ) === ( $def['targets']['include']['term'] ?? null ) );
	ce_ok( '(a) exclude ids from a comma string', array( $p3 ) === ( $def['targets']['exclude']['product'] ?? null ) );
	ce_ok( '(a) SKUs kept', array( 'demo-sku-1', 'DEMO-SKU-2' ) === ( $def['targets']['include']['sku'] ?? null ) );
	$expect_rules = array(
		'op'       => 'and',
		'children' => array(
			array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 15000 ),
			array(
				'op'       => 'or',
				'children' => array(
					array( 'condition' => 'date.weekday', 'cmp' => 'in', 'value' => array( 'SA', 'SU' ) ),
					array( 'condition' => 'customer.role', 'cmp' => 'in', 'value' => array( 'customer' ) ),
					array( 'condition' => 'item.price', 'cmp' => 'between', 'value' => array( 1000, 9990 ) ),
				),
			),
		),
	);
	ce_ok( '(a) conditions JSON stored as a nested tree, money in minor units', $expect_rules === ( $def['rules'] ?? null ) );
	ce_ok( '(a) percent "12,5" -> 12.5', '12.5' === ( $def['actions'][0]['percent'] ?? null ) && 'product_percent' === $def['actions'][0]['type'] );
	ce_ok( '(a) cap 50 -> 5000 minor', 5000 === ( $def['max_discount_minor'] ?? null ) );
	ce_ok( '(a) priority and usage limit integers', 20 === ( $def['priority'] ?? null ) && 100 === ( $def['usage_limit'] ?? null ) && ! isset( $def['usage_limit_per_customer'] ) );
	ce_ok( '(a) season start/end in the site zone', ( new DateTimeImmutable( '2026-10-01 09:00', $tz ) )->getTimestamp() === ( $def['schedule']['starts_ts'] ?? null ) && ( new DateTimeImmutable( '2027-12-31 23:59', $tz ) )->getTimestamp() === ( $def['schedule']['ends_ts'] ?? null ) );
	ce_ok( '(a) weekly recurrence kept', array( 'SA', 'SU' ) === ( $def['schedule']['recurrence']['days'] ?? null ) );
	ce_ok( '(a) description keeps quotes, backslash and newline', "Demo \"tırnak\" \\ ters bölü\nikinci satır" === ( $def['meta']['description'] ?? null ) );
	ce_ok( '(a) badge and page flag in meta', 'Hafta sonu' === ( $def['meta']['badge'] ?? null ) && true === ( $def['meta']['public'] ?? null ) );
	ce_ok( '(a) compiled row active', 'active' === ( CampaignRepository::row( $id )['status'] ?? '' ) );
	$again = WizardForm::parse( WizardForm::fromDefinition( $def ) );
	ce_ok( '(a) form -> definition -> form -> definition is lossless', array() === $again['errors'] && $def === $again['definition'] );
	$coded = $def;
	$coded['actions'][] = array( 'type' => 'future_reward', 'reward' => 'fixed', 'value' => 2500 );
	$coded['actions'][] = array( 'type' => 'cart_fixed', 'amount' => 700 );
	$coded['meta']['custom_key'] = 'kept';
	$again = WizardForm::parse( WizardForm::fromDefinition( $coded ) );
	ce_ok( '(a) actions and meta the wizard has no field for survive a round-trip', $coded === $again['definition'] );

	// Invalid values: field errors, nothing written, a new campaign stays a draft.
	$bad   = ce_wc_campaign( Definitions::get( 'percent_off' )['definition'], 'Demo Taslak 2', 'draft' );
	$prev  = get_post_meta( $bad, CampaignWriter::META, true );
	$after = ce_admin_save( $bad, $admin, array_merge( $input, array( 'percent' => '150' ) ) );
	ce_ok( '(a) invalid percent: definition unchanged', $prev === $after );
	ce_ok( '(a) invalid percent: publishing refused, still a draft', 'draft' === get_post_status( $bad ) );
	$errs = get_transient( 'eru_ce_wizard_' . $admin . '_' . $bad );
	ce_ok( '(a) invalid percent: field error kept for the screen', is_array( $errs ) && isset( $errs['errors']['percent'] ) );
	ce_ok( '(a) invalid percent: submitted input kept for the form', '150' === ( $errs['input']['percent'] ?? null ) );
	$parsed = WizardForm::parse( array_merge( $input, array( 'max_discount' => '12abc', 'rules_json' => '{"op":"and","children":[{"condition":"cart.subtotal","cmp":">=","value":"x"}]}' ) ) );
	ce_ok( '(a) junk money and junk condition amount are field errors', isset( $parsed['errors']['max_discount'] ) && isset( $parsed['errors']['rules'] ) );
	$parsed = WizardForm::parse( array_merge( $input, array( 'rules_json' => '{not json' ) ) );
	ce_ok( '(a) broken conditions JSON is a field error, never "no conditions"', isset( $parsed['errors']['rules'] ) );
	$parsed = WizardForm::parse( array_merge( $input, array( 'include_products' => array( (string) $p1, 'abc' ) ) ) );
	ce_ok( '(a) a malformed target id is an error, never dropped (would widen the campaign)', isset( $parsed['errors']['include_products'] ) );

	// ------------------------------------------------------------------ exclusive warning, trace texts, templates
	$w = WizardForm::warnings( array( 'actions' => array( array( 'type' => 'free_shipping' ) ), 'exclusive' => true ) );
	ce_ok( 'exclusive campaign without line discounts: warning', array() !== $w && false !== stripos( implode( ' ', $w ), 'tek başına' ) );
	ce_ok( 'exclusive reward-only campaign: warning', array() !== WizardForm::warnings( array( 'actions' => array( array( 'type' => 'future_reward', 'reward' => 'fixed', 'value' => 100 ) ), 'exclusive' => true ) ) );
	ce_ok( 'exclusive percent campaign: no warning', array() === WizardForm::warnings( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ) ), 'exclusive' => true ) ) );
	$codes = array(
		array( 'schedule', 'fail', 'outside_schedule', array() ),
		array( 'coupons', 'fail', 'coupon_in_cart', array() ),
		array( 'scope', 'fail', 'no_scope', array() ),
		array( 'usage', 'fail', 'usage_limit', array() ),
		array( 'conditions', 'fail', 'conditions', array( 'pass' => 'cart' ) ),
		array( 'conditions', 'fail', 'conditions', array( 'pass' => 'item' ) ),
		array( 'action', 'fail', 'unknown_action', array( 'type' => 'x' ) ),
		array( 'action', 'fail', 'invalid_args', array( 'type' => 'product_percent', 'field' => 'percent', 'reason' => 'out_of_range' ) ),
		array( 'action', 'fail', 'no_discount', array() ),
		array( 'exclusive', 'fail', 'exclusive', array( 'winner' => 7 ) ),
		array( 'exclusive', 'info', 'excluded_others', array( 'excluded' => array( 3, 4 ) ) ),
		array( 'stacking', 'fail', 'stacking', array() ),
		array( 'stacking', 'info', 'stacking', array( 'line' => 'k', 'winner' => 2 ) ),
		array( 'stacking', 'fail', 'lost_all_lines', array() ),
		array( 'campaign_cap', 'info', 'campaign_cap', array( 'cap' => 500 ) ),
		array( 'cart_cap', 'info', 'cart_cap', array( 'cap' => '20' ) ),
		array( 'min_price', 'info', 'min_price', array( 'line' => 'k' ) ),
		array( 'min_price', 'info', 'line_missing', array( 'line' => 'k' ) ),
		array( 'action', 'pass', 'ok', array() ),
	);
	$untranslated = array();
	foreach ( $codes as $c ) {
		$text = TraceText::describe( array( 'campaign_id' => 5, 'step' => $c[0], 'status' => $c[1], 'code' => $c[2], 'data' => $c[3] ) );
		if ( '' === $text || false !== strpos( $text, $c[2] ) && 'ok' !== $c[2] || ! preg_match( '/[çğıöşüÇĞİÖŞÜ]|kampanya|sepet/iu', $text ) ) {
			$untranslated[] = $c[2] . ' => ' . $text;
		}
	}
	ce_ok( 'every engine trace code has Turkish admin text' . ( $untranslated ? ' — ' . implode( '; ', $untranslated ) : '' ), array() === $untranslated );
	ce_ok( 'invalid_args text names the field in Turkish', false !== mb_stripos( TraceText::describe( array( 'campaign_id' => 5, 'step' => 'action', 'status' => 'fail', 'code' => 'invalid_args', 'data' => array( 'type' => 'product_percent', 'field' => 'percent', 'reason' => 'out_of_range' ) ) ), 'yüzde' ) );
	$fs_badge = \Erusoft\CampaignEngine\Presentation\Texts::badge( \Erusoft\CampaignEngine\Engine\Campaign\Campaign::fromArray( Definitions::get( 'free_shipping' )['definition'] + array( 'id' => 1 ) ) );
	ce_ok( 'threshold campaign badge names its threshold, never a bare "Ücretsiz kargo"', false !== strpos( $fs_badge, 've üzeri' ) );
	$all = Definitions::all();
	ce_ok( 'template gallery has the ten templates', 10 === count( $all ) );
	$broken = array();
	foreach ( $all as $tid => $t ) {
		$v = CampaignWriter::validate( $t['definition'] );
		if ( is_wp_error( $v ) || '' === $t['title'] || '' === $t['summary'] ) {
			$broken[] = $tid;
		}
	}
	ce_ok( 'every template validates with the engine readers' . ( $broken ? ' — ' . implode( ', ', $broken ) : '' ), array() === $broken );
	$json = wp_json_encode( $all );
	ce_ok( 'templates carry none of the reserved demo figures', ! preg_match( '/\b(12000|150000|50000|75000|40000|2000000|10000)\b/', $json ) );

	// ------------------------------------------------------------------ (b) refusals without cap or nonce; shop manager passes
	$before = get_post_meta( $id, CampaignWriter::META, true );
	ce_ok( '(b) wizard: no nonce -> definition unchanged', $before === ce_admin_save( $id, $admin, array_merge( $input, array( 'percent' => '15' ) ), false ) );
	ce_ok( '(b) wizard: forged nonce -> definition unchanged', $before === ce_admin_save( $id, $admin, array_merge( $input, array( 'percent' => '15' ) ), 'abc123' ) );
	ce_ok( '(b) wizard: subscriber with a nonce -> definition unchanged', $before === ce_admin_save( $id, $sub, array_merge( $input, array( 'percent' => '15' ) ) ) );
	wp_set_current_user( $sm );
	ce_ok( '(b) shop manager holds the campaign meta capability', current_user_can( 'manage_eru_ce_campaigns' ) && current_user_can( 'edit_post', $id ) && current_user_can( 'manage_eru_ce_settings' ) );
	$after = ce_admin_save( $id, $sm, array_merge( $input, array( 'percent' => '15' ) ) );
	ce_ok( '(b) wizard: shop manager with a nonce -> saved', '15' === ( $after['actions'][0]['percent'] ?? null ) );

	$preview_req = function ( $user, $nonce ) use ( $input, $p1 ) {
		wp_set_current_user( $user );
		$_POST = array(
			'eru_ce'      => wp_slash( $input ),
			'cart'        => 'custom',
			'cart_lines'  => $p1 . 'x3',
			'customer'    => 'guest',
		);
		if ( $nonce ) {
			$_POST['nonce'] = wp_create_nonce( 'eru_ce_preview' );
		}
		$_REQUEST = $_POST;
		$r = ce_admin_ajax( array( PreviewController::class, 'ajax' ) );
		$_POST = $_REQUEST = array();
		return $r;
	};
	$r = $preview_req( $admin, false );
	ce_ok( '(b) preview: no nonce -> refused', is_array( $r ) && false === $r['success'] );
	$r = $preview_req( $sub, true );
	ce_ok( '(b) preview: subscriber -> refused', is_array( $r ) && false === $r['success'] );
	$r = $preview_req( $sm, true );
	ce_ok( '(b) preview: shop manager -> lines and translated trace', is_array( $r ) && true === $r['success'] && isset( $r['data']['lines'] ) && is_array( $r['data']['trace'] ?? null ) );

	$bulk = function ( $user, $nonce, $action, $ids ) {
		wp_set_current_user( $user );
		$_REQUEST = $nonce ? array( '_wpnonce' => wp_create_nonce( 'bulk-posts' ) ) : array();
		$back = BulkActions::handle( 'edit.php?post_type=eru_ce_campaign', $action, $ids );
		$_REQUEST = array();
		return $back;
	};
	$bulk( $admin, false, 'eru_ce_pause', array( $id ) );
	ce_ok( '(b) bulk pause: no nonce -> still active', 'active' === CampaignRepository::row( $id )['status'] );
	$bulk( $sub, true, 'eru_ce_pause', array( $id ) );
	ce_ok( '(b) bulk pause: subscriber -> still active', 'active' === CampaignRepository::row( $id )['status'] );
	$back = $bulk( $sm, true, 'eru_ce_pause', array( $id ) );
	ce_ok( '(b) bulk pause: shop manager -> paused and inactive', 'inactive' === CampaignRepository::row( $id )['status'] && true === get_post_meta( $id, CampaignWriter::META, true )['paused'] && false !== strpos( $back, 'eru_ce_paused=1' ) );
	$bulk( $sm, true, 'eru_ce_resume', array( $id ) );
	ce_ok( '(b) bulk resume: active again, definition otherwise unchanged', 'active' === CampaignRepository::row( $id )['status'] && '15' === get_post_meta( $id, CampaignWriter::META, true )['actions'][0]['percent'] );

	wp_set_current_user( $sub );
	ce_ok( '(b) template create: subscriber refused', is_wp_error( TemplateGallery::createFrom( 'buy_x_pay_y', wp_create_nonce( 'eru_ce_template' ) ) ) );
	wp_set_current_user( $sm );
	ce_ok( '(b) template create: bad nonce refused', is_wp_error( TemplateGallery::createFrom( 'buy_x_pay_y', 'abc' ) ) );
	$tpl = TemplateGallery::createFrom( 'buy_x_pay_y', wp_create_nonce( 'eru_ce_template' ) );
	ce_ok( '(b) template create: shop manager gets a draft', is_int( $tpl ) && 'draft' === get_post_status( $tpl ) );
	if ( is_int( $tpl ) ) {
		update_post_meta( $tpl, '_ce_test', CE_RUN );
	}
	wp_set_current_user( $sub );
	ce_ok( '(b) settings save: subscriber refused', is_wp_error( SettingsScreen::save( array( 'stacking' => 'stack' ), wp_create_nonce( 'eru_ce_settings' ) ) ) );
	wp_set_current_user( $sm );
	ce_ok( '(b) settings save: bad nonce refused', is_wp_error( SettingsScreen::save( array( 'stacking' => 'stack' ), 'abc' ) ) && 'best' === Options::get( 'stacking' ) );
	ce_ok( '(b) settings save: shop manager saves', true === SettingsScreen::save( array( 'stacking' => 'first', 'min_unit_price' => '1,50' ), wp_create_nonce( 'eru_ce_settings' ) ) && 'first' === Options::get( 'stacking' ) && 150 === (int) Options::get( 'min_unit_price_minor' ) );
	SettingsScreen::save( array( 'stacking' => 'best', 'min_unit_price' => '0' ), wp_create_nonce( 'eru_ce_settings' ) );

	// Slugs: format, uniqueness, clash with a page, rewrite flag only on change.
	$nonce = wp_create_nonce( 'eru_ce_settings' );
	ce_ok( 'slug with a slash refused', is_wp_error( SettingsScreen::save( array( 'archive_slug' => 'kam/pa' ), $nonce ) ) );
	ce_ok( 'archive and detail slug equal refused', is_wp_error( SettingsScreen::save( array( 'archive_slug' => 'firsat', 'single_slug' => 'firsat' ), $nonce ) ) );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'CE Demo Sayfa ' . CE_RUN, 'post_name' => 'ce-demo-sayfa-' . CE_RUN, 'post_status' => 'publish' ) );
	update_post_meta( $page, '_ce_test', CE_RUN );
	$clash = SettingsScreen::save( array( 'archive_slug' => 'ce-demo-sayfa-' . CE_RUN ), $nonce );
	wp_delete_post( $page, true );
	ce_ok( 'slug used by a page refused (would hide the page)', is_wp_error( $clash ) );
	StorefrontOptions::update( array( 'rewrite_pending' => 0 ) );
	SettingsScreen::save( array( 'stacking' => 'best' ), $nonce );
	ce_ok( 'saving without a slug change: no rewrite flush pending', 0 === (int) StorefrontOptions::get( 'rewrite_pending' ) );
	SettingsScreen::save( array( 'pages' => 'yes' ), $nonce );
	ce_ok( 'turning campaign pages on: rewrite flush pending', 1 === (int) StorefrontOptions::get( 'rewrite_pending' ) );
	StorefrontOptions::update( StorefrontOptions::defaults() );

	// ------------------------------------------------------------------ (c) preview of "3 al 2 öde" = cart applier
	wp_set_current_user( 0 );
	$tdef = Definitions::get( 'buy_x_pay_y' )['definition'];
	$tdef['targets'] = array( 'include' => array( 'product' => array( $p1 ) ) );
	$preview = PreviewController::run( $tdef, array( array( $p1, 3 ) ) );
	ce_ok( '(c) preview: 3 × 64.90, one unit free -> 64.90 off', 6490 === $preview['total_discount_minor'] );
	wp_trash_post( $id );
	$xy = ce_wc_campaign( $tdef, 'Demo 3 Al 2 Öde' );
	$cart = ce_wc_fill( array( array( $p1, 3 ) ) );
	$res  = CartApplier::resultFor( $cart );
	ce_ok( '(c) cart applier gives the same discount as the preview', $res && $res->adjustments->totalDiscount() === $preview['total_discount_minor'] );
	ce_ok( '(c) preview lines match the cart line', ( $preview['lines'][0]['discount_minor'] ?? -1 ) === $res->adjustments->totalDiscount() && 12980 === ce_minor( $cart->get_subtotal() ) );
	$p2view = PreviewController::run( $tdef, array( array( $p1, 2 ) ) );
	ce_ok( '(c) preview of an incomplete set: nothing, and a translated reason', 0 === $p2view['total_discount_minor'] && array() !== $p2view['trace'] );
	WC()->cart->empty_cart();

	// ------------------------------------------------------------------ (d) clock route: server time + real end; read-only namespace
	$ends = time() + 3 * DAY_IN_SECONDS + 3600;
	$flash = ce_wc_campaign(
		array(
			'actions'  => array( array( 'type' => 'product_percent', 'percent' => '7' ) ),
			'targets'  => array( 'include' => array( 'product' => array( $p2 ) ) ),
			'schedule' => array( 'starts_ts' => time() - 3600, 'ends_ts' => $ends, 'timezone' => wp_timezone_string() ),
			'meta'     => array( 'public' => true ),
		),
		'Demo Hafta Sonu'
	);
	StorefrontOptions::update( array( 'gain_box' => 'yes' ) );
	$GLOBALS['wp_rest_server'] = null;
	$server = rest_get_server();
	$req    = new WP_REST_Request( 'GET', '/eru-ce/v1/clock' );
	$req->set_param( 'campaign', $flash );
	$t0   = time();
	$resp = rest_do_request( $req );
	$data = $resp->get_data();
	ce_ok( '(d) clock: 200 with server time', 200 === $resp->get_status() && abs( (int) ( $data['now'] ?? 0 ) - $t0 ) <= 5 );
	ce_ok( '(d) clock: the campaign\'s real end', $ends === (int) ( $data['ends'] ?? 0 ) );
	$req2 = new WP_REST_Request( 'GET', '/eru-ce/v1/clock' );
	$req2->set_param( 'campaign', $xy );
	$d2 = rest_do_request( $req2 )->get_data();
	ce_ok( '(d) clock: a campaign without an end gives no end (no invented countdown)', array_key_exists( 'ends', $d2 ) && null === $d2['ends'] );
	$req3 = new WP_REST_Request( 'GET', '/eru-ce/v1/clock' );
	$req3->set_param( 'campaign', $bad );
	$d3 = rest_do_request( $req3 )->get_data();
	ce_ok( '(d) clock: a draft campaign reveals nothing', null === ( $d3['ends'] ?? null ) );
	$writes = array();
	$routes = 0;
	foreach ( $server->get_routes() as $route => $handlers ) {
		if ( 0 !== strpos( $route, '/eru-ce/v1' ) ) {
			continue;
		}
		++$routes;
		foreach ( $handlers as $h ) {
			foreach ( array_keys( (array) $h['methods'] ) as $m ) {
				if ( ! in_array( $m, array( 'GET', 'HEAD' ), true ) ) {
					$writes[] = $route . ' ' . $m;
				}
			}
		}
	}
	ce_ok( '(d) eru-ce/v1 has routes and none of them writes' . ( $writes ? ' — ' . implode( ', ', $writes ) : '' ), $routes > 0 && array() === $writes );
	StorefrontOptions::update( StorefrontOptions::defaults() );

	// ------------------------------------------------------------------ (e) storefront modules off -> no eru-ce markup (real HTTP)
	update_option( 'woocommerce_coming_soon', 'no' ); // The demo store hides its pages from guests; restored below.
	$jar  = tempnam( sys_get_temp_dir(), 'cejar' );
	$shop = wc_get_page_permalink( 'shop' );
	$pdp  = get_permalink( $p2 );
	$cart_url = wc_get_cart_url();
	ce_admin_http( add_query_arg( 'add-to-cart', $p1, home_url( '/' ) ), $jar );
	$pages = array(
		'shop'    => ce_admin_http( add_query_arg( 'x', CE_RUN, $shop ) ),
		'product' => ce_admin_http( add_query_arg( 'x', CE_RUN, $pdp ) ),
		'cart'    => ce_admin_http( add_query_arg( 'x', CE_RUN, $cart_url ), $jar ),
	);
	$expect = array(
		'shop'    => 'CE Demo Ürün A',
		'product' => 'CE Demo Ürün B',
		'cart'    => 'wp-block-woocommerce-cart',
	);
	foreach ( $pages as $name => $html ) {
		ce_ok( '(e) modules off: ' . $name . ' page rendered (not a placeholder)', false !== strpos( $html, $expect[ $name ] ) );
		ce_ok( '(e) modules off: no eru-ce markup on the ' . $name . ' page', false === stripos( $html, 'eru-ce' ) );
	}
	StorefrontOptions::update( array( 'badge' => 'yes', 'price_html' => 'yes', 'gain_box' => 'yes' ) );
	$on_pdp  = ce_admin_http( add_query_arg( 'x', CE_RUN . 'b', $pdp ) );
	$on_shop = ce_admin_http( add_query_arg( 'x', CE_RUN . 'b', $shop ) );
	ce_ok( '(e) control: gain box on the product page once enabled', false !== strpos( $on_pdp, 'eru-ce-gain' ) && false !== strpos( $on_pdp, 'Demo Hafta Sonu' ) );
	ce_ok( '(e) control: real end time drives the countdown', false !== strpos( $on_pdp, 'data-eru-ce-end="' . $ends . '"' ) );
	ce_ok( '(e) control: badge in the shop once enabled', false !== strpos( $on_shop, 'eru-ce-badge' ) );
	ce_ok( '(e) control: block templates get one badge per card, inside the image (no second copy from the classic hook)', ! preg_match( '#</a></div>\s*<span class="eru-ce-badge"#', $on_shop ) );
	ce_ok( '(e) control: product without an end time gets no countdown', false === strpos( ce_admin_http( add_query_arg( 'x', CE_RUN . 'c', get_permalink( $p1 ) ) ), 'data-eru-ce-end' ) );
	StorefrontOptions::update( StorefrontOptions::defaults() );
	null === $keep_soon ? delete_option( 'woocommerce_coming_soon' ) : update_option( 'woocommerce_coming_soon', $keep_soon );
	@unlink( $jar ); // phpcs:ignore

	// ------------------------------------------------------------------ storefront honesty: visitor-conditional campaigns never change shown prices
	$pf = ce_wc_product( 'CE Demo Ürün İlk', '74.90' );
	$pu = ce_wc_product( 'CE Demo Ürün Herkes', '54.90' );
	$pw = ce_wc_product( 'CE Demo Ürün Gün', '44.90' );
	ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '8' ) ),
			'targets' => array( 'include' => array( 'product' => array( $pf ) ) ),
			'rules'   => array( 'op' => 'and', 'children' => array( array( 'condition' => 'customer.first_order', 'value' => true ) ) ),
		),
		'Demo İlk Sipariş'
	);
	ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '6' ) ),
			'targets' => array( 'include' => array( 'product' => array( $pu ) ) ),
		),
		'Demo Herkese'
	);
	ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '9' ) ),
			'targets' => array( 'include' => array( 'product' => array( $pw ) ) ),
			'rules'   => array( 'op' => 'and', 'children' => array( array( 'condition' => 'date.weekday', 'cmp' => 'in', 'value' => array( 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU' ) ) ) ),
		),
		'Demo Gün'
	);
	ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'free_shipping' ) ),
			'targets' => array( 'include' => array( 'product' => array( $pw ) ) ),
			'rules'   => array( 'op' => 'and', 'children' => array( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 98700 ) ) ),
		),
		'Demo Eşik'
	);
	$ps = ce_wc_product( 'CE Demo Ürün Ülke', '64.90' );
	ce_wc_campaign(
		array(
			'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ) ),
			'targets' => array( 'include' => array( 'product' => array( $ps ) ) ),
			'rules'   => array( 'op' => 'and', 'children' => array( array( 'condition' => 'cart.shipping_country', 'cmp' => 'in', 'value' => array( 'TR' ) ) ) ),
		),
		'Demo Ülke'
	);
	$returning = ce_admin_user( 'ret', 'customer' );
	$users[]   = $returning;
	\Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository::apply( $returning, ce_mail( 'ret' ), 3, 45000, time() - 20 * DAY_IN_SECONDS, 0 );
	$front = function ( $user, $product_id ) {
		wp_set_current_user( $user );
		WC()->customer = new WC_Customer( $user, true );
		\Erusoft\CampaignEngine\Presentation\Storefront\ProductView::flush();
		\Erusoft\CampaignEngine\Presentation\Storefront\GainBox::reset();
		$p = wc_get_product( $product_id );
		return array(
			'gain'  => \Erusoft\CampaignEngine\Presentation\Storefront\GainBox::html( $p ),
			'price' => \Erusoft\CampaignEngine\Presentation\Storefront\PriceHtml::filter( 'PLAIN', $p ),
			'badge' => \Erusoft\CampaignEngine\Presentation\Storefront\ListBadge::html( $p ),
		);
	};
	$g = $front( 0, $pf );
	$r = $front( $returning, $pf );
	ce_ok( 'first-order campaign: guest and returning customer get byte-identical product output', $g === $r );
	ce_ok( 'first-order campaign: no struck-through price', 'PLAIN' === $g['price'] );
	ce_ok( 'first-order campaign: condition note, not listed as "geçerli"', false !== strpos( $g['gain'], 'İlk siparişinize özel' ) && false === strpos( $g['gain'], 'geçerli kampanyalar' ) );
	ce_ok( 'first-order campaign: no list badge', '' === $g['badge'] );
	$u = $front( 0, $pu );
	ce_ok( 'unconditional campaign: struck-through price and listed as "geçerli"', false !== strpos( $u['price'], 'eru-ce-price' ) && false !== strpos( $u['price'], '<del' ) && false !== strpos( $u['gain'], 'geçerli kampanyalar' ) );
	ce_ok( 'unconditional campaign: same output for a logged-in returning customer', $u === $front( $returning, $pu ) );
	$w = $front( 0, $pw );
	ce_ok( 'weekday campaign: no struck-through price even while it runs', 'PLAIN' === $w['price'] );
	ce_ok( 'weekday campaign: day note in the gain box', false !== strpos( $w['gain'], 'günleri' ) && false === strpos( $w['gain'], 'geçerli kampanyalar' ) );
	ce_ok( 'cart-threshold campaign: listed under the conditions with its threshold, not as "geçerli"', false !== strpos( $w['gain'], 'Demo Eşik' ) && false !== strpos( $w['gain'], 've üzeri alışverişte' ) );
	$sc = $front( 0, $ps );
	ce_ok( 'shipping-country campaign: listed with a non-empty condition note', false !== strpos( $sc['gain'], 'Demo Ülke' ) && false !== strpos( $sc['gain'], 'Teslimat ülkesine bağlı' ) && 'PLAIN' === $sc['price'] );
	$empty = array();
	foreach ( array( $pf, $pu, $pw, $ps, $p1, $p2 ) as $fixture ) {
		\Erusoft\CampaignEngine\Presentation\Storefront\ProductView::flush();
		foreach ( \Erusoft\CampaignEngine\Presentation\Storefront\ProductView::of( wc_get_product( $fixture ) )['others'] as $other ) {
			if ( '' === trim( $other['note'] ) ) {
				$empty[] = $other['campaign']->label;
			}
		}
	}
	ce_ok( 'no conditional storefront line renders without a condition note' . ( $empty ? ' — ' . implode( ', ', $empty ) : '' ), array() === $empty );
	$wk = \Erusoft\CampaignEngine\Presentation\Storefront\Audience::note( \Erusoft\CampaignEngine\Engine\Campaign\Campaign::fromArray( array( 'id' => 1, 'actions' => array( array( 'type' => 'cart_percent', 'percent' => '8' ) ), 'rules' => array( 'condition' => 'date.weekday', 'cmp' => 'in', 'value' => array( 'SA', 'SU' ) ) ) ) );
	ce_ok( 'weekend weekday rule reads "Hafta sonu"', 'Hafta sonu' === $wk );
	$set = $front( 0, $p1 );
	ce_ok( 'a set campaign that does not apply to one unit is never "geçerli"', false === strpos( $set['gain'], 'geçerli kampanyalar' ) && false !== strpos( $set['gain'], '3 al 2 öde' ) );
	wp_set_current_user( 1 );
	WC()->customer = new WC_Customer( 0, true );

	// ------------------------------------------------------------------ clock rate-limit key filter
	$limit  = fn() => 1;
	$client = 'demo-a';
	$key    = function () use ( &$client ) {
		return $client;
	};
	add_filter( 'eru_ce_clock_rate_limit', $limit );
	add_filter( 'eru_ce_clock_client', $key );
	StorefrontOptions::update( array( 'gain_box' => 'yes' ) );
	$clock = fn() => rest_do_request( new WP_REST_Request( 'GET', '/eru-ce/v1/clock' ) )->get_status();
	$client = 'demo-a-' . CE_RUN;
	$first  = $clock();
	$second = $clock();
	$client = 'demo-b-' . CE_RUN;
	$third  = $clock();
	remove_filter( 'eru_ce_clock_rate_limit', $limit );
	remove_filter( 'eru_ce_clock_client', $key );
	StorefrontOptions::update( StorefrontOptions::defaults() );
	ce_ok( 'clock: eru_ce_clock_client decides the rate-limit bucket (200, 429, other client 200)', 200 === $first && 429 === $second && 200 === $third );

	// ------------------------------------------------------------------ (f) overlap notice when automatic-discounts is active
	wp_set_current_user( $admin );
	$ad = 'automatic-discounts-for-woocommerce/automatic-discounts-for-woocommerce.php';
	ce_ok( '(f) automatic-discounts is linked in the demo', file_exists( WP_PLUGIN_DIR . '/' . $ad ) );
	$was_active = is_plugin_active( $ad );
	if ( $was_active ) {
		deactivate_plugins( $ad, true );
	}
	ob_start();
	ConflictNotice::render();
	$none = (string) ob_get_clean();
	ce_ok( '(f) no overlapping plugin -> no notice', false === strpos( $none, 'eru-ce-conflict' ) );
	activate_plugin( $ad, '', false, true );
	ob_start();
	ConflictNotice::render();
	$notice = (string) ob_get_clean();
	$rows   = Doctor::report();
	$overlap = array_values( array_filter( $rows, fn( $r ) => 'overlap' === $r['key'] ) );
	deactivate_plugins( $ad, true );
	if ( $was_active ) {
		activate_plugin( $ad, '', false, true );
	}
	ce_ok( '(f) notice names the plugin and the double discount', false !== strpos( $notice, 'eru-ce-conflict' ) && false !== strpos( $notice, 'Automatic Discounts' ) && false !== strpos( $notice, 'iki kez' ) );
	ce_ok( '(f) notice is dismissible by POST with a nonce', false !== strpos( $notice, 'method="post"' ) && false !== strpos( $notice, 'eru_ce_dismiss_conflict' ) && false !== strpos( $notice, '_wpnonce' ) );
	ce_ok( '(f) status screen row warns about the overlap', $overlap && 'warn' === $overlap[0]['status'] );
	wp_set_current_user( $sub );
	ob_start();
	activate_plugin( $ad, '', false, true );
	ConflictNotice::render();
	$sub_notice = (string) ob_get_clean();
	deactivate_plugins( $ad, true );
	if ( $was_active ) {
		activate_plugin( $ad, '', false, true );
	}
	ce_ok( '(f) notice hidden from users without the capability', '' === trim( $sub_notice ) );
	update_option( 'active_plugins', $keep_plugins );

	// ------------------------------------------------------------------ doctor basics
	wp_set_current_user( $admin );
	$keys = array_column( Doctor::report(), 'status', 'key' );
	ce_ok( 'doctor: tables, Action Scheduler and database rows present', isset( $keys['tables'], $keys['action_scheduler'], $keys['database'], $keys['php'], $keys['hooks'] ) && 'ok' === $keys['tables'] );
	$late = function ( $price ) {
		return $price;
	};
	add_filter( 'woocommerce_product_get_price', $late, CartApplier::pricePriority() + 5 );
	$hooks = array_values( array_filter( Doctor::report(), fn( $r ) => 'hooks' === $r['key'] ) );
	remove_filter( 'woocommerce_product_get_price', $late, CartApplier::pricePriority() + 5 );
	ce_ok( 'doctor: a price filter after the campaign priority is reported', 'warn' === $hooks[0]['status'] );

	// ------------------------------------------------------------------ Hesabım → Ödüllerim
	$cust  = ce_admin_user( 'cust', 'customer' );
	$other = ce_admin_user( 'other', 'customer' );
	$users[] = $cust;
	$users[] = $other;
	global $wpdb;
	$rid = RewardRepository::insert( array( 'campaign_id' => $xy, 'source_order_id' => 990001, 'customer_id' => $cust, 'email' => ce_mail( 'cust' ), 'currency' => 'TRY', 'amount_minor' => 4590, 'available_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	RewardRepository::update( $rid, array( 'status' => 'available', 'code' => 'CEDEMO' . strtoupper( CE_RUN ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ) );
	$rid2 = RewardRepository::insert( array( 'campaign_id' => $xy, 'source_order_id' => 990002, 'customer_id' => $other, 'email' => ce_mail( 'other' ), 'currency' => 'TRY', 'amount_minor' => 3390, 'available_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	RewardRepository::update( $rid2, array( 'status' => 'available', 'code' => 'CEDEMOX' . strtoupper( CE_RUN ) ) );
	wp_set_current_user( $cust );
	ob_start();
	RewardsEndpoint::render();
	$mine = (string) ob_get_clean();
	ce_ok( 'Ödüllerim: own coupon code and amount listed', false !== strpos( $mine, 'CEDEMO' . strtoupper( CE_RUN ) ) && false !== strpos( $mine, '45' . wc_get_price_decimal_separator() . '90' ) );
	ce_ok( 'Ödüllerim: another customer\'s reward never shown', false === strpos( $mine, 'CEDEMOX' ) );
	ce_ok( 'Ödüllerim: menu item only when the setting is on', ! isset( RewardsEndpoint::menu( array( 'dashboard' => 'Panel' ) )[ RewardsEndpoint::KEY ] ) );
	StorefrontOptions::update( array( 'account' => 'yes' ) );
	ce_ok( 'Ödüllerim: menu item with the setting on, default slug odullerim', isset( RewardsEndpoint::menu( array( 'dashboard' => 'Panel', 'customer-logout' => 'Çıkış' ) )[ RewardsEndpoint::KEY ] ) && 'odullerim' === StorefrontOptions::get( 'account_endpoint' ) );
	StorefrontOptions::update( StorefrontOptions::defaults() );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RewardRepository::table() . ' WHERE id IN (%d, %d)', $rid, $rid2 ) );

	// C1: a guest's reward code is never shown to an account that merely uses the guest's e-mail.
	$guest_mail  = ce_mail( 'gmail' );
	$guest_order = wc_create_order( array( 'status' => 'completed' ) );
	$guest_order->set_billing_email( $guest_mail );
	$guest_order->update_meta_data( '_ce_test', CE_RUN );
	$guest_order->save();
	$grid = RewardRepository::insert( array( 'campaign_id' => $xy, 'source_order_id' => $guest_order->get_id(), 'customer_id' => 0, 'email' => $guest_mail, 'currency' => 'TRY', 'amount_minor' => 2790, 'available_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	RewardRepository::update( $grid, array( 'status' => 'available', 'code' => 'CEDEMOG' . strtoupper( CE_RUN ) ) );
	$taker   = ce_admin_user( 'gmail', 'customer' ); // registered with the guest's e-mail
	$users[] = $taker;
	wp_set_current_user( $taker );
	ob_start();
	RewardsEndpoint::render();
	$taken = (string) ob_get_clean();
	ce_ok( 'C1: an account registered with a guest\'s e-mail sees no row and no code of that guest', false === strpos( $taken, 'CEDEMOG' ) && array() === RewardRepository::forCustomer( $taker ) );
	$guest_order = wc_get_order( $guest_order->get_id() );
	$guest_order->set_customer_id( $taker );
	$guest_order->save();
	ob_start();
	RewardsEndpoint::render();
	$linked = (string) ob_get_clean();
	ce_ok( 'C1: once the merchant links the source order to the account, its reward is listed', false !== strpos( $linked, 'CEDEMOG' . strtoupper( CE_RUN ) ) );
	$wpdb->delete( RewardRepository::table(), array( 'id' => $grid ) );
	wp_set_current_user( 1 );

	// M1: the first-order template is limited to one use per customer, held at checkout.
	ce_ok( 'M1: first-order template has a per-customer limit of 1', 1 === ( Definitions::get( 'first_order' )['definition']['usage_limit_per_customer'] ?? null ) );

	// M3: guests never get order-history benefits from a typed e-mail; first order still uses it.
	$hist = \Erusoft\CampaignEngine\Engine\Campaign\Campaign::fromArray( array( 'id' => 9, 'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ) ), 'rules' => array( 'condition' => 'customer.order_count', 'cmp' => '>=', 'value' => 2 ) ) );
	$spent = \Erusoft\CampaignEngine\Engine\Campaign\Campaign::fromArray( array( 'id' => 10, 'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ) ), 'rules' => array( 'condition' => 'customer.total_spent', 'cmp' => '>=', 'value' => 100 ) ) );
	$since = \Erusoft\CampaignEngine\Engine\Campaign\Campaign::fromArray( array( 'id' => 11, 'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ) ), 'rules' => array( 'condition' => 'customer.days_since_last_order', 'cmp' => '>=', 'value' => 1 ) ) );
	$first = \Erusoft\CampaignEngine\Engine\Campaign\Campaign::fromArray( array( 'id' => 12, 'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ) ), 'rules' => array( 'condition' => 'customer.first_order', 'value' => true ) ) );
	\Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository::apply( 0, ce_mail( 'gst' ), 4, 90000, time() - 30 * DAY_IN_SECONDS, 0 );
	$gctx = \Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory::fromProducts( array( array( wc_get_product( $p2 ), 1 ) ), \Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory::customer( 0, ce_mail( 'gst' ) ) );
	$uctx = \Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory::fromProducts( array( array( wc_get_product( $p2 ), 1 ) ), \Erusoft\CampaignEngine\Infrastructure\WooCommerce\ContextFactory::customer( $returning, ce_mail( 'ret' ) ) );
	$ev   = \Erusoft\CampaignEngine\Engine\Evaluator::withDefaults();
	foreach ( array( 'order count' => $hist, 'total spent' => $spent, 'days since last order' => $since ) as $what => $c ) {
		ce_ok( 'M3: guest with a typed e-mail that has history gets no ' . $what . ' campaign', 0 === $ev->evaluate( $gctx, array( $c ) )->adjustments->totalDiscount() );
		ce_ok( 'M3: the logged-in account with history gets the ' . $what . ' campaign', $ev->evaluate( $uctx, array( $c ) )->adjustments->totalDiscount() > 0 );
	}
	ce_ok( 'M3: first order still reads the typed e-mail (history -> not first)', 0 === $ev->evaluate( $gctx, array( $first ) )->adjustments->totalDiscount() );
	\Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository::delete( ce_mail( 'gst' ), 0 );

	// M5: config errors are not autoloaded.
	\Erusoft\CampaignEngine\Infrastructure\ConfigErrors::record( 'campaign', ce_raw_id(), 'percent', 'invalid', 1 );
	$auto = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", \Erusoft\CampaignEngine\Infrastructure\ConfigErrors::OPTION ) );
	\Erusoft\CampaignEngine\Infrastructure\ConfigErrors::clear( 'campaign', ce_raw_id() );
	ce_ok( 'M5: config errors option is not autoloaded (' . $auto . ')', in_array( $auto, array( 'no', 'off' ), true ) );

	// M7: a lock timeout says "yoğunluk", never "kullanım hakkı doldu".
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$busy  = ce_wc_campaign( array( 'actions' => array( array( 'type' => 'product_percent', 'percent' => '5' ) ), 'usage_limit' => 9 ), 'CE Demo Yoğun' );
	$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', \Erusoft\CampaignEngine\Infrastructure\Lock::name( 'usage_' . $busy ) ) );
	$short = fn() => 1;
	add_filter( 'eru_ce_lock_timeout', $short );
	$lo = wc_create_order( array( 'status' => 'pending' ) );
	$lo->set_billing_email( ce_mail( 'lock' ) );
	$lo->update_meta_data( '_ce_test', CE_RUN );
	$lo->update_meta_data( \Erusoft\CampaignEngine\Infrastructure\WooCommerce\OrderWriter::META, array( 'campaigns' => array( array( 'id' => $busy, 'discount_minor' => 100 ) ) ) );
	$lo->save();
	$lerr = new WP_Error();
	\Erusoft\CampaignEngine\Infrastructure\WooCommerce\ReservationGate::blockReserve( $lo, $lerr );
	remove_filter( 'eru_ce_lock_timeout', $short );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', \Erusoft\CampaignEngine\Infrastructure\Lock::name( 'usage_' . $busy ) ) );
	$other->close();
	$lmsg = (string) $lerr->get_error_message();
	ce_ok( 'M7: lock timeout -> "Şu anda yoğunluk var, lütfen tekrar deneyin." (' . $lmsg . ')', 'Şu anda yoğunluk var, lütfen tekrar deneyin.' === $lmsg );
	CampaignWriter::update( $busy, null, null, 'draft' );

	// M4: retention purge (only this run's rows are checked).
	$old  = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
	$rrid = RewardRepository::insert( array( 'campaign_id' => ce_raw_id(), 'source_order_id' => 990301, 'customer_id' => $returning, 'email' => ce_mail( 'ret' ), 'currency' => 'TRY', 'amount_minor' => 1200, 'available_at' => $old ) );
	$wpdb->update( RewardRepository::table(), array( 'status' => 'used', 'updated_at' => $old ), array( 'id' => $rrid ) );
	$open = RewardRepository::insert( array( 'campaign_id' => ce_raw_id(), 'source_order_id' => 990302, 'customer_id' => $returning, 'email' => ce_mail( 'ret' ), 'currency' => 'TRY', 'amount_minor' => 1300, 'available_at' => $old ) );
	$wpdb->update( RewardRepository::table(), array( 'status' => 'available', 'updated_at' => $old ), array( 'id' => $open ) );
	$utab = \Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository::table();
	$wpdb->insert( $utab, array( 'campaign_id' => ce_raw_id(), 'token' => ce_token( 'ret1' ), 'order_id' => 0, 'customer_id' => $returning, 'email_hash' => hash( 'sha256', ce_mail( 'ret' ) ), 'status' => 'consumed', 'discount_minor' => 100, 'created_at' => $old, 'updated_at' => $old ) );
	$uid1 = (int) $wpdb->insert_id;
	\Erusoft\CampaignEngine\Infrastructure\AuditLog::record( 'campaign', ce_raw_id(), 'ce_retention_probe', array() );
	$aid  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM ' . \Erusoft\CampaignEngine\Infrastructure\Database\Schema::table( 'audit_log' ) . ' WHERE object_id = %d AND action = %s', ce_raw_id(), 'ce_retention_probe' ) );
	$wpdb->update( \Erusoft\CampaignEngine\Infrastructure\Database\Schema::table( 'audit_log' ), array( 'created_at' => $old ), array( 'id' => $aid ) );
	ce_ok( 'M4: retention setting defaults to 365 days', 365 === (int) Options::get( 'retention_days' ) );
	\Erusoft\CampaignEngine\Infrastructure\Retention::run( 500 );
	$rr = RewardRepository::get( $rrid );
	$ro = RewardRepository::get( $open );
	$ur = $wpdb->get_row( $wpdb->prepare( "SELECT email_hash, customer_id FROM {$utab} WHERE id = %d", $uid1 ), ARRAY_A );
	ce_ok( 'M4: a used reward older than the retention loses its e-mail and account link', '' === $rr['email'] && 0 === (int) $rr['customer_id'] && 1200 === (int) $rr['amount_minor'] );
	ce_ok( 'M4: an available reward keeps its e-mail (its coupon is still live)', ce_mail( 'ret' ) === $ro['email'] );
	ce_ok( 'M4: an old usage row of an inactive campaign loses its hash', '' === $ur['email_hash'] && 0 === (int) $ur['customer_id'] );
	ce_ok( 'M4: an audit row older than the retention is deleted', null === $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \Erusoft\CampaignEngine\Infrastructure\Database\Schema::table( 'audit_log' ) . ' WHERE id = %d', $aid ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RewardRepository::table() . ' WHERE id IN (%d, %d)', $rrid, $open ) );
	$wpdb->delete( $utab, array( 'id' => $uid1 ) );

	// ------------------------------------------------------------------ menu
	wp_set_current_user( $sm );
	ce_ok( 'menu: settings and status pages for the shop manager', current_user_can( 'manage_eru_ce_settings' ) );
} catch ( RuntimeException $e ) {
	$fail = true;
	echo 'STOPPED at first failure: ' . $e->getMessage() . "\n";
} finally {
	$_POST    = $keep_post;
	$_REQUEST = $keep_request;
	wp_set_current_user( 1 );
	update_option( 'active_plugins', $keep_plugins );
	null === $keep_soon ? delete_option( 'woocommerce_coming_soon' ) : update_option( 'woocommerce_coming_soon', $keep_soon );
	null === $keep_front ? delete_option( StorefrontOptions::OPTION ) : update_option( StorefrontOptions::OPTION, $keep_front, true );
	ce_wc_purge();
	$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE '\\_transient%eru\\_ce\\_clock\\_%'" );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( 'ret', 'gst', 'cust', 'other', 'gmail' ) as $who ) {
		\Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository::delete( ce_mail( $who ), 0 );
	}
	foreach ( $users as $u ) {
		\Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository::delete( '', (int) $u );
		wp_delete_user( $u );
	}
	ce_wc_restore_options( $keep );
}

echo $fail ? "FAILED\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
