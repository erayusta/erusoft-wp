<?php
/**
 * Campaign Engine — evaluator gates, the resolver (priority, exclusive campaigns, stacking policies
 * best / stack / first, caps, usage limits given as input) and the offer finder. Pure PHP with
 * invented carts.
 *
 *   wp eval-file tests/php/ce-engine-resolver.php
 */

use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Settings;
use Erusoft\CampaignEngine\Engine\Condition\ConditionRegistry;
use Erusoft\CampaignEngine\Engine\Offer\Offer;
use Erusoft\CampaignEngine\Engine\Offer\OfferFinder;
use Erusoft\CampaignEngine\Engine\UsageLimits;

require __DIR__ . '/ce-helpers.php';
ce_require_engine();

/** Total per campaign id from a result. */
$by_campaign = fn( $r ) => $r->adjustments->campaignTotals();

$line_cat = ce_item( 'k', 901, 1, 8000, array( 'category_ids' => array( 61 ) ) );
$line_oth = ce_item( 'o', 902, 2, 4590, array( 'category_ids' => array( 62 ) ) );
$cart     = ce_ctx( array( $line_cat, $line_oth ) );
$in_cat   = array( 'include' => array( 'term' => array( 61 ) ) );

// ---------------------------------------------------------------- campaign model
$c = Campaign::fromArray( array( 'id' => '31', 'label' => 'Demo Hafta Sonu', 'priority' => '7', 'exclusive' => 1, 'basis' => 'tuhaf', 'actions' => array( array( 'type' => 'product_percent', 'percent' => '15' ) ), 'targets' => $in_cat, 'max_discount_minor' => '2500' ) );
ce_check( 'Campaign::fromArray casts and whitelists fields', 31 === $c->id && 7 === $c->priority && true === $c->exclusive && 'current' === $c->basis && 2500 === $c->max_discount_minor && 'Demo Hafta Sonu' === $c->label );
ce_check( 'Campaign targets: include term 61 matches only the category line', $c->targets->matches( $line_cat ) && ! $c->targets->matches( $line_oth ) );
$ex = Campaign::fromArray( array( 'id' => 32, 'actions' => array(), 'targets' => array( 'exclude' => array( 'product' => array( 901 ) ) ) ) );
ce_check( 'Campaign targets: exclude wins, empty include means everything', ! $ex->targets->matches( $line_cat ) && $ex->targets->matches( $line_oth ) );
ce_check( 'Campaign targets: excluded lines (e.g. gift cards) never match', ! $ex->targets->matches( ce_item( 'g', 903, 1, 100, array( 'excluded' => true ) ) ) );

$throws = function ( callable $fn ) {
	try {
		$fn();
	} catch ( ArgumentError $e ) {
		return $e->field . ':' . $e->reason;
	}
	return '';
};
ce_check( 'Campaign::fromArray refuses a malformed target id instead of turning storewide', 'targets.include.term:invalid' === $throws( fn() => Campaign::fromArray( array( 'id' => 39, 'actions' => array(), 'targets' => array( 'include' => array( 'term' => array( 'abc' ) ) ) ) ) ) );
ce_check( 'Campaign::fromArray refuses a malformed discount cap instead of dropping it', 'max_discount_minor:not_integral' === $throws( fn() => Campaign::fromArray( array( 'id' => 39, 'actions' => array(), 'max_discount_minor' => '25.5' ) ) ) && 2500 === Campaign::fromArray( array( 'id' => 39, 'actions' => array(), 'max_discount_minor' => 2500.0 ) )->max_discount_minor );

// ---------------------------------------------------------------- settings never fail open
ce_check( 'Settings: a float cart cap is read, not dropped', '20' === Settings::fromArray( array( 'max_cart_discount_percent' => 20.0 ) )->max_cart_discount_percent && '12.5' === Settings::fromArray( array( 'max_cart_discount_percent' => 12.5 ) )->max_cart_discount_percent );
ce_check( 'Settings: an invalid cart cap is refused with an error (no silent "no cap")', 'max_cart_discount_percent:invalid' === $throws( fn() => Settings::fromArray( array( 'max_cart_discount_percent' => 'yirmi' ) ) ) && 'max_cart_discount_percent:too_precise' === $throws( fn() => Settings::fromArray( array( 'max_cart_discount_percent' => 20.125 ) ) ) );
ce_check( 'Settings: an empty cart cap means no cap; a malformed price floor is refused', null === Settings::fromArray( array( 'max_cart_discount_percent' => '' ) )->max_cart_discount_percent && 'min_unit_price_minor:not_integral' === $throws( fn() => Settings::fromArray( array( 'min_unit_price_minor' => '9.5' ) ) ) && 'stacking:unknown_choice' === $throws( fn() => Settings::fromArray( array( 'stacking' => 'bogus' ) ) ) );

// ---------------------------------------------------------------- evaluator gates and trace
$pct = fn( $p ) => array( array( 'type' => 'product_percent', 'percent' => $p ) );
$off = ce_campaign( 33, $pct( '15' ), array( 'schedule' => array( 'starts_ts' => ce_now( '2026-10-20 00:00:00' )->getTimestamp() ) ) );
$r   = ce_eval( $cart, array( $off ) );
ce_check( 'gate: outside its schedule → skipped, trace code outside_schedule', $r->adjustments->isEmpty() && 'outside_schedule' === $r->trace->failureFor( 33 )['code'] );
$r = ce_eval( $cart, array( ce_campaign( 34, $pct( '15' ), array( 'targets' => array( 'include' => array( 'product' => array( 999 ) ) ) ) ) ) );
ce_check( 'gate: nothing in scope → trace code no_scope', 'no_scope' === $r->trace->failureFor( 34 )['code'] );
$r = ce_eval( $cart, array( ce_campaign( 35, $pct( '15' ), array( 'rules' => array( 'condition' => 'customer.logged_in', 'value' => true ) ) ) ) );
ce_check( 'gate: cart rules fail → trace code conditions', 'conditions' === $r->trace->failureFor( 35 )['code'] );
$r = ce_eval( ce_ctx( array( $line_cat ), null, array( 'coupons' => array( 'demo-kod' ) ) ), array( ce_campaign( 36, $pct( '15' ) ) ), array( 'with_coupons' => false ) );
ce_check( 'gate: coupons in the cart block campaigns when with_coupons is off', $r->adjustments->isEmpty() && 'coupon_in_cart' === $r->trace->failureFor( 36 )['code'] );
$r = ce_eval( ce_ctx( array( $line_cat ), null, array( 'coupons' => array( 'demo-kod' ) ) ), array( ce_campaign( 36, $pct( '15' ) ) ) );
ce_check( 'gate: coupons allowed by default', 1200 === $r->adjustments->totalDiscount() );
$r = ce_eval( $cart, array( ce_campaign( 37, $pct( '15' ) ) ), array(), new UsageLimits( array( 37 => 0 ) ) );
ce_check( 'usage limits as input: no uses left → withdrawn, trace code usage_limit', $r->adjustments->isEmpty() && 'usage_limit' === $r->trace->failureFor( 37 )['code'] );
$r = ce_eval( $cart, array( ce_campaign( 37, $pct( '15' ) ) ), array(), new UsageLimits( array( 37 => 2, 99 => 0 ) ) );
ce_check( 'usage limits: uses left → applies; unknown campaign ids are unlimited', 2578 === $r->adjustments->totalDiscount() && UsageLimits::none()->allows( 37 ) && ( new UsageLimits( array( 37 => 0 ) ) )->allows( 38 ) );
$item_rule = ce_campaign( 38, $pct( '15' ), array( 'rules' => array( 'condition' => 'item.category', 'cmp' => 'in', 'value' => array( 62 ) ) ) );
$r         = ce_eval( $cart, array( $item_rule ) );
ce_check( 'item rules narrow the discount to matching lines', array( 'o' => 1378 ) === ce_lines( $r ) );
ce_check( 'trace lists passing steps too', in_array( 'schedule', array_column( $r->trace->forCampaign( 38 ), 'step' ), true ) && null === $r->trace->failureFor( 38 ) );

// ---------------------------------------------------------------- (c) exclusive and priority
$excl  = ce_campaign( 40, $pct( '15' ), array( 'priority' => 20, 'exclusive' => true, 'targets' => $in_cat ) );
$other = ce_campaign( 41, array( array( 'type' => 'product_fixed', 'amount' => 3000 ) ), array( 'priority' => 5 ) );
$r     = ce_eval( $cart, array( $other, $excl ) );
ce_check( '(c) an exclusive higher-priority campaign blocks a lower one everywhere', array( 40 => 1200 ) === $by_campaign( $r ) && 'exclusive' === $r->trace->failureFor( 41 )['code'] );
$excl2 = ce_campaign( 42, $pct( '20' ), array( 'priority' => 30, 'exclusive' => true ) );
$r     = ce_eval( $cart, array( $excl, $excl2, $other ) );
ce_check( '(c) of two exclusive campaigns the higher priority wins', array( 42 ) === $r->appliedCampaignIds() );
$tie_a = ce_campaign( 44, $pct( '15' ), array( 'priority' => 9, 'exclusive' => true ) );
$tie_b = ce_campaign( 43, $pct( '20' ), array( 'priority' => 9, 'exclusive' => true ) );
$r     = ce_eval( $cart, array( $tie_a, $tie_b ) );
ce_check( '(c) equal priority: the lower campaign id wins (stable)', array( 43 ) === $r->appliedCampaignIds() );
$r = ce_eval( ce_ctx( array( $line_oth ) ), array( $excl, $other ) );
ce_check( '(c) an exclusive campaign that does not apply blocks nothing', array( 41 ) === $r->appliedCampaignIds() );

// ---------------------------------------------------------------- (c) stacking policies
$small = ce_campaign( 45, $pct( '15' ), array( 'priority' => 50 ) );
$big   = ce_campaign( 46, array( array( 'type' => 'product_fixed', 'amount' => 2000 ) ), array( 'priority' => 1 ) );
$one   = ce_ctx( array( ce_item( 'z', 904, 1, 9990 ) ) );
$r     = ce_eval( $one, array( $small, $big ) );
ce_check( '(c) best (default): the larger discount on the line wins (2000 > 1499)', array( 46 => 2000 ) === $by_campaign( $r ) && 'stacking' === $r->trace->failureFor( 45 )['code'] );
$r = ce_eval( $one, array( $small, $big ), array( 'stacking' => 'first' ) );
ce_check( '(c) first: the highest-priority campaign wins even when smaller', array( 45 => 1499 ) === $by_campaign( $r ) );
$r = ce_eval( $one, array( $small, $big ), array( 'stacking' => 'stack' ) );
ce_check( '(c) stack: discounts add up (1499 + 2000)', 3499 === $r->adjustments->totalDiscount() && 2 === count( $r->appliedCampaignIds() ) );
$huge_a = ce_campaign( 47, $pct( '60' ) );
$huge_b = ce_campaign( 48, $pct( '55' ) );
$r      = ce_eval( ce_ctx( array( ce_item( 'z', 905, 2, 8000 ) ) ), array( $huge_a, $huge_b ), array( 'stacking' => 'stack' ) );
$t      = $by_campaign( $r );
ce_check( '(c) stack: the sum is capped at the line price, split between both campaigns', 16000 === $r->adjustments->totalDiscount() && 16000 === array_sum( $t ) && $t[47] > $t[48] && $t[48] > 0 );
$r = ce_eval( ce_ctx( array( ce_item( 'z', 905, 2, 8000 ) ) ), array( $huge_a, $huge_b ), array( 'stacking' => 'stack', 'min_unit_price_minor' => 1500 ) );
ce_check( '(c) stack with a minimum unit price: the line keeps 2 × 15,00', 13000 === $r->adjustments->totalDiscount() );
$r = ce_eval( ce_ctx( array( ce_item( 'z', 905, 2, 8000 ) ) ), array( $huge_a, $huge_b ), array( 'stacking' => 'BEST' ) );
ce_check( '(c) stacking policy is case-insensitive', array( 47 => 9600 ) === $by_campaign( $r ) );
$cartwide = ce_campaign( 49, array( array( 'type' => 'cart_percent', 'percent' => '12' ) ) );
$r        = ce_eval( $cart, array( $cartwide, ce_campaign( 50, $pct( '15' ), array( 'targets' => $in_cat ) ) ) );
ce_check( '(c) best works per line: cart-level 12 % on one line, product 15 % on the other', array( 'k' => 1200, 'o' => 1102 ) === ce_lines( $r ) );
$r = ce_eval( $cart, array( $big, ce_campaign( 51, array( array( 'type' => 'free_shipping' ) ) ) ) );
ce_check( '(c) free shipping is not a line discount and stacks with any policy', $r->adjustments->freeShipping() && 6000 === $r->adjustments->totalDiscount() );
$r = ce_eval( $cart, array( $excl, ce_campaign( 51, array( array( 'type' => 'free_shipping' ) ) ) ) );
ce_check( '(c) …but an exclusive campaign removes it too', ! $r->adjustments->freeShipping() );
// Ruling: admin priority is respected. An exclusive campaign that wins by priority excludes the others
// even when it only gives free shipping or a future reward.
$ship_only = ce_campaign( 57, array( array( 'type' => 'free_shipping' ) ), array( 'priority' => 40, 'exclusive' => true ) );
$r         = ce_eval( $cart, array( $big, $ship_only ) );
ce_check( '(c) exclusive free-shipping-only campaign with higher priority excludes a discount campaign', $r->adjustments->freeShipping() && 0 === $r->adjustments->totalDiscount() && array( 57 ) === $r->appliedCampaignIds() );
ce_check( '(c) the trace names the excluding campaign on both sides', 'exclusive' === $r->trace->failureFor( 46 )['code'] && 57 === $r->trace->failureFor( 46 )['data']['winner'] && array( 46 ) === ( array_values( array_filter( $r->trace->forCampaign( 57 ), fn( $e ) => 'excluded_others' === $e['code'] ) )[0]['data']['excluded'] ?? null ) && array( 46 => 57 ) === $r->trace->excludedBy() );
$earn_only = ce_campaign( 58, array( array( 'type' => 'future_reward', 'value' => 2500 ) ), array( 'priority' => 40, 'exclusive' => true ) );
$r         = ce_eval( $cart, array( $big, $earn_only ) );
ce_check( '(c) exclusive reward-only campaign with higher priority excludes the discount too', 0 === $r->adjustments->totalDiscount() && 1 === count( $r->adjustments->rewards() ) );
$r = ce_eval( $cart, array( ce_campaign( 46, array( array( 'type' => 'product_fixed', 'amount' => 2000 ) ), array( 'priority' => 50 ) ), $ship_only ) );
ce_check( '(c) a higher-priority non-exclusive campaign is still excluded by an applying exclusive one', array( 57 ) === $r->appliedCampaignIds() );

// Ruling: under best/first a campaign that had line discounts but kept none loses its perks too.
$twenty   = ce_campaign( 70, $pct( '20' ), array( 'priority' => 5 ) );
$ten_perk = ce_campaign( 71, array( array( 'type' => 'product_percent', 'percent' => '10' ), array( 'type' => 'free_shipping' ), array( 'type' => 'future_reward', 'value' => 2500 ) ), array( 'priority' => 9 ) );
$lost     = fn( $r, $id ) => array() !== array_filter( $r->trace->forCampaign( $id ), fn( $e ) => 'lost_all_lines' === $e['code'] && 'stacking' === $e['step'] && 'fail' === $e['status'] );
$r        = ce_eval( $cart, array( $twenty, $ten_perk ) );
ce_check( '(c) best: 20 % beats 10 % + free shipping on every line → the loser keeps no shipping and no reward', array( 70 ) === $r->appliedCampaignIds() && ! $r->adjustments->freeShipping() && array() === $r->adjustments->rewards() && $lost( $r, 71 ) );
$r = ce_eval( $cart, array( $twenty, $ten_perk ), array( 'stacking' => 'first' ) );
ce_check( '(c) first: the higher-priority 10 % + perks wins every line and keeps its perks; the 20 % campaign lost all lines', array( 71 ) === $r->appliedCampaignIds() && $r->adjustments->freeShipping() && 1 === count( $r->adjustments->rewards() ) && $lost( $r, 70 ) );
$r = ce_eval( $cart, array( $twenty, $ten_perk ), array( 'stacking' => 'stack' ) );
ce_check( '(c) stack: unchanged, both apply and the perks stay', 2 === count( $r->appliedCampaignIds() ) && $r->adjustments->freeShipping() && ! $lost( $r, 71 ) );
$partial = ce_campaign( 72, array( array( 'type' => 'product_percent', 'percent' => '25' ), array( 'type' => 'free_shipping' ) ), array( 'targets' => $in_cat ) );
$r       = ce_eval( $cart, array( $twenty, $partial ) );
ce_check( '(c) best: a campaign that keeps at least one line keeps its perks', $r->adjustments->freeShipping() && array( 70, 72 ) === array_values( array_intersect( array( 70, 72 ), $r->appliedCampaignIds() ) ) && ! $lost( $r, 72 ) );
$zero_cap = ce_campaign( 73, array( array( 'type' => 'product_percent', 'percent' => '10' ), array( 'type' => 'free_shipping' ) ), array( 'max_discount_minor' => 0 ) );
$r        = ce_eval( $cart, array( $zero_cap ) );
ce_check( '(c) best: a campaign whose lines are capped to zero loses its perks too', array() === $r->appliedCampaignIds() && ! $r->adjustments->freeShipping() && $lost( $r, 73 ) );
$r = ce_eval( $cart, array( $twenty, ce_campaign( 74, array( array( 'type' => 'free_shipping' ) ) ) ) );
ce_check( '(c) a pure free-shipping campaign is unaffected by the rule', $r->adjustments->freeShipping() && in_array( 74, $r->appliedCampaignIds(), true ) && ! $lost( $r, 74 ) );

// ---------------------------------------------------------------- caps
$capped = ce_campaign( 52, $pct( '40' ), array( 'max_discount_minor' => 2500 ) );
$r      = ce_eval( $cart, array( $capped ) );
ce_check( 'campaign cap: total held at 25,00 and split across lines exactly', 2500 === $r->adjustments->totalDiscount() && 'campaign_cap' === ( array_values( array_filter( $r->trace->forCampaign( 52 ), fn( $e ) => 'campaign_cap' === $e['code'] ) )[0]['code'] ?? '' ) );
$r = ce_eval( $cart, array( ce_campaign( 53, $pct( '40' ) ) ), array( 'max_cart_discount_percent' => '20' ) );
ce_check( 'cart cap: 20 % of 171,80 = 3436 at most', 3436 === $r->adjustments->totalDiscount() );
$r = ce_eval( $cart, array( ce_campaign( 53, $pct( '40' ) ) ), array( 'max_cart_discount_percent' => 12.5 ) );
ce_check( 'cart cap rounds down: 12,5 % of 171,80 = 21,475 → 2147', 2147 === $r->adjustments->totalDiscount() );
foreach ( array( array( 'stacking' => 'stack' ), array() ) as $s ) {
	$r = ce_eval( $cart, array( ce_campaign( 54, $pct( '35' ) ), ce_campaign( 55, array( array( 'type' => 'cart_fixed', 'amount' => 99999 ) ) ) ), $s );
	$ok = true;
	foreach ( $cart->items as $item ) {
		$ok = $ok && $r->adjustments->lineDiscount( $item->key ) <= $item->lineSubtotalMinor() && $r->adjustments->lineDiscount( $item->key ) >= 0;
	}
	ce_check( 'no line ever goes below zero (' . ( $s['stacking'] ?? 'best' ) . ')', $ok );
}
$r = ce_eval( $cart, array( ce_campaign( 56, $pct( '15' ) ) ) );
$e = $r->evaluatedCandidates();
ce_check( 'evaluation result exposes candidates before resolution for previews', 1 === count( $e ) && 56 === $e[0]->campaign->id && $e[0]->total() === $r->adjustments->totalDiscount() );

// ---------------------------------------------------------------- (g) offer finder
$finder    = new OfferFinder( ConditionRegistry::withDefaults() );
$three_two = ce_campaign( 60, array( array( 'type' => 'set_reward', 'set_size' => 3, 'reward_count' => 1, 'reward' => 'free' ) ), array( 'label' => 'Demo 3 Al 2 Öde' ) );
$two_units = ce_ctx( array( ce_item( 'm', 911, 2, 4990 ) ) );
$offers    = $finder->find( $two_units, array( $three_two ) );
ce_check( '(g) 2 units with "3 al 2 öde" → one offer: 1 more unit', 1 === count( $offers ) && Offer::KIND_QUANTITY === $offers[0]->kind && 1 === $offers[0]->missing() && 3 === $offers[0]->target );
ce_check( '(g) the offer knows its gain (one free unit), the line to add to and the label', 4990 === $offers[0]->value_minor && $offers[0]->value_known && 'm' === $offers[0]->item_key && 'free' === $offers[0]->reward['type'] && 'Demo 3 Al 2 Öde' === $offers[0]->label );
$three_units = ce_ctx( array( ce_item( 'm', 911, 3, 4990 ) ) );
ce_check( '(g) already qualifying → no offer', array() === $finder->find( $three_units, array( $three_two ) ) );
$applied = ce_eval( $two_units, array( $three_two ) )->appliedCampaignIds();
ce_check( '(g) campaigns passed as applied are skipped', array() === $finder->find( $two_units, array( $three_two ), array( 60 ) ) && array() === $applied );
$nth    = ce_campaign( 61, array( array( 'type' => 'set_reward', 'set_size' => 2, 'reward_count' => 1, 'reward' => 'percent', 'value' => '30' ) ) );
$offers = $finder->find( ce_ctx( array( ce_item( 'n', 912, 1, 6000 ) ) ), array( $nth ) );
ce_check( '(g) "2. üründe %30" with one unit → 1 more unit, gain 1800, reward percent 30', 1 === count( $offers ) && 1 === $offers[0]->missing() && 1800 === $offers[0]->value_minor && 'percent' === $offers[0]->reward['type'] && '30' === $offers[0]->reward['value'] );
$gated  = ce_campaign( 62, array( array( 'type' => 'set_reward', 'set_size' => 3, 'reward_count' => 1, 'reward' => 'free' ) ), array( 'rules' => array( 'condition' => 'customer.role', 'cmp' => 'in', 'value' => array( 'demo_uye' ) ) ) );
ce_check( '(g) other conditions failing → no offer (no promise the customer cannot get)', array() === $finder->find( $two_units, array( $gated ) ) );
$threshold = ce_campaign( 63, array( array( 'type' => 'cart_percent', 'percent' => '8' ) ), array( 'rules' => array( 'op' => 'and', 'children' => array( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 60000 ) ) ) ) );
$offers    = $finder->find( ce_ctx( array( ce_item( 'a', 913, 1, 52500 ) ) ), array( $threshold ) );
ce_check( '(g) amount threshold: 75,00 more for 8 % (4800 at the threshold)', 1 === count( $offers ) && Offer::KIND_AMOUNT === $offers[0]->kind && 7500 === $offers[0]->missing() && 4800 === $offers[0]->value_minor && 'percent' === $offers[0]->reward['type'] );
ce_check( '(g) amount threshold already met → no offer', array() === $finder->find( ce_ctx( array( ce_item( 'a', 913, 1, 60000 ) ) ), array( $threshold ) ) );
$or_rule = ce_campaign( 64, array( array( 'type' => 'cart_percent', 'percent' => '8' ) ), array( 'rules' => array( 'op' => 'or', 'children' => array( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 60000 ), array( 'condition' => 'customer.logged_in', 'value' => true ) ) ) ) );
ce_check( '(g) thresholds under OR are not promised', array() === $finder->find( ce_ctx( array( ce_item( 'a', 913, 1, 52500 ) ) ), array( $or_rule ) ) );
$ship   = ce_campaign( 65, array( array( 'type' => 'free_shipping' ) ), array( 'rules' => array( 'condition' => 'cart.eligible_subtotal', 'cmp' => '>', 'value' => 89999 ) ) );
$offers = $finder->find( ce_ctx( array( ce_item( 'a', 913, 1, 52500 ) ) ), array( $ship ), array(), 4590 );
ce_check( '(g) free shipping threshold: value is the given shipping cost, ">" means one minor unit more', 1 === count( $offers ) && 90000 === $offers[0]->target && 4590 === $offers[0]->value_minor && 'free_shipping' === $offers[0]->reward['type'] );
$tiers  = ce_campaign( 66, array( array( 'type' => 'tiered', 'measure' => 'quantity', 'tiers' => array( array( 'min' => 4, 'type' => 'percent', 'value' => '12' ) ) ) ) );
$offers = $finder->find( $two_units, array( $tiers ) );
ce_check( '(g) tier near-miss: 2 more units for 12 % on 4 units', 1 === count( $offers ) && 2 === $offers[0]->missing() && 2396 === $offers[0]->value_minor );
$all    = $finder->find( $two_units, array( $three_two, $tiers, $nth ) );
ce_check( '(g) at most two offers, best value per effort first', 2 === count( $all ) && 60 === $all[0]->campaign_id );
ce_check( '(g) an inactive campaign gives no offer', array() === $finder->find( $two_units, array( ce_campaign( 67, array( array( 'type' => 'set_reward', 'set_size' => 3, 'reward_count' => 1, 'reward' => 'free' ) ), array( 'schedule' => array( 'ends_ts' => ce_now( '2026-10-01 00:00:00' )->getTimestamp() ) ) ) ) ) );

echo "ce-engine-resolver: all checks passed\n";
