<?php
/**
 * Campaign Engine — money math, unit allocation and every action: product percent/fixed/price, cart
 * percent/fixed, tiered, set reward ("3 al 2 öde", "N. üründe %", mixed pools, paired bundles), free
 * shipping and future rewards. Pure PHP with invented carts.
 *
 *   wp eval-file tests/php/ce-engine-actions.php
 */

use Erusoft\CampaignEngine\Engine\Action\ActionRegistry;
use Erusoft\CampaignEngine\Engine\Action\TieredAction;
use Erusoft\CampaignEngine\Engine\Adjustment\UnitAllocation;
use Erusoft\CampaignEngine\Engine\Args;
use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Money;

require __DIR__ . '/ce-helpers.php';
ce_require_engine();

// ---------------------------------------------------------------- Money
ce_check( 'Money::of parses dot and comma decimals exactly', 3333 === Money::of( '33,33' ) && 3333 === Money::of( '33.33' ) && 29 === Money::of( '0.29' ) && 100000 === Money::of( '1000' ) && 1 === Money::of( '0,01' ) );
ce_check( 'Money::of rounds half up past the currency decimals', 1235 === Money::of( '12.345' ) && 1234 === Money::of( '12.344' ) && 12 === Money::of( '12.4', 0 ) && 12345 === Money::of( '12.345', 3 ) );
ce_check( 'Money::of accepts ints and floats, rejects junk as 0', 4500 === Money::of( 45 ) && 1999 === Money::of( 19.99 ) && 0 === Money::of( 'abc' ) && 0 === Money::of( '' ) && 0 === Money::of( '1.2.3' ) );
ce_check( 'Money::of keeps the sign of negatives', -250 === Money::of( '-2,50' ) );
ce_check( 'Money::toDecimal', '33.33' === Money::toDecimal( 3333 ) && '0.01' === Money::toDecimal( 1 ) && '12' === Money::toDecimal( 12, 0 ) && '-2.50' === Money::toDecimal( -250 ) );
ce_check( 'Money::percent rounds a discount up to the next minor unit', 1499 === Money::percent( 9990, '15' ) && 1500 === Money::percent( 10000, '15' ) && 0 === Money::percent( 0, '15' ) && 0 === Money::percent( 5000, '0' ) && 1 === Money::percent( 1, '12.5' ) );
ce_check( 'Money::percent accepts a comma and two decimals', 1250 === Money::percent( 10000, '12,5' ) && 1225 === Money::percent( 10000, '12.25' ) );
$d = Money::distribute( 100, array( 'x' => 1, 'y' => 1, 'z' => 1 ) );
ce_check( 'Money::distribute largest remainder keeps the exact total', 100 === array_sum( $d ) && array( 34, 33, 33 ) === array_values( $d ) );
$d1 = Money::distribute( 1001, array( 'p' => 3, 'q' => 7, 'r' => 5 ) );
$d2 = Money::distribute( 1001, array( 'r' => 5, 'q' => 7, 'p' => 3 ) );
ce_check( 'Money::distribute does not depend on key order', 1001 === array_sum( $d1 ) && $d1['p'] === $d2['p'] && $d1['q'] === $d2['q'] && $d1['r'] === $d2['r'] );
ce_check( 'Money::distribute with zero weights gives zeros', array( 'a' => 0, 'b' => 0 ) === Money::distribute( 500, array( 'a' => 0, 'b' => 0 ) ) );
$threw = false;
try {
	Money::distribute( -5, array( 1 ) );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
ce_check( 'Money::distribute refuses a negative total', $threw );

// ---------------------------------------------------------------- Args: strict readers, errors instead of silent blanks
/** The ArgumentError reason thrown by $fn, or '' when it did not throw. */
$reason = function ( callable $fn ) {
	try {
		$fn();
	} catch ( ArgumentError $e ) {
		return $e->field . ':' . $e->reason;
	}
	return '';
};
/** Trace code and reason of a campaign dropped for its arguments. */
$why = fn( $r, $id ) => ( $r->trace->failureFor( $id )['code'] ?? '' ) . '/' . ( $r->trace->failureFor( $id )['data']['reason'] ?? '' );
ce_check( 'Args::percent accepts floats, ints and decimal strings, canonical two-decimal output', '12.5' === Args::percent( 12.5 ) && '20' === Args::percent( 20.0 ) && '15' === Args::percent( 15 ) && '12.5' === Args::percent( '12,50' ) && '0.25' === Args::percent( '0.25' ) && '7' === Args::percent( ' 7 ' ) );
ce_check( 'Args::percent: more than two decimals is an error, not a blank', 'percent:too_precise' === $reason( fn() => Args::percent( '12.345' ) ) && 'percent:too_precise' === $reason( fn() => Args::percent( 12.345 ) ) );
ce_check( 'Args::percent: 0, 100, negatives and junk are errors', 'percent:out_of_range' === $reason( fn() => Args::percent( 0 ) ) && 'percent:out_of_range' === $reason( fn() => Args::percent( '100' ) ) && 'percent:out_of_range' === $reason( fn() => Args::percent( 100.0 ) ) && 'percent:invalid' === $reason( fn() => Args::percent( '-5' ) ) && 'percent:invalid' === $reason( fn() => Args::percent( '100abc' ) ) && 'percent:missing' === $reason( fn() => Args::percent( null ) ) && 'percent:missing' === $reason( fn() => Args::percent( '' ) ) );
ce_check( 'Args::minor accepts integral floats and strings', 10000 === Args::minor( 10000.0 ) && 10000 === Args::minor( '10000' ) && 10000 === Args::minor( '10000.00' ) && 0 === Args::minor( 0 ) );
ce_check( 'Args::minor: non-integral, comma, negative and junk inputs are errors', 'amount:not_integral' === $reason( fn() => Args::minor( 100.5, 'amount' ) ) && 'amount:not_integral' === $reason( fn() => Args::minor( '100.5', 'amount' ) ) && 'amount:invalid' === $reason( fn() => Args::minor( '75,00', 'amount' ) ) && 'amount:invalid' === $reason( fn() => Args::minor( -1, 'amount' ) ) && 'amount:invalid' === $reason( fn() => Args::minor( 'bedava', 'amount' ) ) && 'amount:missing' === $reason( fn() => Args::minor( null, 'amount' ) ) );
ce_check( 'Args::positiveInt refuses zero', 'set_size:out_of_range' === $reason( fn() => Args::positiveInt( 0, 'set_size' ) ) && 3 === Args::positiveInt( 3.0, 'set_size' ) );
ce_check( 'Args::choice: missing → default, unknown → error', 'every' === Args::choice( array(), 'repeat', array( 'every', 'once' ), 'every' ) && 'repeat:unknown_choice' === $reason( fn() => Args::choice( array( 'repeat' => 'sometimes' ), 'repeat', array( 'every', 'once' ), 'every' ) ) && 'reward:missing' === $reason( fn() => Args::choice( array(), 'reward', array( 'free' ), null ) ) );
ce_check( 'Args::idList: a malformed id is an error, not a silent drop', array( 4, 21 ) === Args::idList( array( '21', 21, 4.0, 4 ), 'category_ids' ) && 'category_ids:invalid' === $reason( fn() => Args::idList( array( 21, 'x' ), 'category_ids' ) ) && array() === Args::idList( null, 'category_ids' ) );

// ---------------------------------------------------------------- (a) unit allocation
$lines = array(
	'a' => array( 'qty' => 3, 'unit_minor' => 3333 ),
	'b' => array( 'qty' => 1, 'unit_minor' => 1 ),
);
$alloc = UnitAllocation::spread( 10000, $lines );
ce_check( '(a) 100,00 over 3×33,33 + 1×0,01 sums to exactly 10000', 10000 === array_sum( $alloc ) );
ce_check( '(a) no line negative or above its own total', $alloc['a'] >= 0 && $alloc['b'] >= 0 && $alloc['a'] <= 9999 && $alloc['b'] <= 1 );
$units = UnitAllocation::perUnit( $alloc['a'], 3 );
ce_check( '(a) per-unit split of line a sums to the line and stays within the unit price', array_sum( $units ) === $alloc['a'] && max( $units ) <= 3333 && min( $units ) >= 0 );
$alloc = UnitAllocation::spread( 5000, $lines );
$units = UnitAllocation::perUnit( $alloc['a'], 3 );
ce_check( '(a) 50,00 over the same lines: exact sum, unit shares differ by at most one minor unit', 5000 === array_sum( $alloc ) && array_sum( $units ) === $alloc['a'] && max( $units ) - min( $units ) <= 1 );
ce_check( '(a) perUnit puts the extra minor units on the first units', array( 1667, 1667, 1666 ) === UnitAllocation::perUnit( 5000, 3 ) && array() === UnitAllocation::perUnit( 10, 0 ) );
$alloc = UnitAllocation::spread( 15000, $lines );
ce_check( '(a) a discount above the cart is capped at the cart total', 10000 === array_sum( $alloc ) && 9999 === $alloc['a'] && 1 === $alloc['b'] );
$alloc = UnitAllocation::spread( 150, array( 'x' => array( 'qty' => 1, 'unit_minor' => 100, 'cap_minor' => 50 ), 'y' => array( 'qty' => 1, 'unit_minor' => 100 ) ) );
ce_check( '(a) a capped line passes its overflow to lines with room', 50 === $alloc['x'] && 100 === $alloc['y'] );
$r1 = UnitAllocation::spread( 777, array( 'k1' => array( 'qty' => 2, 'unit_minor' => 1250 ), 'k2' => array( 'qty' => 1, 'unit_minor' => 2500 ), 'k3' => array( 'qty' => 5, 'unit_minor' => 990 ) ) );
$r2 = UnitAllocation::spread( 777, array( 'k3' => array( 'qty' => 5, 'unit_minor' => 990 ), 'k2' => array( 'qty' => 1, 'unit_minor' => 2500 ), 'k1' => array( 'qty' => 2, 'unit_minor' => 1250 ) ) );
ce_check( '(a) allocation is stable: same lines in another order give the same shares', 777 === array_sum( $r1 ) && $r1['k1'] === $r2['k1'] && $r1['k2'] === $r2['k2'] && $r1['k3'] === $r2['k3'] );

// End to end: a cart-level fixed amount lands on the lines exactly.
$cart_a = ce_ctx( array( ce_item( 'a', 801, 3, 3333 ), ce_item( 'b', 802, 1, 1 ) ) );
$r      = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_fixed', 'amount' => 10000 ) ) ) ) );
ce_check( '(a) cart_fixed 100,00 → total discount exactly 10000 (9999 + 1)', 10000 === $r->adjustments->totalDiscount() && array( 'a' => 9999, 'b' => 1 ) === ce_lines( $r ) );
$r = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_fixed', 'amount' => 5000 ) ) ) ) );
ce_check( '(a) cart_fixed 50,00 → exactly 5000, never rounded up to a multiple of the quantity', 5000 === $r->adjustments->totalDiscount() );
$r = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_fixed', 'amount' => 25000 ) ) ) ) );
ce_check( '(a) cart_fixed above the cart → capped at the cart, no negative line', 10000 === $r->adjustments->totalDiscount() && 9999 === ce_lines( $r )['a'] );
$r = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_percent', 'percent' => '15' ) ) ) ) );
ce_check( '(a) cart_percent 15 → 1500 spread exactly', 1500 === $r->adjustments->totalDiscount() );
foreach ( array( '100', '100abc', '0', '-5', '', '12.345' ) as $bad ) {
	$r = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_percent', 'percent' => $bad ) ) ) ) );
	ce_check( "(a) cart_percent '{$bad}' is refused with a traced argument error", $r->adjustments->isEmpty() && 0 === strpos( $why( $r, 1 ), 'invalid_args/' ) );
}
foreach ( array( '12,5', 'bedava', -100, 0 ) as $bad ) {
	$r = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_fixed', 'amount' => $bad ) ) ) ) );
	ce_check( '(a) cart_fixed ' . var_export( $bad, true ) . ' is refused with a traced argument error', $r->adjustments->isEmpty() && 0 === strpos( $why( $r, 1 ), 'invalid_args/' ) );
}
foreach ( array( 12.5, '20.0', 20.0 ) as $ok ) {
	$r = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_percent', 'percent' => $ok ) ) ) ) );
	ce_check( '(a) cart_percent ' . var_export( $ok, true ) . ' as a float or decimal string still applies', $r->adjustments->totalDiscount() === Money::percent( 10000, Args::percent( $ok ) ) );
}
foreach ( array( 5000.0, '5000.00' ) as $ok ) {
	$r = ce_eval( $cart_a, array( ce_campaign( 1, array( array( 'type' => 'cart_fixed', 'amount' => $ok ) ) ) ) );
	ce_check( '(a) cart_fixed ' . var_export( $ok, true ) . ' (integral) applies exactly', 5000 === $r->adjustments->totalDiscount() );
}

// ---------------------------------------------------------------- product actions
$p = ce_ctx( array( ce_item( 'p1', 811, 3, 3333 ), ce_item( 'p2', 812, 1, 24990 ) ) );
$r = ce_eval( $p, array( ce_campaign( 2, array( array( 'type' => 'product_percent', 'percent' => '15' ) ) ) ) );
ce_check( 'product_percent 15: per unit rounded up (500 × 3, 3749)', array( 'p1' => 1500, 'p2' => 3749 ) === ce_lines( $r ) );
$r = ce_eval( $p, array( ce_campaign( 2, array( array( 'type' => 'product_fixed', 'amount' => 4000 ) ) ) ) );
ce_check( 'product_fixed 40,00 is capped at each unit price', array( 'p1' => 9999, 'p2' => 4000 ) === ce_lines( $r ) );
$r = ce_eval( $p, array( ce_campaign( 2, array( array( 'type' => 'product_price', 'price' => 19990 ) ) ) ) );
ce_check( 'product_price 199,90: only lines priced above it are reduced', array( 'p2' => 5000 ) === ce_lines( $r ) );
$sale = ce_ctx( array( ce_item( 's1', 821, 1, 7000, array( 'regular_minor' => 10990 ) ), ce_item( 's2', 822, 2, 9000, array( 'regular_minor' => 10990 ) ), ce_item( 's3', 823, 1, 5000 ) ) );
$r    = ce_eval( $sale, array( ce_campaign( 3, array( array( 'type' => 'product_percent', 'percent' => '20' ) ), array( 'basis' => 'regular' ) ) ) );
ce_check( 'basis regular: the target price is regular − 20 % (87,92); a sale price already below it gets nothing, one above it gets the difference', array( 's2' => 416, 's3' => 1000 ) === ce_lines( $r ) );
$r = ce_eval( $sale, array( ce_campaign( 3, array( array( 'type' => 'product_percent', 'percent' => '20' ) ), array( 'basis' => 'exclude_sale' ) ) ) );
ce_check( 'basis exclude_sale: lines on sale are skipped', array( 's3' => 1000 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'u', 824, 1, 11900, array( 'regular_minor' => 10990 ) ) ) ), array( ce_campaign( 3, array( array( 'type' => 'product_percent', 'percent' => '20' ) ), array( 'basis' => 'regular' ) ) ) );
ce_check( 'basis regular never gives more than the stated discount when the current price is above regular', array( 'u' => 2198 ) === ce_lines( $r ) );

// ---------------------------------------------------------------- (b) set reward
$three_two = array( 'type' => 'set_reward', 'set_size' => 3, 'reward_count' => 1, 'reward' => 'free', 'target' => 'cheapest' );
$r         = ce_eval( ce_ctx( array( ce_item( 'x', 601, 1, 8990 ), ce_item( 'y', 602, 1, 6450 ), ce_item( 'z', 603, 1, 12900 ) ) ), array( ce_campaign( 4, array( $three_two + array( 'pool' => 'mixed' ) ) ) ) );
ce_check( '(b) 3 al 2 öde, mixed prices: the cheapest unit is free', array( 'y' => 6450 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'm', 604, 7, 3990 ) ) ), array( ce_campaign( 4, array( $three_two ) ) ) );
ce_check( '(b) 7 units → 2 free', array( 'm' => 7980 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'm', 604, 7, 3990 ) ) ), array( ce_campaign( 4, array( $three_two + array( 'repeat' => 'once' ) ) ) ) );
ce_check( '(b) repeat once → 1 free out of 7', array( 'm' => 3990 ) === ce_lines( $r ) );
$two_lines = array( ce_item( 'p', 605, 2, 4500 ), ce_item( 'q', 606, 2, 3250 ) );
$r         = ce_eval( ce_ctx( $two_lines ), array( ce_campaign( 4, array( $three_two ) ) ) );
ce_check( '(b) per-product pool: 2 + 2 units of two products open no set', $r->adjustments->isEmpty() );
$r = ce_eval( ce_ctx( $two_lines ), array( ce_campaign( 4, array( $three_two + array( 'pool' => 'mixed' ) ) ) ) );
ce_check( '(b) mixed pool across two products: one set, its cheapest unit free', array( 'q' => 3250 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'p', 605, 4, 4500 ), ce_item( 'q', 606, 3, 3250 ) ) ), array( ce_campaign( 4, array( $three_two + array( 'pool' => 'mixed' ) ) ) ) );
ce_check( '(b) mixed pool 4 + 3 units: two sets built from the cheapest units up', array( 'p' => 4500, 'q' => 3250 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'p', 605, 1, 4500 ), ce_item( 'q', 606, 1, 3250 ), ce_item( 'p2', 605, 2, 4500 ) ) ), array( ce_campaign( 4, array( $three_two ) ) ) );
ce_check( '(b) two cart lines of the same product share one pool', array( 'p' => 4500 ) === ce_lines( $r ) || array( 'p2' => 4500 ) === ce_lines( $r ) );
$nth = array( 'type' => 'set_reward', 'set_size' => 2, 'reward_count' => 1, 'reward' => 'percent', 'value' => '50', 'target' => 'cheapest' );
$r   = ce_eval( ce_ctx( array( ce_item( 'n', 609, 3, 7990 ) ) ), array( ce_campaign( 5, array( $nth ) ) ) );
ce_check( '(b) 2. üründe %50: 3 units → one half-price unit', array( 'n' => 3995 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'x', 601, 1, 8990 ), ce_item( 'y', 602, 1, 6450 ) ) ), array( ce_campaign( 5, array( array( 'type' => 'set_reward', 'set_size' => 2, 'reward_count' => 1, 'reward' => 'percent', 'value' => '25', 'target' => 'most_expensive', 'pool' => 'mixed' ) ) ) ) );
ce_check( '(b) target most_expensive: 25 % off the dearer unit, rounded up', array( 'x' => 2248 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'f', 610, 4, 1200 ) ) ), array( ce_campaign( 5, array( array( 'type' => 'set_reward', 'set_size' => 2, 'reward_count' => 1, 'reward' => 'fixed', 'value' => 1500 ) ) ) ) );
ce_check( '(b) fixed reward is capped at the unit price', array( 'f' => 2400 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'k', 611, 5, 2000 ) ) ), array( ce_campaign( 5, array( array( 'type' => 'set_reward', 'set_size' => 5, 'reward_count' => 0, 'reward' => 'percent', 'value' => '20' ) ) ) ) );
ce_check( '(b) reward_count 0 = whole-set discount (koli)', array( 'k' => 2000 ) === ce_lines( $r ) );
foreach ( array( array( 'set_size' => 1 ), array( 'reward_count' => 3 ), array( 'reward' => 'bedava' ), array( 'reward' => 'percent', 'value' => '150' ), array( 'target' => 'same_item', 'pool' => 'mixed' ) ) as $bad ) {
	$r = ce_eval( ce_ctx( array( ce_item( 'm', 604, 6, 3990 ) ) ), array( ce_campaign( 4, array( array_merge( $three_two, $bad ) ) ) ) );
	ce_check( '(b) malformed set reward gives nothing and traces invalid_args: ' . json_encode( $bad ), $r->adjustments->isEmpty() && 0 === strpos( $why( $r, 4 ), 'invalid_args/' ) );
}
$bundle = $three_two + array( 'reward_products' => array( 608 ) );
$r      = ce_eval( ce_ctx( array( ce_item( 't', 607, 2, 5900 ), ce_item( 'g', 608, 1, 2750 ) ) ), array( ce_campaign( 6, array( $bundle ) ) ) );
ce_check( '(b) paired bundle: 2 trigger units unlock the reward product', array( 'g' => 2750 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 't', 607, 1, 5900 ), ce_item( 'g', 608, 1, 2750 ) ) ), array( ce_campaign( 6, array( $bundle ) ) ) );
ce_check( '(b) paired bundle: one trigger unit is not enough', $r->adjustments->isEmpty() );
$r = ce_eval( ce_ctx( array( ce_item( 't', 607, 5, 5900 ), ce_item( 'g', 608, 1, 2750, array( 'category_ids' => array( 51 ) ) ) ) ), array( ce_campaign( 6, array( array_merge( $three_two, array( 'reward_terms' => array( 51 ) ) ) ) ) ) );
ce_check( '(b) paired bundle by reward category: the reward is given as often as reward units allow', array( 'g' => 2750 ) === ce_lines( $r ) );
$adj = $r->adjustments->forItem( 'g' )[0];
ce_check( '(b) set reward meta carries the rewarded unit count', 1 === ( $adj->meta['reward_units'] ?? null ) );

// ---------------------------------------------------------------- (d) tiered
$amount_tiers = array(
	'type'    => 'tiered',
	'measure' => 'subtotal',
	'group'   => 'pool',
	'tiers'   => array(
		array( 'min' => 250000, 'type' => 'percent', 'value' => '12' ),
		array( 'min' => 100000, 'max' => 249999, 'type' => 'percent', 'value' => '5' ),
	),
);
$tier_cart = function ( $total ) {
	$half = intdiv( $total, 2 );
	return ce_ctx( array( ce_item( 't1', 831, 1, $half ), ce_item( 't2', 832, 1, $total - $half ) ) );
};
$r = ce_eval( $tier_cart( 99999 ), array( ce_campaign( 7, array( $amount_tiers ) ) ) );
ce_check( '(d) subtotal 999,99 → below the first tier, nothing', $r->adjustments->isEmpty() );
$r = ce_eval( $tier_cart( 100000 ), array( ce_campaign( 7, array( $amount_tiers ) ) ) );
ce_check( '(d) subtotal exactly 1.000,00 → the 1.000 tier (5 %)', 5000 === $r->adjustments->totalDiscount() );
$r = ce_eval( $tier_cart( 180000 ), array( ce_campaign( 7, array( $amount_tiers ) ) ) );
ce_check( '(d) subtotal 1.800,00 → 5 %', 9000 === $r->adjustments->totalDiscount() );
$r = ce_eval( $tier_cart( 249999 ), array( ce_campaign( 7, array( $amount_tiers ) ) ) );
ce_check( '(d) subtotal 2.499,99 → still 5 %', 12500 === $r->adjustments->totalDiscount() );
$r = ce_eval( $tier_cart( 250000 ), array( ce_campaign( 7, array( $amount_tiers ) ) ) );
ce_check( '(d) subtotal exactly 2.500,00 → the upper tier (12 %)', 30000 === $r->adjustments->totalDiscount() );
$qty_tiers = array(
	'type'    => 'tiered',
	'measure' => 'quantity',
	'tiers'   => array(
		array( 'min' => 3, 'max' => 5, 'type' => 'percent', 'value' => '5' ),
		array( 'min' => 6, 'type' => 'percent', 'value' => '15' ),
	),
);
$r = ce_eval( ce_ctx( array( ce_item( 'q', 833, 6, 1990 ) ) ), array( ce_campaign( 8, array( $qty_tiers ) ) ) );
ce_check( '(d) quantity tiers, all units: 6 units → 15 % on each (299 × 6)', array( 'q' => 1794 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'q', 833, 2, 1990 ), ce_item( 'w', 834, 2, 1990 ) ) ), array( ce_campaign( 8, array( $qty_tiers ) ) ) );
ce_check( '(d) quantity tiers per product: 2 + 2 units reach no tier', $r->adjustments->isEmpty() );
$incr = array(
	'type'    => 'tiered',
	'measure' => 'quantity',
	'mode'    => 'incremental',
	'group'   => 'pool',
	'tiers'   => array( array( 'min' => 3, 'type' => 'percent', 'value' => '20' ) ),
);
$r = ce_eval( ce_ctx( array( ce_item( 'c', 835, 2, 3000 ), ce_item( 'd', 836, 2, 1000 ) ) ), array( ce_campaign( 9, array( $incr ) ) ) );
ce_check( '(d) incremental: units numbered by ascending price, units 3–4 get 20 %', array( 'c' => 1200 ) === ce_lines( $r ) );
$norm = TieredAction::normaliseTiers( array( array( 'min' => '', 'value' => '50' ), array( 'min' => 0, 'value' => '50' ), array( 'min' => 5, 'max' => 'x' ), array( 'min' => 9, 'max' => 3 ), array( 'min' => '4', 'value' => '7' ) ) );
ce_check( '(d) normaliseTiers (lenient) drops malformed tiers, never repairs them', 1 === count( $norm ) && 4 === $norm[0]['min'] && null === $norm[0]['max'] );
$r = ce_eval( ce_ctx( array( ce_item( 'q', 833, 6, 1990 ) ) ), array( ce_campaign( 8, array( array( 'type' => 'tiered', 'tiers' => array( array( 'min' => 3, 'type' => 'percent', 'value' => '5' ), array( 'min' => '', 'type' => 'percent', 'value' => '50' ) ) ) ) ) ) );
ce_check( '(d) the action refuses a tier list with a malformed row (traced), instead of running with fewer tiers', $r->adjustments->isEmpty() && 'invalid_args/invalid' === $why( $r, 8 ) );

// Fixed tiers: one amount per qualifying group unless the tier says per unit.
$fixed_sub = array(
	'type'    => 'tiered',
	'measure' => 'subtotal',
	'group'   => 'pool',
	'tiers'   => array( array( 'min' => 100000, 'type' => 'fixed', 'value' => 7500 ) ),
);
$r = ce_eval( ce_ctx( array( ce_item( 'f1', 851, 3, 21990 ), ce_item( 'f2', 852, 2, 32490 ), ce_item( 'f3', 853, 1, 1 ) ) ), array( ce_campaign( 13, array( $fixed_sub ) ) ) );
$l = ce_lines( $r );
ce_check( '(d) fixed subtotal tier: ONE 75,00 for the group, spread exactly over its lines', 7500 === $r->adjustments->totalDiscount() && $l['f1'] > $l['f2'] && ( $l['f3'] ?? 0 ) <= 1 );
$r = ce_eval( ce_ctx( array( ce_item( 'g1', 854, 1, 60000 ), ce_item( 'g2', 855, 1, 45000 ) ) ), array( ce_campaign( 13, array( array_merge( $fixed_sub, array( 'tiers' => array( array( 'min' => 100000, 'type' => 'fixed', 'value' => 900000 ) ) ) ) ) ) ) );
ce_check( '(d) fixed subtotal tier above the group total is capped at the group total', 105000 === $r->adjustments->totalDiscount() );
$r = ce_eval( ce_ctx( array( ce_item( 'g1', 854, 1, 60000 ) ) ), array( ce_campaign( 13, array( array_merge( $fixed_sub, array( 'tiers' => array( array( 'min' => 50000, 'type' => 'fixed', 'value' => 2500, 'per' => 'unit' ) ) ) ) ) ) ) );
ce_check( '(d) per-unit fixed tiers are refused on a subtotal measure (traced)', $r->adjustments->isEmpty() && 'invalid_args/invalid' === $why( $r, 13 ) );
$fixed_qty = array(
	'type'  => 'tiered',
	'tiers' => array( array( 'min' => 3, 'type' => 'fixed', 'value' => 1500 ) ),
);
$r = ce_eval( ce_ctx( array( ce_item( 'q', 833, 4, 1990 ) ) ), array( ce_campaign( 14, array( $fixed_qty ) ) ) );
ce_check( '(d) fixed quantity tier, all units: 15,00 once for the product group, not per unit', array( 'q' => 1500 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'q', 833, 3, 1990 ), ce_item( 'w', 834, 4, 1250 ) ) ), array( ce_campaign( 14, array( $fixed_qty ) ) ) );
ce_check( '(d) fixed quantity tier per product: each qualifying product group gets its own 15,00', array( 'q' => 1500, 'w' => 1500 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'q', 833, 4, 1990 ) ) ), array( ce_campaign( 14, array( array( 'type' => 'tiered', 'tiers' => array( array( 'min' => 3, 'type' => 'fixed', 'value' => 500, 'per' => 'unit' ) ) ) ) ) ) );
ce_check( '(d) fixed quantity tier with per unit: 5,00 on each of the 4 units', array( 'q' => 2000 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'c', 835, 2, 3000 ), ce_item( 'd', 836, 2, 1000 ) ) ), array( ce_campaign( 15, array( array_merge( $incr, array( 'tiers' => array( array( 'min' => 3, 'type' => 'fixed', 'value' => 1000 ) ) ) ) ) ) ) );
ce_check( '(d) incremental fixed tier: 10,00 once, shared by the units the tier covers (units 3–4)', array( 'c' => 1000 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'c', 835, 2, 3000 ), ce_item( 'd', 836, 2, 1000 ) ) ), array( ce_campaign( 15, array( array_merge( $incr, array( 'tiers' => array( array( 'min' => 3, 'type' => 'fixed', 'value' => 700, 'per' => 'unit' ) ) ) ) ) ) ) );
ce_check( '(d) incremental per-unit fixed tier: 7,00 on each covered unit', array( 'c' => 1400 ) === ce_lines( $r ) );
$r = ce_eval( ce_ctx( array( ce_item( 'c', 835, 2, 300 ), ce_item( 'd', 836, 2, 100 ) ) ), array( ce_campaign( 15, array( array_merge( $incr, array( 'tiers' => array( array( 'min' => 3, 'type' => 'fixed', 'value' => 5000 ) ) ) ) ) ) ) );
ce_check( '(d) incremental fixed tier is capped at the covered units\' total', array( 'c' => 600 ) === ce_lines( $r ) );

// ---------------------------------------------------------------- free shipping and future reward
$ship = ce_campaign( 10, array( array( 'type' => 'free_shipping' ) ), array( 'rules' => array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 90000 ) ) );
$r    = ce_eval( ce_ctx( array( ce_item( 'h', 841, 2, 45000 ) ) ), array( $ship ) );
ce_check( 'free_shipping above 900,00 → flagged, no line discount', $r->adjustments->freeShipping() && array( 10 ) === $r->adjustments->freeShippingCampaignIds() && 0 === $r->adjustments->totalDiscount() && array( 10 ) === $r->appliedCampaignIds() );
$r = ce_eval( ce_ctx( array( ce_item( 'h', 841, 1, 89999 ) ) ), array( $ship ) );
ce_check( 'free_shipping below 900,00 → not flagged', ! $r->adjustments->freeShipping() );
$earn = ce_campaign( 11, array( array( 'type' => 'future_reward', 'reward' => 'fixed', 'value' => 7500, 'min_cart' => 30000, 'valid_days' => 30, 'category_ids' => array( '21', 21 ) ) ) );
$r    = ce_eval( ce_ctx( array( ce_item( 'e', 842, 1, 64900 ) ) ), array( $earn ) );
$rew  = $r->adjustments->forLevel( 'reward' );
ce_check( 'future_reward marks the cart with a fixed reward and its coupon terms', 1 === count( $rew ) && 7500 === $rew[0]->meta['amount_minor'] && 30000 === $rew[0]->meta['min_cart_minor'] && 30 === $rew[0]->meta['valid_days'] && array( 21 ) === $rew[0]->meta['category_ids'] && 0 === $r->adjustments->totalDiscount() );
$r   = ce_eval( ce_ctx( array( ce_item( 'e', 842, 1, 64900 ) ) ), array( ce_campaign( 11, array( array( 'type' => 'future_reward', 'reward' => 'percent', 'value' => '5' ) ) ) ) );
$rew = $r->adjustments->forLevel( 'reward' );
ce_check( 'future_reward percent of the eligible subtotal (3245)', 1 === count( $rew ) && 3245 === $rew[0]->meta['amount_minor'] );
$r = ce_eval( ce_ctx( array( ce_item( 'e', 842, 1, 64900 ) ) ), array( ce_campaign( 11, array( array( 'type' => 'future_reward', 'reward' => 'fixed', 'value' => '75,00' ) ) ) ) );
ce_check( 'future_reward with a comma amount is refused (traced)', $r->adjustments->isEmpty() && 'invalid_args/invalid' === $why( $r, 11 ) );
$r = ce_eval( ce_ctx( array( ce_item( 'e', 842, 1, 64900 ) ) ), array( ce_campaign( 11, array( array( 'type' => 'future_reward', 'value' => 7500, 'category_ids' => array( 21, 'x' ) ) ) ) ) );
ce_check( 'future_reward with a malformed category id is refused (traced), not widened', $r->adjustments->isEmpty() && 'invalid_args/invalid' === $why( $r, 11 ) );

// ---------------------------------------------------------------- registry
$ids = array_keys( ActionRegistry::withDefaults()->all() );
sort( $ids );
ce_check( 'action registry lists the nine actions', array( 'cart_fixed', 'cart_percent', 'free_shipping', 'future_reward', 'product_fixed', 'product_percent', 'product_price', 'set_reward', 'tiered' ) === $ids );
$r = ce_eval( $p, array( ce_campaign( 12, array( array( 'type' => 'nope' ) ) ) ) );
ce_check( 'unknown action type → campaign skipped with a trace code', $r->adjustments->isEmpty() && 'unknown_action' === ( $r->trace->failureFor( 12 )['code'] ?? '' ) );

echo "ce-engine-actions: all checks passed\n";
