<?php
/**
 * Sales Team Commissions — rules, hierarchy and customer assignment.
 *   wp --path=$HOME/code/erusoft-wp-demo eval-file tests/php/stc-rules.php
 *
 * (a) rule precedence product > category > brand > role > agent > global, then priority, then newest;
 *     expired, future, inactive and other-level rules ignored; validation
 * (b) multi-level chain with the level cap; cycle A→B→A stops and is logged once; inactive ancestor skipped
 * (g) round-robin over three active agents across four registrations, suspended agent skipped;
 *     referral cookie (no PHP session), first-touch lock, manual mode, order attribution; manual and
 *     round-robin links are sticky (a cookie never moves them)
 * plus the inert default and the manage_eru_stc meta-capability.
 */

require __DIR__ . '/stc-helpers.php';
stc_require_plugin();

$mails = array();
stc_guards( $mails );
$keep = stc_snapshot();
stc_purge( true );

/** Level-1 rate (as "x.xx") of one order line for an agent. */
function stc_t_rate_of( array $rows, $agent_id, $item_id ) {
	foreach ( $rows as $r ) {
		if ( (int) $r->agent_id === (int) $agent_id && (int) $r->item_id === (int) $item_id && '' === $r->entry_key ) {
			return $r->amount;
		}
	}
	return null;
}

try {
	// ---------------------------------------------------------------- inert default + capability
	delete_option( Eru_STC_Settings::OPTION );
	Eru_STC_Settings::flush();
	stc_check( 'inert: module off by default', 'no' === Eru_STC_Settings::get( 'enabled' ) && ! Eru_STC_Settings::enabled() );
	stc_check( 'defaults: completed trigger, cap 2, hold 14, cookie 30 days, referral mode', 'completed' === Eru_STC_Settings::get( 'trigger_status' ) && 2 === Eru_STC_Settings::get( 'max_levels' ) && 14 === Eru_STC_Settings::get( 'hold_days' ) && 30 === Eru_STC_Settings::get( 'cookie_days' ) && 'referral' === Eru_STC_Settings::get( 'assignment_mode' ) );
	Eru_STC_Settings::update( array( 'max_levels' => 9 ) );
	stc_check( 'settings: max levels clamped to 5', 5 === Eru_STC_Settings::get( 'max_levels' ) );
	Eru_STC_Settings::update( array( 'max_levels' => 0, 'trigger_status' => 'on-hold', 'refund_policy' => 'x' ) );
	stc_check( 'settings: max levels ≥ 1, unknown trigger/policy fall back', 1 === Eru_STC_Settings::get( 'max_levels' ) && 'completed' === Eru_STC_Settings::get( 'trigger_status' ) && 'prorata' === Eru_STC_Settings::get( 'refund_policy' ) );
	stc_check( 'inert: no eligibility job while the module is off', 0 === count( stc_pending( 'eru_stc_eligibility' ) ) );

	$customer_user = stc_user( 'capcheck' );
	$admins        = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	stc_check( 'cap: manage_woocommerce user has manage_eru_stc (meta-cap, no role write)', user_can( (int) $admins[0], 'manage_eru_stc' ) && ! get_role( 'administrator' )->has_cap( 'manage_eru_stc' ) );
	stc_check( 'cap: customer lacks manage_eru_stc', ! user_can( $customer_user, 'manage_eru_stc' ) );

	stc_settings( array( 'max_levels' => 1 ) );

	// ---------------------------------------------------------------- (a) precedence
	$cat    = stc_term( 'product_cat', 'Kategori' );
	$cat2   = stc_term( 'product_cat', 'Kategori2' );
	$brand  = stc_term( 'product_brand', 'Marka' );
	$p_full = stc_product( 'Urun Tam', '100.00', array( $cat ), array( $brand ) );
	$p_cat  = stc_product( 'Urun Kat', '100.00', array( $cat ), array( $brand ) );
	$p_br   = stc_product( 'Urun Marka', '100.00', array(), array( $brand ) );
	$p_none = stc_product( 'Urun Yok', '100.00', array( $cat2 ) );

	$seller = stc_agent( 'seller' );
	$other  = stc_agent( 'other' );
	$buyer  = stc_user( 'buyer' );
	$past   = gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS );
	$ended  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

	stc_rule( array( 'scope' => 'global', 'level' => 1, 'type' => 'percent', 'rate' => '5' ) );
	stc_rule( array( 'scope' => 'agent', 'target_id' => $seller, 'level' => 1, 'type' => 'percent', 'rate' => '5.5' ) );
	stc_rule( array( 'scope' => 'role', 'target_key' => 'customer', 'level' => 1, 'type' => 'percent', 'rate' => '6' ) );
	stc_rule( array( 'scope' => 'brand', 'target_id' => $brand, 'level' => 1, 'type' => 'percent', 'rate' => '6.5' ) );
	stc_rule( array( 'scope' => 'category', 'target_id' => $cat, 'level' => 1, 'type' => 'percent', 'rate' => '7' ) );
	stc_rule( array( 'scope' => 'product', 'target_id' => $p_full->get_id(), 'level' => 1, 'type' => 'percent', 'rate' => '10' ) );
	// Ignored: expired (higher rate), not yet started, inactive, other level.
	stc_rule( array( 'scope' => 'product', 'target_id' => $p_full->get_id(), 'level' => 1, 'type' => 'percent', 'rate' => '20', 'starts_at' => $past, 'ends_at' => $ended, 'priority' => 50 ) );
	stc_rule( array( 'scope' => 'product', 'target_id' => $p_cat->get_id(), 'level' => 1, 'type' => 'percent', 'rate' => '30', 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
	stc_rule( array( 'scope' => 'product', 'target_id' => $p_cat->get_id(), 'level' => 1, 'type' => 'percent', 'rate' => '40', 'active' => 0 ) );
	stc_rule( array( 'scope' => 'product', 'target_id' => $p_cat->get_id(), 'level' => 2, 'type' => 'percent', 'rate' => '50' ) );

	$ctx = fn( $product, $agent, $roles ) => Eru_STC_Rules::context_for_product( $product, $agent, $roles, time() );
	$r   = Eru_STC_Rules::find( 1, $ctx( $p_full, $seller, array( 'customer' ) ) );
	stc_check( '(a) product rule 10 % wins (expired 20 % product rule ignored)', $r && 'product' === $r->scope && '10.0000' === $r->rate );
	$r = Eru_STC_Rules::find( 1, $ctx( $p_cat, $seller, array( 'customer' ) ) );
	stc_check( '(a) category 7 % beats brand/role/agent/global (future, inactive, level-2 product rules ignored)', $r && 'category' === $r->scope && '7.0000' === $r->rate );
	$r = Eru_STC_Rules::find( 1, $ctx( $p_br, $seller, array( 'customer' ) ) );
	stc_check( '(a) brand 6.5 % beats role/agent/global', $r && 'brand' === $r->scope );
	$r = Eru_STC_Rules::find( 1, $ctx( $p_none, $seller, array( 'customer' ) ) );
	stc_check( '(a) role 6 % beats agent/global', $r && 'role' === $r->scope );
	$r = Eru_STC_Rules::find( 1, $ctx( $p_none, $seller, array() ) );
	stc_check( '(a) agent 5.5 % beats global', $r && 'agent' === $r->scope && '5.5000' === $r->rate );
	$r = Eru_STC_Rules::find( 1, $ctx( $p_none, $other, array() ) );
	stc_check( '(a) global 5 % when nothing narrower matches', $r && 'global' === $r->scope && '5.0000' === $r->rate );
	$r = Eru_STC_Rules::find( 1, Eru_STC_Rules::context_for_product( $p_full, $seller, array(), time() - 5 * DAY_IN_SECONDS ) );
	stc_check( '(a) inside its date range the 20 % product rule applies (higher priority)', $r && '20.0000' === $r->rate );

	// Same scope: priority, then newest.
	stc_rule( array( 'scope' => 'global', 'level' => 2, 'type' => 'percent', 'rate' => '3', 'priority' => 5 ) );
	stc_rule( array( 'scope' => 'global', 'level' => 2, 'type' => 'percent', 'rate' => '4', 'priority' => 0 ) );
	stc_rule( array( 'scope' => 'global', 'level' => 3, 'type' => 'percent', 'rate' => '1' ) );
	stc_rule( array( 'scope' => 'global', 'level' => 3, 'type' => 'percent', 'rate' => '2' ) );
	$r2 = Eru_STC_Rules::find( 2, $ctx( $p_none, $other, array() ) );
	$r3 = Eru_STC_Rules::find( 3, $ctx( $p_none, $other, array() ) );
	stc_check( '(a) same scope: higher priority wins', $r2 && '3.0000' === $r2->rate );
	stc_check( '(a) same scope and priority: newest wins', $r3 && '2.0000' === $r3->rate );

	// Validation.
	stc_check( '(a) invalid scope refused', is_wp_error( Eru_STC_Rules::create( array( 'name' => 'STC Demo kural ' . STC_RUN, 'scope' => 'nope', 'level' => 1, 'type' => 'percent', 'rate' => '1' ) ) ) );
	stc_check( '(a) level 6 refused', is_wp_error( Eru_STC_Rules::create( array( 'name' => 'STC Demo kural ' . STC_RUN, 'scope' => 'global', 'level' => 6, 'type' => 'percent', 'rate' => '1' ) ) ) );
	stc_check( '(a) percent above 100 refused', is_wp_error( Eru_STC_Rules::create( array( 'name' => 'STC Demo kural ' . STC_RUN, 'scope' => 'global', 'level' => 1, 'type' => 'percent', 'rate' => '101' ) ) ) );
	stc_check( '(a) negative rate refused', is_wp_error( Eru_STC_Rules::create( array( 'name' => 'STC Demo kural ' . STC_RUN, 'scope' => 'global', 'level' => 1, 'type' => 'fixed', 'rate' => '-1' ) ) ) );
	stc_check( '(a) product scope without target refused', is_wp_error( Eru_STC_Rules::create( array( 'name' => 'STC Demo kural ' . STC_RUN, 'scope' => 'product', 'level' => 1, 'type' => 'percent', 'rate' => '1' ) ) ) );
	stc_check( '(a) end before start refused', is_wp_error( Eru_STC_Rules::create( array( 'name' => 'STC Demo kural ' . STC_RUN, 'scope' => 'global', 'level' => 1, 'type' => 'percent', 'rate' => '1', 'starts_at' => $ended, 'ends_at' => $past ) ) ) );

	// Through the calculator: one order, four lines, the seller linked to the buyer.
	Eru_STC_Links::link( $buyer, $seller, 'manual' );
	$order = stc_order(
		$buyer,
		array(
			array( $p_full, 1, '100.00', '0' ),
			array( $p_cat, 1, '100.00', '0' ),
			array( $p_br, 1, '100.00', '0' ),
			array( $p_none, 1, '100.00', '0' ),
		)
	);
	stc_status( $order->get_id(), 'completed' );
	$rows  = stc_rows( $order->get_id() );
	$items = array_keys( $order->get_items() );
	stc_check( '(a) calculator: one level-1 row per line', 4 === count( $rows ) );
	stc_check( '(a) calculator: 10.00 / 7.00 / 6.50 / 6.00 per line', '10.0000' === stc_t_rate_of( $rows, $seller, $items[0] ) && '7.0000' === stc_t_rate_of( $rows, $seller, $items[1] ) && '6.5000' === stc_t_rate_of( $rows, $seller, $items[2] ) && '6.0000' === stc_t_rate_of( $rows, $seller, $items[3] ) );
	stc_check( '(a) calculator: the row records its rule', (int) $rows[0]->rule_id > 0 && 'percent' === $rows[0]->rule_type );

	// Clear (a)'s rules so the chain tests see only their own.
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}eru_stc_rules WHERE name = %s", 'STC Demo kural ' . STC_RUN ) ); // phpcs:ignore

	// ---------------------------------------------------------------- (b) multi-level
	stc_rule( array( 'scope' => 'global', 'level' => 1, 'type' => 'percent', 'rate' => '10' ) );
	stc_rule( array( 'scope' => 'global', 'level' => 2, 'type' => 'percent', 'rate' => '5' ) );
	stc_rule( array( 'scope' => 'global', 'level' => 3, 'type' => 'percent', 'rate' => '2' ) );
	$a = stc_agent( 'a' );
	$b = stc_agent( 'b', $a );
	$c = stc_agent( 'c', $b );
	$x = stc_user( 'x' );
	Eru_STC_Links::link( $x, $c, 'manual' );

	stc_settings( array( 'max_levels' => 2 ) );
	$o    = stc_order( $x, array( array( $p_none, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(b) cap 2: rows for C (level 1) and B (level 2) only', 2 === count( $rows ) && 1 === count( stc_rows_of( $rows, $c ) ) && 1 === (int) stc_rows_of( $rows, $c )[0]->level && 2 === (int) stc_rows_of( $rows, $b )[0]->level && 0 === count( stc_rows_of( $rows, $a ) ) );
	stc_check( '(b) cap 2: 10.00 and 5.00', '10.0000' === stc_rows_of( $rows, $c )[0]->amount && '5.0000' === stc_rows_of( $rows, $b )[0]->amount );

	stc_settings( array( 'max_levels' => 3 ) );
	$o    = stc_order( $x, array( array( $p_none, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(b) cap 3: A gets level 3 (2.00)', 3 === count( $rows ) && 1 === count( stc_rows_of( $rows, $a ) ) && 3 === (int) stc_rows_of( $rows, $a )[0]->level && '2.0000' === stc_rows_of( $rows, $a )[0]->amount );

	// Hierarchy guards in the repository.
	stc_check( '(b) repository refuses a parent that closes a cycle', is_wp_error( Eru_STC_Agents::update( $a, array( 'parent_agent_id' => $c ) ) ) );
	stc_check( '(b) repository refuses an agent as its own parent', is_wp_error( Eru_STC_Agents::update( $a, array( 'parent_agent_id' => $a ) ) ) );

	// A cycle written behind the repository's back (import, manual SQL): the walk stops, logged once.
	$wpdb->update( $wpdb->prefix . 'eru_stc_agents', array( 'parent_agent_id' => $c ), array( 'id' => $a ) );
	$cycles = 0;
	add_action(
		'eru_stc_cycle_detected',
		function () use ( &$cycles ) {
			++$cycles;
		}
	);
	stc_settings( array( 'max_levels' => 5 ) );
	$chain = Eru_STC_Agents::chain( $c, 5 );
	stc_check( '(b) cycle: chain C, B, A then stops', array( $c, $b, $a ) === array_map( fn( $l ) => (int) $l['agent']->id, $chain ) && array( 1, 2, 3 ) === array_column( $chain, 'level' ) );
	stc_check( '(b) cycle: logged once per walk', 1 === $cycles );
	$cycles = 0;
	$o      = stc_order( $x, array( array( $p_none, 1, '100.00', '0' ), array( $p_full, 1, '50.00', '0' ) ) );
	$o      = stc_status( $o->get_id(), 'completed' );
	$rows   = stc_rows( $o->get_id() );
	stc_check( '(b) cycle in an order: 3 levels × 2 lines, no repeat of C', 6 === count( $rows ) && 2 === count( stc_rows_of( $rows, $c ) ) );
	stc_check( '(b) cycle in an order: logged once for the whole order', 1 === $cycles );
	$wpdb->update( $wpdb->prefix . 'eru_stc_agents', array( 'parent_agent_id' => null ), array( 'id' => $a ) );

	// Inactive ancestor: B suspended → C level 1, A level 3 (levels are distances).
	Eru_STC_Agents::update( $b, array( 'status' => 'suspended' ) );
	stc_settings( array( 'max_levels' => 3 ) );
	$o    = stc_order( $x, array( array( $p_none, 1, '100.00', '0' ) ) );
	$o    = stc_status( $o->get_id(), 'completed' );
	$rows = stc_rows( $o->get_id() );
	stc_check( '(b) suspended B earns nothing; A keeps level 3', 2 === count( $rows ) && 0 === count( stc_rows_of( $rows, $b ) ) && 3 === (int) stc_rows_of( $rows, $a )[0]->level );
	Eru_STC_Agents::update( $b, array( 'status' => 'active' ) );

	// Suspended level-1 agent: nobody earns.
	Eru_STC_Agents::update( $c, array( 'status' => 'suspended' ) );
	$o = stc_order( $x, array( array( $p_none, 1, '100.00', '0' ) ) );
	$o = stc_status( $o->get_id(), 'completed' );
	stc_check( '(b) suspended direct agent: no rows', 0 === count( stc_rows( $o->get_id() ) ) );
	Eru_STC_Agents::update( $c, array( 'status' => 'active' ) );

	// ---------------------------------------------------------------- (g) round-robin + referral
	$r1 = stc_agent( 'rr1' );
	$sx = stc_agent( 'rrs', null, 'suspended' );
	$r2 = stc_agent( 'rr2' );
	$r3 = stc_agent( 'rr3' );
	$pool = array( $r1, $sx, $r2, $r3 );
	add_filter( 'eru_stc_round_robin_agents', fn( $ids ) => array_values( array_intersect( $ids, $pool ) ) );
	delete_option( 'eru_stc_rr_pointer' );
	stc_settings( array( 'assignment_mode' => 'round_robin' ) );

	$got = array();
	foreach ( array( 'n1', 'n2', 'n3', 'n4' ) as $n ) {
		$uid = wc_create_new_customer( stc_mail( $n ), 'stc-demo-' . $n . '-' . STC_RUN, wp_generate_password( 20 ) );
		stc_check( "(g) registration {$n} created", ! is_wp_error( $uid ) );
		$link  = Eru_STC_Links::get( $uid );
		$got[] = $link ? (int) $link->agent_id : 0;
	}
	stc_check( '(g) round-robin assigns R1, R2, R3, R1 (suspended skipped)', array( $r1, $r2, $r3, $r1 ) === $got );
	stc_check( '(g) link type round_robin', 'round_robin' === Eru_STC_Links::get( $uid )->link_type );

	// Referral cookie: a valid code sets the first-party cookie (mirrored into $_COOKIE), no PHP session.
	stc_settings( array( 'assignment_mode' => 'referral' ) );
	$code2 = Eru_STC_Agents::get( $r2 )->referral_code;
	$code3 = Eru_STC_Agents::get( $r3 )->referral_code;
	$_GET['ref'] = $code2;
	Eru_STC_Referral::capture();
	stc_check( '(g) ?ref= stores the code in the cookie', isset( $_COOKIE['eru_stc_ref'] ) && $code2 === $_COOKIE['eru_stc_ref'] );
	stc_check( '(g) no PHP session started', PHP_SESSION_ACTIVE !== session_status() );
	$_GET['ref'] = 'STC-NOPE-' . STC_RUN;
	Eru_STC_Referral::capture();
	stc_check( '(g) unknown code keeps the cookie', $code2 === $_COOKIE['eru_stc_ref'] );
	$_GET['ref'] = Eru_STC_Agents::get( $sx )->referral_code;
	Eru_STC_Referral::capture();
	stc_check( '(g) suspended agent\'s code ignored', $code2 === $_COOKIE['eru_stc_ref'] );

	$uid = wc_create_new_customer( stc_mail( 'ref1' ), 'stc-demo-ref1-' . STC_RUN, wp_generate_password( 20 ) );
	$l   = Eru_STC_Links::get( $uid );
	stc_check( '(g) referral mode: registration linked to the code\'s agent', $l && $r2 === (int) $l->agent_id && 'referral' === $l->link_type && null === $l->locked_at );

	// Referral (last touch): a new code moves an unlocked customer on the next order.
	$_GET['ref'] = $code3;
	Eru_STC_Referral::capture();
	$o = stc_order( $uid, array( array( $p_none, 1, '10.00', '0' ) ) );
	Eru_STC_Referral::attach_to_order( $o );
	$o = wc_get_order( $o->get_id() );
	stc_check( '(g) referral mode: last code wins on the order and moves the link', $r3 === (int) $o->get_meta( '_eru_stc_agent_id' ) && $r3 === (int) Eru_STC_Links::get( $uid )->agent_id );

	// Sticky links: a cookie never moves a manual (admin) or round-robin link, even in last-touch mode.
	$um = stc_user( 'stick1' );
	$ur = stc_user( 'stick2' );
	Eru_STC_Links::link( $um, $r1, 'manual' );
	Eru_STC_Links::link( $ur, $r1, 'round_robin' );
	$_COOKIE['eru_stc_ref'] = $code3;
	foreach ( array( 'manual' => $um, 'round_robin' => $ur ) as $type => $who ) {
		$o = stc_order( $who, array( array( $p_none, 1, '10.00', '0' ) ) );
		Eru_STC_Referral::attach_to_order( $o );
		$o = wc_get_order( $o->get_id() );
		stc_check( "(g) referral mode: a cookie does not move a {$type} link", $r1 === (int) $o->get_meta( '_eru_stc_agent_id' ) && $r1 === (int) Eru_STC_Links::get( $who )->agent_id && $type === Eru_STC_Links::get( $who )->link_type );
	}

	// First-touch: the first link is locked; later codes change neither the link nor the cookie.
	stc_settings( array( 'assignment_mode' => 'first_touch' ) );
	unset( $_COOKIE['eru_stc_ref'] );
	$_GET['ref'] = $code2;
	Eru_STC_Referral::capture();
	$uid2 = wc_create_new_customer( stc_mail( 'ft1' ), 'stc-demo-ft1-' . STC_RUN, wp_generate_password( 20 ) );
	$l    = Eru_STC_Links::get( $uid2 );
	stc_check( '(g) first-touch: linked and locked', $l && $r2 === (int) $l->agent_id && null !== $l->locked_at );
	$_GET['ref'] = $code3;
	Eru_STC_Referral::capture();
	stc_check( '(g) first-touch: cookie keeps the first code', $code2 === $_COOKIE['eru_stc_ref'] );
	$_COOKIE['eru_stc_ref'] = $code3; // even a cookie set elsewhere cannot move a locked customer
	$o = stc_order( $uid2, array( array( $p_none, 1, '10.00', '0' ) ) );
	Eru_STC_Referral::attach_to_order( $o );
	$o = wc_get_order( $o->get_id() );
	stc_check( '(g) first-touch: locked link wins on the order', $r2 === (int) $o->get_meta( '_eru_stc_agent_id' ) && $r2 === (int) Eru_STC_Links::get( $uid2 )->agent_id );
	stc_check( '(g) first-touch: link() refuses to move a locked customer without force', is_wp_error( Eru_STC_Links::link( $uid2, $r3, 'manual' ) ) && true === Eru_STC_Links::link( $uid2, $r3, 'manual', array( 'force' => true ) ) );

	// Guest order: attributed from the cookie, no link (no user).
	stc_settings( array( 'assignment_mode' => 'referral' ) );
	$_COOKIE['eru_stc_ref'] = $code2;
	$g = stc_order( 0, array( array( $p_none, 1, '10.00', '0' ) ) );
	Eru_STC_Referral::attach_to_order( $g );
	stc_check( '(g) guest order attributed from the cookie', $r2 === (int) wc_get_order( $g->get_id() )->get_meta( '_eru_stc_agent_id' ) );

	// Manual mode: no capture, no automatic link.
	stc_settings( array( 'assignment_mode' => 'manual' ) );
	unset( $_COOKIE['eru_stc_ref'] );
	$_GET['ref'] = $code2;
	Eru_STC_Referral::capture();
	stc_check( '(g) manual mode: ?ref= ignored', ! isset( $_COOKIE['eru_stc_ref'] ) );
	$uid3 = wc_create_new_customer( stc_mail( 'man1' ), 'stc-demo-man1-' . STC_RUN, wp_generate_password( 20 ) );
	stc_check( '(g) manual mode: registration not linked', null === Eru_STC_Links::get( $uid3 ) );

	// Module off: capture does nothing.
	stc_settings( array( 'enabled' => 'no' ) );
	Eru_STC_Referral::capture();
	stc_check( '(g) module off: no cookie', ! isset( $_COOKIE['eru_stc_ref'] ) );
	unset( $_GET['ref'] );

	// Self-referral: an agent is never linked to itself.
	stc_check( '(g) agent cannot be its own customer', is_wp_error( Eru_STC_Links::link( (int) Eru_STC_Agents::get( $r1 )->user_id, $r1, 'manual' ) ) );

	echo "ALL PASS: stc-rules\n";
	$status = 0;
} catch ( Throwable $e ) {
	echo 'FAIL: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() . "\n";
	$status = 1;
} finally {
	stc_purge( false );
	stc_restore( $keep );
}
exit( $status );
