<?php
/**
 * Campaign Engine — conditions, the AND/OR rule tree, schedules and recurrences, engine purity and the
 * PHP 8.1 syntax floor. Pure PHP: no database, no HTTP, no WordPress calls.
 *
 *   wp eval-file tests/php/ce-engine-conditions.php
 */

use Erusoft\CampaignEngine\Engine\Campaign\Recurrence;
use Erusoft\CampaignEngine\Engine\Campaign\Schedule;
use Erusoft\CampaignEngine\Engine\Condition\Comparator;
use Erusoft\CampaignEngine\Engine\Condition\ConditionRegistry;
use Erusoft\CampaignEngine\Engine\Condition\RuleTree;
use Erusoft\CampaignEngine\Engine\Context\MatchResult;
use Erusoft\CampaignEngine\Engine\Context\Scope;
use Erusoft\CampaignEngine\Engine\Money;

require __DIR__ . '/ce-helpers.php';
ce_require_engine();

$reg = ConditionRegistry::withDefaults();

/** Evaluates one tree against a context: item pass on every line, then the cart pass. */
$tree_passes = function ( $tree, $ctx ) use ( $reg ) {
	$scope    = new Scope( $ctx, new MatchResult( $ctx->items ) );
	$eligible = array();
	foreach ( $ctx->items as $item ) {
		if ( RuleTree::evaluate( $tree, $reg, $scope, $item ) ) {
			$eligible[] = $item;
		}
	}
	if ( array() === $eligible ) {
		return false;
	}
	return RuleTree::evaluateCart( $tree, $reg, new Scope( $ctx, new MatchResult( $eligible ) ) );
};
/** One condition node against one context (cart scope) or the first line (item scope). */
$cond = function ( array $node, $ctx ) use ( $reg ) {
	$c = $reg->get( $node['condition'] );
	if ( null === $c ) {
		return null;
	}
	$scope = new Scope( $ctx, new MatchResult( $ctx->items ) );
	return $c->matches( $scope, 'item' === $c->scope() ? $ctx->items[0] : null, $node );
};

// ---------------------------------------------------------------- registry and groups
$ids = array_keys( $reg->all() );
$need = array( 'cart.subtotal', 'cart.eligible_subtotal', 'cart.quantity', 'cart.eligible_quantity', 'cart.coupon', 'cart.payment_method', 'cart.shipping_method', 'cart.shipping_country', 'item.product', 'item.quantity', 'item.category', 'item.tag', 'item.brand', 'item.term', 'item.attribute', 'item.sku', 'item.on_sale', 'item.price', 'item.stock_status', 'item.stock_quantity', 'item.age_days', 'customer.logged_in', 'customer.role', 'customer.order_count', 'customer.first_order', 'customer.total_spent', 'customer.days_since_last_order', 'date.range', 'date.weekday', 'time.window' );
ce_check( 'registry: every documented condition is registered (' . count( $need ) . ')', array() === array_diff( $need, $ids ) );
$groups = array();
foreach ( $reg->all() as $c ) {
	$groups[ $c->group() ] = true;
}
ksort( $groups );
ce_check( 'registry: conditions are grouped cart / customer / datetime / item', array( 'cart', 'customer', 'datetime', 'item' ) === array_keys( $groups ) );
ce_check( 'registry: item conditions have item scope, the rest cart scope', 'item' === $reg->get( 'item.category' )->scope() && 'cart' === $reg->get( 'customer.role' )->scope() && 'cart' === $reg->get( 'time.window' )->scope() );

// ---------------------------------------------------------------- comparator
ce_check( 'comparator: numeric operators', Comparator::compare( 12990, '>=', 12990 ) && ! Comparator::compare( 12989, '>=', '12990' ) && Comparator::compare( 5, '<', 6 ) && Comparator::compare( 7, '!=', 8 ) );
ce_check( 'comparator: between is inclusive', Comparator::compare( 30, 'between', array( 30, 45 ) ) && Comparator::compare( 45, 'between', array( 30, 45 ) ) && ! Comparator::compare( 46, 'between', array( 30, 45 ) ) );
ce_check( 'comparator: in / not_in on scalars', Comparator::compare( 'cod', 'in', array( 'bacs', 'cod' ) ) && Comparator::compare( 'cheque', 'not_in', array( 'bacs', 'cod' ) ) );
ce_check( 'comparator: in / not_in on lists mean any / none shared', Comparator::compare( array( 3, 9 ), 'in', array( 9, 12 ) ) && ! Comparator::compare( array( 3, 9 ), 'not_in', array( 9 ) ) && Comparator::compare( array( 3 ), 'not_in', array( 9 ) ) );
ce_check( 'comparator: all needs every value', Comparator::compare( array( 3, 9, 12 ), 'all', array( 9, 12 ) ) && ! Comparator::compare( array( 3, 9 ), 'all', array( 9, 12 ) ) );
ce_check( 'comparator: unknown operator and non-list right side fail closed', ! Comparator::compare( 1, '=~', 1 ) && ! Comparator::compare( 1, 'in', 1 ) );

// ---------------------------------------------------------------- cart conditions
$line_a = ce_item( 'a', 701, 2, 34990, array( 'category_ids' => array( 21 ), 'tag_ids' => array( 31 ), 'brand_ids' => array( 41 ), 'attributes' => array( 'pa_renk' => array( 'lacivert' ) ), 'sku' => 'DEMO-001', 'regular_minor' => 39990, 'stock_status' => 'instock', 'stock_qty' => 4, 'published_ts' => ce_now( '2026-09-14 12:00:00' )->getTimestamp() ) );
$line_b = ce_item( 'b', 702, 1, 18450, array( 'category_ids' => array( 22 ) ) );
$ctx    = ce_ctx(
	array( $line_a, $line_b ),
	null,
	array( 'coupons' => array( 'demo-kod' ), 'payment_method' => 'bacs', 'shipping_methods' => array( 'flat_rate:4' ), 'shipping_country' => 'TR' ),
	array( 'id' => 9, 'logged_in' => true, 'roles' => array( 'customer' ), 'order_count' => 3, 'total_spent_minor' => 412500, 'last_order_ts' => ce_now( '2026-09-24 12:00:00' )->getTimestamp() )
);
ce_check( 'cart.subtotal compares minor units', true === $cond( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 88430 ), $ctx ) && false === $cond( array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 88431 ), $ctx ) );
ce_check( 'cart.quantity counts units', true === $cond( array( 'condition' => 'cart.quantity', 'cmp' => '==', 'value' => 3 ), $ctx ) );
$only_a = new Scope( $ctx, new MatchResult( array( $line_a ) ) );
ce_check( 'cart.eligible_subtotal / eligible_quantity read the eligible lines only', $reg->get( 'cart.eligible_subtotal' )->matches( $only_a, null, array( 'cmp' => '==', 'value' => 69980 ) ) && $reg->get( 'cart.eligible_quantity' )->matches( $only_a, null, array( 'cmp' => '==', 'value' => 2 ) ) );
ce_check( 'cart.coupon is case-insensitive', true === $cond( array( 'condition' => 'cart.coupon', 'cmp' => 'in', 'value' => array( 'DEMO-KOD' ) ), $ctx ) );
ce_check( 'cart.payment_method in / not_in', true === $cond( array( 'condition' => 'cart.payment_method', 'cmp' => 'in', 'value' => array( 'bacs' ) ), $ctx ) && false === $cond( array( 'condition' => 'cart.payment_method', 'cmp' => 'not_in', 'value' => array( 'bacs' ) ), $ctx ) );
ce_check( 'cart.shipping_method matches the method id or the full rate id', true === $cond( array( 'condition' => 'cart.shipping_method', 'cmp' => 'in', 'value' => array( 'flat_rate' ) ), $ctx ) && true === $cond( array( 'condition' => 'cart.shipping_method', 'cmp' => 'in', 'value' => array( 'flat_rate:4' ) ), $ctx ) && false === $cond( array( 'condition' => 'cart.shipping_method', 'cmp' => 'in', 'value' => array( 'local_pickup' ) ), $ctx ) );
ce_check( 'cart.shipping_country', true === $cond( array( 'condition' => 'cart.shipping_country', 'cmp' => 'in', 'value' => array( 'TR' ) ), $ctx ) );

// ---------------------------------------------------------------- item conditions
ce_check( 'item.product matches product or variation id', true === $cond( array( 'condition' => 'item.product', 'cmp' => 'in', 'value' => array( 701 ) ), $ctx ) && true === $cond( array( 'condition' => 'item.product', 'cmp' => 'in', 'value' => array( 7011 ) ), ce_ctx( array( ce_item( 'v', 701, 1, 100, array( 'variation_id' => 7011 ) ) ) ) ) );
ce_check( 'item.quantity reads the line quantity', true === $cond( array( 'condition' => 'item.quantity', 'cmp' => '>=', 'value' => 2 ), $ctx ) && false === $cond( array( 'condition' => 'item.quantity', 'cmp' => '>=', 'value' => 3 ), $ctx ) );
ce_check( 'item.category / tag / brand read their own taxonomy', true === $cond( array( 'condition' => 'item.category', 'cmp' => 'in', 'value' => array( 21 ) ), $ctx ) && false === $cond( array( 'condition' => 'item.category', 'cmp' => 'in', 'value' => array( 31 ) ), $ctx ) && true === $cond( array( 'condition' => 'item.tag', 'cmp' => 'in', 'value' => array( 31 ) ), $ctx ) && true === $cond( array( 'condition' => 'item.brand', 'cmp' => 'in', 'value' => array( 41 ) ), $ctx ) );
ce_check( 'item.term reads every taxonomy', true === $cond( array( 'condition' => 'item.term', 'cmp' => 'in', 'value' => array( 41 ) ), $ctx ) && true === $cond( array( 'condition' => 'item.term', 'cmp' => 'not_in', 'value' => array( 99 ) ), $ctx ) );
ce_check( 'item.attribute compares the values of one attribute', true === $cond( array( 'condition' => 'item.attribute', 'attribute' => 'pa_renk', 'cmp' => 'in', 'value' => array( 'Lacivert' ) ), $ctx ) && false === $cond( array( 'condition' => 'item.attribute', 'attribute' => 'pa_beden', 'cmp' => 'in', 'value' => array( 'lacivert' ) ), $ctx ) );
ce_check( 'item.sku is case-insensitive', true === $cond( array( 'condition' => 'item.sku', 'cmp' => 'in', 'value' => array( 'demo-001' ) ), $ctx ) );
ce_check( 'item.on_sale (regular above current)', true === $cond( array( 'condition' => 'item.on_sale', 'value' => true ), $ctx ) && false === $cond( array( 'condition' => 'item.on_sale', 'value' => false ), $ctx ) );
ce_check( 'item.price / stock_status / stock_quantity', true === $cond( array( 'condition' => 'item.price', 'cmp' => '>', 'value' => 30000 ), $ctx ) && true === $cond( array( 'condition' => 'item.stock_status', 'cmp' => 'in', 'value' => array( 'instock' ) ), $ctx ) && true === $cond( array( 'condition' => 'item.stock_quantity', 'cmp' => '<=', 'value' => 5 ), $ctx ) );
ce_check( 'item.age_days counts whole days since publishing', true === $cond( array( 'condition' => 'item.age_days', 'cmp' => '==', 'value' => 30 ), $ctx ) );
ce_check( 'item.age_days without a publish date never matches', false === $cond( array( 'condition' => 'item.age_days', 'cmp' => '>=', 'value' => 0 ), ce_ctx( array( $line_b ) ) ) );

// ---------------------------------------------------------------- customer conditions
ce_check( 'customer.logged_in / role', true === $cond( array( 'condition' => 'customer.logged_in', 'value' => true ), $ctx ) && true === $cond( array( 'condition' => 'customer.role', 'cmp' => 'in', 'value' => array( 'customer' ) ), $ctx ) && false === $cond( array( 'condition' => 'customer.role', 'cmp' => 'in', 'value' => array( 'demo_toptan' ) ), $ctx ) );
ce_check( 'customer.order_count / total_spent', true === $cond( array( 'condition' => 'customer.order_count', 'cmp' => '>=', 'value' => 3 ), $ctx ) && true === $cond( array( 'condition' => 'customer.total_spent', 'cmp' => '>', 'value' => 400000 ), $ctx ) );
ce_check( 'customer.first_order: false for a returning customer, true for a guest without orders', false === $cond( array( 'condition' => 'customer.first_order', 'value' => true ), $ctx ) && true === $cond( array( 'condition' => 'customer.first_order', 'value' => true ), ce_ctx( array( $line_b ) ) ) );
ce_check( 'customer.days_since_last_order; never ordered → no match', true === $cond( array( 'condition' => 'customer.days_since_last_order', 'cmp' => '==', 'value' => 20 ), $ctx ) && false === $cond( array( 'condition' => 'customer.days_since_last_order', 'cmp' => '>=', 'value' => 0 ), ce_ctx( array( $line_b ) ) ) );

// ---------------------------------------------------------------- date/time conditions (now = Wed 2026-10-14 12:00 Istanbul)
ce_check( 'date.weekday', true === $cond( array( 'condition' => 'date.weekday', 'value' => array( 'WE', 'TH' ) ), $ctx ) && false === $cond( array( 'condition' => 'date.weekday', 'value' => array( 'SA', 'SU' ) ), $ctx ) );
ce_check( 'time.window, end minute inclusive', true === $cond( array( 'condition' => 'time.window', 'from' => '09:30', 'to' => '12:00' ), $ctx ) && false === $cond( array( 'condition' => 'time.window', 'from' => '12:01', 'to' => '18:00' ), $ctx ) );
ce_check( 'time.window across midnight', true === $cond( array( 'condition' => 'time.window', 'from' => '22:00', 'to' => '02:00' ), ce_ctx( array( $line_b ), ce_now( '2026-10-14 01:15:00' ) ) ) && false === $cond( array( 'condition' => 'time.window', 'from' => '22:00', 'to' => '02:00' ), $ctx ) );
ce_check( 'time.window with a malformed clock fails closed', false === $cond( array( 'condition' => 'time.window', 'from' => '24:00', 'to' => '25:00' ), $ctx ) );
ce_check( 'date.range: date-only end includes the whole day, in the context zone', true === $cond( array( 'condition' => 'date.range', 'from' => '2026-10-10', 'to' => '2026-10-14' ), $ctx ) && false === $cond( array( 'condition' => 'date.range', 'from' => '2026-10-15', 'to' => '2026-10-20' ), $ctx ) && true === $cond( array( 'condition' => 'date.range', 'from' => '2026-10-14 11:59' ), $ctx ) );
ce_check( 'date.range accepts timestamps', true === $cond( array( 'condition' => 'date.range', 'from_ts' => ce_now()->getTimestamp(), 'to_ts' => ce_now()->getTimestamp() + 1 ), $ctx ) && false === $cond( array( 'condition' => 'date.range', 'to_ts' => ce_now()->getTimestamp() ), $ctx ) );

// ---------------------------------------------------------------- (e) rule tree truth table: A AND (B OR C)
$tree = array(
	'op'       => 'and',
	'children' => array(
		array( 'condition' => 'cart.subtotal', 'cmp' => '>=', 'value' => 60000 ),
		array(
			'op'       => 'or',
			'children' => array(
				array( 'condition' => 'customer.role', 'cmp' => 'in', 'value' => array( 'demo_uye' ) ),
				array( 'condition' => 'date.weekday', 'value' => array( 'SA', 'SU' ) ),
			),
		),
	),
);
$sat  = ce_now( '2026-10-17 15:00:00' );
$rows = array(
	'A B ¬C'  => array( ce_ctx( array( $line_a ), null, array(), array( 'roles' => array( 'demo_uye' ) ) ), true ),
	'A ¬B C'  => array( ce_ctx( array( $line_a ), $sat ), true ),
	'A ¬B ¬C' => array( ce_ctx( array( $line_a ) ), false ),
	'¬A B C'  => array( ce_ctx( array( $line_b ), $sat, array(), array( 'roles' => array( 'demo_uye' ) ) ), false ),
);
foreach ( $rows as $label => $row ) {
	ce_check( "(e) A AND (B OR C): {$label} → " . ( $row[1] ? 'true' : 'false' ), $row[1] === $tree_passes( $tree, $row[0] ) );
}
ce_check( '(e) empty tree and empty group pass', $tree_passes( null, $ctx ) && $tree_passes( array(), $ctx ) && $tree_passes( array( 'op' => 'or', 'children' => array() ), $ctx ) );
ce_check( '(e) unknown condition and unknown operator fail closed', ! $tree_passes( array( 'condition' => 'cart.nope' ), $ctx ) && ! $tree_passes( array( 'op' => 'xor', 'children' => array( array( 'condition' => 'cart.quantity', 'cmp' => '>=', 'value' => 1 ) ) ), $ctx ) );
// Item conditions filter lines in the item pass and count as satisfied in the cart pass.
$mixed = array(
	'op'       => 'and',
	'children' => array(
		array( 'condition' => 'item.category', 'cmp' => 'in', 'value' => array( 22 ) ),
		array( 'condition' => 'cart.eligible_quantity', 'cmp' => '>=', 'value' => 1 ),
	),
);
$scope    = new Scope( $ctx, new MatchResult( $ctx->items ) );
$eligible = array_values( array_filter( $ctx->items, fn( $i ) => RuleTree::evaluate( $mixed, $reg, $scope, $i ) ) );
ce_check( '(e) item pass keeps only matching lines; the cart pass then sees them', 1 === count( $eligible ) && 'b' === $eligible[0]->key && RuleTree::evaluateCart( $mixed, $reg, new Scope( $ctx, new MatchResult( $eligible ) ) ) );
ce_check( '(e) leaves() lists every condition node', 3 === count( RuleTree::leaves( $tree ) ) );

// ---------------------------------------------------------------- (f) schedule and recurrence
$ist     = new DateTimeZone( 'Europe/Istanbul' );
$weekend = Recurrence::fromArray( array( 'freq' => 'weekly_span', 'from_day' => 'FR', 'from' => '18:00', 'to_day' => 'SU', 'to' => '23:59' ) );
$sched   = new Schedule( null, null, $weekend, $ist );
ce_check( '(f) weekly span recurrence parses', $weekend->isValid() && 'weekly_span' === $weekend->freq() );
$cases = array(
	array( '2026-10-16 17:59:59', false ),
	array( '2026-10-16 18:00:00', true ),
	array( '2026-10-17 03:00:00', true ),
	array( '2026-10-18 23:59:30', true ),
	array( '2026-10-19 00:00:00', false ),
	array( '2026-10-14 20:00:00', false ),
);
foreach ( $cases as $case ) {
	ce_check( "(f) Fri 18:00–Sun 23:59 at {$case[0]} → " . ( $case[1] ? 'active' : 'inactive' ), $case[1] === $sched->isActiveAt( ce_now( $case[0] ) ) );
}
// The same instant given in UTC is judged in the schedule's zone.
ce_check( '(f) a UTC instant is judged in the site zone', $sched->isActiveAt( new DateTimeImmutable( '2026-10-16 15:00:00', new DateTimeZone( 'UTC' ) ) ) && ! $sched->isActiveAt( new DateTimeImmutable( '2026-10-16 14:59:00', new DateTimeZone( 'UTC' ) ) ) );
// DST-free zone sanity: 15:00 UTC on a Friday is 18:00 in Istanbul in January and in July alike.
ce_check( '(f) Istanbul has one offset all year: January and July Fridays open at 15:00 UTC', $sched->isActiveAt( new DateTimeImmutable( '2026-01-16 15:00:00', new DateTimeZone( 'UTC' ) ) ) && $sched->isActiveAt( new DateTimeImmutable( '2026-07-17 15:00:00', new DateTimeZone( 'UTC' ) ) ) && ! $sched->isActiveAt( new DateTimeImmutable( '2026-07-17 14:59:00', new DateTimeZone( 'UTC' ) ) ) );
$next = $sched->nextStart( ce_now( '2026-10-14 09:00:00' ) );
ce_check( '(f) next start from Wednesday is this Friday 18:00', null !== $next && '2026-10-16 18:00' === $next->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
$next = $sched->nextStart( ce_now( '2026-10-17 10:00:00' ) );
ce_check( '(f) next start while a window is open is the following Friday', null !== $next && '2026-10-23 18:00' === $next->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
$end = $sched->currentEnd( ce_now( '2026-10-17 10:00:00' ) );
ce_check( '(f) the open window ends Monday 00:00 (end minute inclusive)', null !== $end && '2026-10-19 00:00' === $end->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
ce_check( '(f) no current end outside a window', null === $sched->currentEnd( ce_now( '2026-10-14 09:00:00' ) ) );
// A zone with DST: the next start keeps the local wall clock across the October change.
$ber  = new DateTimeZone( 'Europe/Berlin' );
$bs   = new Schedule( null, null, $weekend, $ber );
$next = $bs->nextStart( new DateTimeImmutable( '2026-10-24 12:00:00', $ber ) );
ce_check( '(f) DST zone: next start stays 18:00 local across the clock change', null !== $next && '2026-10-30 18:00 +01:00' === $next->setTimezone( $ber )->format( 'Y-m-d H:i P' ) && $bs->isActiveAt( new DateTimeImmutable( '2026-10-24 12:00:00', $ber ) ) );
// Calendar window and recurrence together; the window's end is exclusive.
$season = new Schedule( ce_now( '2026-10-16 00:00:00' )->getTimestamp(), ce_now( '2026-10-25 00:00:00' )->getTimestamp(), $weekend, $ist );
ce_check( '(f) season bounds AND weekly window', $season->isActiveAt( ce_now( '2026-10-17 12:00:00' ) ) && $season->isActiveAt( ce_now( '2026-10-24 12:00:00' ) ) && ! $season->isActiveAt( ce_now( '2026-10-25 00:00:00' ) ) && ! $season->isActiveAt( ce_now( '2026-10-15 20:00:00' ) ) );
ce_check( '(f) no next start after the season ends', null === $season->nextStart( ce_now( '2026-10-24 12:00:00' ) ) );
ce_check( '(f) current end is clipped to the season end', '2026-10-25 00:00' === $season->currentEnd( ce_now( '2026-10-24 20:00:00' ) )->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
$later = new Schedule( ce_now( '2026-11-01 09:00:00' )->getTimestamp(), null, null, $ist );
ce_check( '(f) without recurrence the next start is the calendar start', $later->nextStart( ce_now() )->getTimestamp() === ce_now( '2026-11-01 09:00:00' )->getTimestamp() && ! $later->isActiveAt( ce_now() ) );
$daily = new Schedule( null, null, Recurrence::fromArray( array( 'freq' => 'daily', 'start' => '22:00', 'end' => '01:59' ) ), $ist );
ce_check( '(f) daily overnight window', $daily->isActiveAt( ce_now( '2026-10-15 01:30:00' ) ) && ! $daily->isActiveAt( ce_now( '2026-10-15 02:00:00' ) ) && '2026-10-14 22:00' === $daily->nextStart( ce_now() )->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
$weekly = new Schedule( null, null, Recurrence::fromArray( array( 'freq' => 'weekly', 'days' => array( 'TU', 'TH' ), 'start' => '10:00', 'end' => '13:59' ) ), $ist );
ce_check( '(f) weekly day list with a daily time window', ! $weekly->isActiveAt( ce_now() ) && $weekly->isActiveAt( ce_now( '2026-10-15 13:59:00' ) ) && '2026-10-15 10:00' === $weekly->nextStart( ce_now() )->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
$monthly = new Schedule( null, null, Recurrence::fromArray( array( 'freq' => 'monthly', 'days' => array( 31 ), 'start' => '00:00', 'end' => '23:59' ) ), $ist );
ce_check( '(f) monthly on the 31st skips short months', '2026-10-31 00:00' === $monthly->nextStart( ce_now() )->setTimezone( $ist )->format( 'Y-m-d H:i' ) && '2026-12-31 00:00' === $monthly->nextStart( ce_now( '2026-11-01 00:00:00' ) )->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
foreach ( array( array( 'freq' => 'hourly' ), array( 'freq' => 'weekly', 'days' => array( 'XX' ), 'start' => '10:00', 'end' => '11:00' ), array( 'freq' => 'daily', 'start' => '10:00' ), array( 'freq' => 'weekly_span', 'from_day' => 'FR', 'from' => '18:60', 'to_day' => 'SU', 'to' => '23:59' ), 'weekly' ) as $bad ) {
	$r = Recurrence::fromArray( $bad );
	ce_check( '(f) malformed recurrence is invalid and never active: ' . json_encode( $bad ), ! $r->isValid() && ! ( new Schedule( null, null, $r, $ist ) )->isActiveAt( ce_now() ) && null === ( new Schedule( null, null, $r, $ist ) )->nextStart( ce_now() ) );
}
ce_check( '(f) no recurrence means always (within the season)', Recurrence::fromArray( null )->isValid() && Schedule::always()->isActiveAt( ce_now() ) );
$flash = Schedule::flash( ce_now( '2026-10-14 11:30:00' )->getTimestamp(), 90, $ist );
ce_check( '(f) flash window: active, ends after its duration, flagged', $flash->isFlash() && $flash->isActiveAt( ce_now() ) && ! $flash->isActiveAt( ce_now( '2026-10-14 13:00:00' ) ) && '2026-10-14 13:00' === $flash->currentEnd( ce_now() )->setTimezone( $ist )->format( 'Y-m-d H:i' ) );
$round = Schedule::fromArray( $season->toArray() );
ce_check( '(f) schedule round-trips through toArray/fromArray', $round->toArray() === $season->toArray() && $round->isActiveAt( ce_now( '2026-10-17 12:00:00' ) ) );

// ---------------------------------------------------------------- (h) purity: no WordPress or WooCommerce symbol in src/Engine
$banned = array( 'wp_', 'get_option', 'update_option', 'WC(', '\\WC_', 'WC_', 'apply_filters', 'do_action', 'add_action', 'add_filter', '$wpdb', 'current_time', 'esc_', '__(', '_e(', 'ABSPATH', 'Eru_CE_', 'eru_ce_' );
$hits  = array();
$files = ce_src_files( 'Engine' );
foreach ( $files as $f ) {
	$src = (string) file_get_contents( $f );
	foreach ( $banned as $needle ) {
		if ( false !== stripos( $src, $needle ) ) {
			$hits[] = basename( $f ) . ':' . $needle;
		}
	}
	// The engine never reads the clock; "now" always comes from the context.
	if ( preg_match( '/\b(time|date|strtotime|mktime|microtime|hrtime)\s*\(|new\s+\\\\?DateTime(Immutable)?\s*\(\s*\)|new\s+\\\\?DateTime(Immutable)?\s*\(\s*[\'"]now/i', $src, $m ) ) {
		$hits[] = basename( $f ) . ':' . $m[0];
	}
}
ce_check( '(h) src/Engine has ' . count( $files ) . ' files', count( $files ) >= 40 );
ce_check( '(h) no WordPress/WooCommerce symbol or clock read in src/Engine' . ( $hits ? ' — ' . implode( ', ', $hits ) : '' ), array() === $hits );

// ---------------------------------------------------------------- (i) PHP 8.1 syntax floor over all of src/
$syntax = array();
foreach ( ce_src_files( '' ) as $f ) {
	$src  = (string) file_get_contents( $f );
	$code = '';
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$code .= is_array( $t ) ? $t[1] : $t;
	}
	$checks = array(
		'readonly class'       => '/\breadonly\s+(final\s+|abstract\s+)?class\b|\b(final|abstract)\s+readonly\s+class\b/i',
		'DNF type'             => '/\)\s*\|\s*\(|\|\s*\(\s*\\\\?[A-Za-z_]+\s*&|\(\s*\\\\?[A-Za-z_][\w\\\\]*\s*&\s*\\\\?[A-Za-z_][\w\\\\]*\s*\)\s*\|/',
		'standalone true/false/null type' => '/(\bfunction\b[^{;]*\)\s*:\s*\??(true|false|null)\s*[{;])|(\bfn\b[^{;]*?\)\s*:\s*\??(true|false|null)\s*=>)|([(,]\s*\??(true|false|null)\s+&?\$)|(\b(public|protected|private|readonly)\s+\??(true|false|null)\s+\$)/i',
	);
	foreach ( $checks as $label => $re ) {
		if ( preg_match( $re, $code, $m ) ) {
			$syntax[] = basename( $f ) . ': ' . $label . ' (' . trim( $m[0] ) . ')';
		}
	}
	if ( preg_match_all( '/\btrait\s+\w+\s*\{/i', $code, $tm, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $tm[0] as $hit ) {
			$body = substr( $code, $hit[1] + strlen( $hit[0] ) );
			$body = preg_split( '/\b(class|interface|trait|enum)\s+\w+/i', $body )[0];
			if ( preg_match( '/\bconst\b/i', $body ) ) {
				$syntax[] = basename( $f ) . ': const in trait';
			}
		}
	}
}
ce_check( '(i) no PHP 8.2+ syntax in src/' . ( $syntax ? ' — ' . implode( '; ', $syntax ) : '' ), array() === $syntax );
$php81 = getenv( 'CE_PHP81' ) ?: ( getenv( 'HOME' ) . '/Library/Application Support/Herd/bin/php81' );
if ( is_file( $php81 ) && is_executable( $php81 ) ) {
	$bad = array();
	foreach ( array_merge( ce_src_files( '' ), array( CE_PLUGIN_DIR . '/campaign-engine-for-woocommerce.php' ) ) as $f ) {
		exec( escapeshellarg( $php81 ) . ' -l ' . escapeshellarg( $f ) . ' 2>&1', $out, $code );
		if ( 0 !== $code ) {
			$bad[] = basename( $f );
		}
	}
	ce_check( '(i) php 8.1 lint passes on every file' . ( $bad ? ' — ' . implode( ', ', $bad ) : '' ), array() === $bad );
} else {
	echo "SKIP: (i) php 8.1 binary not found; grep checks only\n";
}

echo "ce-engine-conditions: all checks passed\n";
